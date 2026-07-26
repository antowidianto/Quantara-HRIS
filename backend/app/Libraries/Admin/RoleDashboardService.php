<?php

namespace App\Libraries\Admin;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Safe metric catalog and versioned role-dashboard configuration.
 *
 * Metric queries are code-owned and tenant scoped. Dashboard configuration
 * never accepts SQL, table names, API URLs, or executable expressions.
 */
final class RoleDashboardService
{
    public const STATUSES = ['DRAFT', 'PUBLISHED', 'ARCHIVED'];
    public const WIDTHS = ['quarter', 'half', 'full'];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @return list<array<string,mixed>> */
    public function catalog(): array
    {
        return array_values($this->catalogMap());
    }

    /** @return list<array<string,mixed>> */
    public function templates(?int $companyId = null): array
    {
        if (! $this->db->tableExists('dashboard_templates')) return [];
        $builder = $this->db->table('dashboard_templates d')
            ->select('d.*, creator.name AS created_by_name, publisher.name AS published_by_name')
            ->join('users creator', 'creator.id = d.created_by', 'left')
            ->join('users publisher', 'publisher.id = d.published_by', 'left')
            ->where('d.deleted_at IS NULL');
        if ($companyId !== null && $companyId > 0) {
            $builder->groupStart()->where('d.company_id', $companyId)->orWhere('d.company_id IS NULL')->groupEnd();
        }
        $rows = $builder->orderBy('d.updated_at', 'DESC')->get()->getResultArray();
        foreach ($rows as &$row) $row = $this->decodeTemplate($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        // @tenant-scope-reviewed Super Admin platform lookup by globally unique template ID.
        $row = $this->db->table('dashboard_templates')->where('id', $id)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) throw new \RuntimeException('Template dashboard tidak ditemukan');
        return $this->decodeTemplate($row);
    }

    /** @return array<string,mixed> */
    public function create(array $data, int $userId): array
    {
        $this->assertStorage();
        $payload = $this->validatePayload($data);
        $now = date('Y-m-d H:i:s');
        $this->db->table('dashboard_templates')->insert([
            'company_id' => $payload['company_id'],
            'target_role_slug' => $payload['target_role_slug'],
            'name' => $payload['name'],
            'description' => $payload['description'],
            'status' => 'DRAFT',
            'layout_json' => json_encode($payload['widgets'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'version' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $this->db->insertID();
        $this->snapshot($id, 1, 'CREATE', $userId);
        return $this->find($id);
    }

    /** @return array<string,mixed> */
    public function update(int $id, array $data, int $userId): array
    {
        $existing = $this->find($id);
        if ($existing['status'] === 'PUBLISHED') {
            throw new \InvalidArgumentException('Dashboard aktif tidak dapat diubah langsung; buat draft salinan lalu publish');
        }
        $payload = $this->validatePayload($data);
        $version = (int) $existing['version'] + 1;
        $this->db->table('dashboard_templates')->where('id', $id)->where('deleted_at IS NULL')->update([
            'company_id' => $payload['company_id'],
            'target_role_slug' => $payload['target_role_slug'],
            'name' => $payload['name'],
            'description' => $payload['description'],
            'status' => 'DRAFT',
            'layout_json' => json_encode($payload['widgets'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'version' => $version,
            'published_at' => null,
            'published_by' => null,
            'updated_by' => $userId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->snapshot($id, $version, 'UPDATE', $userId);
        return $this->find($id);
    }

    /** @return array<string,mixed> */
    public function publish(int $id, int $userId): array
    {
        $template = $this->find($id);
        if ($template['widgets'] === []) throw new \RuntimeException('Tambahkan minimal satu widget sebelum publish');
        $this->db->transBegin();
        try {
            $scope = $this->db->table('dashboard_templates')
                ->where('target_role_slug', $template['target_role_slug'])
                ->where('status', 'PUBLISHED')->where('deleted_at IS NULL');
            $template['company_id'] === null
                ? $scope->where('company_id IS NULL')
                : $scope->where('company_id', (int) $template['company_id']);
            $scope->where('id !=', $id)->update([
                'status' => 'ARCHIVED',
                'updated_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            // @tenant-scope-reviewed Template ownership was loaded above through find(); endpoint is Super Admin only.
            $this->db->table('dashboard_templates')->where('id', $id)->update([
                'status' => 'PUBLISHED',
                'published_at' => date('Y-m-d H:i:s'),
                'published_by' => $userId,
                'updated_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if (! $this->db->transStatus()) throw new \RuntimeException('Gagal publish dashboard');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
        return $this->find($id);
    }

    public function delete(int $id, int $userId): void
    {
        $existing = $this->find($id);
        if ($existing['status'] === 'PUBLISHED') {
            throw new \InvalidArgumentException('Dashboard aktif tidak dapat dihapus sebelum ada penggantinya');
        }
        $this->db->table('dashboard_templates')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_by' => $userId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function effective(int $companyId, string $roleSlug, bool $previewAll = false): ?array
    {
        if (! $this->db->tableExists('dashboard_templates') || $roleSlug === '') return null;
        $rows = $this->db->table('dashboard_templates')->where('target_role_slug', $roleSlug)
            ->where('status', 'PUBLISHED')->where('deleted_at IS NULL')
            ->groupStart()->where('company_id', $companyId)->orWhere('company_id IS NULL')->groupEnd()
            ->orderBy('company_id', 'DESC')->orderBy('published_at', 'DESC')->get()->getResultArray();
        if ($rows === []) return null;
        return $this->render($this->decodeTemplate($rows[0]), $companyId, $previewAll);
    }

    /** @return array<string,mixed> */
    public function preview(array $data, int $companyId): array
    {
        $payload = $this->validatePayload($data);
        return $this->render([
            'id' => 0,
            'name' => $payload['name'],
            'description' => $payload['description'],
            'target_role_slug' => $payload['target_role_slug'],
            'company_id' => $payload['company_id'],
            'status' => 'PREVIEW',
            'version' => 0,
            'widgets' => $payload['widgets'],
        ], $companyId, true);
    }

    /** @return array<string,mixed> */
    private function render(array $template, int $companyId, bool $previewAll): array
    {
        $catalog = $this->catalogMap();
        $widgets = [];
        foreach ($template['widgets'] as $widget) {
            $metric = $catalog[$widget['metric_key']] ?? null;
            if (! $metric) continue;
            $permission = (string) ($metric['required_permission'] ?? '');
            if (! $previewAll && $permission !== '' && ! Services::tenant()->hasPermission($permission)) continue;
            $widgets[] = array_merge($widget, [
                'type' => $metric['type'],
                'label' => $widget['title'] ?: $metric['label'],
                'description' => $metric['description'],
                'required_permission' => $permission ?: null,
                'data' => $this->resolveMetric($widget['metric_key'], $companyId, $previewAll),
            ]);
        }
        $template['widgets'] = $widgets;
        $template['rendered_at'] = date('c');
        return $template;
    }

    /** @return array<string,mixed> */
    private function validatePayload(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $role = strtolower(trim((string) ($data['target_role_slug'] ?? '')));
        if ($name === '' || mb_strlen($name) > 150) throw new \InvalidArgumentException('Nama dashboard wajib dan maksimal 150 karakter');
        if (! preg_match('/^[a-z0-9_\\-]+$/', $role)) throw new \InvalidArgumentException('Role target tidak valid');
        if (! $this->db->tableExists('roles') || $this->db->table('roles')->where('slug', $role)->countAllResults() === 0) {
            throw new \InvalidArgumentException('Role target tidak ditemukan');
        }
        $companyId = isset($data['company_id']) && (int) $data['company_id'] > 0 ? (int) $data['company_id'] : null;
        if ($companyId !== null && $this->db->table('companies')->where('id', $companyId)->countAllResults() === 0) {
            throw new \InvalidArgumentException('Tenant target tidak ditemukan');
        }
        $catalog = $this->catalogMap();
        $widgets = [];
        foreach ((array) ($data['widgets'] ?? []) as $index => $widget) {
            $key = (string) ($widget['metric_key'] ?? '');
            if (! isset($catalog[$key])) throw new \InvalidArgumentException("Metric widget #{$index} tidak valid");
            $width = in_array($widget['width'] ?? '', self::WIDTHS, true)
                ? $widget['width'] : $catalog[$key]['default_width'];
            $widgets[] = [
                'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($widget['id'] ?? '')) ?: uniqid('widget_', false),
                'metric_key' => $key,
                'title' => mb_substr(trim((string) ($widget['title'] ?? '')), 0, 100),
                'width' => $width,
                'color' => in_array($widget['color'] ?? '', ['blue', 'emerald', 'amber', 'rose', 'violet', 'slate'], true)
                    ? $widget['color'] : $catalog[$key]['color'],
            ];
        }
        if (count($widgets) > 24) throw new \InvalidArgumentException('Maksimal 24 widget per dashboard');
        return [
            'company_id' => $companyId,
            'target_role_slug' => $role,
            'name' => $name,
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 1000) ?: null,
            'widgets' => $widgets,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function catalogMap(): array
    {
        $definitions = [
            ['headcount.active', 'Karyawan Aktif', 'Jumlah karyawan aktif di tenant.', 'stat', 'Karyawan', 'employee.view', 'quarter', 'blue'],
            ['headcount.contract_ending', 'Kontrak Akan Berakhir', 'Kontrak yang berakhir dalam 30 hari.', 'stat', 'Karyawan', 'employee.view', 'quarter', 'amber'],
            ['attendance.today', 'Kehadiran Hari Ini', 'Karyawan hadir atau terlambat hari ini.', 'progress', 'Absensi', 'attendance.view_all', 'half', 'emerald'],
            ['leave.pending', 'Cuti Menunggu', 'Pengajuan cuti yang belum diputuskan.', 'stat', 'Absensi', 'leave.view', 'quarter', 'amber'],
            ['approval.pending', 'Approval Menunggu', 'Seluruh workflow yang masih menunggu keputusan.', 'stat', 'Workflow', null, 'quarter', 'violet'],
            ['payroll.in_process', 'Payroll Berjalan', 'Periode payroll yang sedang diproses.', 'stat', 'Payroll', 'payroll.view', 'quarter', 'blue'],
            ['recruitment.open_jobs', 'Lowongan Aktif', 'Lowongan recruitment berstatus aktif.', 'stat', 'Rekrutmen', 'recruitment.view', 'quarter', 'emerald'],
            ['helpdesk.open', 'Tiket HR Terbuka', 'Tiket HR yang masih terbuka atau diproses.', 'stat', 'Layanan HR', 'helpdesk.view_all', 'quarter', 'rose'],
            ['employee.by_status', 'Komposisi Karyawan', 'Distribusi karyawan berdasarkan status kerja.', 'donut', 'Karyawan', 'employee.view', 'half', 'blue'],
            ['employee.by_department', 'Karyawan per Departemen', 'Distribusi karyawan berdasarkan departemen.', 'pie', 'Karyawan', 'employee.view', 'half', 'violet'],
            ['payroll.by_status', 'Status Payroll', 'Distribusi periode payroll berdasarkan status.', 'bar', 'Payroll', 'payroll.view', 'half', 'violet'],
            ['approval.by_module', 'Approval per Modul', 'Approval pending dikelompokkan berdasarkan modul.', 'bar', 'Workflow', null, 'half', 'amber'],
            ['recruitment.pipeline', 'Pipeline Kandidat', 'Kandidat aktif dikelompokkan berdasarkan status.', 'bar', 'Rekrutmen', 'recruitment.view', 'half', 'emerald'],
            ['attendance.trend', 'Tren Kehadiran 7 Hari', 'Jumlah kehadiran harian selama 7 hari terakhir.', 'line', 'Absensi', 'attendance.view_all', 'full', 'emerald'],
            ['headcount.trend', 'Tren Karyawan Aktif 6 Bulan', 'Pertumbuhan karyawan aktif per bulan.', 'line', 'Karyawan', 'employee.view', 'full', 'blue'],
            ['leave.trend', 'Tren Pengajuan Cuti 6 Bulan', 'Jumlah pengajuan cuti per bulan.', 'area', 'Absensi', 'leave.view', 'full', 'amber'],
            ['recruitment.applications_trend', 'Tren Pelamar 6 Bulan', 'Jumlah pelamar masuk per bulan.', 'area', 'Rekrutmen', 'recruitment.view', 'full', 'rose'],
            ['quick.links', 'Akses Cepat', 'Shortcut modul yang sering dipakai.', 'links', 'Navigasi', null, 'full', 'slate'],
        ];
        $map = [];
        foreach ($definitions as [$key,$label,$description,$type,$group,$permission,$width,$color]) {
            $map[$key] = compact('key', 'label', 'description', 'type', 'group', 'color')
                + ['required_permission' => $permission, 'default_width' => $width];
        }
        return $map;
    }

    /** @return array<string,mixed> */
    private function resolveMetric(string $key, int $companyId, bool $previewAll): array
    {
        return match ($key) {
            'headcount.active' => ['value' => $this->count('employees', $companyId, fn ($b) => $b->where('is_active', 1)->where('deleted_at IS NULL')), 'unit' => 'orang'],
            'headcount.contract_ending' => ['value' => $this->count('employee_contracts', $companyId, fn ($b) => $b->where('end_date >=', date('Y-m-d'))->where('end_date <=', date('Y-m-d', strtotime('+30 days')))), 'unit' => 'kontrak'],
            'attendance.today' => $this->attendanceToday($companyId),
            'leave.pending' => ['value' => $this->count('leaves', $companyId, fn ($b) => $b->where('status', 'PENDING')), 'unit' => 'pengajuan'],
            'approval.pending' => ['value' => $this->count('approval_requests', $companyId, fn ($b) => $b->where('status', 'PENDING')), 'unit' => 'workflow'],
            'payroll.in_process' => ['value' => $this->count('payroll_periods', $companyId, fn ($b) => $b->whereIn('status', ['CALCULATED','REVIEW','SUBMITTED','APPROVED'])), 'unit' => 'periode'],
            'recruitment.open_jobs' => ['value' => $this->count('job_postings', $companyId, fn ($b) => $b->where('status', 'OPEN')), 'unit' => 'lowongan'],
            'helpdesk.open' => ['value' => $this->count('hr_tickets', $companyId, fn ($b) => $b->whereIn('status', ['OPEN','IN_PROGRESS'])), 'unit' => 'tiket'],
            'employee.by_status' => ['items' => $this->grouped('employees', $companyId, 'employment_status', fn ($b) => $b->where('deleted_at IS NULL'))],
            'employee.by_department' => ['items' => $this->grouped('employees', $companyId, 'department_id', fn ($b) => $b->where('deleted_at IS NULL'))],
            'payroll.by_status' => ['items' => $this->grouped('payroll_periods', $companyId, 'status')],
            'approval.by_module' => ['items' => $this->grouped('approval_requests', $companyId, 'module', fn ($b) => $b->where('status', 'PENDING'))],
            'recruitment.pipeline' => ['items' => $this->grouped('candidates', $companyId, 'status', fn ($b) => $b->where('deleted_at IS NULL'))],
            'attendance.trend' => ['items' => $this->dailyTrend('attendances', $companyId, 'work_date', 7, fn ($b) => $b->whereIn('status', ['PRESENT','LATE'])), 'unit' => 'hadir'],
            'headcount.trend' => ['items' => $this->monthlyTrend('employees', $companyId, 'created_at', 6, fn ($b) => $b->where('deleted_at IS NULL')), 'unit' => 'karyawan', 'cumulative' => true],
            'leave.trend' => ['items' => $this->monthlyTrend('leaves', $companyId, 'created_at', 6), 'unit' => 'pengajuan'],
            'recruitment.applications_trend' => ['items' => $this->monthlyTrend('candidates', $companyId, 'created_at', 6, fn ($b) => $b->where('deleted_at IS NULL')), 'unit' => 'pelamar'],
            'quick.links' => ['items' => $this->quickLinks($previewAll)],
            default => ['value' => 0],
        };
    }

    /**
     * Count rows per day for the last N days (code-owned date bucket, tenant scoped).
     *
     * @return list<array{label:string,value:int}>
     */
    private function dailyTrend(string $table, int $companyId, string $dateColumn, int $days, ?callable $scope = null): array
    {
        if (! $this->db->tableExists($table) || ! $this->db->fieldExists($dateColumn, $table)) return [];
        if ($companyId > 0 && ! $this->db->fieldExists('company_id', $table)) return [];
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            try {
                $builder = $this->db->table($table)
                    ->where("DATE({$dateColumn})", $date);
                if ($companyId > 0) $builder->where('company_id', $companyId);
                if ($scope) $scope($builder);
                $value = (int) $builder->countAllResults();
            } catch (\Throwable) {
                $value = 0;
            }
            $series[] = ['label' => date('d M', strtotime($date)), 'value' => $value];
        }
        return $series;
    }

    /**
     * Count rows per month for the last N months (code-owned month bucket, tenant scoped).
     *
     * @return list<array{label:string,value:int}>
     */
    private function monthlyTrend(string $table, int $companyId, string $dateColumn, int $months, ?callable $scope = null): array
    {
        if (! $this->db->tableExists($table) || ! $this->db->fieldExists($dateColumn, $table)) return [];
        if ($companyId > 0 && ! $this->db->fieldExists('company_id', $table)) return [];
        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = date('Y-m-01', strtotime("-{$i} months"));
            $end = date('Y-m-t', strtotime("-{$i} months"));
            try {
                $builder = $this->db->table($table)
                    ->where("{$dateColumn} >=", $start . ' 00:00:00')
                    ->where("{$dateColumn} <=", $end . ' 23:59:59');
                if ($companyId > 0) $builder->where('company_id', $companyId);
                if ($scope) $scope($builder);
                $value = (int) $builder->countAllResults();
            } catch (\Throwable) {
                $value = 0;
            }
            $series[] = ['label' => date('M Y', strtotime($start)), 'value' => $value];
        }
        return $series;
    }

    /** @return list<array{label:string,to:string}> */
    private function quickLinks(bool $previewAll): array
    {
        $links = [
            ['label' => 'Karyawan', 'to' => '/employees', 'permission' => 'employee.view'],
            ['label' => 'Absensi', 'to' => '/attendance', 'permission' => 'attendance.view_all'],
            ['label' => 'Approval', 'to' => '/approvals/inbox', 'permission' => null],
            ['label' => 'Payroll', 'to' => '/payroll/periods', 'permission' => 'payroll.view'],
            ['label' => 'Laporan', 'to' => '/reports', 'permission' => 'report.view'],
        ];
        return array_values(array_map(
            fn (array $link) => ['label' => $link['label'], 'to' => $link['to']],
            array_filter($links, fn (array $link) => $previewAll
                || $link['permission'] === null
                || Services::tenant()->hasPermission($link['permission'])),
        ));
    }

    /** @return array<string,mixed> */
    private function attendanceToday(int $companyId): array
    {
        $present = $this->count('attendances', $companyId, fn ($b) => $b->where('work_date', date('Y-m-d'))->whereIn('status', ['PRESENT','LATE']));
        $active = $this->count('employees', $companyId, fn ($b) => $b->where('is_active', 1)->where('deleted_at IS NULL'));
        return ['value' => $present, 'max' => max(1, $active), 'unit' => 'hadir', 'percent' => $active > 0 ? round($present / $active * 100, 1) : 0];
    }

    private function count(string $table, int $companyId, ?callable $scope = null): int
    {
        if (! $this->db->tableExists($table)) return 0;
        try {
            $builder = $this->db->table($table);
            if ($companyId > 0 && ! $this->db->fieldExists('company_id', $table)) return 0;
            if ($companyId > 0) $builder->where('company_id', $companyId);
            if ($scope) $scope($builder);
            return (int) $builder->countAllResults();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return list<array{label:string,value:int}> */
    private function grouped(string $table, int $companyId, string $column, ?callable $scope = null): array
    {
        if (! $this->db->tableExists($table) || ! $this->db->fieldExists($column, $table)) return [];
        try {
            $builder = $this->db->table($table)->select("{$column} AS label, COUNT(*) AS value");
            if ($companyId > 0 && ! $this->db->fieldExists('company_id', $table)) return [];
            if ($companyId > 0) $builder->where('company_id', $companyId);
            if ($scope) $scope($builder);
            $rows = $builder->groupBy($column)->orderBy('value', 'DESC')->limit(8)->get()->getResultArray();
            return array_map(fn ($row) => ['label' => (string) ($row['label'] ?: 'Lainnya'), 'value' => (int) $row['value']], $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    private function snapshot(int $id, int $version, string $type, int $userId): void
    {
        // @tenant-scope-reviewed Internal snapshot immediately follows an authorized template mutation.
        $row = $this->db->table('dashboard_templates')->where('id', $id)->get()->getRowArray();
        // @tenant-scope-reviewed Version row inherits tenant ownership from its template snapshot.
        $this->db->table('dashboard_template_versions')->insert([
            'template_id' => $id,
            'version' => $version,
            'snapshot_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'change_type' => $type,
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed> */
    private function decodeTemplate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['company_id'] = $row['company_id'] !== null ? (int) $row['company_id'] : null;
        $row['version'] = (int) $row['version'];
        $row['widgets'] = json_decode((string) $row['layout_json'], true) ?: [];
        unset($row['layout_json']);
        return $row;
    }

    private function assertStorage(): void
    {
        if (! $this->db->tableExists('dashboard_templates') || ! $this->db->tableExists('dashboard_template_versions')) {
            throw new \RuntimeException('Migration Dashboard Builder belum dijalankan');
        }
    }
}
