<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Tests\Support\RouteSource;

/** Prevent high-impact mutation routes from silently falling back to JWT-only access. */
final class SensitiveRoutePolicyTest extends TestCase
{
    private string $routes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->routes = RouteSource::all();
    }

    /** @dataProvider sensitivePayrollRoutes */
    public function testSensitivePayrollRouteDeclaresRequiredPermission(
        string $path,
        string $controller,
        string $permission,
    ): void {
        $routePattern = sprintf(
            '/\$routes->post\(\s*[\'\"]%s[\'\"]\s*,\s*[\'\"]%s[\'\"]\s*,(?<options>.{0,300}?)\);/s',
            preg_quote($path, '/'),
            preg_quote($controller, '/'),
        );

        self::assertSame(1, preg_match($routePattern, $this->routes, $matches));

        self::assertStringContainsString(
            "permission:{$permission}",
            $matches['options'],
            "Sensitive route {$path} must require permission {$permission}.",
        );
    }

    public static function sensitivePayrollRoutes(): array
    {
        return [
            'calculate payroll' => [
                'payroll-periods/(:num)/calculate',
                'Payroll\\PayrollPeriodController::calculate/$1',
                'payroll.calculate',
            ],
            'finalize payroll' => [
                'payroll-periods/(:num)/finalize',
                'Payroll\\PayrollPeriodController::finalize/$1',
                'payroll.finalize',
            ],
            'disburse payroll' => [
                'payroll-periods/(:num)/disburse',
                'Payroll\\DisbursementController::disburse/$1',
                'payroll.finalize',
            ],
        ];
    }

    public function testApprovalConfigurationRoutesDeclareExplicitPolicies(): void
    {
        $expected = [
            "'approval-chains',            'Approval\\ApprovalChainController::index', ['filter' => 'permission:approval_chain.view']",
            "'approval-chains/(:num)',     'Approval\\ApprovalChainController::show/$1', ['filter' => 'permission:approval_chain.view']",
            "'approval-chains',           'Approval\\ApprovalChainController::create', ['filter' => 'permission:approval_chain.manage']",
            "'approval-assignments',      'Approval\\ApprovalChainController::createAssignment', ['filter' => 'permission:approval_chain.manage']",
            "'approval-assignments/(:num)', 'Approval\\ApprovalChainController::deleteAssignment/$1', ['filter' => 'permission:approval_chain.manage']",
        ];

        foreach ($expected as $declaration) {
            self::assertStringContainsString($declaration, $this->routes);
        }
    }

    public function testApiKeyRoutesDeclareExplicitReadAndManagePolicies(): void
    {
        $expected = [
            "'api-scopes',                            'Api\\ApiKeyController::listScopes', ['filter' => 'permission:api_key.view']",
            "'api-keys',                              'Api\\ApiKeyController::listKeys', ['filter' => 'permission:api_key.view']",
            "'api-keys',                             'Api\\ApiKeyController::createKey', ['filter' => 'permission:api_key.manage']",
            "'api-keys/(:num)/rotate',               'Api\\ApiKeyController::rotateKey/$1', ['filter' => 'permission:api_key.manage']",
            "'api-keys/(:num)/revoke',               'Api\\ApiKeyController::revokeKey/$1', ['filter' => 'permission:api_key.manage']",
            "'api-keys/(:num)/usage',                 'Api\\ApiKeyController::usage/$1', ['filter' => 'permission:api_key.view']",
        ];

        foreach ($expected as $declaration) {
            self::assertStringContainsString($declaration, $this->routes);
        }
    }
}
