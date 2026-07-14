<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Filters\IdempotencyFilter;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use PHPUnit\Framework\TestCase;
use Tests\Support\RouteSource;

final class IdempotencyPolicyTest extends TestCase
{
    public function testCriticalMutationPolicyIsConfiguredAsRequired(): void
    {
        $routes = RouteSource::all();

        foreach ([
            'attendance/clock-in',
            'attendance/clock-out',
            'leaves',
            'overtimes',
            'reimbursements',
            'loans',
            'business-trips',
            'approval-requests/bulk-approve',
            'approval-requests/(:num)/reject',
            'payroll-periods/(:num)/calculate',
            'payroll-periods/(:num)/finalize',
            'payroll-periods/(:num)/disburse',
            'bonus-runs/(:num)/mark-paid',
            'offers/(:num)/respond',
        ] as $path) {
            self::assertMatchesRegularExpression(
                "/" . preg_quote("'{$path}'", '/') . ".{0,220}idempotency:required/s",
                $routes,
                "{$path} must require an idempotency key"
            );
        }
    }

    public function testRequiredFilterIsRouteScopedSoAuthenticationRunsFirst(): void
    {
        $filters = file_get_contents(APPPATH . 'Config/Filters.php');
        self::assertNotFalse($filters);
        self::assertStringNotContainsString("'idempotency:required' =>", $filters);
    }

    public function testEveryRequiredRouteIsAStateChangingRoute(): void
    {
        $routes = RouteSource::all();
        preg_match_all('/\$routes->(get|post|put|patch|delete)\([^\n]+idempotency:required/s', $routes, $matches);

        self::assertNotEmpty($matches[0]);
        self::assertSame([], array_values(array_filter(
            $matches[1],
            static fn (string $method): bool => $method === 'get'
        )));
    }

    public function testWebAndMobileClientsKeyEveryStateChangingRequest(): void
    {
        $projectRoot = dirname(ROOTPATH);
        $webPolicy = file_get_contents($projectRoot . '/frontend/src/api/idempotency.js');
        $mobilePolicy = file_get_contents($projectRoot . '/mobile/src/api/idempotencyPolicy.ts');

        self::assertNotFalse($webPolicy);
        self::assertNotFalse($mobilePolicy);
        self::assertStringContainsString("['POST', 'PUT', 'PATCH', 'DELETE']", $webPolicy);
        self::assertStringContainsString("['POST', 'PUT', 'PATCH', 'DELETE']", $mobilePolicy);
        self::assertStringContainsString('return true', $webPolicy);
    }

    public function testRequiredModeReturnsStableMissingKeyError(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getHeaderLine')->with('Idempotency-Key')->willReturn('');

        $response = (new IdempotencyFilter())->before($request, ['required']);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('IDEMPOTENCY_KEY_REQUIRED', (string) $response->getBody());
    }
}
