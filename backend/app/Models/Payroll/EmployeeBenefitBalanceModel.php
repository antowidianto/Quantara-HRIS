<?php

namespace App\Models\Payroll;

use CodeIgniter\Model;

class EmployeeBenefitBalanceModel extends Model
{
    protected $table = 'employee_benefit_balances';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'employee_id',
        'policy_id',
        'year',
        'allocated_amount',
        'used_amount'
    ];
}
