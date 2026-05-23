<?php

namespace Tests\Unit;

use App\Libraries\CacheService;
use PHPUnit\Framework\TestCase;

/**
 * Unit test untuk CacheService dengan in-memory mock cache backend.
 * Mock pakai anonymous class duck-typed (tidak implement CacheInterface)
 * supaya bebas signature constraint dari CodeIgniter framework.
 */
class CacheServiceTest extends TestCase
{
    private CacheService $service;
    /** @var array<string, array{0:mixed,1:int}> */
    private array $store = [];

    protected function setUp(): void
    {
        $this->store = [];
        $store = &$this->store;

        // Anonymous class implementing minimal duck-typed cache backend
        $mockCache = new class($store) {
            public function __construct(private array &$store) {}

            public function get(string $key)
            {
                if (! isset($this->store[$key])) return null;
                [$val, $expiresAt] = $this->store[$key];
                if ($expiresAt !== 0 && $expiresAt < time()) {
                    unset($this->store[$key]);
                    return null;
                }
                return $val;
            }

            public function save(string $key, $value, int $ttl = 60): bool
            {
                $this->store[$key] = [$value, $ttl > 0 ? time() + $ttl : 0];
                return true;
            }

            public function delete(string $key): bool
            {
                $existed = isset($this->store[$key]);
                unset($this->store[$key]);
                return $existed;
            }

            public function deleteMatching(string $pattern): int
            {
                $count = 0;
                $regex = '/^' . str_replace(
                    ['\\*', '\\?'],
                    ['.*', '.'],
                    preg_quote($pattern, '/')
                ) . '$/';
                foreach (array_keys($this->store) as $k) {
                    if (preg_match($regex, $k)) {
                        unset($this->store[$k]);
                        $count++;
                    }
                }
                return $count;
            }
        };

        $this->service = new CacheService($mockCache);
    }

    public function testRememberStoresAndReturnsValue(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;
            return ['name' => 'foo', 'value' => 42];
        };

        // First call — should execute callback
        $result1 = $this->service->remember('test:key', 60, $callback);
        $this->assertSame(['name' => 'foo', 'value' => 42], $result1);
        $this->assertSame(1, $callCount);

        // Second call — should use cache (callback NOT executed)
        $result2 = $this->service->remember('test:key', 60, $callback);
        $this->assertSame(['name' => 'foo', 'value' => 42], $result2);
        $this->assertSame(1, $callCount, 'Callback should be called only once');
    }

    public function testRememberCachesNullViaSentinel(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;
            return null;
        };

        $r1 = $this->service->remember('test:null', 60, $callback);
        $r2 = $this->service->remember('test:null', 60, $callback);

        $this->assertNull($r1);
        $this->assertNull($r2);
        $this->assertSame(1, $callCount, 'NULL should be cached via sentinel');
    }

    public function testRememberSkipFalsyDoesNotCacheEmpty(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;
            return null;
        };

        $this->service->remember('test:skip', 60, $callback, true);
        $this->service->remember('test:skip', 60, $callback, true);

        $this->assertSame(2, $callCount, 'With skipFalsy, NULL should re-execute callback');
    }

    public function testGetSetForget(): void
    {
        $this->assertNull($this->service->get('foo'));

        $this->assertTrue($this->service->set('foo', 'bar', 60));
        $this->assertSame('bar', $this->service->get('foo'));

        $this->assertTrue($this->service->forget('foo'));
        $this->assertNull($this->service->get('foo'));
    }

    public function testSetThenGetNullValue(): void
    {
        $this->service->set('nullable', null, 60);
        $this->assertNull($this->service->get('nullable'));
        // Tapi di store harus ada entry (sentinel '__null__')
        $this->assertNotEmpty($this->store);
    }

    public function testKeyBuildersAreConsistent(): void
    {
        $this->assertSame('ump:31:2026', $this->service->keyUmp(31, 2026));
        $this->assertSame('umk:1101:2026', $this->service->keyUmk(1101, 2026));
        $this->assertSame('holidays:5:2026', $this->service->keyHolidays(5, 2026));
        $this->assertSame('perms:7', $this->service->keyRolePermissions(7));
        $this->assertSame('ter:A:2026', $this->service->keyTerRates('A', 2026));
        $this->assertSame('bpjs:5', $this->service->keyBpjsRates(5));
        $this->assertSame('payset:5', $this->service->keyPayrollSettings(5));
    }

    public function testPrefixIsApplied(): void
    {
        $this->service->set('foo', 'bar', 60);
        // Key di backend storage harus prefixed
        $this->assertArrayHasKey('hris:foo', $this->store);
    }

    public function testForgetPrefixDeletesByWildcard(): void
    {
        $this->service->set('ump:31:2026', 5500000);
        $this->service->set('ump:31:2027', 5800000);
        $this->service->set('ump:32:2026', 4900000);
        $this->service->set('holidays:5:2026', []);

        $deleted = $this->service->forgetPrefix('ump:');
        $this->assertSame(3, $deleted);

        $this->assertNull($this->service->get('ump:31:2026'));
        $this->assertNull($this->service->get('ump:31:2027'));
        $this->assertNull($this->service->get('ump:32:2026'));
        // holidays key tidak ke-hapus
        $this->assertNotNull($this->service->get('holidays:5:2026'));
    }

    public function testTtlConstantsAreCorrect(): void
    {
        $this->assertSame(60, CacheService::TTL_MINUTE);
        $this->assertSame(300, CacheService::TTL_5_MIN);
        $this->assertSame(900, CacheService::TTL_15_MIN);
        $this->assertSame(3600, CacheService::TTL_HOUR);
        $this->assertSame(86400, CacheService::TTL_DAY);
        $this->assertSame(604800, CacheService::TTL_WEEK);
        $this->assertSame(CacheService::TTL_5_MIN, CacheService::TTL_DEFAULT);
    }
}