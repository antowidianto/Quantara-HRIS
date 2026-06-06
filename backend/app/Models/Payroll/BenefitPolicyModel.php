<?php

namespace App\Models\Payroll;

use CodeIgniter\Model;

class BenefitPolicyModel extends Model
{
    protected $table = 'benefit_policies';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'company_id',
        'name',
        'description',
        'limit_amount',
        'job_grade_id',
        'reset_type'
    ];
}
