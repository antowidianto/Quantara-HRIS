<?php

namespace App\Models\Identity;

use App\Models\BaseModel;

/**
 * MenuModel — sumber data sidebar dinamis.
 *
 * Tabel `menus` lihat migration #076. Model ini multi-tenant tetapi dengan
 * fallback global: kalau company tidak punya menu sendiri, pakai menu
 * default (company_id = NULL). Ini berbeda dengan tenant scope BaseModel
 * yang strict; oleh karena itu kita matikan flag `$multiTenant` dan
 * kerjakan filtering manual di controller.
 */
class MenuModel extends BaseModel
{
    protected $table         = 'menus';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected bool $multiTenant = false;

    protected $allowedFields = [
        'company_id', 'parent_id', 'type', 'code', 'label', 'translation_key',
        'icon', 'route_path', 'permission', 'super_admin_only', 'sort_order',
        'is_active',
    ];

    /**
     * Resolve a menu only when it is global or belongs to the active tenant.
     * Super admins retain the explicit cross-tenant administration path.
     */
    public function findForTenantOrGlobal(int $id, int $companyId, bool $isSuperAdmin): ?array
    {
        $query = $this->where($this->primaryKey, $id)->where('deleted_at IS NULL');
        if (! $isSuperAdmin) {
            $query->groupStart()
                ->where('company_id', $companyId)
                ->orWhere('company_id IS NULL')
                ->groupEnd();
        }

        $row = $query->first();
        return is_array($row) ? $row : null;
    }
}
