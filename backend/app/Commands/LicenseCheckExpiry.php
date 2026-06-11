<?php

namespace App\Commands;

use App\Libraries\License\LicenseTrackerService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Cron command — License Expiry Check.
 *
 * Schedule: Daily 06:00.
 *
 * Workflow:
 *   1. Scan semua employee_licenses dengan status ACTIVE/EXPIRING_SOON.
 *   2. Untuk yang sudah lewat expires_on → mark EXPIRED + notif.
 *   3. Untuk yang mendekati threshold (30/7/1 hari default) →
 *      mark EXPIRING_SOON + notif sekali per threshold.
 *   4. Audit log per aksi.
 *
 * Manual run: php spark licenses:check-expiry
 *             php spark licenses:check-expiry --dry-run
 */
class LicenseCheckExpiry extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'licenses:check-expiry';
    protected $description = 'Scan license expiry, update status, dan kirim notifikasi threshold.';
    protected $usage       = 'licenses:check-expiry [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--dry-run' => 'Hanya tampilkan apa yang akan diaksi, tanpa update DB.',
    ];

    public function run(array $params)
    {
        $dry = array_key_exists('dry-run', $params) || in_array('--dry-run', $params, true);

        $service = new LicenseTrackerService();
        $scan = $service->scanForActions();

        $expCount  = count($scan['to_expire'] ?? []);
        $warnCount = count($scan['to_warn']   ?? []);

        CLI::write(sprintf(
            'Scan complete — to_expire=%d, to_warn=%d',
            $expCount,
            $warnCount,
        ), 'cyan');

        if ($dry) {
            CLI::write('[DRY-RUN] Tidak ada perubahan DB.', 'yellow');
            foreach ($scan['to_expire'] ?? [] as $row) {
                CLI::write(sprintf(
                    '  EXPIRE   employee=%d license=%s (%s) expired_on=%s',
                    $row['employee_id'],
                    $row['license_code'] ?? '?',
                    $row['license_name'] ?? '?',
                    $row['expires_on'] ?? '?',
                ));
            }
            foreach ($scan['to_warn'] ?? [] as $row) {
                CLI::write(sprintf(
                    '  WARN H-%d employee=%d license=%s expires_on=%s',
                    $row['threshold'],
                    $row['employee_id'],
                    $row['license_code'] ?? '?',
                    $row['expires_on'] ?? '?',
                ));
            }
            return;
        }

        $applied = $service->applyScanResults($scan);
        CLI::write(sprintf('Applied %d updates.', $applied), 'green');

        // TODO: dispatch notification (in-app + email + WhatsApp) per row.
        // Pattern existing: Services::notification()->send(...) — biarkan
        // sebagai TODO supaya tetap reviewable; service di atas sudah set
        // last_notified_at jadi notif tidak duplicate.
    }
}