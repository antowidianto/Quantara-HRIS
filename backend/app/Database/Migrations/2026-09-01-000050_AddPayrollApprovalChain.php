<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Adds the canonical maker-checker chain for monthly payroll periods. */
final class AddPayrollApprovalChain extends Migration
{
    private const CHAIN_CODE = 'PAYROLL_STANDARD';

    public function up(): void
    {
        if (!$this->db->tableExists('roles')
            || !$this->db->tableExists('approval_chains')
            || !$this->db->tableExists('approval_chain_steps')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $assistantRoleId = $this->ensureRole(
            'assistant_manager_payroll',
            'Assistant Manager Payroll',
            'Checker payroll tahap pertama setelah Payroll Administrator.',
            $now,
        );
        $managerRoleId = $this->ensureRole(
            'manager_payroll',
            'Manager Payroll',
            'Checker final dan pemilik keputusan akhir payroll.',
            $now,
        );
        $this->grantPermissions($assistantRoleId, ['payroll.view', 'payroll.finalize', 'payslip.view_all'], $now);
        $this->grantPermissions($managerRoleId, ['payroll.view', 'payroll.finalize', 'payslip.view_all'], $now);

        $companies = $this->db->table('companies')->where('is_active', 1)->get()->getResultArray();
        foreach ($companies as $company) {
            $this->ensureChain((int) $company['id'], $assistantRoleId, $managerRoleId, $now);
        }
    }

    public function down(): void
    {
        $chains = $this->db->table('approval_chains')->select('id')
            ->where('code', self::CHAIN_CODE)->where('module', 'PAYROLL')->get()->getResultArray();
        foreach ($chains as $chain) {
            $chainId = (int) $chain['id'];
            $pending = $this->db->table('approval_requests')->where('chain_id', $chainId)
                ->where('status', 'PENDING')->countAllResults();
            if ($pending === 0) {
                $this->db->table('approval_chain_steps')->where('chain_id', $chainId)->delete();
                $this->db->table('approval_chains')->where('id', $chainId)->delete();
            }
        }
    }

    private function ensureRole(string $slug, string $name, string $description, string $now): int
    {
        $role = $this->db->table('roles')->where('slug', $slug)->get()->getRowArray();
        if ($role) {
            return (int) $role['id'];
        }
        $this->db->table('roles')->insert([
            'company_id' => null,
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'is_system' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->db->insertID();
    }

    /** @param list<string> $permissionSlugs */
    private function grantPermissions(int $roleId, array $permissionSlugs, string $now): void
    {
        $permissions = $this->db->table('permissions')->select('id')->whereIn('slug', $permissionSlugs)
            ->get()->getResultArray();
        foreach ($permissions as $permission) {
            $permissionId = (int) $permission['id'];
            $exists = $this->db->table('role_permissions')->where('role_id', $roleId)
                ->where('permission_id', $permissionId)->countAllResults();
            if ($exists === 0) {
                $this->db->table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                ]);
            }
        }
    }

    private function ensureChain(int $companyId, int $assistantRoleId, int $managerRoleId, string $now): void
    {
        $chain = $this->db->table('approval_chains')->where('company_id', $companyId)
            ->where('code', self::CHAIN_CODE)->where('deleted_at IS NULL')->get()->getRowArray();
        if (!$chain) {
            $this->db->table('approval_chains')->where('company_id', $companyId)
                ->where('module', 'PAYROLL')->update(['is_default' => 0, 'updated_at' => $now]);
            $this->db->table('approval_chains')->insert([
                'company_id' => $companyId,
                'code' => self::CHAIN_CODE,
                'name' => 'Payroll Admin → Assistant Manager Payroll → Manager Payroll',
                'module' => 'PAYROLL',
                'description' => 'Payroll Admin menyiapkan payroll; Assistant Manager Payroll dan Manager Payroll menyetujui berurutan.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $chainId = (int) $this->db->insertID();
        } else {
            $chainId = (int) $chain['id'];
            $this->db->table('approval_chains')->where('id', $chainId)->update([
                'module' => 'PAYROLL',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'is_active' => 1,
                'updated_at' => $now,
            ]);
            $this->db->table('approval_chain_steps')->where('chain_id', $chainId)
                ->where('company_id', $companyId)->delete();
        }

        foreach ([
            [1, 'Approval Assistant Manager Payroll', $assistantRoleId],
            [2, 'Approval Final Manager Payroll', $managerRoleId],
        ] as [$order, $name, $roleId]) {
            $this->db->table('approval_chain_steps')->insert([
                'company_id' => $companyId,
                'chain_id' => $chainId,
                'step_order' => $order,
                'name' => $name,
                'approver_type' => 'ROLE',
                'approver_role_id' => $roleId,
                'is_required' => 1,
                'allow_skip_if_self' => 0,
                'sla_hours' => 24,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
