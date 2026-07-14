<?php

namespace App\Libraries\Identity;

use CodeIgniter\Database\BaseConnection;

/** Keeps database-driven navigation aligned with assignable RBAC permissions. */
final class RbacMenuCatalogService
{
    private const RBAC_ENTERPRISE_CODES = [
        'admin.enterprise',
        'admin.enterprise.subscription',
        'admin.enterprise.usage',
        'admin.enterprise.erp_journal',
        'admin.enterprise.signing',
        'admin.enterprise.api_keys',
        'admin.enterprise.licenses',
        'admin.enterprise.timesheet',
    ];

    /** Permission requirements for ESS pages that were historically unguarded. */
    private const SELF_SERVICE_REQUIREMENTS = [
        'my.team' => 'employee.team_view,employee.view',
        'my.attendance.list' => 'attendance.view_self,attendance.view',
        'my.attendance.clock' => 'attendance.clock',
        'my.attendance.changes' => 'attendance.request_change',
        'my.attendance.wfh' => 'attendance.request_change',
        'my.leaves' => 'leave.view,leave.create',
        'my.leave_balance' => 'leave.view',
        'my.overtimes' => 'overtime.view,overtime.create',
        'my.overtime_plan' => 'overtime.create',
        'my.payslips' => 'payslip.view_self',
        'my.bonuses' => 'bonus.view_self,bonus.view',
        'my.approvals.delegations' => 'delegation.manage_self,delegation.view',
        'my.performance.goal' => 'goal.view',
        'my.performance.peer_review' => 'review.conduct_self,review.conduct,review.view_team',
        'my.performance.pulse' => 'employee.view_self,pulse_survey.manage',
        'my.talent_marketplace' => 'competency.assess_self,internal_job.apply,talent_pool.manage',
        'my.referral' => 'referral.create_self,recruitment.view',
        'my.transfer' => 'employee_transfer.view_self,employee_transfer.view',
        'my.resignation' => 'resignation.view_self,resignation.view',
    ];

    /** Replace historical UI-only slugs with permissions enforced by APIs. */
    private const LEGACY_REQUIREMENTS = [
        'hr.turnover' => 'hr_analytics.view,compliance.report.view',
        'intel.copilot' => 'ai_assistant.use',
        'intel.workforce' => 'workforce_planning.view,workforce_planning.manage',
        'intel.compensation' => 'compensation_intelligence.view,compensation_intelligence.manage',
        'admin.enterprise.usage' => 'billing.manage',
    ];

    /** Items whose access is contextual rather than permission-based. */
    private const UNMAPPED_ITEM_ALLOWLIST = [
        'dashboard',              // every authenticated account
        'my.approvals.inbox',     // ownership:assigned-approver
        'admin.companies',        // super_admin_only
    ];

    /** Query identity prevents self/team variants sharing a component from colliding. */
    private const ROUTE_IDENTITIES = [
        'my.leaves' => '/leaves?scope=mine',
        'hr.leaves' => '/leaves?scope=all&status=PENDING',
        'my.performance.pulse' => '/hr/pulse-survey?tab=respond',
        'intel.pulse_manage' => '/hr/pulse-survey?tab=manage',
    ];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    public static function allows(string $requirement, array $granted): bool
    {
        $required = array_values(array_filter(array_map('trim', explode(',', $requirement))));
        return $required === [] || array_intersect($required, $granted) !== [];
    }

    /** @return array{unblocked:int,inserted:int,updated:int} */
    public function sync(): array
    {
        if (! $this->db->tableExists('menus')) {
            throw new \RuntimeException('Menus table is not available');
        }

        // A menu governed by an assignable permission must not retain an
        // additional super-admin-only gate, including tenant-local forks.
        $this->db->table('menus')->whereIn('code', self::RBAC_ENTERPRISE_CODES)
            ->where('super_admin_only', 1)->update([
                'super_admin_only' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        $unblocked = $this->db->affectedRows();
        $updatedRequirements = $this->syncPermissionRequirements();
        $updatedRoutes = $this->syncRouteIdentities();

        $parent = $this->db->table('menus')->select('id')
            ->where('company_id IS NULL')->where('code', 'hr.talent')
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $parent) {
            $parent = $this->db->table('menus')->select('id')
                ->where('company_id IS NULL')->where('code', 'hr')
                ->where('deleted_at IS NULL')->get()->getRowArray();
        }
        if (! $parent) {
            throw new \RuntimeException('HR menu parent is not available');
        }

        $items = [
            [
                'code' => 'hr.data_quality',
                'label' => 'Kualitas Data Karyawan',
                'translation_key' => 'data_quality.nav_label',
                'icon' => 'ShieldCheckIcon',
                'route_path' => '/hr/data-quality',
                'permission' => 'hr_analytics.view,compliance.report.view',
                'sort_order' => 30,
            ],
            [
                'code' => 'hr.compensation_review',
                'label' => 'Review Kompensasi',
                'translation_key' => 'compensation_review.nav_label',
                'icon' => 'BanknotesIcon',
                'route_path' => '/hr/compensation-review',
                'permission' => 'employee.view,payroll.view',
                'sort_order' => 40,
            ],
        ];

        $inserted = 0;
        $updated = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($items as $item) {
            $existing = $this->db->table('menus')->select('id')
                ->where('company_id IS NULL')->where('code', $item['code'])
                ->get()->getRowArray();
            $row = $item + [
                'company_id' => null,
                'parent_id' => (int) $parent['id'],
                'type' => 'ITEM',
                'super_admin_only' => 0,
                'is_active' => 1,
                'updated_at' => $now,
            ];
            if ($existing) {
                unset($row['company_id']);
                $this->db->table('menus')
                    ->where('company_id IS NULL')
                    ->where('id', (int) $existing['id'])
                    ->update($row);
                $updated++;
                continue;
            }
            $row['created_at'] = $now;
            $this->db->table('menus')->set('company_id', null)->insert($row);
            $inserted++;
        }

        $updated += $updatedRequirements + $updatedRoutes;
        return compact('unblocked', 'inserted', 'updated');
    }

    public function syncPermissionRequirements(): int
    {
        $updated = 0;
        $now = date('Y-m-d H:i:s');
        foreach (array_merge(self::SELF_SERVICE_REQUIREMENTS, self::LEGACY_REQUIREMENTS) as $code => $permission) {
            $this->db->table('menus')->where('company_id IS NULL')->where('code', $code)->update([
                'permission' => $permission,
                'updated_at' => $now,
            ]);
            $updated += $this->db->affectedRows();
        }
        return $updated;
    }

    public function syncRouteIdentities(): int
    {
        $updated = 0;
        $now = date('Y-m-d H:i:s');
        foreach (self::ROUTE_IDENTITIES as $code => $routePath) {
            $this->db->table('menus')->where('company_id IS NULL')->where('code', $code)->update([
                'route_path' => $routePath,
                'updated_at' => $now,
            ]);
            $updated += $this->db->affectedRows();
        }
        return $updated;
    }

    /** @return array{missing_codes:array<int,string>,conflicting_codes:array<int,string>,unknown_permissions:array<int,string>,unmapped_codes:array<int,string>} */
    public function audit(): array
    {
        $requirements = array_merge(self::SELF_SERVICE_REQUIREMENTS, self::LEGACY_REQUIREMENTS);
        $requiredCodes = array_merge(self::RBAC_ENTERPRISE_CODES, array_keys($requirements), array_keys(self::ROUTE_IDENTITIES), [
            'hr.data_quality',
            'hr.compensation_review',
        ]);
        $rows = $this->db->table('menus')->select('code, super_admin_only, permission, route_path')
            ->where('company_id IS NULL')
            ->whereIn('code', $requiredCodes)->where('deleted_at IS NULL')
            ->get()->getResultArray();
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[(string) $row['code']][] = $row;
        }

        $missing = [];
        $conflicting = [];
        foreach ($requiredCodes as $code) {
            if (! isset($byCode[$code])) {
                $missing[] = $code;
                continue;
            }
            foreach ($byCode[$code] as $row) {
                if ((int) $row['super_admin_only'] === 1) {
                    $conflicting[] = $code;
                    break;
                }
                if (isset($requirements[$code])
                    && trim((string) ($row['permission'] ?? '')) !== $requirements[$code]
                ) {
                    $conflicting[] = $code;
                    break;
                }
                if (isset(self::ROUTE_IDENTITIES[$code])
                    && (string) ($row['route_path'] ?? '') !== self::ROUTE_IDENTITIES[$code]
                ) {
                    $conflicting[] = $code;
                    break;
                }
            }
        }

        $knownRows = $this->db->table('permissions')->select('slug')->get()->getResultArray();
        $known = array_fill_keys(array_column($knownRows, 'slug'), true);
        $unknown = [];
        $unmapped = [];
        $menuRows = $this->db->table('menus')->select('code, permission')
            ->where('company_id IS NULL')
            ->where('type', 'ITEM')->where('is_active', 1)->where('deleted_at IS NULL')
            ->get()->getResultArray();
        foreach ($menuRows as $menuRow) {
            if (trim((string) ($menuRow['permission'] ?? '')) === ''
                && ! in_array((string) $menuRow['code'], self::UNMAPPED_ITEM_ALLOWLIST, true)
            ) {
                $unmapped[] = (string) $menuRow['code'];
            }
            foreach (explode(',', (string) ($menuRow['permission'] ?? '')) as $slug) {
                $slug = trim($slug);
                if ($slug !== '' && ! isset($known[$slug])) $unknown[] = $slug;
            }
        }

        return [
            'missing_codes' => array_values(array_unique($missing)),
            'conflicting_codes' => array_values(array_unique($conflicting)),
            'unknown_permissions' => array_values(array_unique($unknown)),
            'unmapped_codes' => array_values(array_unique($unmapped)),
        ];
    }
}
