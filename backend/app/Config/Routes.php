<?php

namespace Config;

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes = service('routes');

// CORS preflight catch-all for every API endpoint.
$routes->options('api/(:any)', static function () {
    return service('response')->setStatusCode(204);
});

$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function ($routes) {
    \Config\Routes\PublicRoutes::register($routes);

    $routes->group('', ['filter' => 'jwt'], static function ($routes) {
        \Config\Routes\AuthRoutes::register($routes);
        \Config\Routes\AdminRoutes::register($routes);
        \Config\Routes\MasterRoutes::register($routes);
        \Config\Routes\EmployeeRoutes::register($routes);
        \Config\Routes\TimeRoutes::register($routes);
        \Config\Routes\PayrollRoutes::register($routes);
        \Config\Routes\TalentRoutes::register($routes);
        \Config\Routes\CoreRoutes::register($routes);
        \Config\Routes\RecruitmentRoutes::register($routes);
        \Config\Routes\DevelopmentRoutes::register($routes);
        \Config\Routes\LifecycleRoutes::register($routes);
        \Config\Routes\EvolutionRoutes::register($routes);
    });
});

// Fallback 404 must send and return a string body for preflight compatibility.
$routes->set404Override(static function () {
    $resp = service('response')
        ->setStatusCode(404)
        ->setJSON(['status' => 'error', 'message' => 'Endpoint not found']);
    $resp->send();
    exit;
});
