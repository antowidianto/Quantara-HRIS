<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * Apply approved employee transfers yang sudah masuk effective_date.
 *
 * Cocok dijalankan harian via cron, mis. setiap pagi 06:00.
 *   php spark hr:apply-transfers
 *
 * Kerja:
 *   - Loop semua transfer status APPROVED dengan effective_date <= hari ini
 *   - Apply update ke records employees (branch/department/position/work_location)
 *   - Recompute manager_id chain & allowance (kalau branch berubah)
 *   - Status → IMPLEMENTED + audit log
 */
class ApplyTransfers extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:apply-transfers';
    protected $description = 'Apply mutasi karyawan yang APPROVED dan effective_date <= hari ini.';

    public function run(array $params)
    {
        CLI::write('Apply employee transfers due today…', 'cyan');

        try {
            $stats = Services::employeeTransfer()->applyDueTransfers();
        } catch (\Throwable $e) {
            CLI::error('Gagal menjalankan: ' . $e->getMessage());
            return EXIT_ERROR;
        }

        CLI::write("Processed: {$stats['processed']}, Succeeded: {$stats['succeeded']}, Failed: {$stats['failed']}", 'green');

        if ($stats['failed'] > 0) {
            CLI::write("\nErrors:", 'yellow');
            foreach ($stats['errors'] as $err) {
                CLI::write("  - Transfer #{$err['transfer_id']}: {$err['error']}", 'red');
            }
            return EXIT_ERROR;
        }
        return EXIT_SUCCESS;
    }
}