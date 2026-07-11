<?php

namespace App\Models\Master;

use App\Models\BaseModel;

/**
 * CompanyModel — Sprint B / A1.
 *
 * Tabel `companies` adalah tenant root. Tidak punya tenant scope sendiri
 * (super admin lihat semua), jadi `multiTenant = false`. Subset dari
 * controller yang akses companies tetap di-protect oleh PermissionFilter.
 */
class CompanyModel extends BaseModel
{
    protected $table         = 'companies';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected bool $multiTenant = false;

    protected $allowedFields = [
        'code', 'name', 'legal_name', 'npwp', 'phone', 'email',
        'address', 'province', 'city', 'postal_code',
        'logo_path', 'timezone', 'currency', 'fiscal_year_start',
        'is_active', 'settings',
    ];
}
