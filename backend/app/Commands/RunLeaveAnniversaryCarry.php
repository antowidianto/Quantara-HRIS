<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * RunLeaveAnniversaryCarry — daily cron untuk reset & carry-over saldo cuti
 * berdasarkan **tanggal anniversary** (join_date) karyawan, bukan akhir
 * tahun kalender.
 *
 * Dasar hukum:
 *   UU 13/2003 Pasal 79 ayat (5): cuti tahunan hak karyawan yang sudah
 *   bekerja **12 bulan terus-menerus**. Mode ANNIVERSARY menerapkan
 *   aturan ini secara strict — saldo tahunan refresh tepat di hari
 *   ulang tahun kerja.
 *
 *   UU 13/2003 Pasal 84 + praktik internal: cuti yang tidak terpakai
 *   boleh ditiadakan setelah N bulan (default 6 bulan).
 *
 * Kapan command ini relevan?
 *   Kalau leave_types punya `carry_over_basis = ANNIVERSARY`. Default
 *   sistem tetap `YEAR_END` (yang dipakai oleh `hr:leave-yearend`).
 *   HR memilih basis sesuai kebijakan perusahaan:
 *     - YEAR_END     → reset 1 Januari, simple administratif (mayoritas)
 *     - ANNIVERSARY  → reset di tanggal join, strict per UU
 *
 * Cron yang disarankan (jalan setiap hari jam 00:30):
 *   30 0 * * *  cd /path/to/backend && php spark hr:leave-anniversary-carry \
 *               >> writable/logs/leave-anniversary.log 2>&1
 *
 * Pemakaian:
 *   php spark hr:leave-anniversary-carry                # hari ini, semua company
 *   php spark hr:leave-anniversary-carry --date 2026-05-15
 *   php spark hr:leave-anniversary-carry --company 1
 *   php spark hr:leave-anniversary-carry --dry-run
 */
class RunLeaveAnniversaryCarry extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:leave-anniversary-carry';
    protected $description = 'Carry-over saldo cuti berbasis tanggal join (UU 13/2003 Pasal 79).';
    protected $usage       = 'hr:leave-anniversary-carry [--date Y-m-d] [--company ID] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--date'    => 'Override tanggal hari ini (Y-m-d). Default: hari ini.',
        '--company' => 'Hanya proses 1 company (default: semua company aktif).',
        '--dry-run' => 'Hitung saja tanpa menulis ke DB.',
    ];

    public function run(array $params)
    {
        $today      = (string) (CLI::getOption('date') ?: date('Y-m-d'));
        $companyOpt = (int) (CLI::getOption('company') ?: 0);
        $dryRun     = (bool) CLI::getOption('dry-run');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
            CLI::error("Format --date tidak valid. Gunakan Y-m-d, mis. 2026-05-15.");
            return EXIT_USER_INPUT;
        }

        $db = \Config\Database::connect();
        $companies = $db->table('companies')
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->when($companyOpt > 0, fn ($q) => $q->where('id', $companyOpt))
            ->get()->getResultArray();

        if (empty($companies)) {
            CLI::write("Tidak ada company yang cocok.", 'yellow');
            return EXIT_SUCCESS;
        }

        $service = Services::leaveBalance();
        $totalProcessed = 0;
        $totalCarriedDays = 0.0;
        $totalExpiredDays = 0.0;
        $totalEmployeesToday = 0;

        CLI::write(str_repeat('=', 70), 'green');
        CLI::write("Anniversary leave carry-over: {$today}" . ($dryRun ? ' (DRY-RUN)' : ''), 'green');
        CLI::write(str_repeat('=', 70), 'green');

        foreach ($companies as $c) {
            CLI::write("\n▶ {$c['name']} (#{$c['id']})", 'cyan');
            $res = $service->runAnniversaryCarryOver((int) $c['id'], $today, $dryRun);

            if (! empty($res['note'])) {
                CLI::write('  · ' . $res['note'], 'yellow');
                continue;
            }

            $employeesToday = (int) ($res['employees_with_anniversary_today'] ?? 0);
            $totalEmployeesToday += $employeesToday;

            CLI::write(sprintf(
                "  %d karyawan anniversary hari ini, %d saldo diproses, %.2f hari di-carry, %.2f hari hangus",
                $employeesToday,
                $res['processed'],
                $res['carried_total_days'],
                $res['expired_total_days']
            ));

            // Detail beberapa entri pertama untuk visibility
            $sample = array_slice($res['balances'], 0, 5);
            foreach ($sample as $row) {
                CLI::write(sprintf(
                    "    - %s | %s | tahun-%d | sisa %.1f → carry %.1f, expired %.1f, exp %s",
                    $row['employee'],
                    $row['leave_type'],
                    $row['tenure_years'],
                    $row['old_remaining'],
                    $row['carried'],
                    $row['expired'],
                    $row['expires_at'] ?: '-'
                ));
            }
            if (count($res['balances']) > 5) {
                CLI::write(sprintf('    ... dan %d entry lainnya.', count($res['balances']) - 5), 'dark_gray');
            }

            $totalProcessed   += (int) $res['processed'];
            $totalCarriedDays += (float) $res['carried_total_days'];
            $totalExpiredDays += (float) $res['expired_total_days'];

            // Audit per-company hanya kalau ada yg diproses, supaya log
            // tidak banjir entry kosong setiap pagi.
            if (! $dryRun && (int) $res['processed'] > 0) {
                try {
                    Services::audit()
                        ->forModule('LEAVE')
                        ->log(
                            action: 'leave.anniversary_carryover',
                            entity: 'company',
                            entityId: (int) $c['id'],
                            after: [
                                'date'         => $today,
                                'processed'    => $res['processed'],
                                'carried_days' => $res['carried_total_days'],
                                'expired_days' => $res['expired_total_days'],
                                'employees'    => $employeesToday,
                            ],
                            description: "Anniversary carry-over {$today}: {$res['processed']} saldo, "
                                . "{$employeesToday} karyawan",
                            severity: 'NOTICE',
                        );
                } catch (\Throwable $e) {
                    CLI::write('  · Audit log gagal: ' . $e->getMessage(), 'yellow');
                }
            }
        }

        CLI::write("\n" . str_repeat('=', 70), 'green');
        CLI::write(sprintf(
            "Total: %d company, %d karyawan anniversary, %d saldo diproses, %.2f hari di-carry, %.2f hari hangus",
            count($companies),
            $totalEmployeesToday,
            $totalProcessed,
            $totalCarriedDays,
            $totalExpiredDays
        ), 'green');
        CLI::write(str_repeat('=', 70) . "\n", 'green');

        return EXIT_SUCCESS;
    }
}