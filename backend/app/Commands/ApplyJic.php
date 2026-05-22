<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * ApplyJic — apply Job Information Change yang sudah APPROVED dan
 * jatuh tempo ke records (employees + employee_contracts + allowances).
 *
 * Pemakaian:
 *   php spark hr:apply-jic                  # apply semua yang due
 *   php spark hr:apply-jic --dry-run        # preview tanpa eksekusi
 *   php spark hr:apply-jic --company 1
 *   php spark hr:apply-jic --id 42          # paksa apply 1 JIC tertentu
 *
 * Crontab harian (00:30 WIB supaya berlaku awal hari kerja):
 *   30 0 * * *  cd /path/to/backend && php spark hr:apply-jic >> writable/logs/jic.log 2>&1
 */
class ApplyJic extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:apply-jic';
    protected $description = 'Apply JIC yang sudah APPROVED dan effective_date <= hari ini.';
    protected $usage       = 'hr:apply-jic [--dry-run] [--company N] [--id N]';
    protected $arguments   = [];
    protected $options     = [
        '--dry-run' => 'Tampilkan kandidat tanpa apply.',
        '--company' => 'Batasi ke 1 company tertentu.',
        '--id'      => 'Apply JIC dengan id tertentu (abaikan effective_date check).',
    ];

    public function run(array $params)
    {
        $dryRun    = (bool) CLI::getOption('dry-run');
        $companyId = CLI::getOption('company') !== null ? (int) CLI::getOption('company') : null;
        $forceId   = CLI::getOption('id') !== null ? (int) CLI::getOption('id') : null;

        $svc = new \App\Libraries\JicService();
        $db  = \Config\Database::connect();

        if ($forceId) {
            $rows = $db->table('job_information_changes')
                ->where('id', $forceId)
                ->where('deleted_at IS NULL')->get()->getResultArray();
        } else {
            $rows = $svc->findDueForImplementation($companyId);
        }

        if (empty($rows)) {
            CLI::write('Tidak ada JIC yang perlu di-apply.', 'yellow');
            return;
        }

        CLI::write(sprintf('Ditemukan %d JIC kandidat untuk apply:', count($rows)), 'cyan');
        foreach ($rows as $r) {
            $line = sprintf(
                '  #%d %s — emp #%d — %s — effective %s — status %s',
                $r['id'], $r['jic_number'] ?? '-', (int) $r['employee_id'],
                $r['change_type'], $r['effective_date'], $r['status'],
            );
            CLI::write($line);
        }

        if ($dryRun) {
            CLI::write('--dry-run aktif. Tidak ada perubahan disimpan.', 'yellow');
            return;
        }

        $applied = 0; $failed = 0;
        foreach ($rows as $r) {
            // Saat --id dipakai, force apply meski belum APPROVED tidak dilakukan;
            // service akan refuse kalau status bukan APPROVED.
            $result = $svc->apply((int) $r['id']);
            if ($result['success']) {
                $applied++;
                CLI::write(sprintf('  ✔ JIC #%d: %s', $r['id'], $result['message']), 'green');
            } else {
                $failed++;
                CLI::write(sprintf('  ✖ JIC #%d: %s', $r['id'], $result['message']), 'red');
            }
        }

        CLI::newLine();
        CLI::write(sprintf('Selesai. %d ter-apply, %d gagal.', $applied, $failed),
            $failed > 0 ? 'yellow' : 'green');
    }
}