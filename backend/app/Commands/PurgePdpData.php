<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class PurgePdpData extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:pdp-data-purge';
    protected $description = 'Anonymize PII data for employees resigned > X years ago (UU PDP compliance)';
    protected $usage       = 'hr:pdp-data-purge [--years 10] [--dry-run]';

    public function run(array $params)
    {
        $db = \Config\Database::connect();
        
        // Batas retensi umum dokumen ketenagakerjaan di Indonesia adalah 10 tahun
        $years = (int) ($params['years'] ?? CLI::getOption('years') ?? 10);
        $isDryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');

        CLI::write("Scanning for ex-employees resigned more than {$years} years ago...", 'cyan');

        $cutoffDate = date('Y-m-d', strtotime("-{$years} years"));

        $builder = $db->table('employees')
                      ->select('id, user_id, employee_number, first_name, last_name, resign_date')
                      ->where('is_active', 0)
                      ->where('resign_date IS NOT NULL')
                      ->where('resign_date <=', $cutoffDate)
                      ->where('anonymized_at IS NULL');

        $employees = $builder->get()->getResultArray();

        if (empty($employees)) {
            CLI::write("No employees found eligible for PDP anonymization.", 'green');
            return;
        }

        CLI::write("Found " . count($employees) . " employee(s) to anonymize.", 'yellow');

        if ($isDryRun) {
            CLI::write("DRY RUN mode. No data will be altered.", 'yellow');
            foreach ($employees as $emp) {
                CLI::write(" - ID: {$emp['id']} (NIK Karyawan: {$emp['employee_number']}) Resigned: {$emp['resign_date']}");
            }
            return;
        }

        $db->transStart();
        $count = 0;

        foreach ($employees as $emp) {
            $now = date('Y-m-d H:i:s');
            
            // 1. Anonymize Employee Data
            $db->table('employees')->where('id', $emp['id'])->update([
                'first_name'          => 'Archived',
                'last_name'           => 'Employee ' . $emp['id'],
                'email'               => 'archived_' . $emp['id'] . '@deleted.local',
                'phone'               => '0000000000',
                'nik'                 => '***',
                'npwp'                => '***',
                'bank_account_number' => '***',
                'address'             => '***',
                'anonymized_at'       => $now
            ]);

            // 2. Anonymize User Access Data (if linked)
            if (!empty($emp['user_id'])) {
                $db->table('users')->where('id', $emp['user_id'])->update([
                    'name'          => 'Archived User ' . $emp['user_id'],
                    'email'         => 'archived_user_' . $emp['user_id'] . '@deleted.local',
                    'phone'         => '0000000000',
                    'anonymized_at' => $now
                ]);
            }
            
            $count++;
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            CLI::error("An error occurred during anonymization.");
        } else {
            CLI::write("Successfully anonymized {$count} employee records.", 'green');
            
            if (function_exists('\Config\Services::audit')) {
                \Config\Services::audit()->forModule('SECURITY')
                    ->logCritical('pdp.anonymize_batch', 'system', 0, "Anonymized {$count} employees resigned > {$years} years ago for UU PDP Compliance.");
            }
        }
    }
}