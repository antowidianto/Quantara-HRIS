<?php

namespace App\Libraries\Lifecycle;

use Config\Database;
use Config\Services;

/**
 * H1.12 — Kompensasi PKWT & Limit 5 Tahun (PP 35/2021)
 *
 * Layanan ini menjalankan dua kewajiban hukum yang lekat dengan
 * kontrak PKWT (Perjanjian Kerja Waktu Tertentu):
 *
 * 1. **Contract Guard 5 Tahun** — `guardMaxDuration()`:
 *    Mengakumulasi seluruh masa kontrak PKWT (termasuk perpanjangan
 *    dan pembaruan) milik karyawan tertentu. Bila penambahan kontrak
 *    baru menyebabkan total melebihi 1.825 hari (5 tahun), service
 *    melempar exception sehingga controller bisa menolak penyimpanan
 *    dan mengarahkan HR membuat draft PKWTT.
 *
 * 2. **Auto Kompensasi** — `compensationForContract()` &
 *    `earningEntriesForPayrollPeriod()`:
 *    Menghitung uang kompensasi PP 35/2021 Pasal 16 berdasarkan
 *    proporsi `(masa_kerja_bulan / 12) × upah_sebulan` saat kontrak
 *    PKWT berakhir. PayrollService memanggil
 *    `earningEntriesForPayrollPeriod()` untuk menambahkan item
 *    earning "Kompensasi PKWT" pada bulan kontrak expired.
 *
 * Pure helper (`*Pure`) di-decouple dari DB supaya unit-test cepat.
 */
class PkwtCompensationService
{
    /** Default ambang batas akumulasi masa PKWT (5 tahun = 1825 hari). */
    public const MAX_PKWT_DAYS = 1825;

    /**
     * Hitung total hari kontrak PKWT yang sudah berjalan untuk seorang
     * karyawan, lalu tambahkan dengan kandidat kontrak baru
     * (`$candidate`). Bila gabungannya melebihi 5 tahun, lempar
     * exception. Kontrak yang sudah dibatalkan / berakhir karena
     * resignation tetap dihitung — sesuai PP 35/2021.
     *
     * @param int        $employeeId
     * @param array{start_date:string,end_date:string,contract_type?:string} $candidate
     *        Kandidat kontrak yang akan ditambah / di-edit. `id` boleh
     *        diisi untuk mode edit (kontrak existing dengan id ini
     *        akan di-skip dari akumulasi).
     * @param int|null   $excludeId Optional contract_id yang di-skip
     *        (alternatif `id` di candidate).
     *
     * @throws \RuntimeException jika akumulasi > 5 tahun.
     */
    public function guardMaxDuration(int $employeeId, array $candidate, ?int $excludeId = null): array
    {
        $candidateType = strtoupper((string) ($candidate['contract_type'] ?? 'PKWT'));
        if ($candidateType !== 'PKWT') {
            // Hanya PKWT yang di-cap. PKWTT/PROBATION/MAGANG/PKHL bebas.
            return [
                'enforced'        => false,
                'total_days'      => 0,
                'candidate_days'  => 0,
                'limit_days'      => self::MAX_PKWT_DAYS,
            ];
        }

        $start = $candidate['start_date'] ?? null;
        $end   = $candidate['end_date']   ?? null;
        if (! $start || ! $end) {
            throw new \InvalidArgumentException('Kontrak PKWT wajib memiliki start_date dan end_date.');
        }

        $candidateDays = self::daysBetween($start, $end);
        if ($candidateDays <= 0) {
            throw new \InvalidArgumentException('end_date harus setelah start_date.');
        }

        $excludeId = $excludeId ?? (isset($candidate['id']) ? (int) $candidate['id'] : null);

        $db = Database::connect();
        $existing = [];
        if ($db->tableExists('employee_contracts')) {
            $rows = $db->table('employee_contracts')
                ->select('id, start_date, end_date, contract_type')
                ->where('employee_id', $employeeId)
                ->where('contract_type', 'PKWT')
                ->orderBy('start_date', 'ASC')
                ->get()
                ->getResultArray();
            foreach ($rows as $r) {
                if ($excludeId !== null && (int) $r['id'] === $excludeId) {
                    continue;
                }
                $existing[] = [
                    'start_date' => $r['start_date'],
                    'end_date'   => $r['end_date'] ?: $r['start_date'],
                ];
            }
        }

        $totalDays = self::accumulatedDaysPure(
            array_merge($existing, [['start_date' => $start, 'end_date' => $end]]),
        );

        if ($totalDays > self::MAX_PKWT_DAYS) {
            throw new \RuntimeException(sprintf(
                'Akumulasi kontrak PKWT karyawan melebihi batas 5 tahun (%d hari) per PP 35/2021. '
                . 'Total dengan kontrak baru: %d hari. Silakan buat draft PKWTT.',
                self::MAX_PKWT_DAYS,
                $totalDays,
            ));
        }

        return [
            'enforced'       => true,
            'total_days'     => $totalDays,
            'candidate_days' => $candidateDays,
            'limit_days'     => self::MAX_PKWT_DAYS,
            'days_remaining' => max(0, self::MAX_PKWT_DAYS - $totalDays),
        ];
    }

    /**
     * Akumulasi total hari dari kumpulan kontrak (pure helper).
     * Tidak memvalidasi overlap — perpanjangan PKWT yang resmi
     * memang berurutan, tetapi kalau ada gap antar kontrak, gap
     * tidak dihitung.
     *
     * @param list<array{start_date:string,end_date:string}> $contracts
     */
    public static function accumulatedDaysPure(array $contracts): int
    {
        $total = 0;
        foreach ($contracts as $c) {
            $total += max(0, self::daysBetween($c['start_date'] ?? '', $c['end_date'] ?? ''));
        }
        return $total;
    }

    /**
     * Hitung selisih hari (inklusif) antara dua tanggal dalam format
     * `Y-m-d`. Return 0 bila salah satu kosong / invalid.
     */
    public static function daysBetween(string $start, string $end): int
    {
        if ($start === '' || $end === '') return 0;
        $s = strtotime($start);
        $e = strtotime($end);
        if ($s === false || $e === false || $e < $s) return 0;
        return (int) floor(($e - $s) / 86400) + 1;
    }

    /**
     * Pure formula PP 35/2021 Pasal 16:
     *
     *  - Masa kerja ≥ 12 bulan → kompensasi = 1 × upah sebulan ×
     *    (masa_kerja_bulan / 12).
     *  - Masa kerja 1–<12 bulan → kompensasi = (masa_kerja_bulan / 12)
     *    × 1 × upah sebulan.
     *  - Masa kerja < 1 bulan → tidak ada kompensasi.
     *
     * @param int   $tenureMonths Masa kerja dalam bulan kalender.
     * @param float $monthlyWage  Upah sebulan (basic + tunjangan tetap).
     */
    public static function calculatePure(int $tenureMonths, float $monthlyWage): float
    {
        if ($tenureMonths < 1 || $monthlyWage <= 0) return 0.0;
        return round(($tenureMonths / 12.0) * $monthlyWage, 2);
    }

    /**
     * Hitung selisih bulan kalender antara dua tanggal `Y-m-d`.
     * Selalu di-floor (mis. 23/3 → 22/4 = 1 bulan, bukan 1.x).
     */
    public static function tenureMonthsPure(string $start, string $end): int
    {
        if ($start === '' || $end === '') return 0;
        $s = strtotime($start);
        $e = strtotime($end);
        if ($s === false || $e === false || $e < $s) return 0;
        $sd = (int) date('Y', $s) * 12 + (int) date('n', $s);
        $ed = (int) date('Y', $e) * 12 + (int) date('n', $e);
        $months = $ed - $sd;
        if ((int) date('j', $e) < (int) date('j', $s)) $months--;
        return max(0, $months);
    }

    /**
     * Hitung kompensasi PKWT untuk satu kontrak (DB-aware wrapper).
     *
     * Nominal upah sebulan diambil dari basic_salary + tunjangan tetap
     * (recurring earnings dengan kategori ALLOWANCE) sesuai master
     * payroll_components milik karyawan. Fallback ke `basic_salary`
     * di tabel employees jika kontrak tidak punya nilai.
     *
     * @return array{
     *   amount: float,
     *   tenure_months: int,
     *   monthly_wage: float,
     *   contract_id: int,
     *   employee_id: int,
     * }
     */
    public function compensationForContract(int $contractId): array
    {
        $db = Database::connect();
        $row = $db->table('employee_contracts')
            ->where('id', $contractId)
            ->get()->getRowArray();
        if (! $row) {
            throw new \InvalidArgumentException("Kontrak #{$contractId} tidak ditemukan.");
        }
        if (strtoupper((string) $row['contract_type']) !== 'PKWT') {
            return [
                'amount'        => 0.0,
                'tenure_months' => 0,
                'monthly_wage'  => 0.0,
                'contract_id'   => $contractId,
                'employee_id'   => (int) $row['employee_id'],
            ];
        }

        $start = (string) $row['start_date'];
        $end   = (string) ($row['end_date'] ?? $row['start_date']);
        $months = self::tenureMonthsPure($start, $end);

        $monthlyWage = (float) ($row['basic_salary'] ?? 0);
        if ($monthlyWage <= 0) {
            $emp = $db->table('employees')->where('id', $row['employee_id'])->get()->getRowArray();
            $monthlyWage = (float) ($emp['basic_salary'] ?? 0);
        }
        // Tambah tunjangan tetap (allowance recurring) bila ada
        $monthlyWage += $this->fixedAllowanceForEmployee((int) $row['employee_id'], $end);

        return [
            'amount'        => self::calculatePure($months, $monthlyWage),
            'tenure_months' => $months,
            'monthly_wage'  => $monthlyWage,
            'contract_id'   => $contractId,
            'employee_id'   => (int) $row['employee_id'],
        ];
    }

    /**
     * Cari semua kontrak PKWT yang berakhir di periode payroll
     * tersebut DAN belum dibayarkan kompensasinya. PayrollService
     * memanggil method ini dan mem-prepend hasilnya sebagai earning
     * pada payslip karyawan terkait.
     *
     * @return array<int, array{code:string,name:string,category:string,amount:float,is_taxable:int,contract_id:int}>
     *         keyed by employee_id; satu employee bisa hanya punya 1
     *         entry karena per kontrak per period.
     */
    public function earningEntriesForPayrollPeriod(int $payrollPeriodId): array
    {
        $db = Database::connect();
        if (! $db->tableExists('employee_contracts')
            || ! $db->fieldExists('pkwt_compensation_paid', 'employee_contracts')) {
            return [];
        }

        $period = $db->table('payroll_periods')->where('id', $payrollPeriodId)->get()->getRowArray();
        if (! $period) return [];

        $expiringRows = $db->table('employee_contracts')
            ->select('id, employee_id, end_date, basic_salary')
            ->where('contract_type', 'PKWT')
            ->where('end_date >=', $period['start_date'])
            ->where('end_date <=', $period['end_date'])
            ->groupStart()
                ->where('pkwt_compensation_paid', 0)
                ->orWhere('pkwt_compensation_paid IS NULL')
            ->groupEnd()
            ->get()->getResultArray();

        $entries = [];
        foreach ($expiringRows as $row) {
            try {
                $calc = $this->compensationForContract((int) $row['id']);
            } catch (\Throwable $e) {
                log_message('warning', 'PkwtCompensationService.compensationForContract failed: ' . $e->getMessage());
                continue;
            }
            if ($calc['amount'] <= 0) continue;
            $entries[(int) $row['employee_id']] = [
                'code'        => 'PKWT_COMP',
                'name'        => 'Kompensasi PKWT (PP 35/2021)',
                'category'    => 'BONUS',
                'amount'      => $calc['amount'],
                'is_taxable'  => 1,
                'contract_id' => (int) $row['id'],
            ];
        }
        return $entries;
    }

    /**
     * Tandai kontrak sudah dibayarkan kompensasinya untuk satu periode
     * payroll tertentu. Dipanggil PayrollService.finalize().
     */
    public function markPaid(int $contractId, int $payrollPeriodId, float $amount): void
    {
        $db = Database::connect();
        if (! $db->tableExists('employee_contracts')
            || ! $db->fieldExists('pkwt_compensation_paid', 'employee_contracts')) {
            return;
        }
        $db->table('employee_contracts')
            ->where('id', $contractId)
            ->update([
                'pkwt_compensation_paid'           => 1,
                'pkwt_compensation_amount'         => $amount,
                'pkwt_compensation_paid_period_id' => $payrollPeriodId,
                'pkwt_compensation_paid_at'        => date('Y-m-d H:i:s'),
                'updated_at'                       => date('Y-m-d H:i:s'),
            ]);

        try {
            Services::audit()
                ->forModule('PAYROLL')
                ->log(
                    action: 'pkwt_compensation.paid',
                    entity: 'employee_contract',
                    entityId: $contractId,
                    description: sprintf(
                        'Kompensasi PKWT sebesar %s dibayarkan via payroll period #%d',
                        number_format($amount, 2, ',', '.'),
                        $payrollPeriodId,
                    ),
                    severity: 'NOTICE',
                    metadata: [
                        'contract_id'       => $contractId,
                        'payroll_period_id' => $payrollPeriodId,
                        'amount'            => $amount,
                    ],
                );
        } catch (\Throwable $e) {
            log_message('warning', 'PkwtCompensationService audit failed: ' . $e->getMessage());
        }
    }

    /**
     * Sum recurring fixed allowances aktif pada tanggal acuan.
     * Dipakai untuk basis upah-sebulan kompensasi PKWT.
     */
    private function fixedAllowanceForEmployee(int $employeeId, string $referenceDate): float
    {
        $db = Database::connect();
        if (! $db->tableExists('employee_payroll_components')) return 0.0;
        $rows = $db->table('employee_payroll_components epc')
            ->select('epc.amount, c.category, c.type')
            ->join('payroll_components c', 'c.id = epc.component_id', 'left')
            ->where('epc.employee_id', $employeeId)
            ->where('c.type', 'EARNING')
            ->where('c.is_active', 1)
            ->where('c.category', 'ALLOWANCE')
            ->where('epc.effective_from <=', $referenceDate)
            ->groupStart()
                ->where('epc.effective_to IS NULL')
                ->orWhere('epc.effective_to >=', $referenceDate)
            ->groupEnd()
            ->get()->getResultArray();
        $total = 0.0;
        foreach ($rows as $r) $total += (float) $r['amount'];
        return $total;
    }
}