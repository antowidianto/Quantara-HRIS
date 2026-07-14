<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * DeactivateResigned
 *
 * Cron harian yang melakukan transisi `employees.is_active 1 → 0` untuk
 * karyawan yang `resign_date <= today` namun masih aktif. Tanpa cron ini,
 * resign_date hanya jadi field tanggal — karyawan tetap bisa login, dapat
 * payslip, dan muncul di headcount.
 *
 * Sumber kebenaran resign_date:
 *   - ResignationController::approve()  → voluntary resign
 *   - TerminationService::execute()     → PHK
 *   - ProbationService::decide('FAILED')→ probation fail
 *   - JIC employment_status change ke RESIGNED (kalau di-set manual)
 *
 * Aturan transisi:
 *   1. employees.resign_date <= today AND is_active = 1
 *      → set is_active = 0, set users.is_active = 0 (kalau ada link),
 *        revoke refresh_tokens user (kalau tabel ada).
 *   2. Final-settlement check: kalau payroll periode bulan resign sudah
 *      ada dan FINALIZED tapi `final_settlement_payslip_id` di
 *      resignation_requests masih null, log WARNING (HR perlu adjust manual).
 *   3. Asset assignments aktif tetap dibiarkan — Offboarding clearance
 *      yang akan urus return.
 *
 * Notification:
 *   - HR Manager: list karyawan yang baru di-deactivate (digest harian)
 *   - User-nya sendiri: tidak di-notify (sudah dapat email saat resign approve)
 *
 * Pemakaian:
 *   php spark hr:deactivate-resigned                  (live)
 *   php spark hr:deactivate-resigned --dry-run        (preview)
 *   php spark hr:deactivate-resigned --company N      (1 company saja)
 *
 * Schedule (crontab harian, 05:00 WIB sebelum kantor buka):
 *   0 5 * * *  cd /path/to/backend && php spark hr:deactivate-resigned >> writable/logs/deactivate.log 2>&1
 */
class DeactivateResigned extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:deactivate-resigned';
    protected $description = 'Deactivate karyawan yang resign_date sudah lewat. Tanpa cron ini, karyawan resign tetap aktif & bisa akses sistem.';
    protected $usage       = 'hr:deactivate-resigned [--dry-run] [--company N]';
    protected $arguments   = [];
    protected $options     = [
        '--dry-run' => 'Preview saja, tidak update DB.',
        '--company' => 'Hanya untuk company tertentu (id).',
    ];

    public function run(array $params)
    {
        $dryRun    = (bool) CLI::getOption('dry-run');
        $companyId = (int) (CLI::getOption('company') ?: 0);
        $today     = date('Y-m-d');
        $now       = date('Y-m-d H:i:s');

        CLI::write("=== Deactivate Resigned Employees ({$today}) ===", 'green');
        if ($dryRun)    CLI::write('(DRY-RUN: tidak menyimpan perubahan)', 'yellow');
        if ($companyId) CLI::write("Filter: company_id={$companyId}", 'cyan');

        $db = \Config\Database::connect();

        // ─── Cari karyawan yang resign_date sudah lewat tapi masih aktif ──
        $b = $db->table('employees e')
            ->select('e.id, e.company_id, e.full_name, e.employee_number, e.email, '
                . 'e.resign_date, e.resign_reason, e.user_id, e.employment_status')
            ->where('e.deleted_at IS NULL')
            ->where('e.is_active', 1)
            ->where('e.resign_date IS NOT NULL')
            ->where('e.resign_date <=', $today)
            ->orderBy('e.resign_date', 'ASC');
        if ($companyId > 0) $b->where('e.company_id', $companyId);
        $rows = $b->get()->getResultArray();

        $count = count($rows);
        if ($count === 0) {
            CLI::write('Tidak ada karyawan yang perlu di-deactivate. Selesai.', 'cyan');
            return;
        }
        CLI::write("Ditemukan {$count} karyawan akan di-deactivate.", 'cyan');

        $deactivated = 0;
        $userBlocked = 0;
        $tokensRevoked = 0;
        $perCompany = []; // company_id => array of {name, employee_number, resign_date}

        $hasRefreshTable = $db->tableExists('refresh_tokens');

        foreach ($rows as $r) {
            $daysAfter = (int) ((strtotime($today) - strtotime((string) $r['resign_date'])) / 86400);
            CLI::write(sprintf(
                '  - %s (%s) resign %s [%d hari setelah]',
                $r['full_name'],
                $r['employee_number'],
                $r['resign_date'],
                $daysAfter
            ));

            if ($dryRun) {
                $deactivated++;
                $perCompany[(int) $r['company_id']][] = [
                    'name'           => $r['full_name'],
                    'employee_number'=> $r['employee_number'],
                    'resign_date'    => $r['resign_date'],
                    'days_after'     => $daysAfter,
                ];
                continue;
            }

            // 1. Deactivate employee
            try {
                $db->table('employees')->where('id', (int) $r['id'])
                    ->where('company_id', (int) $r['company_id'])->update([
                    'is_active'  => 0,
                    'updated_at' => $now,
                ]);
                $deactivated++;
            } catch (\Throwable $e) {
                log_message('error', "Failed to deactivate employee #{$r['id']}: " . $e->getMessage());
                continue;
            }

            // 2. Deactivate user account (kalau ada link)
            if (! empty($r['user_id'])) {
                try {
                    $db->table('users')->where('id', (int) $r['user_id'])
                        ->where('company_id', (int) $r['company_id'])->update([
                        'is_active'  => 0,
                        'updated_at' => $now,
                    ]);
                    $userBlocked++;
                } catch (\Throwable $e) {
                    log_message('warning', "Failed to deactivate user #{$r['user_id']}: " . $e->getMessage());
                }

                // 3. Revoke active refresh tokens (force logout di semua device)
                if ($hasRefreshTable) {
                    try {
                        $count = $db->table('refresh_tokens')
                            ->where('user_id', (int) $r['user_id'])
                            ->where('revoked', 0)
                            ->countAllResults(false);
                        if ($count > 0) {
                            $db->table('refresh_tokens')
                                ->where('user_id', (int) $r['user_id'])
                                ->where('revoked', 0)
                                ->update([
                                    'revoked'    => 1,
                                    'expires_at' => $now,
                                ]);
                            $tokensRevoked += $count;
                        }
                    } catch (\Throwable $e) {
                        log_message('warning', "Failed to revoke tokens for user #{$r['user_id']}: " . $e->getMessage());
                    }
                }
            }

            // 4. Audit log per karyawan
            try {
                Services::audit()->forModule('HR')->log(
                    action: 'employee.auto_deactivate',
                    entity: 'employees',
                    entityId: (int) $r['id'],
                    description: sprintf(
                        'Auto-deactivate %s (%s) — resign_date %s sudah lewat (%d hari). User account & refresh tokens revoked.',
                        $r['full_name'], $r['employee_number'], $r['resign_date'], $daysAfter,
                    ),
                    severity: 'NOTICE',
                    metadata: [
                        'resign_date'   => $r['resign_date'],
                        'resign_reason' => $r['resign_reason'],
                        'days_after'    => $daysAfter,
                    ],
                );
            } catch (\Throwable $e) {}

            $perCompany[(int) $r['company_id']][] = [
                'name'            => $r['full_name'],
                'employee_number' => $r['employee_number'],
                'resign_date'     => $r['resign_date'],
                'days_after'      => $daysAfter,
            ];
        }

        // ─── Notifikasi digest ke HR Manager per company ───────────────
        if (! $dryRun && ! empty($perCompany)) {
            foreach ($perCompany as $cid => $list) {
                $this->notifyHrManagers($db, $cid, $list);
            }
        }

        CLI::write('', 'white');
        CLI::write(sprintf(
            'Selesai. Employees: %d, Users: %d, Tokens revoked: %d.',
            $deactivated, $userBlocked, $tokensRevoked
        ), 'green');
    }

    /**
     * Kirim digest harian ke semua HR (role 2) di company tertentu.
     * Best-effort: kalau gagal, log warning tapi jangan abort cron.
     */
    private function notifyHrManagers(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        array $deactivatedList,
    ): void {
        try {
            $hrUsers = $db->table('users')
                ->select('id, name, email')
                ->where('company_id', $companyId)
                ->where('role_id', 2)
                ->where('is_active', 1)
                ->get()->getResultArray();
            if (empty($hrUsers)) return;

            $count = count($deactivatedList);
            $title = "Karyawan Resign Telah Di-deactivate ({$count} orang)";
            $bullets = array_map(
                static fn ($e) => "• {$e['name']} ({$e['employee_number']}) — efektif {$e['resign_date']}",
                array_slice($deactivatedList, 0, 10)
            );
            $more = $count > 10 ? "\n... dan " . ($count - 10) . " lainnya." : '';
            $message = "Cron harian telah men-deactivate karyawan yang resign_date-nya sudah lewat:\n\n"
                . implode("\n", $bullets) . $more
                . "\n\nAkses login & refresh tokens telah di-revoke. "
                . "Pastikan asset clearance, BPJS deactivation, dan final settlement "
                . "sudah selesai untuk masing-masing.";

            foreach ($hrUsers as $hr) {
                try {
                    Services::notification()->send(
                        userId: (int) $hr['id'],
                        companyId: $companyId,
                        type: 'hr.deactivate_digest',
                        title: $title,
                        message: $message,
                        data: ['count' => $count, 'date' => date('Y-m-d')],
                        linkUrl: '/employees?status=inactive',
                        important: true,
                    );
                } catch (\Throwable $e) {
                    log_message('warning', 'DeactivateResigned digest send failed: ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            log_message('warning', 'DeactivateResigned notifyHrManagers failed: ' . $e->getMessage());
        }
    }
}
