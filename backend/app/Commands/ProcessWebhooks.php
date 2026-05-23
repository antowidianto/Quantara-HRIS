<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * ProcessWebhooks — Spark command untuk process webhook delivery queue.
 *
 * Run via cron, biasanya tiap menit:
 *
 *   * * * * * cd /var/www/hris/backend && php spark webhooks:process \
 *               --batch=50 >> /var/log/hris/webhooks.log 2>&1
 *
 * Atau via long-running worker (kalau punya supervisor/systemd):
 *
 *   while true; do php spark webhooks:process --batch=100; sleep 30; done
 *
 * Output: ringkasan sent/failed/skipped + exit 0 kalau ada delivery
 * sukses, 1 kalau semua failed (untuk monitoring alert).
 */
class ProcessWebhooks extends BaseCommand
{
    protected $group       = 'HRIS';
    protected $name        = 'webhooks:process';
    protected $description = 'Process webhook delivery queue (HTTP POST + retry on failure)';
    protected $usage       = 'webhooks:process [--batch=N]';
    protected $arguments   = [];
    protected $options     = [
        '--batch' => 'Max delivery di-process per call (default 50)',
    ];

    public function run(array $params)
    {
        $batch = (int) ($params['batch'] ?? CLI::getOption('batch') ?? 50);
        if ($batch < 1) $batch = 50;

        $start = microtime(true);
        $result = Services::webhook()->processQueue($batch);
        $elapsed = round((microtime(true) - $start) * 1000);

        $msg = sprintf(
            'Webhooks processed in %dms — sent=%d, failed=%d, skipped=%d',
            $elapsed,
            $result['sent'],
            $result['failed'],
            $result['skipped'],
        );

        if ($result['failed'] > 0 && $result['sent'] === 0) {
            CLI::error($msg);
            return 1;
        }

        $color = $result['failed'] > 0 ? 'yellow' : 'green';
        CLI::write($msg, $color);
        return 0;
    }
}