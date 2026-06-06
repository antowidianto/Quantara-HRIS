<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;

class BenefitPolicyController extends ApiController
{
    protected $table = 'benefit_policies';

    public function index()
    {
        $db = \Config\Database::connect();
        
        $builder = $db->table('benefit_policies bp')
            ->select('bp.*, jg.name as job_grade_name')
            ->join('job_grades jg', 'jg.id = bp.job_grade_id', 'left')
            ->orderBy('bp.created_at', 'DESC');
            
        return $this->ok($builder->get()->getResultArray());
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $this->validateOrFail($data, [
            'name'         => 'required|max_length[100]',
            'limit_amount' => 'required|numeric',
        ]);

        $data['company_id'] = $this->tenant()->getCompanyId();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        $db = \Config\Database::connect();
        $db->table($this->table)->insert($data);
        return $this->created(['id' => $db->insertID()], 'Policy created');
    }

    public function show($id)
    {
        $db = \Config\Database::connect();
        $row = $db->table($this->table)->where('id', $id)->get()->getRowArray();
        if (! $row) return $this->failNotFound();
        return $this->ok($row);
    }

    public function update($id)
    {
        $data = $this->getJsonInput();
        $data['updated_at'] = date('Y-m-d H:i:s');

        $db = \Config\Database::connect();
        $db->table($this->table)->where('id', $id)->update($data);
        return $this->ok(null, 'Policy updated');
    }

    public function delete($id)
    {
        $db = \Config\Database::connect();
        $db->table($this->table)->where('id', $id)->delete();
        return $this->ok(null, 'Policy deleted');
    }
}
