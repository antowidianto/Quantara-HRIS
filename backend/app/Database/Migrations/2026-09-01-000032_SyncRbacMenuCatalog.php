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
            $audit = $service->audit();
            if ($audit['missing_codes'] !== [] || $audit['conflicting_codes'] !== []
                || $audit['unknown_permissions'] !== [] || $audit['unmapped_codes'] !== []
            ) {
                throw new \RuntimeException('RBAC menu catalog synchronization is incomplete');
            }
        }
    }

    public function down(): void
    {
        // Menu records can be customized and assigned after deployment.
    }
}
