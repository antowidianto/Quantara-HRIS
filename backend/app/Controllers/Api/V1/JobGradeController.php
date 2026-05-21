<?php

namespace App\Controllers\Api\V1;

class JobGradeController extends MasterCrudController
{
    protected string $table = 'job_grades';
    protected array $fillable = ['code','name','level','min_salary','max_salary','is_active'];
    protected string $auditEntity = 'job_grade';
    protected array $createRules = [
        'code' => 'required|alpha_dash|max_length[32]',
        'name' => 'required|max_length[100]',
    ];
    protected string $orderBy = 'level';
}