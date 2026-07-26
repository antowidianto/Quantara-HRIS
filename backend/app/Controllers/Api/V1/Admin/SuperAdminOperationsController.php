<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Admin\SuperAdminOperationsService;

final class SuperAdminOperationsController extends ApiController
{
    public function overview()
    {
        if (! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Forbidden', 403, 'SUPER_ADMIN_ONLY');
        }

        return $this->ok((new SuperAdminOperationsService())->overview());
    }
}
