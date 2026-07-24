<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;

class BenefitPolicyController extends ApiController
{
    private const FILLABLE = [
        'name', 'description', 'job_grade_id', 'limit_amount', 'reset_type',
    ];

    public function index()
    {
        $builder = \Config\Database::connect()->table('benefit_policies bp')
            ->select('bp.*, jg.name as job_grade_name')
            ->join('job_grades jg', 'jg.id = bp.job_grade_id AND jg.company_id = bp.company_id', 'left')
            ->orderBy('bp.created_at', 'DESC');
        $this->tenant()->applyScope($builder, 'bp.company_id');

        return $this->ok($builder->get()->getResultArray());
    }

    public function create()
    {
        $data = $this->getJsonInput();
        if (!$this->validateOrFail($data, [
            'name' => 'required|max_length[100]',
            'limit_amount' => 'required|numeric',
        ])) {
            return null;
        }
        $companyId = $this->requireCompanyId();
        if ($companyId === null) {
            return null;
        }
        $db = \Config\Database::connect();
        if (!$this->validJobGrade($db, $data['job_grade_id'] ?? null, $companyId)) {
            return $this->fail('Job grade di luar perusahaan aktif', 422, 'INVALID_JOB_GRADE_SCOPE');
        }

        $insert = array_intersect_key($data, array_flip(self::FILLABLE));
        $insert['company_id'] = $companyId;
        $insert['created_at'] = date('Y-m-d H:i:s');
        $insert['updated_at'] = date('Y-m-d H:i:s');
        $db->table('benefit_policies')->insert($insert);

        return $this->created(['id' => $db->insertID()], 'Policy created');
    }

    public function show($id)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) {
            return null;
        }
        $row = \Config\Database::connect()->table('benefit_policies')
            ->where('id', (int) $id)->where('company_id', $companyId)->get()->getRowArray();
        if (!$row) {
            return $this->failNotFound();
        }

        return $this->ok($row);
    }

    public function update($id)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) {
            return null;
        }
        $db = \Config\Database::connect();
        $data = array_intersect_key($this->getJsonInput(), array_flip(self::FILLABLE));
        if (array_key_exists('job_grade_id', $data) && !$this->validJobGrade($db, $data['job_grade_id'], $companyId)) {
            return $this->fail('Job grade di luar perusahaan aktif', 422, 'INVALID_JOB_GRADE_SCOPE');
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $db->table('benefit_policies')->where('id', (int) $id)->where('company_id', $companyId)->update($data);
        if ($db->affectedRows() === 0 && !$db->table('benefit_policies')->where('id', (int) $id)->where('company_id', $companyId)->countAllResults()) {
            return $this->failNotFound();
        }

        return $this->ok(null, 'Policy updated');
    }

    public function delete($id)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) {
            return null;
        }
        $db = \Config\Database::connect();
        $db->table('benefit_policies')->where('id', (int) $id)->where('company_id', $companyId)->delete();
        if ($db->affectedRows() === 0) {
            return $this->failNotFound();
        }

        return $this->ok(null, 'Policy deleted');
    }

    private function validJobGrade($db, $jobGradeId, int $companyId): bool
    {
        if ($jobGradeId === null || $jobGradeId === '') {
            return true;
        }

        return $db->table('job_grades')
            ->where('id', (int) $jobGradeId)->where('company_id', $companyId)->countAllResults() === 1;
    }
}
