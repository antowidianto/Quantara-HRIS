<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Commands\SecurityScanTenantLeaks;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class TenantLeakScannerPolicyTest extends TestCase
{
    public function testMultiLineFluentQueryKeepsItsCompanyScopeInOneWindow(): void
    {
        $window = $this->queryWindow([
            '$row = $db->table(\'employees\')',
            '    ->select(\'id, employee_number\')',
            '    ->where(\'status\', \'ACTIVE\')',
            '    ->where(\'company_id\', $companyId)',
            '    ->get()->getRowArray();',
            '$db->table(\'other\')->get();',
        ]);

        self::assertStringContainsString('company_id', $window);
        self::assertStringNotContainsString("table('other')", $window);
    }

    public function testAssignedBuilderIncludesScopeUntilExecution(): void
    {
        $window = $this->queryWindow([
            '$builder = $db->table(\'employees\');',
            '$builder->select(\'id\');',
            '$builder->where(\'company_id\', $companyId);',
            '$rows = $builder->get()->getResultArray();',
            '$db->table(\'other\')->get();',
        ]);

        self::assertStringContainsString('company_id', $window);
        self::assertStringNotContainsString("table('other')", $window);
    }

    public function testUnrelatedLaterCompanyQueryCannotMaskUnscopedStatement(): void
    {
        $window = $this->queryWindow([
            '$row = $db->table(\'employees\')->where(\'id\', $id)->get();',
            '$safe = $db->table(\'employees\')->where(\'company_id\', $companyId)->get();',
        ]);

        self::assertStringNotContainsString('company_id', $window);
    }

    public function testSchemaGlobalClassificationFeedsScannerAllowlist(): void
    {
        $method = new ReflectionMethod(SecurityScanTenantLeaks::class, 'reviewedGlobalTables');
        $tables = $method->invoke($this->scanner());

        self::assertContains('signup_attempts', $tables);
        self::assertContains('pph21_brackets', $tables);
        self::assertNotContains('employees', $tables);
    }

    public function testTenantRepositoriesAreInsideScannerBoundary(): void
    {
        $property = (new ReflectionClass(SecurityScanTenantLeaks::class))->getProperty('targetDirs');
        $directories = $property->getValue($this->scanner());

        self::assertContains('app/Repositories', $directories);
    }

    /** @param array<int, string> $lines */
    private function queryWindow(array $lines): string
    {
        $method = new ReflectionMethod(SecurityScanTenantLeaks::class, 'queryScopeWindow');
        return (string) $method->invoke($this->scanner(), $lines, 0);
    }

    private function scanner(): SecurityScanTenantLeaks
    {
        return (new ReflectionClass(SecurityScanTenantLeaks::class))->newInstanceWithoutConstructor();
    }
}
