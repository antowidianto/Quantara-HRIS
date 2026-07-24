<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Libraries\Identity\RbacMenuCatalogService;
use CodeIgniter\Database\Migration;

final class NormalizeLegacyMenuPermissions extends Migration
{
    public function up(): void
    {
        if (!$this->db->tableExists('menus')) {
            return;
        }

        $service = new RbacMenuCatalogService($this->db);
        $service->syncPermissionRequirements();
        // The catalog continues to evolve in later migrations. The final
        // resync migration performs the complete strict audit.
    }

    public function down(): void
    {
        // Roll forward because tenant menus may be customized after deployment.
    }
}
