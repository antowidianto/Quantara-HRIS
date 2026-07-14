<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Libraries\Identity\RbacMenuCatalogService;
use CodeIgniter\Database\Migration;

final class NormalizeSidebarRouteIdentities extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('menus')) return;

        $service = new RbacMenuCatalogService($this->db);
        $service->syncRouteIdentities();
        $audit = $service->audit();
        if ($audit['missing_codes'] !== [] || $audit['conflicting_codes'] !== []
            || $audit['unknown_permissions'] !== [] || $audit['unmapped_codes'] !== []
        ) {
            throw new \RuntimeException('Sidebar route identities are not consistent');
        }
    }

    public function down(): void
    {
        // Roll forward because tenant-local menus can be customized.
    }
}
