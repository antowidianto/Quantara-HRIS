<?php

namespace Tests\Unit;

use App\Libraries\Approval\ApprovalOutcomeOutbox;
use CodeIgniter\Test\CIUnitTestCase;

final class ApprovalOutcomeOutboxIntegrationTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $tables = ['webhooks', 'approval_outbox_events', 'assets', 'generated_contracts', 'candidate_offers', 'candidates', 'companies', 'leaves', 'employees'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        $db->query('CREATE TABLE db_employees (id INTEGER PRIMARY KEY, company_id INTEGER, user_id INTEGER, full_name TEXT)');
        $db->query('CREATE TABLE db_companies (id INTEGER PRIMARY KEY, career_slug TEXT)');
        $db->query('CREATE TABLE db_leaves (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, request_number TEXT, status TEXT)');
        $db->query('CREATE TABLE db_candidates (id INTEGER PRIMARY KEY, company_id INTEGER, user_id INTEGER)');
        $db->query('CREATE TABLE db_candidate_offers (id INTEGER PRIMARY KEY, company_id INTEGER, candidate_id INTEGER, job_posting_id INTEGER, offer_number TEXT, status TEXT)');
        $db->query('CREATE TABLE db_generated_contracts (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, contract_number TEXT, status TEXT)');
        $db->query('CREATE TABLE db_assets (id INTEGER PRIMARY KEY, company_id INTEGER, asset_number TEXT, status TEXT)');
        $db->query('CREATE TABLE db_approval_outbox_events (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, approval_request_id INTEGER UNIQUE, module TEXT, entity_table TEXT, entity_id INTEGER, decision TEXT, event_type TEXT, event_id TEXT UNIQUE, payload TEXT, status TEXT, attempts INTEGER, max_attempts INTEGER, available_at TEXT, processing_at TEXT, published_at TEXT, last_error TEXT, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE db_webhooks (id INTEGER PRIMARY KEY, company_id INTEGER, is_active INTEGER, event_types TEXT)');
        $db->table('employees')->insert(['id' => 901, 'company_id' => 901, 'user_id' => 9901, 'full_name' => 'Outbox Test']);
        $db->table('companies')->insert(['id' => 901, 'career_slug' => 'acme-fmcg']);
        $db->table('leaves')->insert(['id' => 901, 'company_id' => 901, 'employee_id' => 901, 'request_number' => 'LV-901', 'status' => 'APPROVED']);
        $db->table('candidates')->insert(['id' => 902, 'company_id' => 901, 'user_id' => 9955]);
        $db->table('candidate_offers')->insert(['id' => 902, 'company_id' => 901, 'candidate_id' => 902, 'job_posting_id' => 77, 'offer_number' => 'OFR-901', 'status' => 'SENT']);
        $db->table('generated_contracts')->insert(['id' => 903, 'company_id' => 901, 'employee_id' => 901, 'contract_number' => 'CTR-901', 'status' => 'SENT']);
        $db->table('assets')->insert(['id' => 904, 'company_id' => 901, 'asset_number' => 'AST-901', 'status' => 'ASSIGNED']);
    }

    public function testOutcomeIsRecordedWithMinimalTenantScopedPayload(): void
    {
        $outbox = new ApprovalOutcomeOutbox(\Config\Database::connect());
        $id = $outbox->record([
            'id' => 1901, 'company_id' => 901, 'module' => 'LEAVE',
            'entity_table' => 'leaves', 'entity_id' => 901, 'employee_id' => 901,
        ], 'APPROVED', 8901, '', ['sensitive_balance_snapshot' => 'must-not-leak']);

        $row = \Config\Database::connect()->table('approval_outbox_events')->where('id', $id)->get()->getRowArray();
        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PENDING', $row['status']);
        $this->assertSame('leave.approved', $row['event_type']);
        $this->assertSame(9901, $payload['recipient_user_id']);
        $this->assertSame('LV-901', $payload['request_number']);
        $this->assertArrayNotHasKey('sensitive_balance_snapshot', $payload);
        $this->assertSame(36, strlen($row['event_id']));
    }

    public function testRollbackRemovesOutcomeTogetherWithDomainChange(): void
    {
        $db = \Config\Database::connect();
        $db->transBegin();
        $db->table('leaves')->where('id', 901)->update(['status' => 'REJECTED']);
        (new ApprovalOutcomeOutbox($db))->record([
            'id' => 1902, 'company_id' => 901, 'module' => 'LEAVE',
            'entity_table' => 'leaves', 'entity_id' => 901, 'employee_id' => 901,
        ], 'REJECTED', 8901, 'data tidak valid', []);
        $db->transRollback();

        $this->assertSame('APPROVED', $db->table('leaves')->where('id', 901)->get()->getRowArray()['status']);
        $this->assertSame(0, $db->table('approval_outbox_events')->countAllResults());
    }

    public function testApprovedOfferNotifiesCandidateButInternalRejectionNotifiesMaker(): void
    {
        $db = \Config\Database::connect();
        $outbox = new ApprovalOutcomeOutbox($db);
        $approvedId = $outbox->record([
            'id' => 1910, 'company_id' => 901, 'module' => 'CANDIDATE_OFFER',
            'entity_table' => 'candidate_offers', 'entity_id' => 902, 'employee_id' => 901,
        ], 'APPROVED', 8901, '', []);
        $rejectedId = $outbox->record([
            'id' => 1911, 'company_id' => 901, 'module' => 'CANDIDATE_OFFER',
            'entity_table' => 'candidate_offers', 'entity_id' => 902, 'employee_id' => 901,
        ], 'REJECTED', 8901, 'salary exception rejected', []);

        $approved = json_decode($db->table('approval_outbox_events')->where('id', $approvedId)
            ->get()->getRowArray()['payload'], true, 512, JSON_THROW_ON_ERROR);
        $rejected = json_decode($db->table('approval_outbox_events')->where('id', $rejectedId)
            ->get()->getRowArray()['payload'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(9955, $approved['recipient_user_id']);
        $this->assertSame(9901, $rejected['recipient_user_id']);
        $this->assertSame('OFR-901', $approved['request_number']);
        $this->assertSame('/careers/acme-fmcg/offers', $approved['notification']['link']);
        $this->assertSame('/recruitment/jobs/77', $rejected['notification']['link']);
    }

    public function testContractReleaseNotifiesEmployeeButInternalRejectionNotifiesMaker(): void
    {
        $db = \Config\Database::connect();
        $outbox = new ApprovalOutcomeOutbox($db);
        $approvedId = $outbox->record([
            'id' => 1920, 'company_id' => 901, 'module' => 'GENERATED_CONTRACT',
            'entity_table' => 'generated_contracts', 'entity_id' => 903, 'employee_id' => 901,
            'meta' => ['submitted_by' => 9988, 'approval_action' => 'SEND'],
        ], 'APPROVED', 8901, '', []);
        $rejectedId = $outbox->record([
            'id' => 1921, 'company_id' => 901, 'module' => 'GENERATED_CONTRACT',
            'entity_table' => 'generated_contracts', 'entity_id' => 903, 'employee_id' => 901,
            'meta' => json_encode(['submitted_by' => 9988, 'approval_action' => 'SEND']),
        ], 'REJECTED', 8901, 'dokumen harus direvisi', []);

        $approved = json_decode($db->table('approval_outbox_events')->where('id', $approvedId)
            ->get()->getRowArray()['payload'], true, 512, JSON_THROW_ON_ERROR);
        $rejected = json_decode($db->table('approval_outbox_events')->where('id', $rejectedId)
            ->get()->getRowArray()['payload'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(9901, $approved['recipient_user_id']);
        $this->assertSame(9988, $rejected['recipient_user_id']);
        $this->assertSame('CTR-901', $approved['request_number']);
        $this->assertSame('SEND', $approved['webhook']['approval_action']);
        $this->assertSame('/employees/contracts', $rejected['notification']['link']);
    }

    public function testAssetOutcomeUsesEmployeeForApprovalAndMakerForInternalRejection(): void
    {
        $db = \Config\Database::connect();
        $outbox = new ApprovalOutcomeOutbox($db);
        $approvedId = $outbox->record([
            'id' => 1930, 'company_id' => 901, 'module' => 'ASSET_TRANSACTION',
            'entity_table' => 'assets', 'entity_id' => 904, 'employee_id' => 901,
            'meta' => ['submitted_by' => 9977, 'approval_action' => 'ASSIGN'],
        ], 'APPROVED', 8901, '', []);
        $rejectedId = $outbox->record([
            'id' => 1931, 'company_id' => 901, 'module' => 'ASSET_TRANSACTION',
            'entity_table' => 'assets', 'entity_id' => 904, 'employee_id' => 901,
            'meta' => ['submitted_by' => 9977, 'approval_action' => 'RETURN'],
        ], 'REJECTED', 8901, 'bukti kehilangan belum lengkap', []);
        $approved = json_decode($db->table('approval_outbox_events')->where('id', $approvedId)
            ->get()->getRowArray()['payload'], true, 512, JSON_THROW_ON_ERROR);
        $rejected = json_decode($db->table('approval_outbox_events')->where('id', $rejectedId)
            ->get()->getRowArray()['payload'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(9901, $approved['recipient_user_id']);
        $this->assertSame(9977, $rejected['recipient_user_id']);
        $this->assertSame('asset.transaction_approved', $approved['webhook']['event']);
        $this->assertSame('/hr/assets', $rejected['notification']['link']);
    }

    public function testCrossTenantEntityCannotProduceOutcome(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ApprovalOutcomeOutbox(\Config\Database::connect()))->record([
            'id' => 1903, 'company_id' => 999, 'module' => 'LEAVE',
            'entity_table' => 'leaves', 'entity_id' => 901, 'employee_id' => 901,
        ], 'APPROVED', 8901, '', []);
    }

    public function testStaleProcessingLeaseIsRecovered(): void
    {
        $db = \Config\Database::connect();
        $db->table('employees')->where('id', 901)->update(['user_id' => null]);
        $id = (new ApprovalOutcomeOutbox($db))->record([
            'id' => 1904, 'company_id' => 901, 'module' => 'LEAVE',
            'entity_table' => 'leaves', 'entity_id' => 901, 'employee_id' => 901,
        ], 'APPROVED', 8901, '', []);
        $db->table('approval_outbox_events')->where('id', $id)->update([
            'status' => 'PROCESSING', 'processing_at' => '2020-01-01 00:00:00',
        ]);

        $result = (new ApprovalOutcomeOutbox($db))->processBatch(10);
        $row = $db->table('approval_outbox_events')->where('id', $id)->get()->getRowArray();
        $this->assertSame(1, $result['published']);
        $this->assertSame('PUBLISHED', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
    }

    protected function tearDown(): void
    {
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        parent::tearDown();
    }
}
