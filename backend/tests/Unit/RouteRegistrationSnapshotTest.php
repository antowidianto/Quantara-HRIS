<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/** Protects the effective registry before Routes.php is split by domain. */
final class RouteRegistrationSnapshotTest extends CIUnitTestCase
{
    public function testEffectiveRouteRegistryCountAndWildcardOrderRemainStable(): void
    {
        $routes = service('routes');
        $verbs = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD', 'CLI', '*'];
        $count = 0;
        foreach ($verbs as $verb) {
            $count += count($routes->getRoutes($verb, false));
        }
        // 968 = 967 + POST api/v1/manpower-requests/(:num)/revise
        // (MPP REJECTED/CANCELLED dikembalikan ke DRAFT untuk diajukan ulang).
        self::assertSame(968, $count, 'Intentional route changes must update the audited snapshot count.');

        $getPaths = array_keys($routes->getRoutes('GET', false));
        $myTeam = array_search('api/v1/employees/my-team', $getPaths, true);
        $employeeWildcard = array_search('api/v1/employees/([0-9]+)', $getPaths, true);
        self::assertIsInt($myTeam);
        self::assertIsInt($employeeWildcard);
        self::assertLessThan($employeeWildcard, $myTeam, 'Specific employee routes must precede the wildcard route.');
    }

    public function testPrivilegedMutationsAndPiiExportsAreApprovalGated(): void
    {
        $routes = service('routes');
        self::assertSame(['jwt', 'permission:user.manage', 'idempotency:required', 'privilegedapproval:RBAC'], $routes->getRoutesOptions('api/v1/users/([0-9]+)', 'PUT')['filter'] ?? []);
        self::assertSame(['jwt', 'permission:webhook.manage', 'idempotency:required', 'privilegedapproval:INTEGRATION'], $routes->getRoutesOptions('api/v1/webhooks/([0-9]+)/rotate-secret', 'POST')['filter'] ?? []);
        self::assertSame(['jwt', 'permission:payroll.view', 'privilegedapproval:PII_EXPORT', 'throttle:export'], $routes->getRoutesOptions('api/v1/payroll-periods/([0-9]+)/bank-export', 'GET')['filter'] ?? []);
    }

    public function testPayrollMutationPoliciesSurviveRegistrationRefactors(): void
    {
        $routes = service('routes');
        foreach ([
            'api/v1/payroll-periods/([0-9]+)/calculate' => ['jwt', 'permission:payroll.calculate', 'idempotency:required'],
            'api/v1/payroll-periods/([0-9]+)/finalize' => ['jwt', 'permission:payroll.finalize', 'idempotency:required'],
            'api/v1/payroll-periods/([0-9]+)/disburse' => ['jwt', 'permission:payroll.finalize', 'idempotency:required'],
        ] as $path => $expectedFilters) {
            $options = $routes->getRoutesOptions($path, 'POST');
            $filters = $options['filter'] ?? [];
            self::assertSame($expectedFilters, $filters, $path);
        }
    }

    public function testEmployeeDataQualityDashboardRetainsItsReadPolicy(): void
    {
        $options = service('routes')->getRoutesOptions('api/v1/employees/data-quality', 'GET');
        self::assertSame(['jwt', 'permission:hr_analytics.view,compliance.report.view'], $options['filter'] ?? []);
    }

    public function testEmployeeEffectiveHistoryRoutesRetainReadAndWritePolicies(): void
    {
        $routes = service('routes');
        self::assertSame(['jwt', 'permission:employee.view'], $routes->getRoutesOptions('api/v1/employees/([0-9]+)/effective-history', 'GET')['filter'] ?? []);
        self::assertSame(['jwt', 'permission:employee.view'], $routes->getRoutesOptions('api/v1/employees/([0-9]+)/as-of', 'GET')['filter'] ?? []);
        self::assertSame(['jwt', 'permission:employee.manage', 'idempotency:required'], $routes->getRoutesOptions('api/v1/employees/([0-9]+)/effective-history', 'POST')['filter'] ?? []);
        self::assertSame(['jwt', 'permission:employee.manage', 'idempotency:required'], $routes->getRoutesOptions('api/v1/employees/([0-9]+)/sensitive-change', 'POST')['filter'] ?? []);
    }

    public function testCompensationReviewMutationsRetainPermissionAndIdempotencyPolicies(): void
    {
        $routes = service('routes');
        foreach ([
            'api/v1/compensation-reviews' => 'Compensation review cycle creation',
            'api/v1/compensation-reviews/([0-9]+)/open' => 'Cycle opening',
            'api/v1/compensation-reviews/([0-9]+)/proposals' => 'Manager proposal',
        ] as $path => $label) {
            self::assertSame(['jwt', 'permission:employee.manage', 'idempotency:required'], $routes->getRoutesOptions($path, 'POST')['filter'] ?? [], $label);
        }
    }
}
