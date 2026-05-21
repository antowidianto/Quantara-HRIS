<?php

namespace App\Controllers\Api\V1;

class BranchController extends MasterCrudController
{
    protected string $table = 'branches';
    protected array $fillable = ['code','name','address','province','city','phone','lat','lng','geofence_radius_m','is_active'];
    protected string $auditEntity = 'branch';
    protected array $createRules = [
        'code' => 'required|alpha_dash|max_length[32]',
        'name' => 'required|max_length[200]',
    ];
}