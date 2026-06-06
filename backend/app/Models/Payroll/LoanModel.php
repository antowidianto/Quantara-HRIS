<?php

namespace App\Models\Payroll;

use App\Models\BaseModel;

class LoanModel extends BaseModel
{
    protected $table = 'loans';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'company_id',
        'employee_id',
        'loan_number',
        'principal',
        'interest_rate',
        'total_interest',
        'total_amount',
        'installment_amount',
        'installments',
        'paid_installments',
        'remaining',
        'start_period_id',
        'status',
        'reason',
        'approved_by',
        'approved_at',
    ];

    // Automatic cast to native PHP types
    protected $casts = [
        'principal'          => 'float',
        'interest_rate'      => 'float',
        'total_interest'     => 'float',
        'total_amount'       => 'float',
        'installments'       => 'int',
        'paid_installments'  => 'int',
        'remaining'          => 'float',
        'installment_amount' => 'float',
    ];
}
