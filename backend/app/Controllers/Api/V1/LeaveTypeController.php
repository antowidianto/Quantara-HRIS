<?php

namespace App\Controllers\Api\V1;

class LeaveTypeController extends MasterCrudController
{
    protected string $table = 'leave_types';
    protected array $fillable = [
        'code','name','category','default_days','max_days','is_paid','requires_doc',
        'min_tenure_months','reset_yearly','gender_restriction','description','is_active',
    ];
    protected string $auditEntity = 'leave_type';
    protected array $createRules = [
        'code' => 'required|alpha_dash|max_length[32]',
        'name' => 'required|max_length[100]',
        'category' => 'required|in_list[ANNUAL,SICK,MATERNITY,PATERNITY,MENSTRUAL,MARRIAGE,BEREAVEMENT,HAJJ,UNPAID,SPECIAL]',
    ];
}