<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Jobs\ExportTenantDataJob;
use App\Libraries\Backup\DataExportService;
use App\Models\BaseModel;
use App\Models\Employee\EmployeeModel;
use App\Models\Payroll\PayslipModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

final class TenantIsolationIntegrationTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect([
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => '',
            'foreignKeys' => true,
            'DBDebug' => true,
        ], false);
        $this->createSchema();
        $this->seedTwoTenants();
        $this->activateTenant(1);
    }

    protected function tearDown(): void
    {
        $tenant = Services::tenant();
        $tenant->setCompanyId(0);
        $tenant->setRoleId(0);
        $this->connection->close();
        parent::tearDown();
    }

    public function testSameNikCanExistInTwoTenantsAndLookupDoesNotLeak(): void
    {
        $model = new EmployeeModel($this->connection);

        self::assertSame(1, (int) $model->findByNik('3173000000000001')['company_id']);

        $this->activateTenant(2);
        self::assertSame(2, (int) $model->findByNik('3173000000000001')['company_id']);
    }

    public function testCrossTenantEmployeeReadUpdateAndDeleteFailClosed(): void
    {
        $model = new EmployeeModel($this->connection);

        self::assertNull($model->findScoped(2));
        self::assertFalse($model->updateScoped(2, ['full_name' => 'Compromised']));
        self::assertFalse($model->deleteScoped(2));

        $row = $this->connection->table('employees')->where('id', 2)->get()->getRowArray();
        self::assertSame('Tenant B Employee', $row['full_name']);
        self::assertNull($row['deleted_at']);
    }

    public function testGuessedPayslipAndDocumentIdsDoNotLeakPaths(): void
    {
        $payslip = new PayslipModel($this->connection);
        $document = new TenantTestDocumentModel($this->connection);

        self::assertNull($payslip->findScoped(2));
        self::assertNull($document->findScoped(2));
        self::assertSame('/tenant-a/payslip.pdf', $payslip->findScoped(1)['pdf_path']);
        self::assertSame('/tenant-a/ktp.pdf', $document->findScoped(1)['file_path']);
    }

    public function testCriticalTenantAggregatesRejectCrossTenantReadUpdateAndDelete(): void
    {
        foreach ([
            'attendances',
            'leaves',
            'overtimes',
            'reimbursements',
            'loans',
            'business_trips',
            'approval_requests',
        ] as $table) {
            $model = new TenantAggregateTestModel($table, $this->connection);
            self::assertNull($model->findScoped(2), "{$table} cross-tenant read must fail");
            self::assertFalse($model->updateScoped(2, ['status' => 'COMPROMISED']), "{$table} cross-tenant update must fail");
            self::assertFalse($model->deleteScoped(2), "{$table} cross-tenant delete must fail");
            self::assertSame(
                'TENANT_B',
                $this->connection->table($table)->where('id', 2)->get()->getRowArray()['status'],
                "{$table} tenant-B row must remain unchanged",
            );
        }
    }

    public function testSuperAdminTenantSwitchScopesDataAndGlobalModeIsExplicit(): void
    {
        $tenant = Services::tenant();
        $tenant->setRoleId(1);
        $tenant->setCompanyId(1);
        $model = new EmployeeModel($this->connection);

        self::assertCount(1, $model->scoped()->get()->getResultArray());

        $tenant->setCompanyId(0);
        self::assertCount(2, $model->scoped()->get()->getResultArray());
    }

    public function testBackgroundExportCarriesTenantContextAndZipContainsOnlyThatTenant(): void
    {
        $exportDirectory = WRITEPATH . 'tests/export-' . bin2hex(random_bytes(4));
        mkdir($exportDirectory, 0750, true);
        putenv('EXPORT_LOCAL_PATH=' . $exportDirectory);

        try {
            $this->connection->table('data_export_requests')->insert([
                'id' => 2,
                'company_id' => 2,
                'requested_by' => 22,
                'status' => 'QUEUED',
                'scope_json' => json_encode(['include' => ['employees']]),
                'download_token' => 'tenant-b-token',
                'expires_at' => '2026-07-20 00:00:00',
                'created_at' => '2026-07-13 00:00:00',
                'updated_at' => '2026-07-13 00:00:00',
            ]);

            $job = new ExportTenantDataJob(new DataExportService($this->connection));
            $job->setData(['request_id' => 2, 'company_id' => 2]);
            $job->handle();

            self::assertSame(1, Services::tenant()->getCompanyId(), 'Worker must restore prior tenant context.');
            $request = $this->connection->table('data_export_requests')->where('id', 2)->get()->getRowArray();
            self::assertSame('READY', $request['status']);

            $archives = glob($exportDirectory . '/*.zip') ?: [];
            self::assertCount(1, $archives);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($archives[0]));
            $employeesCsv = (string) $zip->getFromName('employees.csv');
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            $zip->close();

            self::assertStringContainsString('Tenant B Employee', $employeesCsv);
            self::assertStringNotContainsString('Tenant A Employee', $employeesCsv);
            self::assertSame(2, $manifest['company_id']);
        } finally {
            putenv('EXPORT_LOCAL_PATH');
            foreach (glob($exportDirectory . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($exportDirectory)) {
                rmdir($exportDirectory);
            }
        }
    }

    private function activateTenant(int $companyId): void
    {
        $tenant = Services::tenant();
        $tenant->setRoleId(4);
        $tenant->setCompanyId($companyId);
    }

    private function createSchema(): void
    {
        $forge = Database::forge($this->connection);
        $forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'company_id' => ['type' => 'INTEGER'],
            'employee_number' => ['type' => 'VARCHAR', 'constraint' => 32],
            'first_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'full_name' => ['type' => 'VARCHAR', 'constraint' => 200],
            'join_date' => ['type' => 'DATE'],
            'nik_hash' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'nik_encrypted' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addPrimaryKey('id');
        $forge->addUniqueKey(['company_id', 'employee_number']);
        $forge->createTable('employees');

        $forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'company_id' => ['type' => 'INTEGER'],
            'pdf_path' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
        $forge->addPrimaryKey('id');
        $forge->createTable('payslips');

        $forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'company_id' => ['type' => 'INTEGER'],
            'file_path' => ['type' => 'VARCHAR', 'constraint' => 255],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addPrimaryKey('id');
        $forge->createTable('employee_documents');

        $forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'company_id' => ['type' => 'INTEGER'],
            'requested_by' => ['type' => 'INTEGER'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20],
            'scope_json' => ['type' => 'TEXT', 'null' => true],
            'download_token' => ['type' => 'VARCHAR', 'constraint' => 100],
            'expires_at' => ['type' => 'DATETIME'],
            'started_at' => ['type' => 'DATETIME', 'null' => true],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
            'file_path' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'file_size_bytes' => ['type' => 'INTEGER', 'null' => true],
            'sha256' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'error_message' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addPrimaryKey('id');
        $forge->createTable('data_export_requests');

        foreach ([
            'attendances',
            'leaves',
            'overtimes',
            'reimbursements',
            'loans',
            'business_trips',
            'approval_requests',
        ] as $table) {
            $forge->addField([
                'id' => ['type' => 'INTEGER', 'auto_increment' => true],
                'company_id' => ['type' => 'INTEGER'],
                'status' => ['type' => 'VARCHAR', 'constraint' => 30],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
                'deleted_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'status']);
            $forge->createTable($table);
        }
    }

    private function seedTwoTenants(): void
    {
        $nikHash = hash('sha256', '3173000000000001');
        $this->connection->table('employees')->insertBatch([
            ['id' => 1, 'company_id' => 1, 'employee_number' => 'EMP-001', 'first_name' => 'A', 'full_name' => 'Tenant A Employee', 'join_date' => '2026-01-01', 'nik_hash' => $nikHash],
            ['id' => 2, 'company_id' => 2, 'employee_number' => 'EMP-001', 'first_name' => 'B', 'full_name' => 'Tenant B Employee', 'join_date' => '2026-01-01', 'nik_hash' => $nikHash],
        ]);
        $this->connection->table('payslips')->insertBatch([
            ['id' => 1, 'company_id' => 1, 'pdf_path' => '/tenant-a/payslip.pdf'],
            ['id' => 2, 'company_id' => 2, 'pdf_path' => '/tenant-b/payslip.pdf'],
        ]);
        $this->connection->table('employee_documents')->insertBatch([
            ['id' => 1, 'company_id' => 1, 'file_path' => '/tenant-a/ktp.pdf'],
            ['id' => 2, 'company_id' => 2, 'file_path' => '/tenant-b/ktp.pdf'],
        ]);
        foreach ([
            'attendances',
            'leaves',
            'overtimes',
            'reimbursements',
            'loans',
            'business_trips',
            'approval_requests',
        ] as $table) {
            $this->connection->table($table)->insertBatch([
                ['id' => 1, 'company_id' => 1, 'status' => 'TENANT_A'],
                ['id' => 2, 'company_id' => 2, 'status' => 'TENANT_B'],
            ]);
        }
    }
}

final class TenantTestDocumentModel extends BaseModel
{
    protected $table = 'employee_documents';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['company_id', 'file_path'];
}

final class TenantAggregateTestModel extends BaseModel
{
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['company_id', 'status'];

    public function __construct(string $table, BaseConnection $connection)
    {
        $this->table = $table;
        parent::__construct($connection);
    }
}
