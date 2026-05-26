<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * NotifyCarryOverCap (Sprint 7 / H1.2)
 *
 * Cron yang berjalan H-30 sebelum akhir tahun (~1 Desember) untuk memperingatkan
 * karyawan yang saldo cuti remaining-nya akan terpotong oleh `carry_over_max_days`
 * pada saat year-end carry-over berjalan (1 Januari).
 *
 * Tanpa notifikasi ini, banyak karyawan baru sadar saldonya hilang ketika
 * sudah dipotong di awal tahun. Tujuan: kasih waktu 30 hari untuk ambil cuti
 * sebelum saldonya hangus.
 *
 * Logika:
 *   1. Loop semua company aktif × leave_types yang `is_carry_over_allowed = 1`
 *      dan `carry_over_max_days IS NOT NULL` (kalau NULL = unlimited, tidak
 *      perlu notif).
 *   2. Untuk tiap karyawan aktif: cek leave_balances (tahun berjalan).
 *      Jika `remaining > carry_over_max_days`, hitung hari yang akan hangus.
 *   3. Kirim notifikasi ke user (dengan email) + audit log per karyawan.
 *
 * Pemakaian:
 *   php spark hr:notify-carry-over-cap
 *   php spark hr:notify-carry-over-cap --dry-run
 *   php spark hr:notify-carry-over-cap --company 1
 *   php spark hr:notify-carry-over-cap --year 2026
 *
 * Cron (1 Desember setiap tahun):
 *   0 9 1 12 *  cd /path/to/backend && php spark hr:notify-carry-over-cap >> writable/logs/carry-over-notif.log 2>&1
 *
 * Lihat docs/QUANTARA_HRIS_ROADMAP_CHECKLIST_EKSEKUSI.md item H1.2.
 */
class NotifyCarryOverCap extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:notify-carry-over-cap';
    protected $description = 'Notifikasi H-30 ke karyawan yang sisa cuti melebihi cap carry-over.';
    protected $usage       = 'hr:notify-carry-over-cap [--year YYYY] [--company N] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--year'    => 'Tahun balance yang dicek (default: tahun berjalan).',
        '--company' => 'Filter ke 1 company (untuk testing).',
        '--dry-run' => 'Tampilkan hasil tanpa kirim notifikasi/audit.',
    ];

    public function run(array $params)
    {
        $year = (int) (CLI::getOption('year') ?: date('Y'));
        $companyFilter = CLI::getOption('company');
        $companyId = $companyFilter !== null ? (int) $companyFilter : null;
        $dryRun = (bool) CLI::getOption('dry-run');

        $db = \Config\Database::connect();

        $companies = $db->table('companies')
            ->where('is_active', 1)
            ->when($companyId, fn ($q) => $q->where('id', $companyId))
            ->get()->getResultArray();

        if (empty($companies)) {
            CLI::write('Tidak ada company yang cocok.', 'yellow');
            return;
        }

        CLI::write(str_repeat('=', 70), 'green');
        CLI::write("Notifikasi carry-over cap untuk tahun {$year}" . ($dryRun ? ' (DRY-RUN)' : ''), 'green');
        CLI::write(str_repeat('=', 70), 'green');

        $totalNotif = 0;
        $totalDaysAtRisk = 0.0;

        foreach ($companies as $c) {
            CLI::write("\n▶ {$c['name']} (#{$c['id']})", 'cyan');

            // Leave types yang punya cap eksplisit
            $types = $db->table('leave_types')
                ->where('company_id', (int) $c['id'])
                ->where('is_active', 1)
                ->where('is_carry_over_allowed', 1)
                ->where('carry_over_max_days IS NOT NULL')
                ->where('deleted_at IS NULL')
                ->get()->getResultArray();

            if (empty($types)) {
                CLI::write('  Tidak ada leave type ber-cap.', 'dark_gray');
                continue;
            }

            foreach ($types as $type) {
                $cap = (float) $type['carry_over_max_days'];

                // Karyawan aktif × balance > cap
                $rows = $db->table('leave_balances lb')
                    ->select('lb.id, lb.employee_id, lb.remaining, e.full_name, e.user_id, e.employee_number')
                    ->join('employees e', 'e.id = lb.employee_id')
                    ->where('lb.company_id', (int) $c['id'])
                    ->where('lb.leave_type_id', (int) $type['id'])
                    ->where('lb.period_year', $year)
                    ->where('lb.remaining >', $cap)
                    ->where('e.is_active', 1)
                    ->where('e.deleted_at IS NULL')
                    ->get()->getResultArray();

                foreach ($rows as $r) {
                    $remaining = (float) $r['remaining'];
                    $atRisk = round($remaining - $cap, 2);
                    $totalDaysAtRisk += $atRisk;

                    CLI::write(sprintf(
                        '  - %s | %s: sisa %.1f, cap %.1f → %.1f hari akan hangus 1 Jan',
                        $r['full_name'] ?? '?',
                        $type['name'],
                        $remaining,
                        $cap,
                        $atRisk
                    ), $atRisk > 5 ? 'red' : 'yellow');

                    if ($dryRun) continue;
                    if (empty($r['user_id'])) continue;

                    $this->notifyEmployee(
                        userId: (int) $r['user_id'],
                        companyId: (int) $c['id'],
                        employeeId: (int) $r['employee_id'],
                        employeeName: $r['full_name'] ?? '',
                        leaveTypeName: $type['name'],
                        remaining: $remaining,
                        cap: $cap,
                        atRisk: $atRisk,
                        year: $year,
                    );
                    $totalNotif++;
                }
            }
        }

        CLI::write("\n" . str_repeat('=', 70), 'green');
        CLI::write(sprintf(
            'Selesai. %d notifikasi dikirim, total %.2f hari berisiko hangus.',
            $totalNotif,
            $totalDaysAtRisk
        ), 'green');
        CLI::write(str_repeat('=', 70) . "\n", 'green');
    }

    /**
     * Kirim 1 notifikasi ke karyawan + audit log.
     */
    private function notifyEmployee(
        int $userId,
        int $companyId,
        int $employeeId,
        string $employeeName,
        string $leaveTypeName,
        float $remaining,
        float $cap,
        float $atRisk,
        int $year,
    ): void {
        $title = sprintf('Saldo cuti %s akan hangus 1 Januari', $leaveTypeName);
        $message = sprintf(
            'Halo %s, saldo %s Anda saat ini %.1f hari sementara batas carry-over '
            . 'ke tahun depan adalah %.1f hari. Sebanyak %.1f hari akan hangus '
            . 'pada 1 Januari %d. Silakan ajukan cuti sebelum akhir tahun.',
            $employeeName,
            $leaveTypeName,
            $remaining,
            $cap,
            $atRisk,
            $year + 1,
        );

        try {
            Services::notification()->send(
                $userId,
                $companyId,
                'leave.carry_over_warning',
                $title,
                $message,
                [
                    'employee_id' => $employeeId,
                    'leave_type'  => $leaveTypeName,
                    'remaining'   => $remaining,
                    'cap'         => $cap,
                    'at_risk'     => $atRisk,
                    'year'        => $year,
                ],
                '/leave-balance',
                true, // sertakan email karena penting & time-sensitive
            );
        } catch (\Throwable $e) {
            log_message('warning', '[NotifyCarryOverCap] notify failed: ' . $e->getMessage());
        }

        try {
            Services::audit()->forModule('LEAVE')->log(
                action: 'leave.carry_over_warning_sent',
                entity: 'employees',
                entityId: $employeeId,
                description: sprintf(
                    'Carry-over warning %s: %.1f hari akan hangus 1 Jan %d',
                    $leaveTypeName, $atRisk, $year + 1,
                ),
                severity: $atRisk > 5 ? 'WARNING' : 'NOTICE',
                metadata: [
                    'leave_type' => $leaveTypeName,
                    'remaining'  => $remaining,
                    'cap'        => $cap,
                    'at_risk'    => $atRisk,
                ],
            );
        } catch (\Throwable $e) {
            // audit failure tidak fatal
        }
    }
}