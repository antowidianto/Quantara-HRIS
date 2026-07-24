<?php

namespace App\Repositories\Identity;

use Config\Database;

class RoleRepository
{
    public function listForCompany(int $companyId): array
    {
        // A tenant-local role shadows the global template with the same slug.
        // Showing both lets an admin accidentally edit the shared template.
        $companyId = max(0, $companyId);
        return Database::connect()->table('roles r')
            ->where(
                '(r.company_id = ' . $companyId
                . ' OR (r.company_id IS NULL AND NOT EXISTS ('
                . 'SELECT 1 FROM roles local_role'
                . ' WHERE local_role.company_id = ' . $companyId
                . ' AND local_role.slug = r.slug)))',
                null,
                false,
            )
            ->orderBy('name')
            ->get()->getResultArray();
    }
}
