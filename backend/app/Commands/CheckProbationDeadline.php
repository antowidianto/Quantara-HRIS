<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * CheckProbationDeadline
 *
 * Cron harian untuk monitoring SLA probation karyawan PKWTT (Sprint 2 / HIRE-1).
 *
 * Aturan UU 13/2003 Pasal 60:
 *   - Probation max 3 bulan
 *   - Lewat tanpa keputusan tertulis → otomatis jadi karyawan tetap
 *
 * Action:
 *   - H-14: WARNING reminder ke HR + supervisor langsung
 *   - H-7:  WARNING reminder kedua (lebih urgent)
 *   - Lewat hari ini tanpa keputusan: auto-PASS dengan audit CRITICAL
 *
 * Pemakaian:
 *   php spark hr:check-probation              (default: kirim notifikasi + auto-pass yang lewat)
 *   php spark hr:check-probation --dry-run    (hanya tampilkan, tidak save)
 *   php spark hr:check-probation --no-auto-pass (skip auto-pass, hanya reminder)
 *   php spark hr:check-probation --company 1
 *
 * Crontab harian:
 *   0 8 * * *  cd /path/to/backend && php spark hr:check-probation >> writable/logs/probation.log 2>&1
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item HIRE-1.
 */
class CheckProbationDeadline extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:check-probation';
    protected $description = 'Cek probation PKWTT yang akan/sudah berakhir & kirim reminder + auto-pass.';
    protected $usage       = 'hr:check-probation [--dry-run] [--no-auto-pass] [--company N]';
    protected $arguments   = [];
    protected $options     = [
        '--dry-run'      => 'Tampilkan hasil tanpa kirim notifikasi/audit/auto-pass.',
        '--no-auto-pass' => 'Skip auto-pass untuk yang lewat tanpa keputusan; hanya kirim reminder.',
        '--company'      => 'Filter ke 1 company (untuk testing).',
    ];

    public function run(array $params)
    {
        $dryRun = (bool) CLI::getOption('dry-run');
        $noAutoPass = (bool) CLI::getOption('no-auto-pass');
        $companyFilter = CLI::getOption('company');
        $companyId = $companyFilter !== null ? (int) $companyFilter : null;

        CLI::write('Memeriksa probation deadline (UU 13/2003 Pasal 60)...', 'green');
        if ($dryRun) CLI::write('(DRY-RUN)', 'yellow');
        if ($noAutoPass) CLI::write('(Auto-pass disabled)', 'yellow');

        $svc = Services::probation();
        $expiring = $svc->findExpiringSoon($companyId);
        $expired = $svc->findExpiredWithoutDecision($companyId);

        CLI::newLine();
        CLI::write('=== Probation Berakhir H-14 (Reminder) ===', 'magenta');
        $today = date('Y-m-d');
        $reminderCount = 0;
        foreach ($expiring as $e) {
            $daysLeft = (int) round((strtotime($e['probation_end_date']) - strtotime($today)) / 86400);
            $color = $daysLeft <= 7 ? 'red' : 'yellow';
            CLI::write(sprintf(
                '  %s (#%d) — probation berakhir %s (%d hari lagi). Dept: %s',
                $e['full_name'],
                $e['id'],
                $e['probation_end_date'],
                $daysLeft,
                $e['department_name'] ?? '-',
            ), $color);

            if (! $dryRun) {
                $this->sendReminder($e, $daysLeft);
                $reminderCount++;
            }
        }

        CLI::newLine();
        CLI::write('=== Probation Sudah Lewat Tanpa Keputusan (Auto-PASS UU) ===', 'magenta');
        $autoPassCount = 0;
        if ($expired) {
            foreach ($expired as $e) {
                $daysOverdue = (int) round((strtotime($today) - strtotime($e['probation_end_date'])) / 86400);
                CLI::write(sprintf(
                    '  [%s] %s (#%d) — probation berakhir %s (%d hari overdue)',
                    $noAutoPass ? 'WARNING' : 'AUTO-PASS',
                    $e['full_name'],
                    $e['id'],
                    $e['probation_end_date'],
                    $daysOverdue,
                ), 'red');
            }
            if (! $dryRun && ! $noAutoPass) {
                $autoPassCount = $svc->autoPassExpired($companyId);
            }
        }

        CLI::newLine();
        CLI::write(sprintf(
            'Selesai. Reminder: %d karyawan (H-14), Auto-pass: %d karyawan (UU 13/2003 Pasal 60).',
            $reminderCount,
            $autoPassCount,
        ), 'green');
    }

    /**
     * Kirim reminder ke HR Manager + supervisor langsung karyawan.
     */
    private function sendReminder(array $emp, int $daysLeft): void
    {
        $db = \Config\Database::connect();
        $companyId = (int) $emp['company_id'];

        $title = $daysLeft <= 7
            ? "Urgent: Probation {$emp['full_name']} berakhir dalam {$daysLeft} hari"
            : "Probation {$emp['full_name']} berakhir dalam {$daysLeft} hari";
        $message = sprintf(
            "Probation %s (%s) berakhir %s. Lakukan evaluasi & submit keputusan "
            . "(PASS/FAIL/EXTEND) lewat menu HR → Probation. Kalau lewat tanpa "
            . "keputusan, sistem otomatis set PASS sesuai UU 13/2003 Pasal 60.",
            $emp['full_name'],
            $emp['employee_number'] ?? '-',
            $emp['probation_end_date'],
        );

        $notifier = Services::notification();
        $recipients = [];

        // 1. Supervisor langsung — cek user_id-nya
        if (! empty($emp['supervisor_id'])) {
            $sup = $db->table('employees')->select('user_id')
                ->where('id', (int) $emp['supervisor_id'])->get()->getRowArray();
            if ($sup && ! empty($sup['user_id'])) {
                $recipients[] = (int) $sup['user_id'];
            }
        }

        // 2. HR Manager (role 2)
        $hrUsers = $db->table('users')
            ->select('id')
            ->where('company_id', $companyId)
            ->where('role_id', 2)
            ->where('is_active', 1)
            ->get()->getResultArray();
        foreach ($hrUsers as $u) {
            $recipients[] = (int) $u['id'];
        }

        $recipients = array_unique($recipients);
        foreach ($recipients as $userId) {
            try {
                $notifier->send(
                    $userId,
                    $companyId,
                    'probation.expiring',
                    $title,
                    $message,
                    [
                        'employee_id'        => (int) $emp['id'],
                        'days_left'          => $daysLeft,
                        'probation_end_date' => $emp['probation_end_date'],
                    ],
                    '/employees/' . $emp['id'],
                    $daysLeft <= 7, // email kalau urgent
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Probation reminder send failed: ' . $e->getMessage());
            }
        }

        try {
            Services::audit()->forModule('HR')->log(
                action: 'probation.reminder_sent',
                entity: 'employees',
                entityId: (int) $emp['id'],
                description: "Probation reminder sent untuk {$emp['full_name']} (H-{$daysLeft})",
                severity: $daysLeft <= 7 ? 'WARNING' : 'NOTICE',
                metadata: [
                    'days_left'  => $daysLeft,
                    'recipients' => count($recipients),
                ],
            );
        } catch (\Throwable $e) {}
    }
}