<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Billing\UsageMeterService;

/**
 * S1.4 — Reset monthly usage meters (cron).
 *
 * Reset semua meter dengan period=MONTHLY ke 0 + roll period_start/end ke
 * bulan baru. Jalankan tanggal 1 setiap bulan setelah tengah malam.
 *
 * Usage:
 *   php spark billing:reset-monthly-meters
 *
 * Recommended cron:
 *   30 0 1 * * cd /var/www/hris && php spark billing:reset-monthly-meters
 */
class BillingResetMonthlyMeters extends BaseCommand
{
    protected $group       = 'Billing';
    protected $name        = 'billing:reset-monthly-meters';
    protected $description = 'Reset usage_meters dengan period=MONTHLY ke 0 dan roll period (S1.4 Roadmap SaaS)';
    protected $usage       = 'billing:reset-monthly-meters';

    public function run(array $params)
    {
        CLI::write('[' . date('Y-m-d H:i:s') . '] Starting monthly meter reset…', 'cyan');

        try {
            $svc = new UsageMeterService();
            $count = $svc->resetMonthly();

            CLI::write("✓ Reset {$count} monthly meters", 'green');

            // Audit log severity NOTICE
            $db = \Config\Database::connect();
            if ($db->tableExists('audit_logs')) {
                $db->table('audit_logs')->insert([
                    'company_id'    => null,
                    'user_id'       => null,
                    'event'         => 'billing.meters_reset_monthly',
                    'entity_type'   => 'usage_meters',
                    'entity_id'     => null,
                    'severity'      => 'NOTICE',
                    'metadata_json' => json_encode(['reset_count' => $count]),
                    'ip_address'    => 'cli',
                    'user_agent'    => 'spark:billing:reset-monthly-meters',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            CLI::error('Monthly reset failed: ' . $e->getMessage());
            exit(1);
        }
    }
}