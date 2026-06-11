<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\CustomerSuccess\CustomerHealthService;

/**
 * S3.4 — Customer Success: Health Score weekly cron.
 *
 * Recompute health score untuk semua company aktif. Hasil disimpan ke
 * customer_health_scores. Tim CS Quantara bisa monitor dashboard untuk
 * spot tenant dengan tier RED dan reach out proactively.
 *
 * Usage:
 *   php spark cs:compute-health-scores
 *
 * Recommended cron: Senin 06:00 (sebelum CSM standup)
 *   0 6 * * 1 cd /var/www/hris && php spark cs:compute-health-scores
 */
class CsComputeHealthScores extends BaseCommand
{
    protected $group       = 'CustomerSuccess';
    protected $name        = 'cs:compute-health-scores';
    protected $description = 'Compute customer health score per company (S3.4 Roadmap SaaS)';

    public function run(array $params)
    {
        CLI::write('[' . date('Y-m-d H:i:s') . '] Computing customer health scores…', 'cyan');

        try {
            $svc = new CustomerHealthService();
            $stats = $svc->computeAll();

            CLI::write(sprintf(
                '✓ Processed: %d | GREEN: %d | YELLOW: %d | RED: %d | Failed: %d',
                $stats['processed'] ?? 0,
                $stats['GREEN'] ?? 0,
                $stats['YELLOW'] ?? 0,
                $stats['RED'] ?? 0,
                $stats['failed'] ?? 0,
            ), 'green');

            // Notify Slack #cs-team kalau ada tenant baru masuk RED
            if (($stats['RED'] ?? 0) > 0) {
                CLI::write("⚠️  {$stats['RED']} tenant masuk tier RED — tim CS perlu follow-up", 'red');
            }

            // Audit log NOTICE
            $db = \Config\Database::connect();
            if ($db->tableExists('audit_logs')) {
                $db->table('audit_logs')->insert([
                    'company_id'    => null,
                    'user_id'       => null,
                    'event'         => 'cs.health_scores_computed',
                    'entity_type'   => 'customer_health_scores',
                    'entity_id'     => null,
                    'severity'      => 'NOTICE',
                    'metadata_json' => json_encode($stats),
                    'ip_address'    => 'cli',
                    'user_agent'    => 'spark:cs:compute-health-scores',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            CLI::error('Compute failed: ' . $e->getMessage());
            exit(1);
        }
    }
}