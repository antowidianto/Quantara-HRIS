<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * SyncPermissions — sinkronkan permission yang baru di seeder/migrasi ke
 * role yang sudah ada di tiap company (Sprint 4 item M).
 *
 * Use case:
 *   - Migrasi tambah permission baru (mis. 'helpdesk.assign')
 *   - Tenant lama tidak otomatis dapat permission tsb di role HR Admin / dst.
 *   - Command ini scan tabel `permissions`, identifikasi permission yang
 *     belum ada di setiap role, grant berdasarkan rule auto-grant.
 *
 * Rule auto-grant per role slug:
 *   super_admin → SEMUA permission (wildcard)
 *   hr_admin    → semua kecuali super_admin-only (audit_log.system, dst.)
 *   manager     → permission yang ada slug `*.approve`, `*.reject`, `view.*`
 *   employee    → permission yang ada slug `view.own.*` atau standar baseline
 *   finance     → permission payroll.*, reimbursement.*, loan.*
 *
 * Idempotent: skip kalau sudah ada baris di role_permissions.
 *
 * Usage:
 *   php spark hr:sync-permissions                  # semua company
 *   php spark hr:sync-permissions --company=3       # 1 company saja
 *   php spark hr:sync-permissions --dry-run         # preview, tidak commit
 */
class SyncPermissions extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:sync-permissions';
    protected $description = 'Sync permission baru ke role default per company.';
    protected $usage       = 'hr:sync-permissions [options]';
    protected $options     = [
        '--company' => 'Hanya proses 1 company (id)',
        '--dry-run' => 'Preview tanpa commit',
    ];

    /** Mapping role slug → predicate pakai array matcher */
    private const RULES = [
        'super_admin' => ['*'],
        'hr_admin'    => ['employee.*', 'leave.*', 'overtime.*', 'attendance.*',
                          'payroll.*', 'master.*', 'report.*', 'audit_log.*',
                          'asset.*', 'training.*', 'recruitment.*', 'helpdesk.*',
                          'severance.*', 'sp.*', 'disciplinary.*', 'business_trip.*',
                          'performance.*', 'onboarding.*', 'task.*', 'contract.*',
                          'allowance.*', 'work_location.*', 'approval.*',
                          'anniversary.*', 'anomaly.*'],
        'manager'     => ['leave.approve', 'leave.reject', 'leave.view',
                          'overtime.approve', 'overtime.reject', 'overtime.view',
                          'attendance.approve', 'attendance.reject', 'attendance.view',
                          'employee.view', 'report.view', 'performance.review',
                          'business_trip.approve', 'training.approve'],
        'employee'    => ['attendance.clock_in', 'attendance.clock_out',
                          'leave.create', 'leave.view.own',
                          'overtime.create', 'overtime.view.own',
                          'reimbursement.create', 'reimbursement.view.own',
                          'payslip.view.own', 'profile.view.own',
                          'training.register', 'helpdesk.create', 'helpdesk.view.own'],
        'finance'     => ['payroll.*', 'reimbursement.*', 'loan.*',
                          'employee.view', 'report.payroll'],
    ];

    public function run(array $params)
    {
        $companyFilter = (int) (CLI::getOption('company') ?? 0);
        $dryRun        = (bool) CLI::getOption('dry-run');

        CLI::write('=== Sync Permissions ===', 'yellow');
        if ($dryRun) CLI::write('Mode: DRY-RUN (tidak commit)', 'cyan');
        CLI::newLine();

        $db = \Config\Database::connect();
        if (! $db->tableExists('permissions') || ! $db->tableExists('roles')
            || ! $db->tableExists('role_permissions')
        ) {
            CLI::error('Tabel permissions/roles/role_permissions tidak ditemukan.');
            return EXIT_ERROR;
        }

        // Ambil semua permission (global, tidak per-company)
        $allPermissions = $db->table('permissions')->get()->getResultArray();
        if (empty($allPermissions)) {
            CLI::write('Tidak ada permission di tabel — apakah seed sudah jalan?', 'yellow');
            return EXIT_SUCCESS;
        }

        // Filter company
        $companies = $db->table('companies')->where('is_active', 1);
        if ($companyFilter) $companies->where('id', $companyFilter);
        $companies = $companies->get()->getResultArray();

        $totalGranted = 0;
        foreach ($companies as $c) {
            $cid = (int) $c['id'];
            CLI::write("\n→ Company #{$cid} ({$c['name']})", 'green');

            $roles = $db->table('roles')->where('company_id', $cid)->get()->getResultArray();
            foreach ($roles as $role) {
                $slug = (string) $role['slug'];
                $expected = $this->permissionsForRole($slug, $allPermissions);
                if (empty($expected)) {
                    CLI::write("  · role {$slug}: tidak ada rule, skip", 'cyan');
                    continue;
                }
                // Cari yang belum ada di role_permissions
                $existing = $db->table('role_permissions')
                    ->where('role_id', (int) $role['id'])
                    ->select('permission_id')
                    ->get()->getResultArray();
                $existingIds = array_map(fn ($r) => (int) $r['permission_id'], $existing);
                $toGrant = array_filter($expected, fn ($p) => ! in_array((int) $p['id'], $existingIds, true));

                if (empty($toGrant)) {
                    CLI::write("  · role {$slug}: sudah lengkap (" . count($expected) . " perms)");
                    continue;
                }

                CLI::write("  · role {$slug}: tambah " . count($toGrant) . " permission");
                if (! $dryRun) {
                    $now = date('Y-m-d H:i:s');
                    $rows = array_map(fn ($p) => [
                        'role_id'       => (int) $role['id'],
                        'permission_id' => (int) $p['id'],
                        'created_at'    => $now,
                    ], $toGrant);
                    $db->table('role_permissions')->insertBatch($rows);
                }
                $totalGranted += count($toGrant);
            }
        }

        CLI::newLine();
        CLI::write('=== Selesai ===', 'yellow');
        CLI::write("Total grant: {$totalGranted}", $totalGranted > 0 ? 'green' : 'white');

        if ($totalGranted > 0 && ! $dryRun) {
            try {
                Services::audit()->forModule('SECURITY')->log(
                    action: 'permissions.sync',
                    entity: 'role_permissions',
                    description: "Sync permission ke role default: {$totalGranted} grant baru",
                    metadata: ['total_granted' => $totalGranted, 'company_filter' => $companyFilter ?: 'all'],
                    severity: 'NOTICE',
                );
            } catch (\Throwable $e) {}
        }

        return EXIT_SUCCESS;
    }

    /**
     * Filter permission yang relevan untuk role ini berdasarkan RULES.
     * Pattern wildcard `*` cocok semua, `<prefix>.*` cocok yang punya prefix sama.
     */
    private function permissionsForRole(string $roleSlug, array $allPermissions): array
    {
        if (! isset(self::RULES[$roleSlug])) return [];
        $patterns = self::RULES[$roleSlug];

        // Wildcard '*' = semua permission
        if (in_array('*', $patterns, true)) {
            return $allPermissions;
        }

        return array_values(array_filter($allPermissions, function ($p) use ($patterns) {
            $slug = (string) ($p['slug'] ?? $p['name'] ?? '');
            foreach ($patterns as $pattern) {
                if (str_ends_with($pattern, '.*')) {
                    $prefix = substr($pattern, 0, -2);
                    if (str_starts_with($slug, $prefix . '.') || $slug === $prefix) {
                        return true;
                    }
                } elseif ($slug === $pattern) {
                    return true;
                }
            }
            return false;
        }));
    }
}