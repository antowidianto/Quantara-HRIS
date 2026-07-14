<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class EmployeeRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // Employees
        // Custom routes HARUS sebelum resource agar tidak tabrakan dengan resource show()
        $routes->get('employees/(:num)/contracts', 'Employee\EmployeeController::contracts/$1', ['filter' => 'permission:employee.view']);
        $routes->post('employees/(:num)/contracts', 'Employee\EmployeeController::addContract/$1', ['filter' => 'permission:employee.manage']);
        $routes->get('employees/(:num)/documents', 'Employee\EmployeeController::documents/$1', ['filter' => 'permission:employee.view']);
        $routes->post('employees/(:num)/documents', 'Employee\EmployeeController::uploadDocument/$1', ['filter' => 'permission:employee.manage']);
        $routes->get('employees/(:num)/effective-history', 'Employee\EmployeeEffectiveHistoryController::index/$1', ['filter' => 'permission:employee.view']);
        $routes->get('employees/(:num)/as-of', 'Employee\EmployeeEffectiveHistoryController::showAsOf/$1', ['filter' => 'permission:employee.view']);
        $routes->post('employees/(:num)/effective-history', 'Employee\EmployeeEffectiveHistoryController::create/$1', ['filter' => ['permission:employee.manage', 'idempotency:required']]);
        // Pakai placeholder `(:num)` supaya `employees/(:any)` versi default
        // dari resource() (yang regex-nya `(.*)` — greedy, match juga slash)
        // tidak menelan sub-route seperti `/employees/1/bonus-history`,
        // `/employees/1/work-locations`, `/employees/1/360`, dll. yang
        // didaftarkan SETELAH resource. Tanpa ini, GET ke sub-route
        // tersebut salah ke `Employee\EmployeeController::show("1/bonus-history")`
        // dan mengembalikan data karyawan, bukan data sub-resource.
        $routes->get('employees/my-team', 'Employee\EmployeeController::myTeam', ['filter' => 'permission:employee.team_view,employee.view']);
        $routes->resource('employees', [
            'controller' => 'Employee\EmployeeController',
            'placeholder' => '(:num)',
            'only' => ['index', 'show'],
            'filter' => 'permission:employee.view',
        ]);
        $routes->resource('employees', [
            'controller' => 'Employee\EmployeeController',
            'placeholder' => '(:num)',
            'only' => ['create', 'update', 'delete'],
            'filter' => 'permission:employee.manage',
        ]);
    }
}
