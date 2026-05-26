<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * CheckBpjsCompliance
 *
 * Cron harian untuk monitoring SLA BPJS:
 *   - HIRE-4: Karyawan baru wajib didaftarkan ke BPJS Kesehatan & TK
 *     dalam 30 hari sejak join_date (PP 86/2013 + UU SJSN 40/2004).
 *     Telat = denda 0,1% per hari dari iuran yang seharusnya dibayar
 *     (PP 86/2013 Pasal 20).
 *
 *   - OFF-6: Karyawan resign wajib di-deactivate dari BPJS Kes & TK
 *     dalam 7 hari sejak resign_date (Permenkes 6/2023 + Perdir BPJS-TK).
 *     Telat = karyawan terus dipotong iuran walau sudah keluar →
 *     keluhan + sengketa.
 *
 * Pemakaian:
 *   php spark hr:check-bpjs              (default kirim notifikasi + audit)
 *   php spark hr:check-bpjs --dry-run    (hanya tampilkan, tidak save)
 *   php spark hr:check-bpjs --company 1  (filter ke 1 company saja)
 *
 * Crontab harian, mis. tiap pagi 07:30 WIB (sebelum HR mulai kerja):
 *   30 7 * * *  cd /path/to/backend && php spark hr:check-bpjs >> writable/logs/bpjs.log 2>&1
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item HIRE-4 dan OFF-6.
 */
class CheckBpjsCompliance extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:check-bpjs';
    protected $description = 'Cek BPJS register/deactivate yang melewati SLA & kirim escalation ke HR Manager.';
    protected $usage       = 'hr:check-bpjs [--dry-run] [--company N]';
    protected $arguments   = [];
    protected $options     = [
        '--dry-run' => 'Tampilkan hasil tanpa kirim notifikasi/audit.',
        '--company' => 'Filter ke 1 company (untuk testing). Default: semua company.',
    ];

    public function run(array $params)
    {
        $dryRun = (bool) CLI::getOption('dry-run');
        $companyFilter = CLI::getOption('company');
        $companyId = $companyFilter !== null ? (int) $companyFilter : null;

        CLI::write('Memeriksa compliance BPJS (Pendaftaran H+30 / Deaktivasi H+7)...', 'green');
        if ($dryRun) CLI::write('(DRY-RUN: tidak menyimpan apa pun)', 'yellow');
        if ($companyId) CLI::write("Filter company: #{$companyId}", 'cyan');

        $svc = Services::offboarding();
        $registerOverdue   = $svc->findBpjsRegisterOverdue($companyId);
        $deactivateOverdue = $svc->findBpjsDeactivateOverdue($companyId);

        CLI::newLine();
        CLI::write('=== Pendaftaran BPJS overdue (HIRE-4) ===', 'magenta');
        $countRegister = $this->processRegisterOverdue($registerOverdue, $dryRun);

        CLI::newLine();
        CLI::write('=== Deaktivasi BPJS overdue (OFF-6) ===', 'magenta');
        $countDeactivate = $this->processDeactivateOverdue($deactivateOverdue, $dryRun);

        CLI::newLine();
        CLI::write(
            sprintf(
                'Selesai. Pendaftaran overdue: %d (CRITICAL: %d), Deaktivasi overdue: %d (CRITICAL: %d).',
                $countRegister['total'], $countRegister['critical'],
                $countDeactivate['total'], $countDeactivate['critical'],
            ),
            'green',
        );
    }

    /**
     * Iterasi register overdue. Severity:
     *   - WARNING: H+25 sampai H+29 (reminder 5 hari sebelum SLA habis)
     *   - CRITICAL: H+30+ (SLA UU SJSN sudah lewat → audit log)
     */
    private function processRegisterOverdue(array $rows, bool $dryRun): array
    {
        $total = 0; $critical = 0;
        foreach ($rows as $r) {
            $total++;
            $isCritical = $r['severity'] === 'CRITICAL';
            if ($isCritical) $critical++;

            $color = $isCritical ? 'red' : 'yellow';
            CLI::write(sprintf(
                '  [%s] %s (#%d) — join %s, %d hari sejak masuk, BPJS_REGISTER masih PENDING.',
                $r['severity'],
                $r['employee_name'],
                $r['employee_id'],
                $r['join_date'],
                $r['days_since_join'],
            ), $color);

            if ($dryRun) continue;

            // Notifikasi HR Manager + audit
            $this->escalateRegister($r);
        }
        return ['total' => $total, 'critical' => $critical];
    }

    /**
     * Iterasi deactivate overdue. Severity:
     *   - WARNING: 1-6 hari overdue (reminder)
     *   - CRITICAL: 7+ hari overdue (SLA H+7 sudah lewat 1 minggu)
     */
    private function processDeactivateOverdue(array $rows, bool $dryRun): array
    {
        $total = 0; $critical = 0;
        foreach ($rows as $r) {
            $total++;
            $isCritical = $r['severity'] === 'CRITICAL';
            if ($isCritical) $critical++;

            $color = $isCritical ? 'red' : 'yellow';
            CLI::write(sprintf(
                '  [%s] %s (resign %s) — %d hari overdue dari batas H+7.',
                $r['severity'],
                $r['employee_name'],
                $r['resign_date'],
                $r['days_overdue'],
            ), $color);

            if ($dryRun) continue;

            $this->escalateDeactivate($r);
        }
        return ['total' => $total, 'critical' => $critical];
    }

    /**
     * Kirim notifikasi + audit untuk satu register-overdue case.
     */
    private function escalateRegister(array $r): void
    {
        $db = \Config\Database::connect();
        $emp = $db->table('employees')
            ->select('id, company_id, employee_number, full_name')
            ->where('id', (int) $r['employee_id'])->get()->getRowArray();
        if (! $emp) return;

        $companyId = (int) $emp['company_id'];
        $title = $r['severity'] === 'CRITICAL'
            ? 'CRITICAL: BPJS belum didaftarkan, sudah lewat SLA 30 hari'
            : 'BPJS belum didaftarkan untuk karyawan baru';
        $message = sprintf(
            "Karyawan %s (%s) sudah %d hari sejak join (%s) — pendaftaran BPJS Kesehatan & TK belum selesai. "
            . "Wajib dilakukan ≤ H+30 (PP 86/2013). %s",
            $emp['full_name'],
            $emp['employee_number'],
            $r['days_since_join'],
            $r['join_date'],
            $r['severity'] === 'CRITICAL'
                ? 'SLA SUDAH LEWAT — risiko denda 0,1%/hari dari iuran tertunggak.'
                : 'Sisa waktu sebelum SLA habis: ' . max(0, 30 - $r['days_since_join']) . ' hari.',
        );

        // Notifikasi ke HR Manager (role_id 2) di company
        $hrUsers = $db->table('users')
            ->select('id')
            ->where('company_id', $companyId)
            ->where('role_id', 2)
            ->where('is_active', 1)
            ->get()->getResultArray();
        $notifier = Services::notification();
        foreach ($hrUsers as $u) {
            try {
                $notifier->send(
                    (int) $u['id'],
                    $companyId,
                    'bpjs.register_overdue',
                    $title,
                    $message,
                    [
                        'employee_id'    => (int) $r['employee_id'],
                        'days_since_join'=> $r['days_since_join'],
                        'severity'       => $r['severity'],
                    ],
                    '/hr/onboarding-board',
                    $r['severity'] === 'CRITICAL', // email kalau CRITICAL
                );
            } catch (\Throwable $e) {
                log_message('warning', 'BPJS reminder send failed: ' . $e->getMessage());
            }
        }

        // Audit dengan severity sesuai aturan
        try {
            Services::audit()->forModule('ONBOARDING')->log(
                action: 'bpjs.register_overdue',
                entity: 'employees',
                entityId: (int) $r['employee_id'],
                description: "BPJS register overdue: {$emp['full_name']} ({$r['days_since_join']} hari sejak join)",
                severity: $r['severity'],
                metadata: [
                    'days_since_join' => $r['days_since_join'],
                    'join_date'       => $r['join_date'],
                ],
            );
        } catch (\Throwable $e) {}
    }

    /**
     * Kirim notifikasi + audit untuk satu deactivate-overdue case.
     */
    private function escalateDeactivate(array $r): void
    {
        $db = \Config\Database::connect();
        $resign = $db->table('resignation_requests')
            ->select('id, company_id, employee_id')
            ->where('id', (int) $r['resignation_id'])->get()->getRowArray();
        if (! $resign) return;

        $companyId = (int) $resign['company_id'];
        $title = $r['severity'] === 'CRITICAL'
            ? 'CRITICAL: BPJS belum di-deactivate, sudah > 7 hari overdue'
            : 'BPJS belum di-deactivate untuk karyawan resign';
        $message = sprintf(
            "Karyawan %s sudah resign (%s) — BPJS Kesehatan & Ketenagakerjaan belum di-deactivate. "
            . "%d hari overdue dari batas H+7. Submit deactivate via e-Dabu (Kes) & SiPP (TK), "
            . "lalu tandai status di modul Offboarding.",
            $r['employee_name'],
            $r['resign_date'],
            $r['days_overdue'],
        );

        $hrUsers = $db->table('users')
            ->select('id')
            ->where('company_id', $companyId)
            ->where('role_id', 2)
            ->where('is_active', 1)
            ->get()->getResultArray();
        $notifier = Services::notification();
        foreach ($hrUsers as $u) {
            try {
                $notifier->send(
                    (int) $u['id'],
                    $companyId,
                    'bpjs.deactivate_overdue',
                    $title,
                    $message,
                    [
                        'resignation_id' => (int) $r['resignation_id'],
                        'employee_id'    => (int) $r['employee_id'],
                        'days_overdue'   => $r['days_overdue'],
                        'severity'       => $r['severity'],
                    ],
                    '/hr/resignation-review',
                    $r['severity'] === 'CRITICAL',
                );
            } catch (\Throwable $e) {
                log_message('warning', 'BPJS deactivate reminder send failed: ' . $e->getMessage());
            }
        }

        try {
            Services::audit()->forModule('OFFBOARDING')->log(
                action: 'bpjs.deactivate_overdue',
                entity: 'resignation_requests',
                entityId: (int) $r['resignation_id'],
                description: "BPJS deactivate overdue: {$r['employee_name']} ({$r['days_overdue']} hari overdue)",
                severity: $r['severity'],
                metadata: [
                    'days_overdue' => $r['days_overdue'],
                    'resign_date'  => $r['resign_date'],
                ],
            );
        } catch (\Throwable $e) {}
    }
}