<?php

namespace App\Controllers\Api\V1;

/**
 * AuditLogController
 *
 * Endpoint admin untuk melihat & memfilter audit log + export CSV.
 *
 *  GET /audit-logs            list dengan filter
 *  GET /audit-logs/(:num)     detail satu log + log lain di request_id sama
 *  GET /audit-logs/stats      ringkasan modul/severity untuk dashboard
 *  GET /audit-logs/export     export CSV (max 50.000 baris)
 */
class AuditLogController extends ApiController
{
    public function index()
    {
        $builder = $this->buildQuery();
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(200, max(1, (int) ($this->request->getGet('per_page') ?? 50)));

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('al.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        // Decode JSON columns biar enak dipakai frontend
        foreach ($rows as &$r) {
            $r['changes']     = $r['changes']     ? json_decode($r['changes'],     true) : null;
            $r['before_data'] = isset($r['before_data']) && $r['before_data'] ? json_decode($r['before_data'], true) : null;
            $r['after_data']  = isset($r['after_data'])  && $r['after_data']  ? json_decode($r['after_data'],  true) : null;
            $r['metadata']    = $r['metadata']    ? json_decode($r['metadata'],    true) : null;
        }

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('audit_logs al')
            ->select('al.*, u.email AS user_email, u.name AS user_name')
            ->join('users u', 'u.id = al.user_id', 'left')
            ->where('al.id', (int) $id)
            ->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) ($row['company_id'] ?? 0) !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        // Decode JSON
        foreach (['before_data', 'after_data', 'changes', 'metadata'] as $k) {
            $row[$k] = isset($row[$k]) && $row[$k] ? json_decode($row[$k], true) : null;
        }

        // Ambil semua log lain di request_id yang sama (transaction trail)
        $related = [];
        if (! empty($row['request_id'])) {
            $related = $db->table('audit_logs')
                ->select('id, action, severity, module, description, success, http_method, http_path, status_code, created_at')
                ->where('request_id', $row['request_id'])
                ->where('id !=', $row['id'])
                ->orderBy('id', 'ASC')
                ->limit(50)
                ->get()
                ->getResultArray();
        }

        return $this->ok(['log' => $row, 'related' => $related]);
    }

    public function stats()
    {
        $db = \Config\Database::connect();
        $startDate = (string) ($this->request->getGet('start_date') ?? date('Y-m-d', strtotime('-30 days')));
        $endDate   = (string) ($this->request->getGet('end_date')   ?? date('Y-m-d'));

        $base = $db->table('audit_logs')
            ->where('created_at >=', $startDate . ' 00:00:00')
            ->where('created_at <=', $endDate . ' 23:59:59');
        if (! $this->tenant()->isSuperAdmin()) {
            $base->where('company_id', $this->companyId());
        }

        $byModule = (clone $base)
            ->select('module, COUNT(*) AS total')
            ->groupBy('module')
            ->orderBy('total', 'DESC')
            ->get()->getResultArray();

        $bySeverity = (clone $base)
            ->select('severity, COUNT(*) AS total')
            ->groupBy('severity')
            ->get()->getResultArray();

        $failures = (clone $base)
            ->where('success', 0)
            ->countAllResults();

        $topUsers = (clone $base)
            ->select('al.user_id, u.email, u.name, COUNT(*) AS total', false)
            ->from('audit_logs al', true)
            ->join('users u', 'u.id = al.user_id', 'left')
            ->where('created_at >=', $startDate . ' 00:00:00')
            ->where('created_at <=', $endDate . ' 23:59:59')
            ->where('al.user_id IS NOT NULL')
            ->groupBy('al.user_id, u.email, u.name')
            ->orderBy('total', 'DESC')
            ->limit(10)
            ->get()->getResultArray();

        return $this->ok([
            'period'      => ['start' => $startDate, 'end' => $endDate],
            'by_module'   => $byModule,
            'by_severity' => $bySeverity,
            'failures'    => (int) $failures,
            'top_users'   => $topUsers,
        ]);
    }

    public function export()
    {
        // Batas aman supaya tidak overload memory pada export
        $maxRows = 50000;
        $builder = $this->buildQuery();
        $rows = $builder->orderBy('al.id', 'DESC')->limit($maxRows)->get()->getResultArray();

        $filename = 'audit-logs-' . date('Ymd-His') . '.csv';
        $this->response->setHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

        $out = fopen('php://temp', 'r+');
        // BOM supaya Excel buka utf-8 dengan benar
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'ID','Waktu','User','Email','Modul','Severity','Action','Deskripsi',
            'Entitas','ID Entitas','Berhasil','Kode Error','HTTP','Path','Status',
            'IP','Sumber','Request ID','Durasi (ms)',
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'],
                $r['created_at'],
                $r['user_name'] ?? '',
                $r['user_email'] ?? '',
                $r['module'] ?? '',
                $r['severity'] ?? '',
                $r['action'] ?? '',
                $r['description'] ?? '',
                $r['entity'] ?? '',
                $r['entity_id'] ?? '',
                ((int) ($r['success'] ?? 1)) === 1 ? 'YA' : 'TIDAK',
                $r['error_code'] ?? '',
                $r['http_method'] ?? '',
                $r['http_path'] ?? '',
                $r['status_code'] ?? '',
                $r['ip_address'] ?? '',
                $r['source'] ?? '',
                $r['request_id'] ?? '',
                $r['duration_ms'] ?? '',
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        \Config\Services::audit()
            ->forModule('AUDIT')
            ->log(
                action: 'audit_log.export',
                entity: 'audit_log',
                entityId: null,
                description: 'Export CSV audit log (' . count($rows) . ' baris)',
                severity: 'NOTICE'
            );

        return $this->response->setBody($csv);
    }

    /**
     * Build query dasar dengan semua filter yang didukung.
     */
    private function buildQuery()
    {
        $db = \Config\Database::connect();

        $userId    = (int) ($this->request->getGet('user_id') ?? 0);
        $entity    = trim((string) $this->request->getGet('entity'));
        $module    = trim((string) $this->request->getGet('module'));
        $severity  = trim((string) $this->request->getGet('severity'));
        $action    = trim((string) $this->request->getGet('action'));
        $search    = trim((string) $this->request->getGet('search'));
        $successQ  = $this->request->getGet('success');
        $startDate = trim((string) $this->request->getGet('start_date'));
        $endDate   = trim((string) $this->request->getGet('end_date'));
        $requestId = trim((string) $this->request->getGet('request_id'));
        // Default exclude data yang sudah di-archive (>2 tahun, lihat
        // hr:archive-audit). Operator bisa override via ?include_archived=1
        // untuk forensic / compliance review yang perlu data lama.
        $includeArchived = (bool) ($this->request->getGet('include_archived') ?? 0);

        $builder = $db->table('audit_logs al')
            ->select('al.*, u.email AS user_email, u.name AS user_name')
            ->join('users u', 'u.id = al.user_id', 'left');

        if (! $this->tenant()->isSuperAdmin()) {
            $builder->where('al.company_id', $this->companyId());
        }
        if (! $includeArchived) {
            $builder->where('al.archived_at IS NULL');
        }
        if ($userId)    $builder->where('al.user_id', $userId);
        if ($entity)    $builder->where('al.entity', $entity);
        if ($module)    $builder->where('al.module', strtoupper($module));
        if ($severity)  $builder->where('al.severity', strtoupper($severity));
        if ($action)    $builder->like('al.action', $action);
        if ($requestId) $builder->where('al.request_id', $requestId);
        if ($successQ !== null && $successQ !== '') {
            $builder->where('al.success', $successQ === '0' || $successQ === 'false' ? 0 : 1);
        }
        if ($startDate) $builder->where('al.created_at >=', $startDate . ' 00:00:00');
        if ($endDate)   $builder->where('al.created_at <=', $endDate . ' 23:59:59');
        if ($search) {
            $builder->groupStart()
                ->like('al.description', $search)
                ->orLike('al.action', $search)
                ->orLike('al.entity', $search)
                ->orLike('u.email', $search)
                ->orLike('u.name', $search)
                ->groupEnd();
        }

        return $builder;
    }
}