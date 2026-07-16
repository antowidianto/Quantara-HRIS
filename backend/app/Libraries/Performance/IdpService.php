<?php

namespace App\Libraries\Performance;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * IdpService — Individual Development Plan (Sprint 3 / OD-2).
 *
 * Closing-the-loop performance review. Saat HR/manager finalize
 * `performance_reviews`, field `improvements` (yang text-based) bisa
 * di-extract jadi terstruktur IDP dengan:
 *   - Gap analysis: area yang perlu improve (current vs target level)
 *   - Recommended trainings: link ke modul Training kalau ada match
 *   - Action items: konkret apa yang dilakukan + deadline
 *
 * Tanggung jawab:
 *   1. `createFromReview($reviewId)` — auto-bootstrap IDP DRAFT dari review
 *      data, parse improvements field jadi gaps[] awal.
 *   2. `update()` — manager/karyawan kolaboratif edit
 *   3. `finalize()` — DRAFT → ACTIVE (siap di-track)
 *   4. `complete()` — ACTIVE → COMPLETED (di akhir cycle)
 *   5. `progress` — hitung dari action_items.status
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item OD-2.
 */
class IdpService
{
    public const STATUSES = ['DRAFT', 'PENDING_APPROVAL', 'APPROVAL_REJECTED', 'ACTIVE', 'COMPLETED', 'REVIEWED', 'CANCELLED'];

    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    /**
     * Auto-bootstrap IDP dari performance review yang sudah finalize.
     * Idempotent — kalau review sudah punya IDP terkait, return existing
     * (tidak duplikasi).
     *
     * @return array IDP row + decoded JSON
     */
    public function createFromReview(int $reviewId, int $userId, int $companyId): array
    {
        $db = $this->db();
        $review = $db->table('performance_reviews')
            ->where('id', $reviewId)
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $review) {
            throw new \InvalidArgumentException("Review #{$reviewId} tidak ditemukan");
        }
        if (! in_array($review['status'], ['SUBMITTED', 'ACKNOWLEDGED', 'FINALIZED'], true)) {
            throw new \RuntimeException(
                "IDP hanya bisa di-generate dari review status SUBMITTED/ACKNOWLEDGED/FINALIZED. "
                . "Current: {$review['status']}"
            );
        }

        // Idempotent: cek IDP existing untuk kombinasi review_id + employee_id
        $existing = $db->table('individual_development_plans')
            ->where('review_id', $reviewId)
            ->where('employee_id', (int) $review['employee_id'])
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if ($existing) {
            return $this->decodeJsonFields($existing);
        }

        // Fetch cycle untuk plan_period_start/end
        $cycle = $db->table('performance_cycles')
            ->where('id', (int) $review['cycle_id'])->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        $planStart = date('Y-m-d');
        $planEnd = $cycle && ! empty($cycle['end_date'])
            ? $cycle['end_date']
            : date('Y-m-d', strtotime('+1 year'));

        // Parse improvements field jadi gaps awal. Format text, kita split
        // by newline/bullet, dan setiap line jadi 1 gap entry.
        $gaps = $this->parseImprovementsToGaps((string) ($review['improvements'] ?? ''));

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'          => (int) $review['company_id'],
            'employee_id'         => (int) $review['employee_id'],
            'cycle_id'            => (int) $review['cycle_id'],
            'review_id'           => $reviewId,
            'plan_period_start'   => $planStart,
            'plan_period_end'     => $planEnd,
            'gaps_json'           => json_encode($gaps, JSON_UNESCAPED_UNICODE),
            'target_competencies_json' => null,
            'recommended_trainings_json' => null,
            'action_items_json'   => null,
            'manager_notes'       => $review['development_plan'] ?? null,
            'status'              => 'DRAFT',
            'progress_percent'    => 0,
            'created_by'          => $userId,
            'created_at'          => $now,
            'updated_at'          => $now,
        ];
        $db->table('individual_development_plans')->insert($insert);
        $idpId = (int) $db->insertID();

        try {
            Services::audit()->forModule('PERFORMANCE')->logCreate(
                'individual_development_plan', $idpId, $insert,
                "Auto-create IDP DRAFT dari review #{$reviewId} ({$cycle['name']})"
            );
        } catch (\Throwable $e) {}

        return $this->load($idpId, $companyId);
    }

    /**
     * Manual create IDP (standalone, tidak dari review).
     */
    public function create(array $data, int $userId, int $companyId): array
    {
        $db = $this->db();
        $employeeId = (int) ($data['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            throw new \InvalidArgumentException('employee_id wajib');
        }
        $emp = $db->table('employees')->where('id', $employeeId)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) {
            throw new \InvalidArgumentException("Employee #{$employeeId} tidak ditemukan");
        }
        if (empty($data['plan_period_start']) || empty($data['plan_period_end'])) {
            throw new \InvalidArgumentException('plan_period_start dan plan_period_end wajib');
        }
        if (strtotime($data['plan_period_end']) <= strtotime($data['plan_period_start'])) {
            throw new \InvalidArgumentException('plan_period_end harus > plan_period_start');
        }
        if (! empty($data['cycle_id'])) {
            $cycle = $db->table('performance_cycles')->where('id', (int) $data['cycle_id'])
                ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
            if (! $cycle) throw new \InvalidArgumentException('cycle_id tidak valid untuk tenant aktif');
        }
        if (! empty($data['review_id'])) {
            $review = $db->table('performance_reviews')->where('id', (int) $data['review_id'])
                ->where('company_id', $companyId)->where('employee_id', $employeeId)
                ->where('deleted_at IS NULL')->get()->getRowArray();
            if (! $review) throw new \InvalidArgumentException('review_id tidak valid untuk employee dan tenant aktif');
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'        => $companyId,
            'employee_id'       => $employeeId,
            'cycle_id'          => isset($data['cycle_id']) ? (int) $data['cycle_id'] : null,
            'review_id'         => isset($data['review_id']) ? (int) $data['review_id'] : null,
            'plan_period_start' => $data['plan_period_start'],
            'plan_period_end'   => $data['plan_period_end'],
            'gaps_json'         => $this->encodeJson($data['gaps'] ?? null),
            'target_competencies_json' => $this->encodeJson($data['target_competencies'] ?? null),
            'recommended_trainings_json' => $this->encodeJson($data['recommended_trainings'] ?? null),
            'action_items_json' => $this->encodeJson($data['action_items'] ?? null),
            'manager_notes'     => $data['manager_notes'] ?? null,
            'employee_notes'    => $data['employee_notes'] ?? null,
            'status'            => 'DRAFT',
            'progress_percent'  => 0,
            'created_by'        => $userId,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];
        $db->table('individual_development_plans')->insert($insert);
        $idpId = (int) $db->insertID();
        return $this->load($idpId, $companyId);
    }

    /**
     * Update IDP. Allowed fields tergantung status:
     *   - DRAFT: semua field
     *   - ACTIVE: hanya progress (action_items.status update) + employee_notes
     *   - COMPLETED/REVIEWED: hanya manager_notes (review akhir)
     */
    public function update(int $idpId, array $data, int $userId, int $companyId): array
    {
        $db = $this->db();
        $idp = $db->table('individual_development_plans')
            ->where('id', $idpId)
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $idp) {
            throw new \InvalidArgumentException("IDP #{$idpId} tidak ditemukan");
        }
        if ($idp['status'] === 'PENDING_APPROVAL') {
            throw new \RuntimeException('IDP sedang menunggu approval dan tidak dapat diedit');
        }

        $update = [];

        if (in_array($idp['status'], ['DRAFT', 'APPROVAL_REJECTED'], true)) {
            // Semua field boleh edit
            foreach (['plan_period_start', 'plan_period_end', 'manager_notes', 'employee_notes'] as $f) {
                if (array_key_exists($f, $data)) $update[$f] = $data[$f];
            }
            foreach (['gaps', 'target_competencies', 'recommended_trainings', 'action_items'] as $f) {
                if (array_key_exists($f, $data)) {
                    $update[$f . '_json'] = $this->encodeJson($data[$f]);
                }
            }
        } elseif ($idp['status'] === 'ACTIVE') {
            // Hanya update progress (action items) + employee_notes
            if (array_key_exists('action_items', $data)) {
                $update['action_items_json'] = $this->encodeJson($data['action_items']);
                $update['progress_percent'] = $this->computeProgress($data['action_items']);
            }
            if (array_key_exists('employee_notes', $data)) {
                $update['employee_notes'] = $data['employee_notes'];
            }
        } else {
            // COMPLETED/REVIEWED — hanya manager_notes
            if (array_key_exists('manager_notes', $data)) {
                $update['manager_notes'] = $data['manager_notes'];
            }
        }

        if (empty($update)) {
            throw new \InvalidArgumentException(
                "Tidak ada field yang bisa di-update di status {$idp['status']}"
            );
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('individual_development_plans')->where('id', $idpId)
            ->where('company_id', $companyId)->update($update);

        try {
            Services::audit()->forModule('PERFORMANCE')->logUpdate(
                'individual_development_plan', $idpId, $idp, $update
            );
        } catch (\Throwable $e) {}

        return $this->load($idpId, $companyId);
    }

    /** Direct activation is intentionally unavailable outside ApprovalEngine. */
    public function finalize(int $idpId, int $userId, int $companyId): never
    {
        unset($idpId, $userId, $companyId);
        throw new \RuntimeException(
            'Direct finalize dinonaktifkan; gunakan workflow approval aktivasi IDP'
        );
    }

    /**
     * ACTIVE → COMPLETED. Manager / HR mark IDP as completed di akhir cycle.
     */
    public function complete(int $idpId, int $userId, int $companyId, ?string $managerNotes = null): array
    {
        $db = $this->db();
        $idp = $db->table('individual_development_plans')->where('id', $idpId)
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $idp) throw new \InvalidArgumentException("IDP #{$idpId} tidak ditemukan");
        if ($idp['status'] !== 'ACTIVE') {
            throw new \RuntimeException("Hanya ACTIVE yang bisa di-complete. Current: {$idp['status']}");
        }

        $now = date('Y-m-d H:i:s');
        $update = [
            'status'       => 'COMPLETED',
            'completed_at' => $now,
            'updated_at'   => $now,
        ];
        if ($managerNotes !== null) {
            $update['manager_notes'] = $managerNotes;
        }
        $db->table('individual_development_plans')->where('id', $idpId)
            ->where('company_id', $companyId)->where('status', 'ACTIVE')->update($update);
        if ($db->affectedRows() !== 1) throw new \RuntimeException('IDP berubah saat finalisasi completion');

        try {
            Services::audit()->forModule('PERFORMANCE')->log(
                action: 'idp.complete',
                entity: 'individual_development_plan',
                entityId: $idpId,
                description: "IDP completed untuk employee #{$idp['employee_id']}",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        return $this->load($idpId, $companyId);
    }

    /**
     * Cancel IDP. Hanya allowed dari DRAFT atau ACTIVE.
     */
    public function cancel(int $idpId, int $userId, int $companyId, string $reason): array
    {
        if (strlen(trim($reason)) < 5) {
            throw new \InvalidArgumentException('Alasan cancel minimum 5 karakter');
        }
        $db = $this->db();
        $idp = $db->table('individual_development_plans')->where('id', $idpId)
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $idp) throw new \InvalidArgumentException("IDP #{$idpId} tidak ditemukan");
        if (! in_array($idp['status'], ['DRAFT', 'APPROVAL_REJECTED', 'ACTIVE'], true)) {
            throw new \RuntimeException("Tidak bisa cancel di status {$idp['status']}");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('individual_development_plans')->where('id', $idpId)
            ->where('company_id', $companyId)->whereIn('status', ['DRAFT', 'ACTIVE', 'APPROVAL_REJECTED'])->update([
            'status'        => 'CANCELLED',
            'manager_notes' => '[CANCELLED] ' . $reason
                . (! empty($idp['manager_notes']) ? "\n\n--- Notes lama ---\n" . $idp['manager_notes'] : ''),
            'updated_at'    => $now,
        ]);
        if ($db->affectedRows() !== 1) throw new \RuntimeException('IDP berubah saat pembatalan');

        try {
            Services::audit()->forModule('PERFORMANCE')->log(
                action: 'idp.cancel',
                entity: 'individual_development_plan',
                entityId: $idpId,
                description: "IDP dibatalkan: {$reason}",
                severity: 'WARNING',
            );
        } catch (\Throwable $e) {}

        return $this->load($idpId, $companyId);
    }

    /**
     * Load IDP dengan join karyawan + cycle untuk display.
     */
    public function load(int $idpId, int $companyId): array
    {
        $db = $this->db();
        $row = $db->table('individual_development_plans i')
            ->select('i.*, e.full_name AS employee_name, e.employee_number, '
                . 'c.name AS cycle_name, c.code AS cycle_code')
            ->join('employees e', 'e.id = i.employee_id AND e.company_id = i.company_id')
            ->join('performance_cycles c', 'c.id = i.cycle_id AND c.company_id = i.company_id', 'left')
            ->where('i.id', $idpId)
            ->where('i.company_id', $companyId)
            ->where('i.deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            throw new \InvalidArgumentException("IDP #{$idpId} tidak ditemukan");
        }
        return $this->decodeJsonFields($row);
    }

    /**
     * List IDP per karyawan (atau cross-employee untuk HR dashboard).
     */
    public function listIdps(array $filters = []): array
    {
        if (empty($filters['company_id'])) {
            throw new \InvalidArgumentException('company_id wajib untuk list IDP');
        }
        $db = $this->db();
        $b = $db->table('individual_development_plans i')
            ->select('i.id, i.employee_id, i.cycle_id, i.review_id, '
                . 'i.plan_period_start, i.plan_period_end, '
                . 'i.status, i.progress_percent, i.finalized_at, i.completed_at, '
                . 'i.created_at, '
                . 'e.full_name AS employee_name, e.employee_number, '
                . 'c.name AS cycle_name')
            ->join('employees e', 'e.id = i.employee_id AND e.company_id = i.company_id')
            ->join('performance_cycles c', 'c.id = i.cycle_id AND c.company_id = i.company_id', 'left')
            ->where('i.deleted_at IS NULL');

        if (! empty($filters['company_id'])) {
            $b->where('i.company_id', (int) $filters['company_id']);
        }
        if (! empty($filters['employee_id'])) {
            $b->where('i.employee_id', (int) $filters['employee_id']);
        }
        if (! empty($filters['cycle_id'])) {
            $b->where('i.cycle_id', (int) $filters['cycle_id']);
        }
        if (! empty($filters['status'])) {
            $b->where('i.status', $filters['status']);
        }

        return $b->orderBy('i.created_at', 'DESC')->get()->getResultArray();
    }

    /**
     * Hitung progress percent dari action_items[]. Setiap item punya field
     * `status` (PENDING/IN_PROGRESS/COMPLETED). Progress = completed/total * 100.
     */
    public function computeProgress(?array $actionItems): int
    {
        if (empty($actionItems) || ! is_array($actionItems)) return 0;
        $total = 0;
        $completed = 0;
        foreach ($actionItems as $item) {
            if (! is_array($item)) continue;
            $total++;
            $status = strtoupper((string) ($item['status'] ?? 'PENDING'));
            if ($status === 'COMPLETED') $completed++;
        }
        if ($total === 0) return 0;
        return (int) round(($completed / $total) * 100);
    }

    /**
     * Parse improvements text dari review jadi structured gaps[].
     * Format expected: text dengan bullet/newline. Kita split & jadikan
     * setiap baris jadi 1 gap dengan area = isi line, current_level/target
     * left empty untuk diisi manager.
     */
    public function parseImprovementsToGaps(string $improvements): array
    {
        if (trim($improvements) === '') return [];

        // Split by newline atau bullet markers (-, *, •, 1., dst)
        $lines = preg_split('/(\r\n|\n|\r)/', $improvements) ?: [];
        $gaps = [];
        foreach ($lines as $line) {
            // Strip bullet markers
            $clean = preg_replace('/^[\s\-\*\•]+/', '', $line);
            $clean = preg_replace('/^\d+[\.\)]\s*/', '', (string) $clean);
            $clean = trim((string) $clean);
            if ($clean === '' || strlen($clean) < 5) continue;
            $gaps[] = [
                'area'           => $clean,
                'current_level'  => null,
                'target_level'   => null,
                'evidence'       => null,
            ];
        }
        return $gaps;
    }

    private function encodeJson($value): ?string
    {
        if ($value === null) return null;
        if (! is_array($value)) return null;
        if (empty($value)) return null;
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function decodeJsonFields(array $row): array
    {
        foreach (['gaps', 'target_competencies', 'recommended_trainings', 'action_items'] as $f) {
            $row[$f] = $row[$f . '_json']
                ? (json_decode($row[$f . '_json'], true) ?: [])
                : [];
        }
        return $row;
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
