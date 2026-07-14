<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\RouteSource;

final class LifecycleRoutePolicyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function criticalStateRoutes(): iterable
    {
        yield 'severance finalize' => ['severance/(:num)/finalize', 'severance.manage'];
        yield 'resignation approve' => ['resignations/(:num)/approve', 'resignation.manage'];
        yield 'JIC approve' => ['jic/(:num)/approve', 'jic.approve'];
        yield 'JIC implement' => ['jic/(:num)/implement', 'jic.implement'];
        yield 'transfer approve' => ['employee-transfers/(:num)/approve', 'employee_transfer.approve'];
        yield 'transfer implement' => ['employee-transfers/(:num)/implement', 'employee_transfer.implement'];
        yield 'termination approve' => ['terminations/(:num)/approve', 'termination.approve'];
        yield 'termination execute' => ['terminations/(:num)/execute', 'termination.execute'];
        yield 'offboarding paid' => ['resignations/(:num)/offboarding/final-settlement/paid', 'offboarding.clearance.manage'];
    }

    #[DataProvider('criticalStateRoutes')]
    public function testCriticalLifecycleMutationRequiresPermissionAndIdempotency(
        string $path,
        string $permission,
    ): void {
        $routes = RouteSource::all();
        $quotedPath = preg_quote("'{$path}'", '/');

        self::assertMatchesRegularExpression(
            "/{$quotedPath}.{0,260}permission:" . preg_quote($permission, '/') . ".{0,160}idempotency:required/s",
            $routes,
        );
    }

    public function testLifecycleReadAndManagePermissionsRemainSeparated(): void
    {
        $routes = RouteSource::all();
        foreach (['severance', 'disciplinary', 'resignation', 'termination'] as $domain) {
            self::assertStringContainsString("permission:{$domain}.view", $routes);
            self::assertStringContainsString("permission:{$domain}.manage", $routes);
        }
    }
}
