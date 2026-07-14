<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Backup\DataExportService;
use App\Libraries\Queue\BaseJob;
use Config\Services;
use RuntimeException;

final class ExportTenantDataJob extends BaseJob
{
    public int $tries = 3;

    public function __construct(private ?DataExportService $exportService = null)
    {
    }

    public function handle(): void
    {
        $requestId = (int) ($this->data['request_id'] ?? 0);
        $companyId = (int) ($this->data['company_id'] ?? 0);
        if ($requestId <= 0 || $companyId <= 0) {
            throw new RuntimeException('Export job requires request_id and company_id.');
        }

        $tenant = Services::tenant();
        $previousCompanyId = $tenant->getCompanyId();
        $previousRoleId = $tenant->getRoleId();

        try {
            // Queue workers are long-lived. Resolve scope for each job and
            // never inherit the tenant from the previously processed job.
            $tenant->setRoleId(0);
            $tenant->setCompanyId($companyId);
            ($this->exportService ?? new DataExportService())->execute($requestId, $companyId);
        } finally {
            $tenant->setCompanyId($previousCompanyId);
            $tenant->setRoleId($previousRoleId);
        }
    }
}

