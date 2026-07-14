<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class MasterRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // Master: Companies / Business Units
        $routes->resource('companies', ['controller' => 'Master\CompanyController', 'only' => ['index', 'show'], 'filter' => 'permission:company.view']);
        $routes->resource('companies', ['controller' => 'Master\CompanyController', 'only' => ['create', 'update', 'delete'], 'filter' => 'permission:company.manage']);
        $routes->resource('branches', ['controller' => 'Master\BranchController', 'only' => ['index', 'show'], 'filter' => 'permission:branch.view']);
        $routes->resource('branches', ['controller' => 'Master\BranchController', 'only' => ['create', 'update', 'delete'], 'filter' => 'permission:branch.manage']);
        $routes->resource('departments', ['controller' => 'Master\DepartmentController', 'only' => ['index', 'show'], 'filter' => 'permission:department.view']);
        $routes->resource('departments', ['controller' => 'Master\DepartmentController', 'only' => ['create', 'update', 'delete'], 'filter' => 'permission:department.manage']);
        $routes->resource('positions', ['controller' => 'Master\PositionController', 'only' => ['index', 'show'], 'filter' => 'permission:position.view']);
        $routes->resource('positions', ['controller' => 'Master\PositionController', 'only' => ['create', 'update', 'delete'], 'filter' => 'permission:position.manage']);
        $routes->resource('job-grades', ['controller' => 'Master\JobGradeController', 'filter' => 'permission:hr.manage']);

        // Master Job Titles â€” standar nama jabatan untuk reporting & WLKP.
        // Migration #064 menambahkan tabel ini + auto-backfill dari positions.
        // Endpoint khusus didefinisikan SEBELUM resource agar /picker dan
        // /(:num)/usage tidak nyangkut sebagai resource id.
        $routes->get('job-titles/picker',          'Master\JobTitleController::picker', ['filter' => 'permission:job_title.view']);
        $routes->get('job-titles/(:num)/usage',    'Master\JobTitleController::usage/$1', ['filter' => 'permission:job_title.view']);
        $routes->resource('job-titles', ['controller' => 'Master\JobTitleController', 'only' => ['index', 'show'], 'filter' => 'permission:job_title.view']);
        $routes->resource('job-titles', ['controller' => 'Master\JobTitleController', 'only' => ['create', 'update', 'delete'], 'filter' => 'permission:job_title.manage']);

        // â”€â”€â”€ Cost Centers (Sprint 6) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Endpoint khusus didefinisikan SEBELUM /(:num) supaya path
        // /tree, /picker, /backfill-employees tidak nyangkut sebagai id.
        $routes->get('cost-centers/tree',                  'Master\CostCenterController::tree', ['filter' => 'permission:cost_center.view']);
        $routes->get('cost-centers/picker',                'Master\CostCenterController::picker', ['filter' => 'permission:cost_center.view']);
        $routes->post('cost-centers/backfill-employees',   'Master\CostCenterController::backfillEmployees', ['filter' => 'permission:cost_center.manage']);
        $routes->get('cost-centers',                       'Master\CostCenterController::index', ['filter' => 'permission:cost_center.view']);
        $routes->post('cost-centers',                      'Master\CostCenterController::create', ['filter' => 'permission:cost_center.manage']);
        $routes->get('cost-centers/(:num)',                'Master\CostCenterController::show/$1', ['filter' => 'permission:cost_center.view']);
        $routes->put('cost-centers/(:num)',                'Master\CostCenterController::update/$1', ['filter' => 'permission:cost_center.manage']);
        $routes->delete('cost-centers/(:num)',             'Master\CostCenterController::delete/$1', ['filter' => 'permission:cost_center.manage']);
        $routes->get('cost-centers/(:num)/budgets',        'Master\CostCenterController::listBudgets/$1', ['filter' => 'permission:cost_center.view']);
        $routes->post('cost-centers/(:num)/budgets',       'Master\CostCenterController::setBudgets/$1', ['filter' => 'permission:cost_center.manage']);
        $routes->delete('cost-center-budgets/(:num)',      'Master\CostCenterController::deleteBudget/$1', ['filter' => 'permission:cost_center.manage']);
        $routes->get('cost-centers/(:num)/budget-vs-actual','Master\CostCenterController::budgetVsActual/$1', ['filter' => 'permission:cost_center.view']);
        $routes->get('cost-centers/(:num)/actuals',        'Master\CostCenterController::actuals/$1', ['filter' => 'permission:cost_center.view']);
        $routes->get('cost-centers/(:num)/committed',      'Master\CostCenterController::committed/$1', ['filter' => 'permission:cost_center.view']);
    }
}

