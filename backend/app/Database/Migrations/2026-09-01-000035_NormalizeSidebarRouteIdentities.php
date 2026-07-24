<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Libraries\Identity\RbacMenuCatalogService;
use CodeIgniter\Database\Migration;

final class NormalizeSidebarRouteIdentities extends Migration
{
    public function up(): void
    {
        if (!$this->db->tableExists('menus')) {
            return;
        }

        $service = new RbacMenuCatalogService($this->db);
        $service->syncRouteIdentities();
        // Later migrations add permission-backed items. The final resync
        // migration performs the complete strict catalog audit.
    }

    public function down(): void
    {
        // Roll forward because tenant-local menus can be customized.
    }
}
