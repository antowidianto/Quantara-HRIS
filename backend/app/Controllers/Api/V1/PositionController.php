<?php

namespace App\Controllers\Api\V1;

class PositionController extends MasterCrudController
{
    protected string $table = 'positions';
    protected array $fillable = ['department_id','job_grade_id','code','name','description','is_active'];
    protected string $auditEntity = 'position';
    protected array $createRules = [
        'code' => 'required|alpha_dash|max_length[32]',
        'name' => 'required|max_length[200]',
    ];
}