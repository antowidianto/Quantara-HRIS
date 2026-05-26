<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * CheckOfferExpiry — cron harian untuk auto-expire offer yang lewat
 * deadline (Sprint 2 / REC-1 polish).
 *
 * Aturan:
 *   - Offer SENT dengan response_deadline < today → set EXPIRED
 *   - Notifikasi HR dikirim otomatis dari `OfferService::expire()`
 *   - Cron jangan jalankan di jam kerja awal (kandidat mungkin baru
 *     respon di pagi terakhir) — saran: jalan jam 23:50 daily
 *
 * Pemakaian:
 *   php spark hr:check-offer-expiry              (default: expire semua yg lewat)
 *   php spark hr:check-offer-expiry --dry-run    (hanya tampilkan, tidak save)
 *   php spark hr:check-offer-expiry --company N  (filter ke 1 company)
 *
 * Crontab harian:
 *   50 23 * * *  cd /path/to/backend && php spark hr:check-offer-expiry >> writable/logs/offer-expiry.log 2>&1
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item REC-1.
 */
class CheckOfferExpiry extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:check-offer-expiry';
    protected $description = 'Auto-expire candidate offers yang sudah lewat response_deadline.';
    protected $usage       = 'hr:check-offer-expiry [--dry-run] [--company N]';
    protected $arguments   = [];
    protected $options     = [
        '--dry-run' => 'Tampilkan offer yang akan di-expire tanpa save.',
        '--company' => 'Filter ke 1 company (untuk testing).',
    ];

    public function run(array $params)
    {
        $dryRun        = (bool) CLI::getOption('dry-run');
        $companyFilter = CLI::getOption('company');
        $companyId     = $companyFilter !== null ? (int) $companyFilter : null;

        CLI::write('Memeriksa offer yang sudah lewat deadline...', 'green');
        if ($dryRun) CLI::write('(DRY-RUN)', 'yellow');

        $svc       = Services::offer();
        $expiredIds = $svc->findExpiredOffers($companyId);

        if (empty($expiredIds)) {
            CLI::newLine();
            CLI::write('Tidak ada offer yang perlu di-expire. Selesai.', 'green');
            return;
        }

        // Sekalian fetch detail untuk display
        $db = \Config\Database::connect();
        $rows = $db->table('candidate_offers o')
            ->select('o.id, o.offer_number, o.response_deadline, o.basic_salary, '
                . 'o.company_id, c.full_name AS candidate_name')
            ->join('candidates c', 'c.id = o.candidate_id', 'left')
            ->whereIn('o.id', $expiredIds)
            ->get()->getResultArray();

        CLI::newLine();
        CLI::write('=== Offer yang akan di-expire ===', 'magenta');
        $today = date('Y-m-d');
        foreach ($rows as $r) {
            $daysOverdue = (int) round(
                (strtotime($today) - strtotime((string) $r['response_deadline'])) / 86400
            );
            CLI::write(sprintf(
                '  %s — %s (deadline %s, %d hari overdue, salary Rp %s)',
                $r['offer_number'],
                $r['candidate_name'] ?? '#?',
                $r['response_deadline'],
                $daysOverdue,
                number_format((float) $r['basic_salary'], 0, ',', '.'),
            ), 'red');
        }

        $count = 0;
        if (! $dryRun) {
            foreach ($expiredIds as $offerId) {
                try {
                    $svc->expire($offerId, 'cron:check-offer-expiry');
                    $count++;
                } catch (\Throwable $e) {
                    CLI::write("  ! Gagal expire offer #{$offerId}: " . $e->getMessage(), 'red');
                }
            }
        }

        CLI::newLine();
        CLI::write(sprintf(
            'Selesai. %s %d offer.',
            $dryRun ? 'Akan expire' : 'Ter-expire',
            $dryRun ? count($expiredIds) : $count,
        ), 'green');
    }
}