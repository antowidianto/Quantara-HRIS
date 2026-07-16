<?php

namespace Tests\Unit;

use App\Libraries\Approval\Adapters\BusinessTripApprovalDomainAdapter;
use App\Libraries\Attendance\BusinessTripApprovalException;
use App\Libraries\Attendance\BusinessTripApprovalWorkflowService;
use App\Libraries\Attendance\BusinessTripService;
use CodeIgniter\Test\CIUnitTestCase;

final class BusinessTripApprovalDomainAdapterIntegrationTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $tables = ['business_trips', 'employees'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        $db->query('CREATE TABLE db_employees (id INTEGER PRIMARY KEY, company_id INTEGER, user_id INTEGER, full_name TEXT)');
        $db->query('CREATE TABLE db_business_trips (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, reference_number TEXT, status TEXT, approved_by INTEGER, approved_at TEXT, rejected_at TEXT, rejection_reason TEXT, sppd_html TEXT, updated_at TEXT)');
        $db->table('employees')->insert(['id' => 1101, 'company_id' => 1101, 'user_id' => 11101, 'full_name' => 'Trip Test']);
    }

    public function testFinalApprovalCreatesSppdSnapshotExactlyOnce(): void
    {
        $db = \Config\Database::connect();
        $db->table('business_trips')->insert([
            'id' => 1101, 'company_id' => 1101, 'employee_id' => 1101,
            'reference_number' => 'SPPD/TEST/2026/00001', 'status' => 'PENDING',
        ]);
        $renderer = new class extends BusinessTripService {
            public function renderSppdHtml(int $tripId, ?int $companyId = null): string
            {
                return "<html>SPPD {$companyId}/{$tripId}</html>";
            }
        };
        $workflow = new BusinessTripApprovalWorkflowService($db, $renderer);
        $adapter = new BusinessTripApprovalDomainAdapter($workflow);
        $request = ['entity_id' => 1101, 'company_id' => 1101];
        $adapter->apply($request, 'APPROVED', 12101, 'verified');

        $trip = $db->table('business_trips')->where('id', 1101)->get()->getRowArray();
        $this->assertSame('APPROVED', $trip['status']);
        $this->assertSame(12101, (int) $trip['approved_by']);
        $this->assertSame('<html>SPPD 1101/1101</html>', $trip['sppd_html']);

        $this->expectException(BusinessTripApprovalException::class);
        $adapter->apply($request, 'APPROVED', 12101, 'duplicate');
    }

    public function testRejectIsTenantScopedAndRequiresReason(): void
    {
        $db = \Config\Database::connect();
        $db->table('business_trips')->insert([
            'id' => 1102, 'company_id' => 1101, 'employee_id' => 1101,
            'reference_number' => 'SPPD/TEST/2026/00002', 'status' => 'PENDING',
        ]);
        $adapter = new BusinessTripApprovalDomainAdapter(
            new BusinessTripApprovalWorkflowService($db)
        );
        try {
            $adapter->apply(['entity_id' => 1102, 'company_id' => 1101], 'REJECTED', 12101, 'no');
            $this->fail('Short reason must fail');
        } catch (BusinessTripApprovalException $exception) {
            $this->assertSame('REASON_REQUIRED', $exception->errorCode);
        }
        try {
            $adapter->apply(['entity_id' => 1102, 'company_id' => 999], 'REJECTED', 12101, 'budget ditolak');
            $this->fail('Cross-tenant decision must fail');
        } catch (BusinessTripApprovalException $exception) {
            $this->assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
        }
        $this->assertSame('PENDING', $db->table('business_trips')->where('id', 1102)->get()->getRowArray()['status']);
    }

    protected function tearDown(): void
    {
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        parent::tearDown();
    }
}
