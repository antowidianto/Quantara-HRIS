<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * AnomalyController — HR review attendance dengan anomaly score tinggi.
 *
 * Endpoint:
 *   GET    /attendance-anomaly                   List attendance anomalies
 *   GET    /attendance-anomaly/stats             Counter
 *   POST   /attendance-anomaly/(:num)/review     Mark sebagai reviewed + note
 *   GET    /attendance-devices                   List devices per user
 *   POST   /attendance-devices/(:num)/trust      HR trust device
 *   POST   /attendance-devices/(:num)/block      HR block device
 *
 * Permission: `attendance.review_anomaly` untuk review, `attendance.manage_devices` untuk trust/block.
 */
class AnomalyController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    private function db() { return \Config\Database::connect(); }
    private function svc() { return Services::antiCheat(); }

    private function can(string $slug): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission($slug);
    }

    /** GET /attendance-anomaly — list dengan filter */
    public function index()
    {
        if (! $this->can('attendance.review_anomaly')) return $this->fail('Forbidden', 403);

        $perPage = max(1, min(100, (int) ($this->request->getGet('per_page') ?? 20)));
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $minScore = (int) ($this->request->getGet('min_score') ?? 30);
        $reviewed = $this->request->getGet('reviewed'); // null|0|1
        $from    = (string) $this->request->getGet('from_date');
        $to      = (string) $this->request->getGet('to_date');

        $b = $this->db()->table('attendances a')
            ->select('a.*, e.full_name AS employee_name, e.employee_number, '
                . 'b.name AS branch_name, d.name AS department_name')
            ->join('employees e', 'e.id = a.employee_id')
            ->join('branches b', 'b.id = e.branch_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->where('a.deleted_at IS NULL')
            ->where('a.anomaly_score >=', $minScore);
        $this->tenant()->applyScope($b, 'e.company_id');

        if ($reviewed !== null && $reviewed !== '') {
            if ((int) $reviewed === 1) $b->where('a.anomaly_reviewed_at IS NOT NULL');
            else                       $b->where('a.anomaly_reviewed_at IS NULL');
        }
        if ($from) $b->where('a.attendance_date >=', $from);
        if ($to)   $b->where('a.attendance_date <=', $to);

        $total = $b->countAllResults(false);
        $rows = $b->orderBy('a.anomaly_score', 'DESC')
            ->orderBy('a.attendance_date', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        // Decode anomaly_flags JSON untuk masing-masing
        foreach ($rows as &$r) {
            if (! empty($r['anomaly_flags'])) {
                $decoded = json_decode($r['anomaly_flags'], true);
                $r['anomaly_flags'] = is_array($decoded) ? $decoded : [];
            } else {
                $r['anomaly_flags'] = [];
            }
        }

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /** GET /attendance-anomaly/stats */
    public function stats()
    {
        if (! $this->can('attendance.review_anomaly')) return $this->fail('Forbidden', 403);
        $from = (string) ($this->request->getGet('from_date') ?: date('Y-m-01'));
        $to   = (string) ($this->request->getGet('to_date')   ?: date('Y-m-d'));
        $companyId = (int) $this->tenant()->getCompanyId();
        if (! $companyId) return $this->fail('Company context required', 400);

        return $this->ok($this->svc()->stats($companyId, $from, $to));
    }

    /** POST /attendance-anomaly/(:num)/review */
    public function review($id = null)
    {
        $id = (int) $id;
        if (! $this->can('attendance.review_anomaly')) return $this->fail('Forbidden', 403);

        $row = $this->db()->table('attendances')->where('id', $id)->get()->getRowArray();
        if (! $row) return $this->fail('Attendance tidak ditemukan', 404);

        // Multi-tenant scope cek via employee
        $emp = $this->db()->table('employees')->where('id', (int) $row['employee_id'])->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $emp['company_id'] !== $tenant->getCompanyId()) {
            return $this->fail('Akses ditolak', 403);
        }

        $data = $this->getJsonInput();
        $note = trim((string) ($data['note'] ?? ''));
        $userId = (int) $tenant->getUserId();

        $this->svc()->markReviewed($id, $userId, $note);

        try {
            Services::audit()->forModule('ATTENDANCE')->log(
                action: 'anomaly.review', entity: 'attendances', entityId: $id, before: $row,
                description: "Anomaly review by user #{$userId}: {$note}",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        // Sprint 16+ — webhook outbound `attendance.anomaly_flagged` ke
        // sistem external (mis. SIEM, fraud monitor) untuk audit trail
        // lintas-sistem. Hanya fire kalau anomaly_score memang signifikan
        // (> threshold), jangan untuk false-positive review yang sudah
        // dikonfirmasi tidak masalah.
        $score = (int) ($row['anomaly_score'] ?? 0);
        $decision = strtoupper(trim((string) ($data['decision'] ?? '')));
        if ($score >= 30 && $decision !== 'CLEARED') {
            $this->dispatchWebhook(
                \App\Constants\WebhookEvent::ATTENDANCE_ANOMALY_FLAGGED,
                (int) $emp['company_id'],
                [
                    'attendance_id'   => $id,
                    'employee_id'     => (int) $emp['id'],
                    'employee_number' => $emp['employee_number'] ?? null,
                    'work_date'       => $row['work_date'] ?? null,
                    'anomaly_score'   => $score,
                    'anomaly_reasons' => $row['anomaly_reasons'] ?? null,
                    'decision'        => $decision !== '' ? $decision : 'FLAGGED',
                    'reviewer_note'   => $note,
                    'reviewed_by'     => $userId,
                    'reviewed_at'     => date('c'),
                ],
            );
        }

        return $this->ok(['id' => $id, 'reviewed_by' => $userId]);
    }

    /** GET /attendance-devices */
    public function devices()
    {
        if (! $this->can('attendance.review_anomaly') && ! $this->can('attendance.manage_devices')) {
            return $this->fail('Forbidden', 403);
        }

        $userIdFilter = (int) ($this->request->getGet('user_id') ?? 0);

        $b = $this->db()->table('attendance_devices d')
            ->select('d.*, u.name AS user_name, e.full_name AS employee_name, e.employee_number')
            ->join('users u', 'u.id = d.user_id', 'left')
            ->join('employees e', 'e.user_id = d.user_id', 'left');
        $this->tenant()->applyScope($b, 'd.company_id');
        if ($userIdFilter) $b->where('d.user_id', $userIdFilter);

        $rows = $b->orderBy('d.last_seen_at', 'DESC')->limit(200)->get()->getResultArray();
        return $this->ok($rows);
    }

    /** POST /attendance-devices/(:num)/trust */
    public function trustDevice($id = null)
    {
        $id = (int) $id;
        if (! $this->can('attendance.manage_devices')) return $this->fail('Forbidden', 403);

        $row = $this->db()->table('attendance_devices')->where('id', $id)->get()->getRowArray();
        if (! $row) return $this->fail('Device tidak ditemukan', 404);
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            return $this->fail('Akses ditolak', 403);
        }

        $userId = (int) $tenant->getUserId();
        $this->svc()->trustDevice($id, $userId);

        try {
            Services::audit()->forModule('ATTENDANCE')->log(
                action: 'device.trust', entity: 'attendance_devices', entityId: $id,
                description: "Device {$row['device_name']} di-trust",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}
        return $this->ok(['id' => $id]);
    }

    /** POST /attendance-devices/(:num)/block */
    public function blockDevice($id = null)
    {
        $id = (int) $id;
        if (! $this->can('attendance.manage_devices')) return $this->fail('Forbidden', 403);

        $row = $this->db()->table('attendance_devices')->where('id', $id)->get()->getRowArray();
        if (! $row) return $this->fail('Device tidak ditemukan', 404);
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            return $this->fail('Akses ditolak', 403);
        }

        $data = $this->getJsonInput();
        $reason = trim((string) ($data['reason'] ?? ''));
        if (strlen($reason) < 5) return $this->fail('Alasan blok min 5 karakter', 400);

        $userId = (int) $tenant->getUserId();
        $this->svc()->blockDevice($id, $reason, $userId);

        try {
            Services::audit()->forModule('ATTENDANCE')->log(
                action: 'device.block', entity: 'attendance_devices', entityId: $id,
                description: "Device {$row['device_name']} di-block: {$reason}",
                severity: 'WARNING',
            );
        } catch (\Throwable $e) {}
        return $this->ok(['id' => $id]);
    }
}