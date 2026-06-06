<?php

namespace App\Models\Payroll;

use App\Models\BaseModel;

class LoanInstallmentModel extends BaseModel
{
    protected $table = 'loan_installments';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'loan_id',
        'company_id',
        'installment_number',
        'due_date',
        'amount',
        'status',
        'paid_at',
        'payroll_period_id',
    ];

    protected $casts = [
        'installment_number' => 'int',
        'amount'             => 'float',
    ];
}
