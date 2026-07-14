<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Libraries\Identity\RbacMenuCatalogService;
use CodeIgniter\Database\Migration;

final class NormalizeLegacyMenuPermissions extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('menus')) return;

        $service = new RbacMenuCatalogService($this->db);
        $service->syncPermissionRequirements();
        $audit = $service->audit();
        if ($audit['missing_codes'] !== []
            || $audit['conflicting_codes'] !== []
            || $audit['unknown_permissions'] !== []
            || $audit['unmapped_codes'] !== []
        ) {
            throw new \RuntimeException('Menu permissions are not aligned with the RBAC master');
        }
    }

    public function down(): void
    {
        // Roll forward because tenant menus may be customized after deployment.
    }
}
