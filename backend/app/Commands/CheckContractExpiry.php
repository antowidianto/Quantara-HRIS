<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * CheckContractExpiry
 *
 * Memeriksa karyawan yang kontrak (PKWT) atau masa probationnya akan
 * berakhir dalam N hari ke depan, lalu mengirim notifikasi ke HR & manager.
 * Juga mengecek dokumen karyawan (KTP, SIM, kontrak) yang akan kadaluarsa.
 *
 * Pemakaian:
 *   php spark hr:check-expiry              (default 30 hari ke depan)
 *   php spark hr:check-expiry --days 14
 *   php spark hr:check-expiry --dry-run
 *
 * Tambahkan ke crontab harian, mis. tiap jam 07:00 WIB:
 *   0 7 * * *  cd /path/to/backend && php spark hr:check-expiry >> writable/logs/expiry.log 2>&1
 */
class CheckContractExpiry extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:check-expiry';
    protected $description = 'Cek kontrak/probation/dokumen yang akan berakhir dan kirim notifikasi.';
    protected $usage       = 'hr:check-expiry [--days N] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--days'    => 'Jumlah hari ke depan untuk dicek (default 30).',
        '--dry-run' => 'Tampilkan hasil tanpa menyimpan notifikasi.',
    ];

    public function run(array $params)
    {
        $days   = (int) (CLI::getOption('days') ?: 30);
        $dryRun = (bool) CLI::getOption('dry-run');
        $today  = date('Y-m-d');
        $until  = date('Y-m-d', strtotime("+{$days} days"));

        CLI::write("Memeriksa kontrak/dokumen yang berakhir antara {$today} s/d {$until}...", 'green');
        if ($dryRun) CLI::write('(DRY-RUN: tidak menyimpan notifikasi)', 'yellow');

        $db = \Config\Database::connect();

        // ─── Kontrak PKWT ────────────────────────────────────────────
        $expiringContracts = $db->table('employees e')
            ->select('e.id, e.full_name, e.employee_number, e.email, e.user_id, e.company_id, e.contract_end_date, e.employment_status')
            ->where('e.deleted_at IS NULL')
            ->where('e.is_active', 1)
            ->where('e.contract_end_date IS NOT NULL')
            ->where('e.contract_end_date >=', $today)
            ->where('e.contract_end_date <=', $until)
            ->whereIn('e.employment_status', ['PKWT', 'PROBATION', 'MAGANG'])
            ->orderBy('e.contract_end_date', 'ASC')
            ->get()
            ->getResultArray();

        $contractCount = 0;
        foreach ($expiringContracts as $emp) {
            $daysLeft = (int) ((strtotime($emp['contract_end_date']) - strtotime($today)) / 86400);
            CLI::write(sprintf(
                '  Kontrak: %s (%s) %s berakhir %s (%d hari)',
                $emp['full_name'],
                $emp['employee_number'],
                $emp['employment_status'],
                $emp['contract_end_date'],
                $daysLeft
            ));

            if (! $dryRun) {
                $this->notifyHr(
                    (int) $emp['company_id'],
                    'contract.expiring',
                    'Kontrak karyawan akan berakhir',
                    "Kontrak {$emp['employment_status']} {$emp['full_name']} ({$emp['employee_number']}) berakhir pada {$emp['contract_end_date']} ({$daysLeft} hari lagi)",
                    ['employee_id' => $emp['id'], 'days_left' => $daysLeft]
                );

                Services::audit()
                    ->forModule('EMPLOYEE')
                    ->withSource('CRON')
                    ->log(
                        action: 'employee.contract_expiring',
                        entity: 'employee',
                        entityId: (int) $emp['id'],
                        description: "Pengingat: kontrak {$emp['employment_status']} {$emp['full_name']} berakhir dalam {$daysLeft} hari",
                        severity: $daysLeft <= 7 ? 'WARNING' : 'NOTICE',
                        metadata: ['contract_end_date' => $emp['contract_end_date'], 'days_left' => $daysLeft]
                    );
            }
            $contractCount++;
        }

        // ─── Probation berakhir ──────────────────────────────────────
        $expiringProbations = $db->table('employees e')
            ->select('e.id, e.full_name, e.employee_number, e.company_id, e.probation_end_date')
            ->where('e.deleted_at IS NULL')
            ->where('e.is_active', 1)
            ->where('e.probation_end_date IS NOT NULL')
            ->where('e.probation_end_date >=', $today)
            ->where('e.probation_end_date <=', $until)
            ->orderBy('e.probation_end_date', 'ASC')
            ->get()
            ->getResultArray();

        $probationCount = 0;
        foreach ($expiringProbations as $emp) {
            $daysLeft = (int) ((strtotime($emp['probation_end_date']) - strtotime($today)) / 86400);
            CLI::write(sprintf(
                '  Probation: %s (%s) berakhir %s (%d hari)',
                $emp['full_name'],
                $emp['employee_number'],
                $emp['probation_end_date'],
                $daysLeft
            ));
            if (! $dryRun) {
                $this->notifyHr(
                    (int) $emp['company_id'],
                    'probation.ending',
                    'Masa probation karyawan berakhir',
                    "Masa probation {$emp['full_name']} ({$emp['employee_number']}) berakhir pada {$emp['probation_end_date']}. Mohon segera melakukan evaluasi.",
                    ['employee_id' => $emp['id'], 'days_left' => $daysLeft]
                );
            }
            $probationCount++;
        }

        // ─── Dokumen karyawan akan expired ───────────────────────────
        $expiringDocs = $db->table('employee_documents ed')
            ->select('ed.id, ed.title, ed.doc_type, ed.expires_at, ed.company_id, ed.employee_id, e.full_name, e.employee_number')
            ->join('employees e', 'e.id = ed.employee_id')
            ->where('ed.deleted_at IS NULL')
            ->where('ed.expires_at IS NOT NULL')
            ->where('ed.expires_at >=', $today)
            ->where('ed.expires_at <=', $until)
            ->orderBy('ed.expires_at', 'ASC')
            ->get()
            ->getResultArray();

        $docCount = 0;
        foreach ($expiringDocs as $doc) {
            $daysLeft = (int) ((strtotime($doc['expires_at']) - strtotime($today)) / 86400);
            CLI::write(sprintf(
                '  Dokumen: %s (%s) milik %s berakhir %s (%d hari)',
                $doc['title'],
                $doc['doc_type'],
                $doc['full_name'],
                $doc['expires_at'],
                $daysLeft
            ));
            if (! $dryRun) {
                $this->notifyHr(
                    (int) $doc['company_id'],
                    'document.expiring',
                    'Dokumen karyawan akan kadaluarsa',
                    "Dokumen '{$doc['title']}' milik {$doc['full_name']} kadaluarsa pada {$doc['expires_at']} ({$daysLeft} hari)",
                    ['document_id' => $doc['id'], 'employee_id' => $doc['employee_id']]
                );
            }
            $docCount++;
        }

        CLI::newLine();
        CLI::write("Selesai. Kontrak: {$contractCount}, Probation: {$probationCount}, Dokumen: {$docCount}", 'green');
    }

    private function notifyHr(int $companyId, string $type, string $title, string $message, array $data = []): void
    {
        $db = \Config\Database::connect();
        $hrUsers = $db->table('users')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->whereIn('role_id', [1, 2, 3])  // super admin, admin, HR
            ->get()
            ->getResultArray();

        $notifier = Services::notification();
        foreach ($hrUsers as $u) {
            $notifier->send(
                (int) $u['id'],
                $companyId,
                $type,
                $title,
                $message,
                $data,
                '/employees/' . ($data['employee_id'] ?? ''),
                false   // tidak kirim email tiap hari supaya tidak spam
            );
        }
    }
}