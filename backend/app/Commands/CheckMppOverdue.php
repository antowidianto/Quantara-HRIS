<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * CheckMppOverdue — daily scan MPP yang melewati / mendekati
 * `vacancy_started_at`, kirim reminder ke HRD dan eskalasi ke Direktur.
 *
 * Tiga kategori:
 *
 *   1. **Upcoming** — `vacancy_started_at` BETWEEN today AND today+30:
 *      Reminder ke HRD bahwa posisi akan kosong dalam waktu dekat.
 *      Anti-spam: skip kalau `overdue_warning_sent_at` < 7 hari lalu.
 *
 *   2. **Overdue** — `vacancy_started_at` < today, status masih
 *      DRAFT/SUBMITTED/APPROVED/HIRING (belum FILLED):
 *      Kirim notifikasi setiap kali cron jalan (anti-spam: cek
 *      `overdue_escalated_at` < 7 hari lalu untuk eskalasi).
 *      Eskalasi ke user dengan permission `mpp.approve` dan
 *      `mpp.fulfill` (HRD + Direktur + TA Lead).
 *
 *   3. **Audit log** — semua aksi dicatat di module=RECRUITMENT
 *      action=mpp.overdue_warning / mpp.overdue_escalated.
 *
 * Cron yang disarankan (jalan setiap pagi 07:00):
 *
 *   0 7 * * *  cd /var/www/hris/backend && php spark hr:check-mpp-overdue \
 *              >> writable/logs/mpp-overdue.log 2>&1
 *
 * Usage:
 *   php spark hr:check-mpp-overdue              # all companies
 *   php spark hr:check-mpp-overdue --company 1
 *   php spark hr:check-mpp-overdue --dry-run    # preview tanpa kirim notif
 *   php spark hr:check-mpp-overdue --days 30    # threshold upcoming
 */
class CheckMppOverdue extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:check-mpp-overdue';
    protected $description = 'Reminder + eskalasi MPP yang mendekati / melewati vacancy_started_at.';
    protected $usage       = 'hr:check-mpp-overdue [--company ID] [--days 30] [--dry-run]';
    protected $options     = [
        '--company' => 'Hanya proses 1 company (default: semua company aktif).',
        '--days'    => 'Threshold upcoming (default: 30 hari ke depan).',
        '--dry-run' => 'Hitung saja, tidak kirim notif & tidak update timestamp.',
    ];

    public function run(array $params)
    {
        $companyOpt = (int) (CLI::getOption('company') ?: 0);
        $days       = max(1, min(180, (int) (CLI::getOption('days') ?: 30)));
        $dryRun     = (bool) CLI::getOption('dry-run');

        $db = \Config\Database::connect();
        $companies = $db->table('companies')
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->when($companyOpt > 0, fn ($q) => $q->where('id', $companyOpt))
            ->get()->getResultArray();

        if (empty($companies)) {
            CLI::write('Tidak ada company yang cocok.', 'yellow');
            return EXIT_SUCCESS;
        }

        $svc = Services::manpower();
        $totalUpcoming = 0;
        $totalOverdue  = 0;
        $totalNotified = 0;
        $totalEscalated = 0;

        CLI::write(str_repeat('=', 70), 'green');
        CLI::write('MPP Overdue Check' . ($dryRun ? ' (DRY-RUN)' : ''), 'green');
        CLI::write(str_repeat('=', 70), 'green');

        foreach ($companies as $c) {
            $companyId = (int) $c['id'];
            CLI::write("\n▶ {$c['name']} (#{$companyId})", 'cyan');

            // Upcoming (warning H-N)
            $upcoming = $svc->aboutToBeOverdueRequests($companyId, $days);
            $totalUpcoming += count($upcoming);
            if (! empty($upcoming)) {
                CLI::write(sprintf('  · %d MPP akan overdue dalam ≤ %d hari', count($upcoming), $days));
                foreach ($upcoming as $mpp) {
                    $daysToVacancy = max(0, (int) round(
                        (strtotime($mpp['vacancy_started_at']) - strtotime(date('Y-m-d'))) / 86400
                    ));
                    CLI::write(sprintf(
                        "    - %s | %s | dept: %s | H-%d (vacancy %s)",
                        $mpp['reference_number'],
                        $mpp['proposed_title'],
                        $mpp['department_name'] ?? '-',
                        $daysToVacancy,
                        $mpp['vacancy_started_at']
                    ));
                    if (! $dryRun) {
                        $sent = $this->notifyHrd($companyId, $mpp, 'warning', $daysToVacancy);
                        if ($sent > 0) {
                            $svc->markOverdueWarningSent((int) $mpp['id']);
                            $totalNotified += $sent;
                        }
                    }
                }
            } else {
                CLI::write('  · Tidak ada upcoming overdue.', 'dark_gray');
            }

            // Overdue (sudah lewat — eskalasi)
            $overdue = $svc->overdueRequests($companyId, true);
            $totalOverdue += count($overdue);
            if (! empty($overdue)) {
                CLI::write(sprintf('  · %d MPP overdue (perlu eskalasi)', count($overdue)), 'red');
                foreach ($overdue as $mpp) {
                    CLI::write(sprintf(
                        "    - %s | %s | dept: %s | overdue %d hari | status %s | %d/%d hired",
                        $mpp['reference_number'],
                        $mpp['proposed_title'],
                        $mpp['department_name'] ?? '-',
                        (int) $mpp['days_overdue'],
                        $mpp['status'],
                        (int) $mpp['hired_count'],
                        (int) $mpp['headcount']
                    ), 'red');
                    if (! $dryRun) {
                        $sent = $this->notifyHrd($companyId, $mpp, 'escalation', (int) $mpp['days_overdue']);
                        if ($sent > 0) {
                            $svc->markOverdueEscalated((int) $mpp['id']);
                            $totalEscalated += $sent;
                        }
                    }
                }

                // Audit log per company kalau ada eskalasi
                if (! $dryRun) {
                    try {
                        Services::audit()
                            ->forModule('RECRUITMENT')
                            ->log(
                                action: 'mpp.overdue_escalated',
                                entity: 'company',
                                entityId: $companyId,
                                after: [
                                    'overdue_count' => count($overdue),
                                    'oldest_days'   => (int) $overdue[0]['days_overdue'],
                                ],
                                description: "Eskalasi MPP overdue: " . count($overdue)
                                    . " MPP, terlama " . (int) $overdue[0]['days_overdue'] . " hari",
                                severity: 'WARNING'
                            );
                    } catch (\Throwable $e) {
                        CLI::write('  · Audit log gagal: ' . $e->getMessage(), 'yellow');
                    }
                }
            } else {
                CLI::write('  · Tidak ada MPP overdue.', 'dark_gray');
            }
        }

        CLI::write("\n" . str_repeat('=', 70), 'green');
        CLI::write(sprintf(
            "Total: %d company, upcoming=%d, overdue=%d, %d notif sent, %d eskalasi sent",
            count($companies), $totalUpcoming, $totalOverdue, $totalNotified, $totalEscalated
        ), $totalOverdue > 0 ? 'red' : 'green');
        CLI::write(str_repeat('=', 70) . "\n");

        return EXIT_SUCCESS;
    }

    /**
     * Kirim notif ke HRD + relevant approvers untuk satu MPP.
     *
     * Untuk `warning`: kirim ke requester + user dengan permission
     * `mpp.approve` di company tsb.
     * Untuk `escalation`: tambahkan user dengan `mpp.fulfill` (TA Lead)
     * dan user dengan `recruitment.manage`.
     *
     * @return int  Jumlah notif yang berhasil dikirim
     */
    private function notifyHrd(int $companyId, array $mpp, string $kind, int $daysDelta): int
    {
        $db = \Config\Database::connect();
        // Cari user target — semua user company yang punya permission relevan
        $perms = $kind === 'escalation'
            ? ['mpp.approve', 'mpp.fulfill', 'recruitment.manage']
            : ['mpp.approve'];

        $userIds = $db->table('users u')
            ->select('DISTINCT u.id')
            ->join('role_permissions rp', 'rp.role_id = u.role_id')
            ->join('permissions p',       'p.id = rp.permission_id')
            ->where('u.company_id', $companyId)
            ->where('u.is_active', 1)
            ->where('u.deleted_at IS NULL')
            ->whereIn('p.slug', $perms)
            ->get()->getResultArray();
        $userIds = array_column($userIds, 'id');

        // Tambahkan requester (line manager) supaya dia juga aware
        if (! empty($mpp['requested_by_user_id'])) {
            $userIds[] = (int) $mpp['requested_by_user_id'];
        }
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (empty($userIds)) return 0;

        $title = $kind === 'escalation'
            ? "⚠️ MPP OVERDUE: {$mpp['reference_number']}"
            : "MPP Akan Overdue: {$mpp['reference_number']}";

        $remainingHc = max(0, (int) $mpp['headcount'] - (int) $mpp['hired_count']);
        $deptStr = ! empty($mpp['department_name']) ? " ({$mpp['department_name']})" : '';
        $resignStr = ! empty($mpp['resigner_name']) ? " — pengganti {$mpp['resigner_name']}" : '';

        $message = $kind === 'escalation'
            ? sprintf(
                "MPP %s — %s%s%s sudah %d hari lewat dari target tanggal kosong (%s) "
                . "tapi masih %s. Sisa %d posisi belum terisi. "
                . "Mohon segera fulfill atau review pipeline.",
                $mpp['reference_number'], $mpp['proposed_title'], $deptStr, $resignStr,
                $daysDelta, $mpp['vacancy_started_at'],
                $mpp['status'], $remainingHc
            )
            : sprintf(
                "MPP %s — %s%s%s akan butuh on-board dalam %d hari (%s), "
                . "status masih %s. Sisa %d posisi belum terisi. "
                . "Mohon dipercepat fulfillment-nya.",
                $mpp['reference_number'], $mpp['proposed_title'], $deptStr, $resignStr,
                $daysDelta, $mpp['vacancy_started_at'],
                $mpp['status'], $remainingHc
            );

        $type = $kind === 'escalation' ? 'mpp.overdue' : 'mpp.upcoming';
        $sent = 0;
        foreach ($userIds as $uid) {
            try {
                Services::notification()->send(
                    userId:    (int) $uid,
                    companyId: $companyId,
                    type:      $type,
                    title:     $title,
                    message:   $message,
                    data:      [
                        'manpower_request_id' => (int) $mpp['id'],
                        'reference_number'    => $mpp['reference_number'],
                        'days_delta'          => $daysDelta,
                        'kind'                => $kind,
                    ],
                    linkUrl:   '/recruitment/mpp',
                );
                $sent++;
            } catch (\Throwable $e) {
                log_message('warning', "MPP overdue notif failed user #{$uid}: " . $e->getMessage());
            }
        }
        return $sent;
    }
}