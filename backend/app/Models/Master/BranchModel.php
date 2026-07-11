<?php

namespace App\Models\Master;

use App\Models\BaseModel;

class BranchModel extends BaseModel
{
    protected $table         = 'branches';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';

    protected $allowedFields = [
        'company_id', 'code', 'name', 'address', 'province', 'city',
        'phone', 'lat', 'lng', 'geofence_radius_m', 'is_active',
    ];
}
