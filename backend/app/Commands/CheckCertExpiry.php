<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * `php spark hr:check-cert-expiry`
 *
 * Cron daily — cek sertifikat yang akan expire (60/30/14/7 hari) dan kirim
 * notifikasi ke karyawan ybs + atasan + HR. Juga auto-expire yang sudah lewat.
 *
 * Anti-spam: skip kalau last_alert_sent_at di range yang sama < 7 hari lalu.
 *
 * Flags:
 *   --dry-run               — print tanpa kirim notif/update
 *   --company=N             — batasi ke 1 company
 *   --days=60,30,14,7       — list bucket hari (override default)
 */
class CheckCertExpiry extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:check-cert-expiry';
    protected $description = 'Cek sertifikat yang akan/sudah expire dan kirim alert ke karyawan + HR.';
    protected $usage       = 'hr:check-cert-expiry [--dry-run] [--company=N] [--days=60,30,14,7]';

    public function run(array $params): void
    {
        $dryRun = isset($params['dry-run']);
        $onlyCompany = isset($params['company']) ? (int) $params['company'] : null;
        $daysList = isset($params['days'])
            ? array_map('intval', explode(',', (string) $params['days']))
            : [60, 30, 14, 7];

        $svc = Services::training();
        $db  = \Config\Database::connect();
        $notifier = Services::notification();

        // 1. Auto-expire yang sudah lewat
        $expired = $svc->expireOverdueCertificates($onlyCompany);
        CLI::write("Auto-expired certificates: {$expired}", $expired > 0 ? 'yellow' : 'dark_gray');

        // 2. Cycle bucket hari (gunakan threshold maksimum)
        $maxDays = max($daysList);
        $expiring = $svc->findExpiringCertificates($maxDays, $onlyCompany);

        $alerted = 0;
        $skipped = 0;

        foreach ($expiring as $cert) {
            $expiresDate = $cert['expires_date'];
            $daysLeft = (int) ((strtotime($expiresDate) - time()) / 86400);

            // Pakai bucket terdekat (paling kecil yg masih > daysLeft)
            $bucket = null;
            foreach ($daysList as $d) {
                if ($daysLeft <= $d) { $bucket = $d; break; }
            }
            if ($bucket === null) continue;

            // Anti-spam: kalau last_alert_sent_at dalam 7 hari, skip
            if (! empty($cert['last_alert_sent_at'])) {
                $sinceLast = (time() - strtotime($cert['last_alert_sent_at'])) / 86400;
                if ($sinceLast < 7) { $skipped++; continue; }
            }

            $title = "Sertifikat akan habis: {$cert['title']}";
            $body  = "Sertifikat \"{$cert['title']}\" Anda akan kedaluwarsa pada {$expiresDate} ({$daysLeft} hari lagi). Mohon segera renewal/perpanjang.";

            CLI::write(sprintf(
                "  - [#%d] %s | %s | exp %s (%dhari) → bucket %d",
                $cert['id'], $cert['employee_name'], $cert['title'], $expiresDate, $daysLeft, $bucket
            ));

            if (! $dryRun) {
                // Kirim ke user karyawan
                if (! empty($cert['user_id'])) {
                    try {
                        $notifier->send(
                            (int) $cert['user_id'],
                            (int) $cert['company_id'],
                            'certificate.expiring',
                            $title, $body,
                            ['certificate_id' => (int) $cert['id'], 'days_left' => $daysLeft],
                            '/certificates'
                        );
                    } catch (\Throwable $e) {}
                }

                // Kirim ke HR (role 1, 2)
                $hrUsers = $db->table('users')->where('company_id', $cert['company_id'])
                    ->whereIn('role_id', [1, 2])->where('is_active', 1)->get()->getResultArray();
                foreach ($hrUsers as $hr) {
                    try {
                        $notifier->send(
                            (int) $hr['id'], (int) $cert['company_id'],
                            'certificate.expiring_hr',
                            "Sertifikat {$cert['employee_name']} akan habis",
                            "{$cert['title']} — exp {$expiresDate} ({$daysLeft} hari lagi)",
                            ['certificate_id' => (int) $cert['id'], 'employee_id' => (int) $cert['employee_id']],
                            '/certificates'
                        );
                    } catch (\Throwable $e) {}
                }

                $db->table('certificates')->where('id', $cert['id'])->update([
                    'last_alert_sent_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $alerted++;
        }

        CLI::write("Alerted: {$alerted}, Skipped (anti-spam): {$skipped}",
            $alerted > 0 ? 'green' : 'dark_gray');

        if (! $dryRun && $alerted > 0) {
            try {
                Services::audit()->forModule('TRAINING')->log(
                    action: 'cert.expiry_check',
                    entity: 'certificates', entityId: 0,
                    description: "Cert expiry check: {$alerted} alerted, {$expired} auto-expired",
                );
            } catch (\Throwable $e) {}
        }
    }
}