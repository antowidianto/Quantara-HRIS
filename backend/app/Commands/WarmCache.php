<?php

namespace App\Commands;

use App\Libraries\CacheService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Services;

/**
 * WarmCache — pre-populate cache untuk lookup yang sering dipakai.
 *
 * Idealnya jalan setelah `migrate` atau saat container boot, supaya
 * request pertama tidak hit cold cache (latency lebih konsisten).
 *
 * Yang di-warm:
 *   - UMP per provinsi untuk current year
 *   - Holidays per company × current year
 *   - BPJS rates per company
 *   - Payroll settings per company
 *
 * Catatan: warm tidak include user-specific cache (permissions per role)
 * karena variasi tinggi & invalidate sering — biarkan diisi saat first hit.
 *
 * Usage:
 *   php spark cache:warm                  # current year
 *   php spark cache:warm --year=2027      # specific year
 *   php spark cache:warm --company=5      # specific company only
 */
class WarmCache extends BaseCommand
{
    protected $group       = 'HRIS';
    protected $name        = 'cache:warm';
    protected $description = 'Pre-populate cache untuk lookup tabel yang jarang berubah';
    protected $usage       = 'cache:warm [--year=YYYY] [--company=N]';
    protected $options     = [
        '--year'    => 'Tahun untuk UMP/holidays (default: current)',
        '--company' => 'Limit ke 1 company saja (default: semua)',
    ];

    public function run(array $params)
    {
        $year      = (int) ($params['year']    ?? CLI::getOption('year')    ?? date('Y'));
        $companyId = (int) ($params['company'] ?? CLI::getOption('company') ?? 0);

        $db    = Database::connect();
        $cache = Services::cacheService();

        $totals = ['ump' => 0, 'holidays' => 0, 'bpjs' => 0, 'payset' => 0];

        // ─── Warm UMP per provinsi ──────────────────────────────────
        if ($db->tableExists('ump_rates')) {
            $rows = $db->table('ump_rates')->where('year', $year)->get()->getResultArray();
            foreach ($rows as $r) {
                $cache->set(
                    $cache->keyUmp((int) $r['province_id'], $year),
                    $r,
                    CacheService::TTL_DAY,
                );
                $totals['ump']++;
            }
        }

        // ─── Warm holidays per company × year ───────────────────────
        if ($db->tableExists('holidays')) {
            $companiesQuery = $db->table('companies');
            if ($companyId > 0) $companiesQuery->where('id', $companyId);
            $companies = $companiesQuery->get()->getResultArray();

            foreach ($companies as $co) {
                $cid = (int) $co['id'];
                $holidays = $db->table('holidays')
                    ->where('company_id', $cid)
                    ->where("strftime('%Y', date)", (string) $year)  // SQLite syntax
                    ->orderBy('date', 'ASC')
                    ->get()->getResultArray();
                // Fallback untuk MySQL (kalau SQLite syntax fail)
                if (empty($holidays)) {
                    try {
                        $holidays = $db->table('holidays')
                            ->where('company_id', $cid)
                            ->where('YEAR(date)', $year)
                            ->orderBy('date', 'ASC')
                            ->get()->getResultArray();
                    } catch (\Throwable $e) {
                        // Driver tidak support YEAR(), skip
                    }
                }
                $cache->set(
                    $cache->keyHolidays($cid, $year),
                    $holidays,
                    CacheService::TTL_DAY,
                );
                $totals['holidays']++;
            }
        }

        // ─── Warm BPJS rates per company ────────────────────────────
        if ($db->tableExists('bpjs_rates')) {
            $bpjsQuery = $db->table('bpjs_rates');
            if ($companyId > 0) $bpjsQuery->where('company_id', $companyId);
            $rates = $bpjsQuery->get()->getResultArray();
            foreach ($rates as $r) {
                $cache->set(
                    $cache->keyBpjsRates((int) $r['company_id']),
                    $r,
                    CacheService::TTL_HOUR,
                );
                $totals['bpjs']++;
            }
        }

        // ─── Warm payroll settings per company ──────────────────────
        // PayrollSettings library punya cache internal; kita warm key
        // yang dibaca CacheService aja.
        if ($db->tableExists('settings')) {
            $companiesQuery = $db->table('settings')
                ->select('company_id')
                ->groupBy('company_id');
            if ($companyId > 0) $companiesQuery->where('company_id', $companyId);
            $rows = $companiesQuery->get()->getResultArray();

            foreach ($rows as $r) {
                $cid = (int) $r['company_id'];
                $payrollKeys = [
                    'payroll.working_days_per_month',
                    'payroll.overtime_hours_per_month',
                    'payroll.overtime_to_leave_hours_per_day',
                    'payroll.absent_deduction_enabled',
                ];
                $settings = $db->table('settings')
                    ->where('company_id', $cid)
                    ->whereIn('key', $payrollKeys)
                    ->get()->getResultArray();
                $kv = array_column($settings, 'value', 'key');
                $cache->set(
                    $cache->keyPayrollSettings($cid),
                    $kv,
                    CacheService::TTL_HOUR,
                );
                $totals['payset']++;
            }
        }

        CLI::write(sprintf(
            'Cache warmed (year=%d): %d UMP, %d holidays sets, %d BPJS rates, %d payroll settings',
            $year,
            $totals['ump'],
            $totals['holidays'],
            $totals['bpjs'],
            $totals['payset'],
        ), 'green');
        return 0;
    }
}