<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Libraries\Identity\RbacMenuCatalogService;
use CodeIgniter\Database\Migration;

/** Align ESS menus, route permissions, and the Employee baseline. */
final class NormalizeSelfServiceRbac extends Migration
{
    private const PERMISSIONS = [
        ['slug' => 'employee.team_view', 'name' => 'View Own Reporting Team', 'group' => 'employee'],
        ['slug' => 'bonus.view_self', 'name' => 'View Own Bonus History', 'group' => 'bonus'],
        ['slug' => 'review.conduct_self', 'name' => 'Conduct Assigned Performance Review', 'group' => 'performance'],
        ['slug' => 'referral.create_self', 'name' => 'Create Own Employee Referral', 'group' => 'recruitment'],
        ['slug' => 'resignation.view_self', 'name' => 'Manage Own Resignation Request', 'group' => 'lifecycle'],
        ['slug' => 'employee_transfer.view_self', 'name' => 'View Own Employee Transfer', 'group' => 'employee'],
    ];

    private const EMPLOYEE_BASELINE = [
        'employee.team_view',
        'bonus.view_self',
        'review.conduct_self',
        'referral.create_self',
        'resignation.view_self',
        'employee_transfer.view_self',
        'competency.assess_self',
        'internal_job.apply',
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        if ($this->db->tableExists('permissions')) {
            foreach (self::PERMISSIONS as $definition) {
                if ($this->db->table('permissions')->where('slug', $definition['slug'])->countAllResults() === 0) {
                    $this->db->table('permissions')->insert($definition + ['created_at' => $now]);
                }
            }
        }

        if ($this->db->tableExists('roles') && $this->db->tableExists('role_permissions')) {
            $roles = $this->db->table('roles')->select('id')->where('slug', 'employee')->get()->getResultArray();
            $permissions = $this->db->table('permissions')->select('id, slug')
                ->whereIn('slug', self::EMPLOYEE_BASELINE)->get()->getResultArray();
            foreach ($roles as $role) {
                foreach ($permissions as $permission) {
                    $exists = $this->db->table('role_permissions')
                        ->where('role_id', $role['id'])->where('permission_id', $permission['id'])
                        ->countAllResults();
                    if ($exists === 0) {
                        $this->db->table('role_permissions')->insert([
                            'role_id' => $role['id'],
                            'permission_id' => $permission['id'],
                            'created_at' => $now,
                        ]);
                    }
                }
            }
        }

        if ($this->db->tableExists('menus')) {
            (new RbacMenuCatalogService($this->db))->syncPermissionRequirements();
        }
    }

    public function down(): void
    {
        // Roll forward: menu/role assignments may be customized after deployment.
    }
}
