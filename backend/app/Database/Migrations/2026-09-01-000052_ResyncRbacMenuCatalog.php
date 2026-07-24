<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Libraries\Identity\RbacMenuCatalogService;
use CodeIgniter\Database\Migration;

/** Reconcile and validate the RBAC menu after all permission migrations. */
final class ResyncRbacMenuCatalog extends Migration
{
    public function up(): void
    {
        if (!$this->db->tableExists('menus')) {
            return;
        }

        $service = new RbacMenuCatalogService($this->db);
        $service->sync();
        $audit = $service->audit();
        // A fresh migration database is intentionally unseeded, so the
        // permission registry can be incomplete here. The CI permission audit
        // validates code/menu slugs against all seed definitions.
        if ($audit['missing_codes'] !== [] || $audit['conflicting_codes'] !== []
            || $audit['unmapped_codes'] !== []
        ) {
            throw new \RuntimeException(
                'RBAC menu catalog synchronization is incomplete: ' . json_encode($audit)
            );
        }
    }

    public function down(): void
    {
        // Catalog rows may be customized after deployment; roll forward only.
    }
}
