<?php

namespace App\Libraries\Approval;

use Config\Database;

/**
 * ReportingChainService — auto-resolve `employees.manager_id` (direct
 * report) berdasarkan kombinasi:
 *
 *   - `positions.tier`     : MANAGER / ASST_MANAGER / SUPERVISOR / STAFF
 *   - `employees.department_id`
 *   - `departments.parent_id`
 *
 * Aturan rantai (cari atasan dari paling dekat ke paling jauh):
 *
 *   STAFF        → SUPERVISOR (dept sama)
 *                → ASST_MANAGER (dept sama)
 *                → MANAGER (dept sama)
 *                → MANAGER (parent dept, naik sampai ketemu)
 *
 *   SUPERVISOR   → ASST_MANAGER (dept sama)
 *                → MANAGER (dept sama)
 *                → MANAGER (parent dept, naik)
 *
 *   ASST_MANAGER → MANAGER (dept sama)
 *                → MANAGER (parent dept, naik)
 *
 *   MANAGER      → MANAGER (parent dept, naik)
 *                → kalau tidak ada → manager_id = NULL (top of org)
 *
 * Kalau ada banyak orang di tier yang sama (mis. dua MANAGER di satu
 * department), pilih yang `id` paling kecil supaya hasilnya deterministik
 * dan stabil antar pemanggilan.
 *
 * Self-reference dicegah: kalau kandidat = employee itu sendiri, lewati.
 *
 * Service ini dipanggil dari OrgStructureController setelah setiap
 * perubahan struktur (move-department, move-position, set-manager,
 * bulk-reorder). Bisa juga dipanggil manual via CLI atau script
 * maintenance.
 */
class ReportingChainService
{
    /** Urutan tier dari atas ke bawah. */
    private const TIER_ORDER = ['MANAGER', 'ASST_MANAGER', 'SUPERVISOR', 'STAFF'];

    /**
     * Hitung ulang manager_id untuk seluruh karyawan aktif di perusahaan.
     * Returns: ['updated' => N, 'unchanged' => M, 'errors' => [...]]
     */
    public function recomputeForCompany(int $companyId): array
    {
        return $this->computeForCompany($companyId, true);
    }

    /**
     * Preview recompute tanpa menulis ke database. Dipakai UI sebelum user
     * klik sinkron supaya dampaknya jelas.
     */
    public function previewForCompany(int $companyId): array
    {
        return $this->computeForCompany($companyId, false);
    }

    private function computeForCompany(int $companyId, bool $apply): array
    {
        $db = Database::connect();

        // 1) Load department parent map (id => parent_id|null) sekali.
        $deptParent = [];
        $rows = $db->table('departments')
            ->select('id, parent_id')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()
            ->getResultArray();
        foreach ($rows as $r) {
            $deptParent[(int) $r['id']] = $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
        }

        // 2) Load semua karyawan aktif + posisi/tier-nya. Sekali query.
        $emps = $db->table('employees e')
            ->select('e.id, e.employee_number, e.full_name, e.department_id, e.position_id, e.manager_id, e.supervisor_id, p.tier')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->where('e.company_id', $companyId)
            ->where('e.is_active', 1)
            ->where('e.deleted_at IS NULL')
            ->get()
            ->getResultArray();

        // 3) Index: deptId => [tier => [employeeId, ...]]
        $byDeptTier = [];
        foreach ($emps as $e) {
            $did = $e['department_id'] !== null ? (int) $e['department_id'] : 0;
            $tier = $e['tier'] ?: 'STAFF';
            if (! $did) continue;
            $byDeptTier[$did][$tier][] = (int) $e['id'];
        }
        // Sort tiap bucket biar deterministik.
        foreach ($byDeptTier as $did => &$tiers) {
            foreach ($tiers as $t => &$ids) sort($ids);
        }
        unset($tiers, $ids);

        // 4) Untuk tiap karyawan, resolve atasan baru.
        $updated = 0; $unchanged = 0; $managerUpdated = 0; $supervisorUpdated = 0; $errors = []; $sample = [];
        $now = date('Y-m-d H:i:s');

        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            $did = $e['department_id'] !== null ? (int) $e['department_id'] : null;
            $tier = $e['tier'] ?: 'STAFF';
            $oldManager = $e['manager_id'] !== null ? (int) $e['manager_id'] : null;
            $oldSupervisor = $e['supervisor_id'] !== null ? (int) $e['supervisor_id'] : null;

            $newManager = $this->resolveManager($eid, $tier, $did, $byDeptTier, $deptParent);
            $update = [];
            if ($newManager !== $oldManager) {
                $update['manager_id'] = $newManager;
                $managerUpdated++;
            }

            // supervisor_id adalah approval-line manual. Ikut sinkron hanya
            // jika masih kosong atau sebelumnya sama dengan manager_id lama.
            if ($oldSupervisor === null || $oldSupervisor === $oldManager) {
                if ($oldSupervisor !== $newManager) {
                    $update['supervisor_id'] = $newManager;
                    $supervisorUpdated++;
                }
            }

            if (empty($update)) {
                $unchanged++;
                continue;
            }
            if (count($sample) < 10) {
                $sample[] = [
                    'employee_id' => $eid,
                    'employee_number' => $e['employee_number'] ?? null,
                    'employee_name' => $e['full_name'] ?? null,
                    'department_id' => $did,
                    'old_manager_id' => $oldManager,
                    'new_manager_id' => $newManager,
                    'old_supervisor_id' => $oldSupervisor,
                    'new_supervisor_id' => $update['supervisor_id'] ?? $oldSupervisor,
                ];
            }
            $update['updated_at'] = $now;
            if ($apply) {
                $db->table('employees')->where('id', $eid)->update($update);
            }
            $updated++;
        }

        return [
            'updated' => $updated,
            'unchanged' => $unchanged,
            'manager_updated' => $managerUpdated,
            'supervisor_updated' => $supervisorUpdated,
            'sample' => $sample,
            'errors' => $errors,
        ];
    }

    /**
     * Hanya recompute karyawan dalam subtree department yang diberikan
     * (department itu sendiri + semua descendant). Lebih cepat untuk
     * kasus move-department di mana hanya cabang yang berubah.
     */
    public function recomputeForDepartmentSubtree(int $companyId, int $rootDeptId): array
    {
        $db = Database::connect();

        // Build child map sekali → walk subtree.
        $childMap = [];
        $rows = $db->table('departments')
            ->select('id, parent_id')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()
            ->getResultArray();
        $deptParent = [];
        foreach ($rows as $r) {
            $deptParent[(int) $r['id']] = $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
            if ($r['parent_id'] !== null) {
                $childMap[(int) $r['parent_id']][] = (int) $r['id'];
            }
        }
        $deptIds = [$rootDeptId];
        $stack = [$rootDeptId];
        while (! empty($stack)) {
            $cur = array_pop($stack);
            foreach (($childMap[$cur] ?? []) as $c) {
                $deptIds[] = $c;
                $stack[] = $c;
            }
        }

        // Load semua employee di company (perlu untuk resolve atasan
        // yang mungkin di luar subtree, mis. ke parent dept).
        $emps = $db->table('employees e')
            ->select('e.id, e.department_id, e.position_id, e.manager_id, e.supervisor_id, p.tier')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->where('e.company_id', $companyId)
            ->where('e.is_active', 1)
            ->where('e.deleted_at IS NULL')
            ->get()
            ->getResultArray();

        $byDeptTier = [];
        foreach ($emps as $e) {
            $did = $e['department_id'] !== null ? (int) $e['department_id'] : 0;
            $tier = $e['tier'] ?: 'STAFF';
            if (! $did) continue;
            $byDeptTier[$did][$tier][] = (int) $e['id'];
        }
        foreach ($byDeptTier as $did => &$tiers) {
            foreach ($tiers as $t => &$ids) sort($ids);
        }
        unset($tiers, $ids);

        $deptSet = array_flip($deptIds);
        $updated = 0; $unchanged = 0; $supervisorUpdated = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($emps as $e) {
            $did = $e['department_id'] !== null ? (int) $e['department_id'] : null;
            // Hanya update karyawan yang ada di subtree.
            if ($did === null || ! isset($deptSet[$did])) continue;

            $eid = (int) $e['id'];
            $tier = $e['tier'] ?: 'STAFF';
            $oldManager = $e['manager_id'] !== null ? (int) $e['manager_id'] : null;
            $oldSupervisor = $e['supervisor_id'] !== null ? (int) $e['supervisor_id'] : null;

            $newManager = $this->resolveManager($eid, $tier, $did, $byDeptTier, $deptParent);
            $update = [];
            if ($newManager !== $oldManager) {
                $update['manager_id'] = $newManager;
            }
            if ($oldSupervisor === null || $oldSupervisor === $oldManager) {
                if ($oldSupervisor !== $newManager) {
                    $update['supervisor_id'] = $newManager;
                    $supervisorUpdated++;
                }
            }
            if (empty($update)) {
                $unchanged++;
                continue;
            }
            $update['updated_at'] = $now;
            $db->table('employees')->where('id', $eid)->update($update);
            $updated++;
        }

        return [
            'updated' => $updated,
            'unchanged' => $unchanged,
            'supervisor_updated' => $supervisorUpdated,
            'subtree_size' => count($deptIds),
        ];
    }

    /**
     * Resolve manager untuk satu karyawan.
     * Return employee id (atasan) atau null.
     */
    private function resolveManager(int $selfId, string $tier, ?int $deptId, array $byDeptTier, array $deptParent): ?int
    {
        if ($deptId === null) return null;

        // Tier di atas tier saya, urut dari paling dekat.
        $higherTiers = $this->higherTiers($tier);

        // 1) Cari di department sendiri dulu (kecuali tier MANAGER yang
        //    selalu naik ke parent dept — manager tidak melapor ke
        //    sesama manager dalam dept yang sama secara otomatis).
        if ($tier !== 'MANAGER') {
            foreach ($higherTiers as $t) {
                $cand = $this->pickFirstNotSelf($byDeptTier[$deptId][$t] ?? [], $selfId);
                if ($cand !== null) return $cand;
            }
        }

        // 2) Naik ke parent department, cari MANAGER (lalu fallback
        //    ASST_MANAGER kalau parent dept memang tidak punya MANAGER —
        //    edge case organisasi yang belum lengkap).
        $cur = $deptParent[$deptId] ?? null;
        $hops = 0;
        while ($cur !== null) {
            foreach (['MANAGER', 'ASST_MANAGER', 'SUPERVISOR'] as $t) {
                $cand = $this->pickFirstNotSelf($byDeptTier[$cur][$t] ?? [], $selfId);
                if ($cand !== null) return $cand;
            }
            $cur = $deptParent[$cur] ?? null;
            if (++$hops > 100) break; // safety
        }
        return null; // top of org
    }

    private function higherTiers(string $tier): array
    {
        $idx = array_search($tier, self::TIER_ORDER, true);
        if ($idx === false || $idx === 0) return [];
        // Urut dari paling dekat (idx-1) ke paling jauh (0).
        return array_reverse(array_slice(self::TIER_ORDER, 0, $idx));
    }

    private function pickFirstNotSelf(array $ids, int $selfId): ?int
    {
        foreach ($ids as $id) {
            if ($id !== $selfId) return $id;
        }
        return null;
    }
}
