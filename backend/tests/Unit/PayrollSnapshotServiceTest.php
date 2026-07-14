<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Payroll\PayrollSnapshotService;
use CodeIgniter\Test\CIUnitTestCase;

final class PayrollSnapshotServiceTest extends CIUnitTestCase
{
    public function testValidEvidenceIsAccepted(): void
    {
        $json = json_encode([
            'schema_version' => 1,
            'period' => ['id' => 7, 'company_id' => 3],
            'payslips' => [['id' => 100]],
        ], JSON_THROW_ON_ERROR);
        $period = [
            'id' => 7,
            'company_id' => 3,
            'snapshot_json' => $json,
            'snapshot_hash' => hash('sha256', $json),
        ];

        $snapshot = (new PayrollSnapshotService())->assertValid($period);

        self::assertSame(100, $snapshot['payslips'][0]['id']);
    }

    public function testAlteredEvidenceIsRejected(): void
    {
        $json = json_encode([
            'schema_version' => 1,
            'period' => ['id' => 7, 'company_id' => 3],
            'payslips' => [],
        ], JSON_THROW_ON_ERROR);
        $period = [
            'id' => 7,
            'company_id' => 3,
            'snapshot_json' => $json . ' ',
            'snapshot_hash' => hash('sha256', $json),
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        (new PayrollSnapshotService())->assertValid($period);
    }

    public function testEvidenceCannotBelongToAnotherTenantOrPeriod(): void
    {
        $json = json_encode([
            'schema_version' => 1,
            'period' => ['id' => 8, 'company_id' => 99],
            'payslips' => [],
        ], JSON_THROW_ON_ERROR);
        $period = [
            'id' => 7,
            'company_id' => 3,
            'snapshot_json' => $json,
            'snapshot_hash' => hash('sha256', $json),
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('evidence is invalid');
        (new PayrollSnapshotService())->assertValid($period);
    }
}
