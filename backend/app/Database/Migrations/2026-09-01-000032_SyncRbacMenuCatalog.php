<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Libraries\Identity\RbacMenuCatalogService;
use CodeIgniter\Database\Migration;

final class SyncRbacMenuCatalog extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('menus')) {
            $service = new RbacMenuCatalogService($this->db);
            $service->sync();
            // Do not audit against the evolving runtime catalog here. Later
            // migrations add self-service permissions; a final resync migration
            // validates the complete catalog after those definitions exist.
        }
    }

    public function down(): void
    {
        // Menu records can be customized and assigned after deployment.
    }
}
