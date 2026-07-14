<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Employee\EmployeeDataQualityService;
use App\Libraries\System\Encryption;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class EmployeeDataQualityServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private Encryption $encryption;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'foreignKeys' => false, 'DBDebug' => true], false);
        $this->encryption = new Encryption();
        $this->schema();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        parent::tearDown();
    }

    public function testDashboardCoversAllCategoriesWithoutCrossTenantOrPlaintextLeak(): void
    {
        $result = (new EmployeeDataQualityService($this->connection, $this->encryption))->dashboard(1, 20);

        self::assertSame(1, $result['company_id']);
        self::assertSame(2, $result['total_active_employees']);
        self::assertSame([
            'identity_tax', 'bank_account', 'bpjs', 'expired_records', 'organization_completeness', 'minimum_wage', 'orphaned_references',
        ], array_keys($result['categories']));
        self::assertSame(1, $result['categories']['identity_tax']['count']);
        self::assertSame(2, $result['categories']['bank_account']['count']);
        self::assertSame(1, $result['categories']['bpjs']['count']);
        self::assertSame(3, $result['categories']['expired_records']['count']);
        self::assertSame(1, $result['categories']['organization_completeness']['count']);
        self::assertSame(1, $result['categories']['minimum_wage']['count']);
        self::assertSame(1, $result['categories']['orphaned_references']['count']);

        $json = json_encode($result);
        self::assertIsString($json);
        self::assertStringNotContainsString('3173010101900001', $json);
        self::assertStringNotContainsString('123456789012345', $json);
        self::assertStringNotContainsString('9988776655', $json);
        self::assertStringNotContainsString('Tenant B Employee', $json);
    }

    public function testCompanyContextAndSampleLimitFailClosed(): void
    {
        $service = new EmployeeDataQualityService($this->connection, $this->encryption);
        $this->expectException(\InvalidArgumentException::class);
        $service->dashboard(0);
    }

    private function schema(): void
    {
        foreach ([
            'CREATE TABLE users (id INTEGER PRIMARY KEY, company_id INTEGER)',
            'CREATE TABLE branches (id INTEGER PRIMARY KEY, company_id INTEGER)',
            'CREATE TABLE departments (id INTEGER PRIMARY KEY, company_id INTEGER)',
            'CREATE TABLE positions (id INTEGER PRIMARY KEY, company_id INTEGER)',
            'CREATE TABLE cost_centers (id INTEGER PRIMARY KEY, company_id INTEGER)',
            'CREATE TABLE work_locations (id INTEGER PRIMARY KEY, company_id INTEGER)',
            'CREATE TABLE employees (id INTEGER PRIMARY KEY, company_id INTEGER, employee_number TEXT, full_name TEXT, user_id INTEGER, manager_id INTEGER, branch_id INTEGER, department_id INTEGER, position_id INTEGER, cost_center_id INTEGER, work_location_id INTEGER, nik_encrypted TEXT, npwp_encrypted TEXT, bank_name TEXT, bank_account_encrypted TEXT, bank_account_holder TEXT, bpjs_kesehatan_no TEXT, bpjs_tk_no TEXT, basic_salary REAL, province TEXT, city TEXT, contract_end_date TEXT, is_active INTEGER, deleted_at TEXT)',
            'CREATE TABLE employee_contracts (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, contract_number TEXT, end_date TEXT)',
            'CREATE TABLE employee_documents (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, title TEXT, expires_at TEXT, deleted_at TEXT)',
            'CREATE TABLE certificates (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, title TEXT, expires_date TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE regional_minimum_wages (id INTEGER PRIMARY KEY, province TEXT, city TEXT, level TEXT, amount REAL, effective_year INTEGER)',
        ] as $sql) $this->connection->query($sql);
    }

    private function seed(): void
    {
        foreach (['users', 'branches', 'departments', 'positions', 'cost_centers', 'work_locations'] as $table) {
            $this->connection->table($table)->insert(['id' => 1, 'company_id' => 1]);
        }
        $common = [
            'company_id' => 1, 'manager_id' => 1, 'branch_id' => 1, 'department_id' => 1, 'position_id' => 1, 'cost_center_id' => 1, 'work_location_id' => 1,
            'bank_name' => 'BCA', 'bank_account_encrypted' => $this->encryption->encrypt('9988776655'), 'bank_account_holder' => 'Employee',
            'province' => 'DKI Jakarta', 'city' => 'Jakarta', 'is_active' => 1, 'deleted_at' => null,
        ];
        $this->connection->table('employees')->insert(array_merge($common, [
            'id' => 1, 'employee_number' => 'A-001', 'full_name' => 'Complete Employee', 'user_id' => 1,
            'nik_encrypted' => $this->encryption->encrypt('3173010101900001'), 'npwp_encrypted' => $this->encryption->encrypt('123456789012345'),
            'bpjs_kesehatan_no' => '1234567890123', 'bpjs_tk_no' => '12345678901', 'basic_salary' => 4_000_000, 'contract_end_date' => date('Y-m-d', strtotime('-1 day')),
        ]));
        $this->connection->table('employees')->insert(array_merge($common, [
            'id' => 2, 'employee_number' => 'A-002', 'full_name' => 'Incomplete Employee', 'user_id' => 999,
            'manager_id' => null, 'position_id' => null, 'cost_center_id' => null, 'work_location_id' => null,
            'nik_encrypted' => $this->encryption->encrypt('invalid'), 'npwp_encrypted' => null,
            'bpjs_kesehatan_no' => null, 'bpjs_tk_no' => 'bad', 'basic_salary' => 8_000_000,
        ]));
        $this->connection->table('employees')->insert(array_merge($common, [
            'id' => 3, 'company_id' => 2, 'employee_number' => 'B-001', 'full_name' => 'Tenant B Employee', 'user_id' => null,
            'nik_encrypted' => null, 'npwp_encrypted' => null, 'bpjs_kesehatan_no' => null, 'bpjs_tk_no' => null, 'basic_salary' => 1,
        ]));
        $past = date('Y-m-d', strtotime('-1 day'));
        $this->connection->table('employee_contracts')->insert(['id' => 1, 'company_id' => 1, 'employee_id' => 1, 'contract_number' => 'C-1', 'end_date' => $past]);
        $this->connection->table('employee_documents')->insert(['id' => 1, 'company_id' => 1, 'employee_id' => 1, 'title' => 'License', 'expires_at' => $past, 'deleted_at' => null]);
        $this->connection->table('certificates')->insert(['id' => 1, 'company_id' => 1, 'employee_id' => 1, 'title' => 'Safety', 'expires_date' => $past, 'status' => 'ACTIVE', 'deleted_at' => null]);
        $this->connection->table('regional_minimum_wages')->insert(['id' => 1, 'province' => 'DKI Jakarta', 'city' => null, 'level' => 'UMP', 'amount' => 5_000_000, 'effective_year' => (int) date('Y')]);
    }
}
