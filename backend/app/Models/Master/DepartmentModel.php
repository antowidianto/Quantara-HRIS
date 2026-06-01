<?php

namespace App\Models\Master;

class DepartmentModel extends BaseModel
{
    protected $table         = 'departments';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';

    protected $allowedFields = [
        'company_id', 'parent_id', 'code', 'name', 'description',
        'manager_employee_id', 'is_active',
    ];
}