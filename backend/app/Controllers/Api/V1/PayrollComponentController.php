<?php

namespace App\Controllers\Api\V1;

class PayrollComponentController extends MasterCrudController
{
    protected string $table = 'payroll_components';
    protected array $fillable = ['code','name','type','category','is_taxable','is_fixed','amount','formula','is_active'];
    protected string $auditEntity = 'payroll_component';
    protected array $createRules = [
        'code' => 'required|alpha_dash|max_length[32]',
        'name' => 'required|max_length[100]',
        'type' => 'required|in_list[EARNING,DEDUCTION]',
        'category' => 'required|in_list[BASIC,ALLOWANCE,BONUS,OVERTIME,THR,REIMBURSEMENT,BPJS_KES,BPJS_TK,PPH21,LOAN,OTHER]',
    ];
}