<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * RunLeaveYearEnd
 *
 * Lakukan year-end carry-over saldo cuti: pindahkan sisa cuti tahun lalu
 * ke balance tahun ini sebagai `carried_over` (capped per leave_types) dan
 * set `expires_at` (default 6 bulan sesuai UU 13/2003 Pasal 79 ayat 5).
 *
 * Pemakaian:
 *   php spark hr:leave-yearend                       # tahun otomatis
 *   php spark hr:leave-yearend --from 2025 --to 2026
 *   php spark hr:leave-yearend --company 1
 *   php spark hr:leave-yearend --dry-run
 *
 * Cron:
 *   0 1 1 1 *  cd /path/to/backend && php spark hr:leave-yearend >> writable/logs/leave-yearend.log 2>&1
 */
class RunLeaveYearEnd extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:leave-yearend';
    protected $description = 'Carry-over saldo cuti dari tahun lalu ke tahun berjalan.';
    protected $usage       = 'hr:leave-yearend [--from YYYY] [--to YYYY] [--company ID] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--from'    => 'Tahun sumber (default: tahun lalu).',
        '--to'      => 'Tahun tujuan (default: tahun berjalan).',
        '--company' => 'Hanya proses company tertentu (default: semua company aktif).',
        '--dry-run' => 'Hitung saja tanpa menulis ke DB.',
    ];

    public function run(array $params)
    {
        $now = (int) date('Y');
        $from = (int) (CLI::getOption('from') ?: ($now - 1));
        $to   = (int) (CLI::getOption('to')   ?: $now);
        $companyOpt = (int) (CLI::getOption('company') ?: 0);
        $dryRun = (bool) CLI::getOption('dry-run');

        if ($from >= $to) {
            CLI::error("Tahun sumber ({$from}) harus < tahun tujuan ({$to}).");
            return;
        }

        $db = \Config\Database::connect();
        $companies = $db->table('companies')
            ->where('is_active', 1)
            ->when($companyOpt > 0, fn ($q) => $q->where('id', $companyOpt))
            ->get()->getResultArray();

        if (empty($companies)) {
            CLI::write("Tidak ada company yang cocok.", 'yellow');
            return;
        }

        $service = Services::leaveBalance();
        $totalCarried = 0;
        $totalCarriedDays = 0.0;
        $totalExpiredDays = 0.0;

        CLI::write(str_repeat('=', 70), 'green');
        CLI::write("Leave year-end carry-over: {$from} → {$to}" . ($dryRun ? ' (DRY-RUN)' : ''), 'green');
        CLI::write(str_repeat('=', 70), 'green');

        foreach ($companies as $c) {
            CLI::write("\n▶ {$c['name']} (#{$c['id']})", 'cyan');
            $res = $service->runYearEndCarryOver((int) $c['id'], $from, $to, $dryRun);

            CLI::write(sprintf(
                "  %d balance, %.2f hari di-carry, %.2f hari hangus",
                $res['carried'], $res['total_days'], $res['expired_days']
            ));

            // Detail beberapa entri pertama untuk visibility
            $sample = array_slice($res['balances'], 0, 5);
            foreach ($sample as $row) {
                CLI::write(sprintf(
                    "    - %s | %s | sisa %.1f → carry %.1f, expired %.1f, exp %s",
                    $row['employee'],
                    $row['leave_type'],
                    $row['old_remaining'],
                    $row['carried'],
                    $row['expired'],
                    $row['expires_at'] ?: '-'
                ));
            }
            if (count($res['balances']) > 5) {
                CLI::write(sprintf('    ... dan %d entry lainnya.', count($res['balances']) - 5), 'dark_gray');
            }

            $totalCarried     += $res['carried'];
            $totalCarriedDays += $res['total_days'];
            $totalExpiredDays += $res['expired_days'];

            // Audit log per-company
            if (! $dryRun) {
                Services::audit()
                    ->forModule('LEAVE')
                    ->log(
                        action: 'leave.year_end_carryover',
                        entity: 'company',
                        entityId: (int) $c['id'],
                        after: [
                            'from_year' => $from, 'to_year' => $to,
                            'carried' => $res['carried'],
                            'total_days' => $res['total_days'],
                            'expired_days' => $res['expired_days'],
                        ],
                        description: "Year-end carry-over {$from}→{$to}: {$res['carried']} balance",
                        severity: 'NOTICE'
                    );
            }
        }

        CLI::write("\n" . str_repeat('=', 70), 'green');
        CLI::write(sprintf(
            "Total: %d balance × %d company, %.2f hari di-carry, %.2f hari hangus",
            $totalCarried, count($companies), $totalCarriedDays, $totalExpiredDays
        ), 'green');
        CLI::write(str_repeat('=', 70) . "\n", 'green');
    }
}