<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class BackfillOrganizationKeyRoles extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        if (! $db->tableExists('organization_structures')) return;

        $definitions = [
            'CEO' => ['Direktur Utama','BOD',10,['mpp.unbudget'],null,['ceo']],
            'DIRECTOR_HR' => ['Direktur HR','BOD',20,['mpp.unbudget','severance.high_value'],'CEO',['hr_director']],
            'DIRECTOR_FINANCE' => ['Direktur Keuangan','BOD',30,['mpp.unbudget','reimbursement.high_value'],'CEO',['cfo']],
            'DIRECTOR_OPS' => ['Direktur Operasional','BOD',40,[],'CEO',['department_head']],
            'GM_HR' => ['General Manager HR','GENERAL_MANAGER',100,[],'DIRECTOR_HR',['hr_business_partner']],
            'GM_FINANCE' => ['General Manager Finance','GENERAL_MANAGER',110,[],'DIRECTOR_FINANCE',['finance']],
            'HRD_MANAGER' => ['HR Manager','MANAGER',200,['mpp.budget','leave.long_term','resignation.approve','disciplinary.issue'],'DIRECTOR_HR',['hr_business_partner','hr_admin']],
            'TALENT_ACQ_LEAD' => ['Talent Acquisition Lead','MANAGER',210,['recruitment.manage','mpp.fulfill'],'HRD_MANAGER',['recruiter']],
            'COMP_BEN_LEAD' => ['Compensation & Benefit Lead','MANAGER',220,['payroll.manage','allowance.manage'],'HRD_MANAGER',['compensation_benefits']],
            'HR_OPS_LEAD' => ['HR Operations Lead','MANAGER',230,['attendance.review_anomaly'],'HRD_MANAGER',['payroll_admin','hr_admin']],
            'PEOPLE_DEV_LEAD' => ['People Development Lead','MANAGER',240,['training.manage','performance.manage'],'HRD_MANAGER',['learning_development']],
            'FINANCE_MANAGER' => ['Finance Manager','MANAGER',300,['payroll.finalize','reimbursement.approve'],'DIRECTOR_FINANCE',['finance']],
            'PAYROLL_LEAD' => ['Payroll Lead','SUPERVISOR',310,[],'FINANCE_MANAGER',['payroll_admin']],
            'TAX_LEAD' => ['Tax Lead','SUPERVISOR',320,[],'FINANCE_MANAGER',['finance']],
            'IT_MANAGER' => ['IT Manager','MANAGER',400,['onboarding.it_setup'],'CEO',['it_admin']],
            'OPS_MANAGER' => ['Operations Manager','MANAGER',410,[],'DIRECTOR_OPS',['department_head','branch_manager']],
        ];

        $companies = $db->table('companies')->where('is_active', 1)->get()->getResultArray();
        $now = date('Y-m-d H:i:s');
        foreach ($companies as $company) {
            $companyId = (int) $company['id'];
            $ids = [];
            foreach ($definitions as $key => [$title,$level,$sort,$scopes,$parentKey,$roleSlugs]) {
                $row = $db->table('organization_structures')
                    ->where('company_id', $companyId)->where('role_key', $key)
                    ->where('deleted_at IS NULL')->get()->getRowArray();
                if (! $row) {
                    $db->table('organization_structures')->insert([
                        'company_id'=>$companyId, 'role_key'=>$key, 'title'=>$title, 'level'=>$level,
                        'approval_scopes'=>$scopes ? json_encode($scopes) : null,
                        'is_key_role'=>1, 'is_active'=>1, 'sort_order'=>$sort,
                        'created_at'=>$now, 'updated_at'=>$now,
                    ]);
                    $id = (int) $db->insertID();
                    $row = ['id'=>$id, 'employee_id'=>null, 'backup_employee_ids'=>null];
                }
                $ids[$key] = (int) $row['id'];

                if (empty($row['employee_id'])) {
                    $candidates = $db->table('employees e')
                        ->select('e.id')
                        ->join('users u', 'u.employee_id = e.id')
                        ->join('roles r', 'r.id = u.role_id')
                        ->where('e.company_id', $companyId)
                        ->where('e.is_active', 1)
                        ->where('e.deleted_at IS NULL')
                        ->where('u.is_active', 1)
                        ->whereIn('r.slug', $roleSlugs)
                        ->orderBy('e.id', 'ASC')->get()->getResultArray();
                    if ($candidates) {
                        $employeeIds = array_values(array_unique(array_map(static fn ($r) => (int) $r['id'], $candidates)));
                        $db->table('organization_structures')->where('id', (int) $row['id'])->update([
                            'employee_id' => array_shift($employeeIds),
                            'backup_employee_ids' => $employeeIds ? json_encode(array_slice($employeeIds, 0, 3)) : null,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }

            foreach ($definitions as $key => $definition) {
                $parentKey = $definition[4];
                if ($parentKey && isset($ids[$key], $ids[$parentKey])) {
                    $db->table('organization_structures')->where('id', $ids[$key])->update([
                        'parent_role_id' => $ids[$parentKey], 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Data assignment bersifat master operasional; rollback tidak
        // menghapusnya agar keputusan HR setelah migration tidak hilang.
    }
}
