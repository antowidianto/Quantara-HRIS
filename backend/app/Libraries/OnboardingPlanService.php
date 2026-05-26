<?php

namespace App\Libraries;

use Config\Database;
use Config\Services;

/**
 * OnboardingPlanService
 *
 * Service untuk konsolidasi flow "MPP-driven onboarding planning":
 *   1. Saat MPP dibuat, requestor mengisi job_grade, supervisor, manager,
 *      dan daftar asset yang dibutuhkan. Service ini sediakan sync helper
 *      untuk planned assets (CRUD bulk).
 *   2. Saat MPP di-convert ke job_postings, planning fields & asset list
 *      ikut tercopy ke posting (`syncFromMpp`).
 *   3. Saat candidate di-hire ke `employees`, planning fields:
 *        - job_grade_id, supervisor_id, manager_id → di-apply ke employee row
 *      dan asset list dipakai untuk `pre-allocate` asset_assignments.
 *      Hasilnya: HR/IT pas onboarding tinggal centang "sudah serah terima"
 *      → status pre_allocated 1 → 2 (handover done).
 *   4. Saldo cuti awal di-seed dengan filter `gender_restriction` di
 *      `leave_types` — cuti melahirkan auto-skip kalau employee laki-laki
 *      dan sebaliknya.
 *
 * Semua method publik di sini dirancang agar bisa di-unit-test sebagian
 * tanpa perlu DB (lihat method *Pure variant).
 *
 * Method publik:
 *   - genderMatchesLeaveType($empGender, $restriction): bool
 *   - isPlannedAssetValid($row): array (errors)
 *   - syncMppPlannedAssets($mppId, $plans, $userId): array
 *   - syncFromMpp($mppId, $jobPostingId, $userId): void
 *   - applyPlanningToEmployee($candidate, $jobPosting, $employeeId, $userId): array
 *   - seedInitialLeaveBalances($employeeId, $year=null): array
 *   - preAllocateAssetsForEmployee($employeeId, $jobPostingId, $userId): array
 *   - confirmAssetHandover($assignmentId, $userId, $params=[]): void
 */
class OnboardingPlanService
{
    /** Kategori asset yang valid (sinkron dengan AssetService dan migration 17) */
    public const ASSET_CATEGORIES = [
        'LAPTOP', 'DESKTOP', 'PHONE', 'VEHICLE', 'FURNITURE',
        'TOOL', 'ID_CARD', 'ACCESS_CARD', 'OTHER',
    ];

    // ─── Pure helpers (no DB) ──────────────────────────────────────

    /**
     * Cocokkan gender karyawan dengan `leave_types.gender_restriction`.
     *
     * Aturan:
     *   - restriction 'ALL'  → match siapa saja
     *   - restriction 'M'    → hanya karyawan M
     *   - restriction 'F'    → hanya karyawan F
     *   - restriction null   → diperlakukan sebagai 'ALL' (legacy data)
     *   - empGender null     → tidak match dengan restriction spesifik (M/F);
     *                          karyawan harus isi gender dulu untuk dapat cuti gender-specific
     *
     * Berbeda dari kebiasaan SQL "NULL = NULL = unknown" — di sini kita
     * pakai semantik bisnis: gender belum diisi berarti tidak boleh
     * mendapat saldo cuti yang gender-restricted, supaya tidak mis-issue.
     */
    public static function genderMatchesLeaveType(?string $empGender, ?string $restriction): bool
    {
        $restriction = $restriction !== null && $restriction !== '' ? strtoupper($restriction) : 'ALL';
        if ($restriction === 'ALL') return true;
        if ($empGender === null || $empGender === '') return false;
        return strtoupper($empGender) === $restriction;
    }

    /**
     * Validasi satu baris planned asset. Return array of error messages
     * (empty = valid).
     *
     * Aturan:
     *   - category wajib & harus salah satu dari ASSET_CATEGORIES
     *   - name wajib, length 2..200
     *   - is_required di-coerce 0/1
     *
     * @return string[]
     */
    public static function validatePlannedAssetRow(array $row): array
    {
        $errors = [];
        $cat = strtoupper(trim((string) ($row['category'] ?? '')));
        if ($cat === '') {
            $errors[] = 'category wajib diisi';
        } elseif (! in_array($cat, self::ASSET_CATEGORIES, true)) {
            $errors[] = "category '{$cat}' tidak valid (boleh: " . implode(',', self::ASSET_CATEGORIES) . ')';
        }
        $name = trim((string) ($row['name'] ?? ''));
        $len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($len < 2) {
            $errors[] = 'name minimal 2 karakter';
        } elseif ($len > 200) {
            $errors[] = 'name maksimal 200 karakter';
        }
        return $errors;
    }

    /**
     * Resolve candidate.gender → employees.gender. Skema candidate pakai
     * 'M' / 'F' / 'OTHER' / 'PREFER_NOT_TO_SAY' (lihat migration 30/50).
     * Tabel employees hanya M/F. Mapping konservatif:
     *   'M' / 'F' → diteruskan
     *   selain itu → null (HR isi manual)
     */
    public static function normalizeGenderForEmployee(?string $candidateGender): ?string
    {
        $g = strtoupper(trim((string) ($candidateGender ?? '')));
        return in_array($g, ['M', 'F'], true) ? $g : null;
    }

    // ─── DB-aware methods ──────────────────────────────────────────

    /**
     * Sync planned assets untuk satu MPP. Strategi `replace`: semua row
     * lama dihapus dan diganti dengan list baru. Idempotent.
     *
     * @param array<int, array{category:string, name:string, brand_model_pref?:string, asset_id?:int|null, is_required?:int|bool, notes?:string}> $plans
     * @return array{inserted: int, removed: int, errors: array<int, array{index:int, errors:array<string>}>}
     */
    public function syncMppPlannedAssets(int $mppId, array $plans, int $userId): array
    {
        $db = Database::connect();
        $mpp = $db->table('manpower_requests')->where('id', $mppId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $mpp) {
            throw new \InvalidArgumentException("MPP #{$mppId} tidak ditemukan");
        }
        if (! in_array($mpp['status'], ['DRAFT', 'SUBMITTED'], true)) {
            throw new \RuntimeException("Daftar asset MPP hanya bisa diubah saat status DRAFT/SUBMITTED, sekarang {$mpp['status']}");
        }

        $errors = [];
        $clean = [];
        foreach ($plans as $i => $p) {
            $rowErrors = self::validatePlannedAssetRow($p);
            if ($rowErrors) {
                $errors[] = ['index' => $i, 'errors' => $rowErrors];
                continue;
            }
            $clean[] = [
                'category'         => strtoupper(trim((string) $p['category'])),
                'name'             => trim((string) $p['name']),
                'brand_model_pref' => isset($p['brand_model_pref']) ? trim((string) $p['brand_model_pref']) : null,
                'asset_id'         => isset($p['asset_id']) && $p['asset_id'] ? (int) $p['asset_id'] : null,
                'is_required'      => (int) (bool) ($p['is_required'] ?? 1),
                'notes'            => $p['notes'] ?? null,
            ];
        }
        if ($errors) {
            // Ada baris invalid — abort sebelum mutasi DB.
            return ['inserted' => 0, 'removed' => 0, 'errors' => $errors];
        }

        // Validasi specific asset_id (kalau di-set) milik perusahaan
        // yang sama dan masih AVAILABLE.
        if (! empty($clean) && $db->tableExists('assets')) {
            $assetIds = array_filter(array_map(fn($r) => $r['asset_id'], $clean));
            if ($assetIds) {
                $rows = $db->table('assets')
                    ->select('id, company_id, status')
                    ->whereIn('id', $assetIds)
                    ->where('deleted_at IS NULL')
                    ->get()->getResultArray();
                $byId = [];
                foreach ($rows as $r) $byId[(int) $r['id']] = $r;
                foreach ($clean as $i => $row) {
                    if (! $row['asset_id']) continue;
                    $a = $byId[(int) $row['asset_id']] ?? null;
                    if (! $a) {
                        $errors[] = ['index' => $i, 'errors' => ["asset #{$row['asset_id']} tidak ditemukan"]];
                    } elseif ((int) $a['company_id'] !== (int) $mpp['company_id']) {
                        $errors[] = ['index' => $i, 'errors' => ["asset #{$row['asset_id']} dari perusahaan lain"]];
                    } elseif ($a['status'] !== 'AVAILABLE') {
                        $errors[] = ['index' => $i,
                            'errors' => ["asset #{$row['asset_id']} status {$a['status']} (harus AVAILABLE)"],
                        ];
                    }
                }
                if ($errors) return ['inserted' => 0, 'removed' => 0, 'errors' => $errors];
            }
        }

        // Replace strategy: hapus semua lalu insert. Aman karena ini
        // tabel planning, bukan transaksional.
        $removed = $db->table('manpower_request_assets')
            ->where('manpower_request_id', $mppId)
            ->countAllResults(false);
        $db->table('manpower_request_assets')->where('manpower_request_id', $mppId)->delete();

        $inserted = 0;
        if (! empty($clean)) {
            $now = date('Y-m-d H:i:s');
            $rows = [];
            foreach ($clean as $row) {
                $rows[] = array_merge($row, [
                    'company_id'          => (int) $mpp['company_id'],
                    'manpower_request_id' => $mppId,
                    'created_by'          => $userId,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
            $db->table('manpower_request_assets')->insertBatch($rows);
            $inserted = count($rows);
        }

        return ['inserted' => $inserted, 'removed' => $removed, 'errors' => []];
    }

    /**
     * Copy planning fields & planned assets dari MPP ke satu job_posting.
     * Dipanggil dari `ManpowerService::createJobPostingFromMpp` setelah
     * insert posting. Idempotent: kalau posting sudah punya assets, di-skip
     * supaya re-run tidak duplikasi. Field default_supervisor/manager hanya
     * di-set kalau posting belum punya nilai non-null (override aman).
     */
    public function syncFromMpp(int $mppId, int $jobPostingId, int $userId): void
    {
        $db = Database::connect();
        $mpp = $db->table('manpower_requests')->where('id', $mppId)->get()->getRowArray();
        $posting = $db->table('job_postings')->where('id', $jobPostingId)->get()->getRowArray();
        if (! $mpp || ! $posting) return;

        $postingCols = $db->getFieldNames('job_postings');
        $update = [];
        $now = date('Y-m-d H:i:s');

        foreach ([
            'job_grade_id'                    => 'job_grade_id',
            'proposed_supervisor_employee_id' => 'default_supervisor_employee_id',
            'proposed_manager_employee_id'    => 'default_manager_employee_id',
            // Sprint 6 — Cost Center carry-over MPP → posting
            'cost_center_id'                  => 'cost_center_id',
        ] as $mppCol => $postingCol) {
            if (! in_array($postingCol, $postingCols, true)) continue;
            if (! array_key_exists($mppCol, $mpp)) continue;
            $val = $mpp[$mppCol] !== null ? (int) $mpp[$mppCol] : null;
            // Hanya set kalau posting belum punya value (preserves manual
            // override TA saat publish).
            if ($val !== null && empty($posting[$postingCol])) {
                $update[$postingCol] = $val;
            }
        }
        if ($update) {
            $update['updated_at'] = $now;
            $db->table('job_postings')->where('id', $jobPostingId)->update($update);
        }

        // Copy planned assets (idempotent — skip kalau posting sudah punya)
        if ($db->tableExists('manpower_request_assets') && $db->tableExists('job_posting_assets')) {
            $existing = $db->table('job_posting_assets')->where('job_posting_id', $jobPostingId)->countAllResults();
            if ($existing === 0) {
                $plans = $db->table('manpower_request_assets')
                    ->where('manpower_request_id', $mppId)->get()->getResultArray();
                if ($plans) {
                    $rows = [];
                    foreach ($plans as $p) {
                        $rows[] = [
                            'company_id'      => (int) $mpp['company_id'],
                            'job_posting_id'  => $jobPostingId,
                            'category'        => (string) $p['category'],
                            'name'            => (string) $p['name'],
                            'brand_model_pref'=> $p['brand_model_pref'] ?? null,
                            'asset_id'        => $p['asset_id'] !== null ? (int) $p['asset_id'] : null,
                            'is_required'     => (int) ($p['is_required'] ?? 1),
                            'notes'           => $p['notes'] ?? null,
                            'created_at'      => $now,
                            'updated_at'      => $now,
                        ];
                    }
                    $db->table('job_posting_assets')->insertBatch($rows);
                }
            }
        }
    }

    /**
     * Apply planning fields ke employee row (job_grade, supervisor, manager).
     * Dipanggil dari hire flow setelah employee row baru di-insert.
     *
     * Sumber prioritas (dari paling tinggi):
     *   1. Field dari `$overrides` (HR isi manual di hire form)
     *   2. job_postings.default_*
     *   3. manpower_request.proposed_* (lookup via manpower_request_postings)
     *
     * Return ringkasan field apa saja yang ter-apply (untuk audit).
     *
     * @return array{job_grade_id: ?int, supervisor_id: ?int, manager_id: ?int, source: array<string,string>}
     */
    public function applyPlanningToEmployee(
        int $employeeId,
        ?int $jobPostingId,
        array $overrides = []
    ): array {
        $db = Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) {
            throw new \InvalidArgumentException("Employee #{$employeeId} tidak ditemukan");
        }
        $empCols = $db->getFieldNames('employees');

        $posting = null;
        if ($jobPostingId) {
            $posting = $db->table('job_postings')->where('id', $jobPostingId)->get()->getRowArray();
        }
        $mpp = null;
        if ($jobPostingId) {
            $row = $db->table('manpower_request_postings')
                ->where('job_posting_id', $jobPostingId)
                ->orderBy('id', 'ASC')->limit(1)
                ->get()->getRowArray();
            if ($row) {
                $mpp = $db->table('manpower_requests')
                    ->where('id', (int) $row['manpower_request_id'])
                    ->where('deleted_at IS NULL')
                    ->get()->getRowArray();
            }
        }

        // Resolver: pilih nilai pertama yang non-null antara overrides → posting → mpp
        $resolve = function (array $overrideKeys, array $postingKeys, array $mppKeys) use (
            $overrides, $posting, $mpp, &$source, &$srcKey
        ) {
            foreach ($overrideKeys as $k) {
                if (array_key_exists($k, $overrides) && $overrides[$k] !== null && $overrides[$k] !== '') {
                    $source[$srcKey] = 'override';
                    return (int) $overrides[$k];
                }
            }
            if ($posting) {
                foreach ($postingKeys as $k) {
                    if (! empty($posting[$k])) { $source[$srcKey] = 'job_posting'; return (int) $posting[$k]; }
                }
            }
            if ($mpp) {
                foreach ($mppKeys as $k) {
                    if (! empty($mpp[$k])) { $source[$srcKey] = 'mpp'; return (int) $mpp[$k]; }
                }
            }
            $source[$srcKey] = 'none';
            return null;
        };

        $source = [];
        $srcKey = 'job_grade_id';
        $jobGrade   = $resolve(['job_grade_id'],     ['job_grade_id'],                     ['job_grade_id']);
        $srcKey = 'supervisor_id';
        $supervisor = $resolve(['supervisor_id'],    ['default_supervisor_employee_id'],   ['proposed_supervisor_employee_id']);
        $srcKey = 'manager_id';
        $manager    = $resolve(['manager_id'],       ['default_manager_employee_id'],      ['proposed_manager_employee_id']);

        $update = [];
        if ($jobGrade !== null && in_array('job_grade_id', $empCols, true) && empty($emp['job_grade_id'])) {
            $update['job_grade_id'] = $jobGrade;
        }
        if ($supervisor !== null && in_array('supervisor_id', $empCols, true) && empty($emp['supervisor_id'])) {
            // Validasi: supervisor harus dari company yang sama & bukan self-ref
            if ($supervisor !== $employeeId) {
                $sup = $db->table('employees')->where('id', $supervisor)->get()->getRowArray();
                if ($sup && (int) $sup['company_id'] === (int) $emp['company_id']) {
                    $update['supervisor_id'] = $supervisor;
                }
            }
        }
        if ($manager !== null && in_array('manager_id', $empCols, true) && empty($emp['manager_id'])) {
            if ($manager !== $employeeId) {
                $mgr = $db->table('employees')->where('id', $manager)->get()->getRowArray();
                if ($mgr && (int) $mgr['company_id'] === (int) $emp['company_id']) {
                    $update['manager_id'] = $manager;
                }
            }
        }

        if ($update) {
            $update['updated_at'] = date('Y-m-d H:i:s');
            $db->table('employees')->where('id', $employeeId)->update($update);
        }

        return [
            'job_grade_id'  => $jobGrade,
            'supervisor_id' => $supervisor,
            'manager_id'    => $manager,
            'source'        => $source,
        ];
    }

    /**
     * Generate saldo cuti awal untuk karyawan baru.
     *
     * Filter `gender_restriction`:
     *   - leave_types row dengan gender_restriction = 'M' hanya di-seed
     *     untuk karyawan M.
     *   - Sama untuk 'F' (cuti haid, melahirkan, dst).
     *   - 'ALL' untuk semua.
     *   - Karyawan dengan gender NULL hanya dapat tipe 'ALL'.
     *
     * Idempotent — kalau `employees.initial_balances_seeded_at` sudah terisi,
     * skip kecuali `$force = true`.
     *
     * @return array{seeded: int, skipped_gender: int, skipped_existing: int, year: int, types: array<int, array>}
     */
    public function seedInitialLeaveBalances(int $employeeId, ?int $year = null, bool $force = false): array
    {
        $db = Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) {
            throw new \InvalidArgumentException("Employee #{$employeeId} tidak ditemukan");
        }
        $empCols = $db->getFieldNames('employees');
        $hasFlag = in_array('initial_balances_seeded_at', $empCols, true);
        if (! $force && $hasFlag && ! empty($emp['initial_balances_seeded_at'])) {
            return ['seeded' => 0, 'skipped_gender' => 0, 'skipped_existing' => 0,
                'year' => $year ?? (int) date('Y'), 'types' => [], 'note' => 'already_seeded'];
        }

        $year = $year ?? (int) (date('Y', strtotime((string) ($emp['join_date'] ?? 'now'))));
        $types = $db->table('leave_types')
            ->where('company_id', (int) $emp['company_id'])
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();

        $leaveSvc = Services::leaveBalance();
        $seeded = 0;
        $skipGender = 0;
        $skipExisting = 0;
        $detail = [];
        foreach ($types as $t) {
            // Skip yang tidak reset_yearly atau tanpa default_days — ensureBalance
            // juga akan skip-nya, tapi early-skip biar lebih cepat.
            if ((int) ($t['reset_yearly'] ?? 1) !== 1) continue;
            if ((float) ($t['default_days'] ?? 0) <= 0) continue;

            $restriction = $t['gender_restriction'] ?? 'ALL';
            if (! self::genderMatchesLeaveType($emp['gender'] ?? null, $restriction)) {
                $skipGender++;
                $detail[] = [
                    'leave_type_id' => (int) $t['id'],
                    'code'          => $t['code'],
                    'name'          => $t['name'],
                    'status'        => 'skip_gender',
                    'restriction'   => $restriction,
                ];
                continue;
            }

            $bal = $leaveSvc->ensureBalance($employeeId, (int) $t['id'], $year);
            if (! $bal) continue;

            if (! empty($bal['__created'])) {
                $seeded++;
                $detail[] = [
                    'leave_type_id' => (int) $t['id'],
                    'code'          => $t['code'],
                    'name'          => $t['name'],
                    'status'        => 'created',
                    'allocated'     => (float) $bal['allocated'],
                    'remaining'     => (float) $bal['remaining'],
                    'prorate'       => $bal['__prorate'] ?? null,
                ];
            } else {
                $skipExisting++;
                $detail[] = [
                    'leave_type_id' => (int) $t['id'],
                    'code'          => $t['code'],
                    'name'          => $t['name'],
                    'status'        => 'existing',
                    'allocated'     => (float) $bal['allocated'],
                    'remaining'     => (float) $bal['remaining'],
                ];
            }
        }

        if ($hasFlag) {
            $db->table('employees')->where('id', $employeeId)->update([
                'initial_balances_seeded_at' => date('Y-m-d H:i:s'),
                'updated_at'                 => date('Y-m-d H:i:s'),
            ]);
        }

        return [
            'seeded'           => $seeded,
            'skipped_gender'   => $skipGender,
            'skipped_existing' => $skipExisting,
            'year'             => $year,
            'types'            => $detail,
        ];
    }

    /**
     * Pre-allocate asset untuk karyawan baru berdasarkan job_posting_assets.
     *
     * Logika:
     *   - Untuk setiap planned asset row di posting:
     *       - Kalau row punya specific asset_id dan asset masih AVAILABLE,
     *         langsung buat asset_assignments dengan flag pre_allocated=1
     *         (BAST belum dikeluarkan, IT/HR yang konfirmasi handover).
     *       - Kalau row punya asset_id tapi sudah tidak AVAILABLE, skip
     *         dan tambah ke `unfulfilled` untuk dilihat IT.
     *       - Kalau row tidak punya asset_id (IT akan pilihkan), buat
     *         "intent" log saja — tidak ada assignment, biar IT pilih
     *         secara manual via halaman Asset.
     *   - Pengecekan idempotency: kalau employee sudah punya assignment
     *     pre_allocated dari MPP yang sama, skip biar tidak dobel.
     *
     * @return array{
     *   pre_allocated: int,
     *   unfulfilled: int,
     *   intent_only: int,
     *   details: array<int, array>
     * }
     */
    public function preAllocateAssetsForEmployee(
        int $employeeId,
        ?int $jobPostingId,
        int $userId
    ): array {
        $db = Database::connect();
        if (! $jobPostingId || ! $db->tableExists('job_posting_assets')) {
            return ['pre_allocated' => 0, 'unfulfilled' => 0, 'intent_only' => 0, 'details' => []];
        }
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) {
            throw new \InvalidArgumentException("Employee #{$employeeId} tidak ditemukan");
        }

        // Resolve MPP id (untuk audit & idempotency check)
        $mppRow = $db->table('manpower_request_postings')
            ->where('job_posting_id', $jobPostingId)
            ->orderBy('id', 'ASC')->limit(1)
            ->get()->getRowArray();
        $mppId = $mppRow ? (int) $mppRow['manpower_request_id'] : null;

        $aaCols = $db->tableExists('asset_assignments')
            ? $db->getFieldNames('asset_assignments') : [];
        $supportsPreAllocFlag = in_array('pre_allocated', $aaCols, true);
        $supportsSourceFlag   = in_array('source_mpp_id', $aaCols, true);

        // Idempotency — kalau sudah pernah pre-alloc dari MPP yang sama, skip.
        if ($supportsSourceFlag && $mppId) {
            $existed = $db->table('asset_assignments')
                ->where('employee_id', $employeeId)
                ->where('source_mpp_id', $mppId)
                ->countAllResults();
            if ($existed > 0) {
                return [
                    'pre_allocated' => 0, 'unfulfilled' => 0, 'intent_only' => 0,
                    'details' => [], 'note' => 'already_pre_allocated',
                ];
            }
        }

        $plans = $db->table('job_posting_assets')
            ->where('job_posting_id', $jobPostingId)
            ->get()->getResultArray();

        $assetSvc = Services::asset();
        $now = date('Y-m-d H:i:s');
        $preAllocated = 0;
        $unfulfilled = 0;
        $intentOnly = 0;
        $details = [];

        foreach ($plans as $p) {
            $assetId = $p['asset_id'] !== null ? (int) $p['asset_id'] : null;
            if ($assetId) {
                $asset = $db->table('assets')
                    ->where('id', $assetId)->where('deleted_at IS NULL')
                    ->get()->getRowArray();
                if (! $asset) {
                    $unfulfilled++;
                    $details[] = [
                        'planned' => $p['name'], 'category' => $p['category'],
                        'status' => 'asset_missing', 'asset_id' => $assetId,
                    ];
                    continue;
                }
                if ($asset['status'] !== 'AVAILABLE') {
                    $unfulfilled++;
                    $details[] = [
                        'planned' => $p['name'], 'category' => $p['category'],
                        'status' => 'asset_not_available', 'asset_id' => $assetId,
                        'asset_status' => $asset['status'],
                    ];
                    continue;
                }
                if ((int) $asset['company_id'] !== (int) $emp['company_id']) {
                    $unfulfilled++;
                    $details[] = [
                        'planned' => $p['name'], 'category' => $p['category'],
                        'status' => 'asset_other_company', 'asset_id' => $assetId,
                    ];
                    continue;
                }

                // Buat assignment via AssetService — BAST otomatis ke-render.
                // Kalau kolom pre_allocated tersedia, set flag = 1 supaya
                // jelas ini reservasi (belum handover fisik).
                $assignmentId = $assetSvc->assignToEmployee($assetId, $employeeId, [
                    'assigned_at' => $now,
                    'purpose'     => 'Pre-allocation dari MPP'
                        . ($mppId ? " #{$mppId}" : '')
                        . ' — menunggu handover fisik saat onboarding.',
                    'condition_when_assigned' => $asset['condition'] ?? 'GOOD',
                    'assigned_by' => $userId,
                ]);

                $patch = ['updated_at' => $now];
                if ($supportsPreAllocFlag) $patch['pre_allocated'] = 1;
                if ($supportsSourceFlag)   $patch['source_mpp_id'] = $mppId;
                if (count($patch) > 1) {
                    $db->table('asset_assignments')->where('id', $assignmentId)->update($patch);
                }

                $preAllocated++;
                $details[] = [
                    'planned' => $p['name'], 'category' => $p['category'],
                    'status' => 'pre_allocated', 'asset_id' => $assetId,
                    'assignment_id' => $assignmentId,
                ];
            } else {
                // No specific asset → intent-only (IT pilih manual nanti)
                $intentOnly++;
                $details[] = [
                    'planned' => $p['name'], 'category' => $p['category'],
                    'status' => 'intent_only',
                    'note' => 'IT pilih unit yang sesuai. Brand/model pref: '
                        . ($p['brand_model_pref'] ?: '-'),
                ];
            }
        }

        return [
            'pre_allocated' => $preAllocated,
            'unfulfilled'   => $unfulfilled,
            'intent_only'   => $intentOnly,
            'details'       => $details,
        ];
    }

    /**
     * Konfirmasi handover fisik asset yang sebelumnya pre-allocated.
     * Dipanggil saat HR/IT klik "Sudah serah terima" di halaman onboarding.
     *
     * - Set pre_allocated=2 (handover done)
     * - Set employee_acknowledged_at kalau belum
     * - Update purpose-note tambahan (timestamp handover)
     */
    public function confirmAssetHandover(int $assignmentId, int $userId, array $params = []): void
    {
        $db = Database::connect();
        $aaCols = $db->getFieldNames('asset_assignments');
        $row = $db->table('asset_assignments')->where('id', $assignmentId)->get()->getRowArray();
        if (! $row) {
            throw new \InvalidArgumentException("Assignment #{$assignmentId} tidak ditemukan");
        }
        $now = date('Y-m-d H:i:s');
        $update = ['updated_at' => $now];
        if (in_array('pre_allocated', $aaCols, true)) {
            $update['pre_allocated'] = 2;
        }
        if (in_array('employee_acknowledged_at', $aaCols, true) && empty($row['employee_acknowledged_at'])) {
            $update['employee_acknowledged_at'] = $now;
        }
        $db->table('asset_assignments')->where('id', $assignmentId)->update($update);

        try {
            Services::audit()->forModule('ASSET')->log(
                action: 'asset.handover_confirmed',
                entity: 'asset_assignment',
                entityId: $assignmentId,
                description: "Handover asset assignment #{$assignmentId} dikonfirmasi oleh user #{$userId}",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {
            // ignore audit failures
        }
    }
}