<?php

namespace Tests\Unit;

use App\Libraries\System\CacheService;
use App\Libraries\System\LookupCache;
use PHPUnit\Framework\TestCase;

/**
 * LookupCacheTest — Sprint D+1 / D4.
 *
 * Verifikasi:
 *   - Read-through pattern: miss → loader call → cache → hit (loader tidak
 *     dipanggil lagi)
 *   - Invalidate per-resource membuat next call kena loader lagi
 *   - Setiap resource punya key + TTL terpisah (tidak saling tabrakan)
 *
 * DB di-stub via class anonymous yang implement `table().where().get().getRow*Array()`
 * minimal yang LookupCache butuhkan. Cache pakai in-memory stub.
 */
final class LookupCacheTest extends TestCase
{
    private object $cacheBackend;
    private CacheService $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheBackend = new class () {
            public array $store = [];

            public function get(string $key)
            {
                return $this->store[$key] ?? null;
            }

            public function save(string $key, $value, int $ttl = 60): bool
            {
                $this->store[$key] = $value;
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);
                return true;
            }

            public function deleteMatching(string $pattern): bool
            {
                $prefix = rtrim($pattern, '*');
                foreach (array_keys($this->store) as $k) {
                    if (str_starts_with($k, $prefix)) {
                        unset($this->store[$k]);
                    }
                }
                return true;
            }
        };
        $this->cache = new CacheService($this->cacheBackend);
    }

    /**
     * Bangun LookupCache dengan stub DB yang mengembalikan dataset
     * deterministik dan mencatat berapa kali query dipanggil.
     *
     * Counter pakai `stdClass` (bukan array) supaya semantic by-reference
     * konsisten saat di-passing antar anonymous class — array akan kena
     * copy-on-write di property promotion sehingga increment hilang.
     *
     * @param array<string,mixed> $datasets Map "table_id" → row|rows
     * @return array{0:LookupCache, 1:\stdClass} Tuple (lookup, counter dengan field `queries`)
     */
    private function buildLookup(array $datasets): array
    {
        $counter = new \stdClass();
        $counter->queries = 0;

        $db = new class ($datasets, $counter) {
            public function __construct(private array $datasets, private \stdClass $counter) {}

            public function table(string $name)
            {
                return new class ($name, $this->datasets, $this->counter) {
                    /** @var array<string,mixed> */
                    private array $where = [];
                    private string $orderBy = '';

                    public function __construct(
                        private string $table,
                        private array $datasets,
                        private \stdClass $counter,
                    ) {}

                    public function where($k, $v = null): self
                    {
                        if (is_array($k)) {
                            $this->where = array_merge($this->where, $k);
                        } else {
                            $this->where[$k] = $v;
                        }
                        return $this;
                    }

                    public function orderBy(string $col, string $dir = 'ASC'): self
                    {
                        $this->orderBy = $col . ' ' . $dir;
                        return $this;
                    }

                    public function limit(int $n): self
                    {
                        return $this;
                    }

                    public function join(string $t, string $cond): self
                    {
                        $this->table .= '+' . $t;
                        return $this;
                    }

                    public function select(string $cols): self
                    {
                        return $this;
                    }

                    public function get(): self
                    {
                        $this->counter->queries++;
                        return $this;
                    }

                    public function getRowArray(): ?array
                    {
                        $rows = $this->datasets[$this->table] ?? null;
                        if ($rows === null) return null;
                        if (! is_array($rows)) return null;
                        // Return first row matching all where filters
                        foreach ($rows as $r) {
                            if ($this->matches($r)) {
                                return $r;
                            }
                        }
                        return null;
                    }

                    public function getResultArray(): array
                    {
                        $rows = $this->datasets[$this->table] ?? [];
                        if (! is_array($rows)) return [];
                        return array_values(array_filter(
                            $rows,
                            fn(array $r) => $this->matches($r),
                        ));
                    }

                    private function matches(array $row): bool
                    {
                        foreach ($this->where as $k => $v) {
                            // Support comparison clauses ("date >=", "year !=") with naive ==.
                            if (preg_match('/^(?:\w+\.)?(\w+)\s+(>=|<=|>|<|!=)$/', $k, $m)) {
                                $col = $m[1]; $op = $m[2];
                                if (! array_key_exists($col, $row)) return false;
                                $a = $row[$col]; $b = $v;
                                $ok = match ($op) {
                                    '>=' => $a >= $b,
                                    '<=' => $a <= $b,
                                    '>'  => $a > $b,
                                    '<'  => $a < $b,
                                    '!=' => $a != $b,
                                };
                                if (! $ok) return false;
                                continue;
                            }
                            // Strip alias prefix kalau caller pakai `rp.role_id` style.
                            $col = $k;
                            if (str_contains($col, '.')) {
                                $col = substr($col, strrpos($col, '.') + 1);
                            }
                            if (! array_key_exists($col, $row) || $row[$col] != $v) {
                                return false;
                            }
                        }
                        return true;
                    }
                };
            }
        };

        $lookup = new LookupCache($this->cache, $db);
        return [$lookup, $counter];
    }

    public function testGetUmpReadThroughCachesAfterFirstHit(): void
    {
        [$lookup, $count] = $this->buildLookup([
            'ump_rates' => [
                ['province_id' => 31, 'year' => 2026, 'amount' => 5_067_000],
            ],
        ]);

        $first = $lookup->getUmp(31, 2026);
        $second = $lookup->getUmp(31, 2026);
        $third = $lookup->getUmp(31, 2026);

        $this->assertSame(['province_id' => 31, 'year' => 2026, 'amount' => 5_067_000], $first);
        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
        $this->assertSame(1, $count->queries, 'DB query should run only once for cache hit');
    }

    public function testGetUmpReturnsNullAndCachesNullWhenMissing(): void
    {
        [$lookup, $count] = $this->buildLookup([
            'ump_rates' => [],
        ]);

        $a = $lookup->getUmp(99, 2026);
        $b = $lookup->getUmp(99, 2026);

        $this->assertNull($a);
        $this->assertNull($b);
        // CacheService::remember caches NULL via sentinel — second call should
        // still hit cache, not DB.
        $this->assertSame(1, $count->queries);
    }

    public function testForgetUmpInvalidates(): void
    {
        [$lookup, $count] = $this->buildLookup([
            'ump_rates' => [
                ['province_id' => 31, 'year' => 2026, 'amount' => 5_067_000],
            ],
        ]);

        $lookup->getUmp(31, 2026);
        $lookup->getUmp(31, 2026);
        $this->assertSame(1, $count->queries);

        $lookup->forgetUmp(31, 2026);
        $lookup->getUmp(31, 2026);
        $this->assertSame(2, $count->queries, 'Cache forget should force re-query');
    }

    public function testGetHolidaysReturnsListAndCaches(): void
    {
        [$lookup, $count] = $this->buildLookup([
            'holidays' => [
                ['company_id' => 1, 'date' => '2026-01-01', 'name' => 'Tahun Baru', 'is_national' => 1],
                ['company_id' => 1, 'date' => '2026-08-17', 'name' => 'HUT RI',     'is_national' => 1],
                ['company_id' => 2, 'date' => '2026-01-01', 'name' => 'Tahun Baru', 'is_national' => 1],
            ],
        ]);

        $companyOne = $lookup->getHolidays(1, 2026);
        $companyOneAgain = $lookup->getHolidays(1, 2026);
        $companyTwo = $lookup->getHolidays(2, 2026);

        $this->assertCount(2, $companyOne);
        $this->assertSame($companyOne, $companyOneAgain);
        $this->assertCount(1, $companyTwo);
        $this->assertSame(2, $count->queries, 'One query per company key');
    }

    public function testForgetHolidaysInvalidatesPerCompany(): void
    {
        [$lookup, $count] = $this->buildLookup([
            'holidays' => [
                ['company_id' => 1, 'date' => '2026-01-01', 'name' => 'X', 'is_national' => 1],
            ],
        ]);

        $lookup->getHolidays(1, 2026);
        $lookup->getHolidays(2, 2026);
        $this->assertSame(2, $count->queries);

        $lookup->forgetHolidays(1, 2026);
        $lookup->getHolidays(1, 2026); // re-query
        $lookup->getHolidays(2, 2026); // still cached
        $this->assertSame(3, $count->queries);
    }

    public function testGetRolePermissionsExtractsSlugList(): void
    {
        [$lookup, $count] = $this->buildLookup([
            'role_permissions rp+permissions p' => [
                ['role_id' => 5, 'slug' => 'employee.view'],
                ['role_id' => 5, 'slug' => 'employee.create'],
                ['role_id' => 5, 'slug' => 'employee.update'],
            ],
        ]);

        $perms = $lookup->getRolePermissions(5);
        $this->assertSame(['employee.view', 'employee.create', 'employee.update'], $perms);

        // Second call cache hit
        $lookup->getRolePermissions(5);
        $this->assertSame(1, $count->queries);
    }

    public function testDifferentResourcesHaveSeparateCacheKeys(): void
    {
        [$lookup, $count] = $this->buildLookup([
            'ump_rates' => [['province_id' => 31, 'year' => 2026, 'amount' => 5_000_000]],
            'holidays'  => [['company_id' => 1, 'date' => '2026-01-01', 'name' => 'X', 'is_national' => 1]],
            'bpjs_rates'=> [['company_id' => 1, 'is_active' => 1, 'effective_from' => '2026-01-01']],
        ]);

        $lookup->getUmp(31, 2026);
        $lookup->getHolidays(1, 2026);
        $lookup->getBpjsRates(1);
        $this->assertSame(3, $count->queries);

        // Repeat — semua hit cache
        $lookup->getUmp(31, 2026);
        $lookup->getHolidays(1, 2026);
        $lookup->getBpjsRates(1);
        $this->assertSame(3, $count->queries);
    }

    public function testForgetAllRolePermissionsClearsAllRoles(): void
    {
        [$lookup, $count] = $this->buildLookup([
            'role_permissions rp+permissions p' => [
                ['role_id' => 1, 'slug' => 'admin.full'],
                ['role_id' => 2, 'slug' => 'manager.read'],
            ],
        ]);

        $lookup->getRolePermissions(1);
        $lookup->getRolePermissions(2);
        $this->assertSame(2, $count->queries);

        $lookup->forgetAllRolePermissions();

        $lookup->getRolePermissions(1);
        $lookup->getRolePermissions(2);
        // Setelah forget all, kedua role harus re-query
        $this->assertSame(4, $count->queries);
    }

    public function testCacheKeyPrefixIsolatesFromOtherCacheTraffic(): void
    {
        // Pre-populate cache backend dengan key non-hris untuk pastikan
        // LookupCache hanya read/write yang ber-prefix `hris:`.
        $this->cacheBackend->store['unrelated:key'] = 'untouched';

        [$lookup, $count] = $this->buildLookup([
            'ump_rates' => [['province_id' => 31, 'year' => 2026, 'amount' => 5_067_000]],
        ]);

        $lookup->getUmp(31, 2026);
        $this->assertSame('untouched', $this->cacheBackend->store['unrelated:key']);
        $this->assertArrayHasKey('hris:ump:31:2026', $this->cacheBackend->store);
    }
}