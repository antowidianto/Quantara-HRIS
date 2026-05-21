<?php

namespace App\Controllers\Api\V1;

class PositionController extends MasterCrudController
{
    protected string $table = 'positions';
    protected array $fillable = ['department_id','job_grade_id','code','name','description','tier','is_active'];
    protected string $auditEntity = 'position';
    protected array $createRules = [
        'code' => 'required|alpha_dash|max_length[32]',
        'name' => 'required|max_length[200]',
        'tier' => 'permit_empty|in_list[MANAGER,ASST_MANAGER,SUPERVISOR,STAFF]',
    ];
    protected array $updateRules = [
        'code' => 'permit_empty|alpha_dash|max_length[32]',
        'name' => 'permit_empty|max_length[200]',
        'tier' => 'permit_empty|in_list[MANAGER,ASST_MANAGER,SUPERVISOR,STAFF]',
    ];
}
