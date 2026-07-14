<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Seed permissions that became enforceable at the route boundary. */
final class SeedRoutePolicyPermissions extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('permissions')) {
            return;
        }

        $permissions = [
            ['slug' => 'attendance.view_self', 'name' => 'View Own Attendance', 'group' => 'attendance', 'description' => 'View the authenticated employee attendance'],
            ['slug' => 'billing.manage', 'name' => 'Manage Billing', 'group' => 'billing', 'description' => 'Manage the tenant subscription lifecycle'],
            ['slug' => 'employee.manage', 'name' => 'Manage Employees', 'group' => 'employee', 'description' => 'Create, update, and deactivate employees'],
            ['slug' => 'payroll.adjustment.manage', 'name' => 'Manage Payroll Adjustments', 'group' => 'payroll', 'description' => 'Create append-only payroll corrections'],
            ['slug' => 'payroll.adjustment.approve', 'name' => 'Approve Payroll Adjustments', 'group' => 'payroll', 'description' => 'Independently approve or reverse payroll corrections'],
            ['slug' => 'shift_schedules.manage', 'name' => 'Manage Shift Schedules', 'group' => 'shift_schedules', 'description' => 'Create and maintain employee shift schedules'],
            ['slug' => 'shift_schedules.view', 'name' => 'View Shift Schedules', 'group' => 'shift_schedules', 'description' => 'View employee shift schedules and assignment previews'],
            ['slug' => 'webhook.view', 'name' => 'View Webhooks', 'group' => 'webhook', 'description' => 'View tenant webhook configuration and delivery evidence'],
            ['slug' => 'webhook.manage', 'name' => 'Manage Webhooks', 'group' => 'webhook', 'description' => 'Create, rotate, update, and remove tenant webhooks'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($permissions as $permission) {
            if ($this->db->table('permissions')->where('slug', $permission['slug'])->countAllResults() === 0) {
                $this->db->table('permissions')->insert($permission + ['created_at' => $now]);
            }
        }

        if (! $this->db->tableExists('roles') || ! $this->db->tableExists('role_permissions')) {
            return;
        }
        $grants = [
            'attendance.view_self' => ['employee', 'manager', 'hr_admin', 'finance', 'payroll_admin', 'cfo'],
            'billing.manage' => ['hr_admin'],
            'employee.manage' => ['hr_admin'],
            'payroll.adjustment.manage' => ['hr_admin', 'payroll_admin'],
            'payroll.adjustment.approve' => ['finance', 'cfo'],
            'shift_schedules.manage' => ['hr_admin', 'manager'],
            'shift_schedules.view' => ['employee', 'manager', 'hr_admin'],
            'webhook.view' => ['hr_admin', 'it_admin', 'internal_auditor'],
            'webhook.manage' => ['hr_admin', 'it_admin'],
        ];
        foreach ($grants as $permissionSlug => $roleSlugs) {
            $permission = $this->db->table('permissions')->select('id')->where('slug', $permissionSlug)->get()->getRowArray();
            if (! $permission) {
                continue;
            }
            $roles = $this->db->table('roles')->select('id')->whereIn('slug', $roleSlugs)->get()->getResultArray();
            foreach ($roles as $role) {
                $exists = $this->db->table('role_permissions')
                    ->where('role_id', $role['id'])
                    ->where('permission_id', $permission['id'])
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

    public function down(): void
    {
        // Permissions may already be assigned to custom roles in production.
        // Roll forward with a replacement policy instead of deleting evidence.
    }
}
