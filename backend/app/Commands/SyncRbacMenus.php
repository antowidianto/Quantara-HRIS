<?php

namespace App\Commands;

use App\Libraries\Identity\RbacMenuCatalogService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class SyncRbacMenus extends BaseCommand
{
    protected $group = 'Identity';
    protected $name = 'menu:sync-rbac';
    protected $description = 'Synchronize missing RBAC menu records and remove conflicting super-admin gates.';

    public function run(array $params)
    {
        try {
            $service = new RbacMenuCatalogService();
            $result = $service->sync();
            $audit = $service->audit();
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());
            return 1;
        }
        CLI::write(sprintf(
            'unblocked=%d inserted=%d updated=%d',
            $result['unblocked'],
            $result['inserted'],
            $result['updated'],
        ));
        CLI::write(sprintf(
            'missing=%d conflicting_superadmin_gates=%d unknown_permissions=%d unmapped=%d',
            count($audit['missing_codes']),
            count($audit['conflicting_codes']),
            count($audit['unknown_permissions']),
            count($audit['unmapped_codes']),
        ));
        foreach ($audit['missing_codes'] as $code) CLI::error('missing: ' . $code);
        foreach ($audit['conflicting_codes'] as $code) CLI::error('conflicting: ' . $code);
        foreach ($audit['unknown_permissions'] as $slug) CLI::error('unknown permission: ' . $slug);
        foreach ($audit['unmapped_codes'] as $code) CLI::error('unmapped menu: ' . $code);
        return $audit['missing_codes'] !== [] || $audit['conflicting_codes'] !== []
            || $audit['unknown_permissions'] !== [] || $audit['unmapped_codes'] !== [] ? 1 : 0;
    }
}
