<?php

namespace App\Commands;

use App\Libraries\Compliance\TurnoverAnalyticsService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark turnover:snapshot
 *
 * Cron command untuk H2.6 Turnover Analytics. Loop semua company aktif,
 * generate snapshot turnover untuk periode terbaru (default MONTH bulan
 * berjalan; flag --period=YEAR opsional). Snapshot di-cache di tabel
 * `turnover_metrics_snapshot` supaya dashboard tidak hitung ulang on-demand.
 *
 * Idempotent — `TurnoverAnalyticsService::snapshot()` upsert by
 * (company_id, period_type, period_key).
 *
 * Cron: jadwalkan setiap awal bulan (mis. 02:00 tanggal 1) untuk MONTH;
 * sekali per kuartal untuk YEAR. Aman dipanggil ad-hoc kapan saja.
 */
class TurnoverSnapshotCommand extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'turnover:snapshot';
    protected $description = 'Generate turnover analytics snapshot per company (H2.6).';
    protected $usage       = 'turnover:snapshot [--period=MONTH|YEAR] [--key=YYYY-MM|YYYY] [--company=N]';
    protected $arguments   = [];
    protected $options     = [
        '--period'  => 'Period type: MONTH (default) atau YEAR',
        '--key'     => 'Period key. Default: bulan berjalan (YYYY-MM) atau tahun berjalan (YYYY)',
        '--company' => 'Limit ke 1 company id (default: semua company aktif)',
    ];

    public function run(array $params)
    {
        $period = strtoupper((string) ($params['period'] ?? CLI::getOption('period') ?? 'MONTH'));
        if (! in_array($period, ['MONTH', 'YEAR'], true)) {
            CLI::error("--period harus MONTH atau YEAR (got: {$period})");
            return 1;
        }

        $key = (string) ($params['key'] ?? CLI::getOption('key') ?? '');
        if ($key === '') {
            $key = $period === 'MONTH' ? date('Y-m') : date('Y');
        }

        $companyOnly = (int) ($params['company'] ?? CLI::getOption('company') ?? 0);

        $db = \Config\Database::connect();
        $companiesQuery = $db->table('companies')->where('deleted_at', null);
        if ($companyOnly > 0) {
            $companiesQuery->where('id', $companyOnly);
        }
        $companies = $companiesQuery->get()->getResultArray();

        if (empty($companies)) {
            CLI::write('Tidak ada company yang match.', 'yellow');
            return 0;
        }

        $service = new TurnoverAnalyticsService();
        $okCount = 0;
        $errCount = 0;

        CLI::write("Generating turnover snapshot — period={$period} key={$key}", 'white');
        CLI::write(str_repeat('-', 60));

        foreach ($companies as $c) {
            $cid = (int) $c['id'];
            $name = (string) ($c['name'] ?? "company#{$cid}");
            try {
                $snap = $service->snapshot($cid, $period, $key);
                CLI::write(sprintf(
                    '  ✓ [%-3d] %-30s rate=%5.2f%% headcount=%-6d separations=%d',
                    $cid,
                    mb_substr($name, 0, 30),
                    (float) ($snap['turnover_rate'] ?? 0),
                    (int) ($snap['avg_headcount'] ?? 0),
                    (int) ($snap['separations'] ?? 0),
                ), 'green');
                $okCount++;
            } catch (\Throwable $e) {
                CLI::write("  ✗ [{$cid}] {$name} — " . $e->getMessage(), 'red');
                log_message('error', 'TurnoverSnapshotCommand: ' . $e->getMessage());
                $errCount++;
            }
        }

        CLI::write(str_repeat('-', 60));
        CLI::write("Done. ok={$okCount} error={$errCount}", $errCount > 0 ? 'yellow' : 'green');
        return $errCount > 0 ? 1 : 0;
    }
}