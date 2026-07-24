<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/** Creates non-employee checker accounts for local dummy payroll testing. */
final class PayrollDummyApproverSeeder extends Seeder
{
    private const PASSWORD = 'DummyPayroll@123';

    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('PayrollDummyApproverSeeder tidak boleh dijalankan di production');
        }

        $roles = [];
        foreach (['assistant_manager_payroll', 'manager_payroll'] as $slug) {
            $role = $this->db->table('roles')->where('slug', $slug)->get()->getRowArray();
            if (!$role) {
                throw new \RuntimeException("Role {$slug} belum tersedia. Jalankan migrasi terlebih dahulu.");
            }
            $roles[$slug] = (int) $role['id'];
        }

        $now = date('Y-m-d H:i:s');
        $passwordHash = password_hash(self::PASSWORD, PASSWORD_ARGON2ID);
        $created = 0;
        $reassigned = 0;
        $removedDummy = 0;
        $companies = $this->db->table('companies')->where('is_active', 1)->get()->getResultArray();
        foreach ($companies as $company) {
            $companyId = (int) $company['id'];
            $companyCode = strtolower(preg_replace('/[^a-z0-9]+/i', '.', (string) $company['code']));

            $roleMappings = [
                'assistant_manager_payroll' => ['Asst.', 'Manager'],
                'manager_payroll' => ['Payroll Manager'],
            ];
            foreach ($roleMappings as $targetRole => $positionTerms) {
                $builder = $this->db->table('users u')->select('u.id')
                    ->join('employees e', 'e.id = u.employee_id')
                    ->join('positions p', 'p.id = e.position_id')
                    ->join('roles r', 'r.id = u.role_id')
                    ->where('u.company_id', $companyId)->where('u.is_active', 1)
                    ->where('r.slug', 'payroll_admin');
                foreach ($positionTerms as $term) {
                    $builder->like('p.name', $term);
                }
                $candidate = $builder->orderBy('u.id', 'ASC')->get()->getRowArray();
                if ($candidate) {
                    $this->db->table('users')->where('id', (int) $candidate['id'])
                        ->where('company_id', $companyId)->update([
                            'role_id' => $roles[$targetRole],
                            'updated_at' => $now,
                        ]);
                    $reassigned++;
                }
            }

            foreach (['assistant_manager_payroll', 'manager_payroll'] as $targetRole) {
                $realAccountCount = $this->db->table('users')->where('company_id', $companyId)
                    ->where('role_id', $roles[$targetRole])->where('is_active', 1)
                    ->notLike('email', '@dummy.hris.local')->countAllResults();
                if ($realAccountCount > 0) {
                    $dummyUsers = $this->db->table('users')->select('id')->where('company_id', $companyId)
                        ->where('role_id', $roles[$targetRole])->like('email', '@dummy.hris.local')
                        ->get()->getResultArray();
                    foreach ($dummyUsers as $dummyUser) {
                        $this->db->table('users')->where('id', (int) $dummyUser['id'])
                            ->where('company_id', $companyId)->delete();
                        $removedDummy++;
                    }
                }
            }

            foreach ([
                ['assistant_manager_payroll', 'Dummy Assistant Manager Payroll', "asst.manager.payroll.{$companyCode}@dummy.hris.local"],
                ['manager_payroll', 'Dummy Manager Payroll', "manager.payroll.{$companyCode}@dummy.hris.local"],
            ] as [$roleSlug, $name, $email]) {
                $exists = $this->db->table('users')->where('company_id', $companyId)
                    ->where('role_id', $roles[$roleSlug])->where('is_active', 1)->countAllResults();
                if ($exists > 0) {
                    continue;
                }
                $this->db->table('users')->insert([
                    'company_id' => $companyId,
                    'role_id' => $roles[$roleSlug],
                    'employee_id' => null,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'name' => $name,
                    'is_active' => 1,
                    'must_change_password' => 0,
                    'password_changed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created++;
            }
        }

        echo "Existing payroll approvers reassigned: {$reassigned}. Dummy users removed: {$removedDummy}." . PHP_EOL;
        echo "Payroll dummy approvers created: {$created}. Password: " . self::PASSWORD . PHP_EOL;
        $candidates = $this->db->table('users u')
            ->select('u.id, u.company_id, u.name, u.email, r.slug AS role_slug, r.name AS role_name, e.employee_number, p.name AS position_name')
            ->join('roles r', 'r.id = u.role_id', 'left')
            ->join('employees e', 'e.id = u.employee_id', 'left')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->groupStart()
            ->whereIn('r.slug', ['payroll_admin', 'assistant_manager_payroll', 'manager_payroll'])
            ->orLike('p.name', 'payroll')
            ->orLike('u.name', 'payroll')
            ->groupEnd()
            ->where('u.is_active', 1)
            ->orderBy('u.company_id', 'ASC')->orderBy('u.id', 'ASC')->get()->getResultArray();
        echo 'Active payroll account candidates:' . PHP_EOL;
        foreach ($candidates as $candidate) {
            echo sprintf(
                '  user=%d company=%d email=%s role=%s position=%s employee=%s%s',
                (int) $candidate['id'],
                (int) $candidate['company_id'],
                (string) $candidate['email'],
                (string) ($candidate['role_name'] ?? '-'),
                (string) ($candidate['position_name'] ?? '-'),
                (string) ($candidate['employee_number'] ?? '-'),
                PHP_EOL,
            );
        }
    }
}
