<?php

namespace App\Controllers\Api\V1\System;

use App\Controllers\Api\V1\ApiController;
class HealthController extends ApiController
{
    public function index()
    {
        $dbOk = true;
        try {
            \Config\Database::connect()->query('SELECT 1');
        } catch (\Throwable $e) {
            $dbOk = false;
        }
        return $this->ok([
            'service'   => 'hris-api',
            'time'      => date('c'),
            'database'  => $dbOk ? 'up' : 'down',
            'version'   => '1.0.0',
        ]);
    }
}