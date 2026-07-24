<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Maps existing payroll leadership accounts onto the new sequential chain roles. */
final class AssignExistingPayrollApproverRoles extends Migration
{
    public function up(): void
    {
        $payrollAdmin = $this->roleId('payroll_admin');
        $assistantManager = $this->roleId('assistant_manager_payroll');
        $manager = $this->roleId('manager_payroll');
        if (!$payrollAdmin || !$assistantManager || !$manager) {
            return;
        }

        $companies = $this->db->table('companies')->where('is_active', 1)->get()->getResultArray();
        foreach ($companies as $company) {
            $companyId = (int) $company['id'];
            $this->assignByPosition($companyId, $payrollAdmin, $assistantManager, ['Asst.', 'Manager']);
            $this->assignByPosition($companyId, $payrollAdmin, $manager, ['Payroll Manager']);
        }
    }

    public function down(): void
    {
        // Role assignments are intentionally retained to avoid revoking access
        // from accounts that may have been used after this migration.
    }

    /** @param list<string> $positionTerms */
    private function assignByPosition(
        int $companyId,
        int $sourceRoleId,
        int $targetRoleId,
        array $positionTerms,
    ): void {
        $alreadyAssigned = $this->db->table('users')->where('company_id', $companyId)
            ->where('role_id', $targetRoleId)->where('is_active', 1)->countAllResults();
        if ($alreadyAssigned > 0) {
            return;
        }

        $builder = $this->db->table('users u')->select('u.id')
            ->join('employees e', 'e.id = u.employee_id')
            ->join('positions p', 'p.id = e.position_id')
            ->where('u.company_id', $companyId)->where('u.role_id', $sourceRoleId)
            ->where('u.is_active', 1)->where('e.is_active', 1)->where('e.deleted_at IS NULL');
        foreach ($positionTerms as $term) {
            $builder->like('p.name', $term);
        }
        $candidate = $builder->orderBy('u.id', 'ASC')->get()->getRowArray();
        if ($candidate) {
            $this->db->table('users')->where('id', (int) $candidate['id'])
                ->where('company_id', $companyId)->update([
                    'role_id' => $targetRoleId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        }
    }

    private function roleId(string $slug): int
    {
        $role = $this->db->table('roles')->select('id')->where('slug', $slug)->get()->getRowArray();

        return (int) ($role['id'] ?? 0);
    }
}
