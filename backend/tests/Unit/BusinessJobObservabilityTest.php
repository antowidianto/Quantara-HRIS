<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BusinessJobObservabilityTest extends TestCase
{
    public function testCriticalOutcomesAndSafeContextAreInstrumented(): void
    {
        $metrics = file_get_contents(APPPATH . 'Controllers/Api/V1/System/MetricsController.php');
        $monitor = file_get_contents(APPPATH . 'Libraries/System/BusinessJobMonitor.php');
        $payroll = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/PayrollPeriodController.php');
        $scanner = file_get_contents(APPPATH . 'Commands/SecurityScanTenantLeaks.php');
        $backup = file_get_contents(APPPATH . 'Commands/DbBackupEncrypted.php');
        foreach ([$metrics, $monitor, $payroll, $scanner, $backup] as $source) self::assertIsString($source);

        foreach (['hris_business_job_duration_ms', 'hris_approvals_stuck_sla', 'hris_email_delivery_failed',
            'hris_webhook_dead_letter_count', 'hris_backup_age_seconds', 'hris_restore_drill_age_seconds'] as $metric) {
            self::assertStringContainsString($metric, $metrics);
        }
        self::assertStringContainsString("start('payroll.calculate'", $payroll);
        self::assertStringContainsString("start('security.tenant_leak_scan'", $scanner);
        self::assertStringContainsString("start('backup.full'", $backup);
        self::assertStringContainsString("'request_id'", $monitor);
        self::assertStringContainsString("'bank_account'", $monitor);
    }

    public function testRestoreDrillPassRequiresCompleteExternalEvidence(): void
    {
        $command = file_get_contents(APPPATH . 'Commands/RecordRestoreDrill.php');
        self::assertIsString($command);
        foreach (['--counts', '--hashes', '--attachments', '--login', "['SUCCESS', 'VERIFIED']", 'evidence_reference'] as $guard) {
            self::assertStringContainsString($guard, $command);
        }
    }
}
