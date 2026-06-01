<?php

namespace App\Controllers\Api\V1\Master;

use App\Controllers\Api\V1\MasterCrudController;
class DepartmentController extends MasterCrudController
{
    protected string $table = 'departments';
    protected array $fillable = ['parent_id','code','name','description','manager_employee_id','is_active'];
    protected string $auditEntity = 'department';
    protected array $createRules = [
        'code' => 'required|alpha_dash|max_length[32]',
        'name' => 'required|max_length[200]',
    ];
}