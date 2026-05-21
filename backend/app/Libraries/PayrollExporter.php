<?php

namespace App\Libraries;

/**
 * PayrollExporter
 *
 * Generate file ekspor untuk payroll period:
 *   1. Bank Transfer CSV (BCA, Mandiri, BNI, BRI, CIMB Niaga, BSI)
 *   2. e-SPT PPh21 CSV (DJP) — masa bulanan
 *   3. SiPP BPJS Ketenagakerjaan CSV (iuran JHT/JP/JKK/JKM)
 *   4. e-Dabu BPJS Kesehatan CSV (iuran kesehatan)
 *
 * Setiap method mengembalikan array:
 *   ['filename' => 'xxx.csv', 'mime' => 'text/csv', 'content' => '...']
 *
 * Catatan format:
 *  - Format bank di Indonesia bervariasi dan beberapa bank update format-nya
 *    secara berkala. Implementasi ini mengikuti format umum yang banyak
 *    diadopsi vendor payroll. Kalau bank Anda butuh format eksklusif (mis.
 *    .CTE BCA versi 2024), tambah method khusus dan mapping di switch.
 *  - e-SPT PPh21: format CSV kolom-by-kolom; impor ke aplikasi e-SPT DJP
 *    bisa pakai fitur "Impor → CSV" dengan pemisah `;`.
 *  - SiPP BPJS-TK: file CSV dengan header per row sesuai panduan SiPP. Untuk
 *    upload mutasi (PER1721) gunakan method dedicated.
 *  - Encoding: kita output ANSI/UTF-8 dengan BOM agar Excel di Windows
 *    membuka dengan benar.
 */
class PayrollExporter
{
    private const BANKS = [
        'BCA'     => 'Bank Central Asia',
        'MANDIRI' => 'Bank Mandiri',
        'BNI'     => 'Bank Negara Indonesia',
        'BRI'     => 'Bank Rakyat Indonesia',
        'CIMB'    => 'CIMB Niaga',
        'BSI'     => 'Bank Syariah Indonesia',
        'PERMATA' => 'Bank Permata',
        'DANAMON' => 'Bank Danamon',
    ];

    /**
     * Bank transfer batch.
     *
     * Output baris (semicolon-separated):
     *   no_rekening_tujuan; nama_pemilik; bank_tujuan; jumlah; berita
     *
     * Format spesifik per bank (mis. BCA prefer ; sebagai delimiter dan
     * jumlah tanpa pemisah ribuan) di-handle di buildLine().
     */
    public function bankTransfer(int $payrollPeriodId, string $bank = 'BCA'): array
    {
        $bank = strtoupper($bank);
        if (! isset(self::BANKS[$bank])) {
            throw new \InvalidArgumentException("Bank '{$bank}' tidak didukung. Pilih: " . implode(', ', array_keys(self::BANKS)));
        }

        $db = \Config\Database::connect();
        $period = $this->loadPeriod($payrollPeriodId);
        $company = $db->table('companies')->where('id', $period['company_id'])->get()->getRowArray();

        $payslips = $db->table('payslips p')
            ->select('p.*, e.bank_name, e.bank_account_encrypted, e.bank_account_holder, e.full_name, e.employee_number')
            ->join('employees e', 'e.id = p.employee_id')
            ->where('p.payroll_period_id', $payrollPeriodId)
            ->where('p.take_home_pay >', 0)
            ->orderBy('e.full_name', 'ASC')
            ->get()->getResultArray();

        $crypt = \Config\Services::encryption();

        $rows = [];
        $rows[] = $this->bankHeader($bank);

        $totalAmount = 0;
        $totalRecords = 0;
        foreach ($payslips as $p) {
            $accountNumber = '';
            try {
                $accountNumber = $p['bank_account_encrypted']
                    ? $crypt->decrypt($p['bank_account_encrypted']) : '';
            } catch (\Throwable $e) {
                // Jika gagal decrypt, skip dan tandai
                $accountNumber = '';
            }

            if ($accountNumber === '') {
                // Skip entri tanpa rekening; catat di end note saja
                continue;
            }

            $holder = $p['bank_account_holder'] ?: $p['employee_name'];
            $amount = (float) $p['take_home_pay'];
            $note = $period['code'] . '-' . $p['employee_number'];

            $rows[] = $this->buildLine($bank, [
                'account_number' => $accountNumber,
                'account_holder' => $holder,
                'bank_destination' => strtoupper((string) ($p['bank_name'] ?? $bank)),
                'amount' => $amount,
                'note' => $note,
                'employee_number' => $p['employee_number'],
                'employee_name' => $p['employee_name'],
            ]);
            $totalAmount += $amount;
            $totalRecords++;
        }

        // Footer/summary
        $rows[] = $this->bankFooter($bank, $totalRecords, $totalAmount);

        $content = "\xEF\xBB\xBF" . implode("\r\n", $rows) . "\r\n";
        $filename = sprintf(
            'BANK_%s_%s_%s.csv',
            $bank,
            $this->slug($company['code'] ?? 'COMPANY'),
            $this->slug($period['code'] ?? 'PERIOD')
        );
        return ['filename' => $filename, 'mime' => 'text/csv', 'content' => $content];
    }

    private function bankHeader(string $bank): string
    {
        return match ($bank) {
            'BCA'     => 'NoRekening;Nama;BankTujuan;Jumlah;Berita',
            'MANDIRI' => 'Account;Name;Bank;Amount;Description',
            'BNI'     => 'NoRek;Nama;Bank;Jumlah;Keterangan',
            'BRI'     => 'AccountNo;BeneficiaryName;BankCode;Amount;Remark',
            'CIMB'    => 'AccountNumber;BeneficiaryName;BankName;Amount;Description',
            'BSI'     => 'NoRekening;Nama;BankTujuan;Nominal;Berita',
            default   => 'Account;Name;Bank;Amount;Description',
        };
    }

    private function bankFooter(string $bank, int $count, float $total): string
    {
        // Sebagian besar bank tidak butuh footer di file batch;
        // kita tampilkan total sebagai komentar baris terakhir.
        return sprintf('# TOTAL;%d records;;%s;', $count, number_format($total, 0, '.', ''));
    }

    private function buildLine(string $bank, array $row): string
    {
        $amount = number_format((float) $row['amount'], 0, '.', '');
        // Sanitize karakter delimiter dan newline.
        $name = preg_replace('/[;\r\n]+/', ' ', (string) $row['account_holder']);
        $note = preg_replace('/[;\r\n]+/', ' ', (string) $row['note']);
        $bankDest = preg_replace('/[;\r\n]+/', ' ', (string) $row['bank_destination']);
        $accNo = preg_replace('/[^0-9A-Za-z\-]+/', '', (string) $row['account_number']);

        return implode(';', [$accNo, $name, $bankDest, $amount, $note]);
    }

    /**
     * e-SPT PPh21 — masa bulanan.
     *
     * Format CSV mengikuti template impor PPh21 (semi-baku DJP). Kolom:
     *   NPWP_Pemotong;Masa_Pajak;Tahun_Pajak;NPWP_Pegawai;NIK;Nama;
     *   Status_PTKP;Bruto;PPh21;Tarif_TER;Kode_Bukti_Potong
     *
     * Untuk masa final (Desember) kita pakai kode 1721-A1 / A2; untuk
     * masa biasa pakai 1721-VI (final tidak final). Caller boleh override
     * via param $finalForm.
     */
    public function esptPph21(int $payrollPeriodId, bool $finalForm = false): array
    {
        $db = \Config\Database::connect();
        $period = $this->loadPeriod($payrollPeriodId);
        $company = $db->table('companies')->where('id', $period['company_id'])->get()->getRowArray();

        $payslips = $db->table('payslips p')
            ->select('p.*, e.npwp_encrypted, e.nik_encrypted, e.full_name, e.employee_number, e.ptkp_status')
            ->join('employees e', 'e.id = p.employee_id')
            ->where('p.payroll_period_id', $payrollPeriodId)
            ->orderBy('e.full_name', 'ASC')
            ->get()->getResultArray();

        $crypt = \Config\Services::encryption();
        $masa  = (int) ($period['period_month'] ?? date('n', strtotime($period['start_date'])));
        $tahun = (int) $period['period_year'];
        $isDecember = (int) ($period['is_december_correction'] ?? 0) === 1 || $finalForm;
        $kodeBuktiPotong = $isDecember ? '1721-A1' : '1721-VI';

        $rows = [];
        $rows[] = 'NPWP_Pemotong;Masa_Pajak;Tahun_Pajak;NPWP_Pegawai;NIK;Nama;Status_PTKP;Bruto;PPh21;Tarif_TER;Kode_Bukti_Potong';

        $companyNpwp = $this->cleanNpwp($company['npwp'] ?? '');

        foreach ($payslips as $p) {
            $npwp = '';
            $nik  = '';
            try {
                $npwp = $p['npwp_encrypted'] ? $crypt->decrypt($p['npwp_encrypted']) : '';
                $nik  = $p['nik_encrypted']  ? $crypt->decrypt($p['nik_encrypted'])  : '';
            } catch (\Throwable $e) { /* abaikan, biarkan kosong */ }

            $rows[] = implode(';', [
                $companyNpwp,
                str_pad((string) $masa, 2, '0', STR_PAD_LEFT),
                $tahun,
                $this->cleanNpwp($npwp),
                preg_replace('/\D/', '', (string) $nik),
                $this->safeName($p['employee_name']),
                strtoupper((string) ($p['ptkp_status'] ?? 'TK0')),
                number_format((float) $p['gross_earning'], 0, '.', ''),
                number_format((float) $p['pph21_amount'], 0, '.', ''),
                number_format((float) ($p['ter_rate'] ?? 0), 4, '.', ''),
                $kodeBuktiPotong,
            ]);
        }

        $content = "\xEF\xBB\xBF" . implode("\r\n", $rows) . "\r\n";
        $filename = sprintf('eSPT_PPh21_%s_%04d%02d.csv',
            $this->slug($company['code'] ?? 'COMPANY'), $tahun, $masa);
        return ['filename' => $filename, 'mime' => 'text/csv', 'content' => $content];
    }

    /**
     * SiPP BPJS Ketenagakerjaan — iuran JHT/JP/JKK/JKM bulanan.
     *
     * Format kolom:
     *   No_KPJ;NIK;Nama;Tgl_Lahir;Upah;JHT_Karyawan;JHT_Perusahaan;
     *   JP_Karyawan;JP_Perusahaan;JKK;JKM
     */
    public function bpjsKetenagakerjaan(int $payrollPeriodId): array
    {
        $db = \Config\Database::connect();
        $period = $this->loadPeriod($payrollPeriodId);
        $company = $db->table('companies')->where('id', $period['company_id'])->get()->getRowArray();

        $payslips = $db->table('payslips p')
            ->select('p.*, e.bpjs_tk_no, e.nik_encrypted, e.full_name, e.date_of_birth, e.employee_number')
            ->join('employees e', 'e.id = p.employee_id')
            ->where('p.payroll_period_id', $payrollPeriodId)
            ->orderBy('e.full_name', 'ASC')
            ->get()->getResultArray();

        $crypt = \Config\Services::encryption();
        $rows = [];
        $rows[] = 'No_KPJ;NIK;Nama;Tgl_Lahir;Upah;JHT_Karyawan;JHT_Perusahaan;JP_Karyawan;JP_Perusahaan;JKK;JKM';

        foreach ($payslips as $p) {
            $nik = '';
            try {
                $nik = $p['nik_encrypted'] ? $crypt->decrypt($p['nik_encrypted']) : '';
            } catch (\Throwable $e) { /* */ }

            $rows[] = implode(';', [
                preg_replace('/\D/', '', (string) ($p['bpjs_tk_no'] ?? '')),
                preg_replace('/\D/', '', (string) $nik),
                $this->safeName($p['employee_name']),
                ! empty($p['date_of_birth']) ? date('d/m/Y', strtotime($p['date_of_birth'])) : '',
                number_format((float) $p['basic_salary'], 0, '.', ''),
                number_format((float) $p['bpjs_jht_employee'], 0, '.', ''),
                number_format((float) $p['bpjs_jht_company'], 0, '.', ''),
                number_format((float) $p['bpjs_jp_employee'], 0, '.', ''),
                number_format((float) $p['bpjs_jp_company'], 0, '.', ''),
                number_format((float) $p['bpjs_jkk_company'], 0, '.', ''),
                number_format((float) $p['bpjs_jkm_company'], 0, '.', ''),
            ]);
        }

        $content = "\xEF\xBB\xBF" . implode("\r\n", $rows) . "\r\n";
        $filename = sprintf('SiPP_BPJSTK_%s_%s.csv',
            $this->slug($company['code'] ?? 'COMPANY'), $this->slug($period['code'] ?? ''));
        return ['filename' => $filename, 'mime' => 'text/csv', 'content' => $content];
    }

    /**
     * e-Dabu BPJS Kesehatan — iuran 5% (1% karyawan + 4% perusahaan).
     *
     * Format kolom:
     *   No_BPJS;NIK;Nama;Status_PTKP;Upah_Pokok;Iuran_Karyawan;Iuran_Perusahaan;Total
     */
    public function bpjsKesehatan(int $payrollPeriodId): array
    {
        $db = \Config\Database::connect();
        $period = $this->loadPeriod($payrollPeriodId);
        $company = $db->table('companies')->where('id', $period['company_id'])->get()->getRowArray();

        $payslips = $db->table('payslips p')
            ->select('p.*, e.bpjs_kesehatan_no, e.nik_encrypted, e.full_name, e.employee_number, e.ptkp_status')
            ->join('employees e', 'e.id = p.employee_id')
            ->where('p.payroll_period_id', $payrollPeriodId)
            ->orderBy('e.full_name', 'ASC')
            ->get()->getResultArray();

        $crypt = \Config\Services::encryption();
        $rows = [];
        $rows[] = 'No_BPJS;NIK;Nama;Status_PTKP;Upah_Pokok;Iuran_Karyawan;Iuran_Perusahaan;Total';

        foreach ($payslips as $p) {
            $nik = '';
            try {
                $nik = $p['nik_encrypted'] ? $crypt->decrypt($p['nik_encrypted']) : '';
            } catch (\Throwable $e) { /* */ }

            $emp = (float) $p['bpjs_kes_employee'];
            $com = (float) $p['bpjs_kes_company'];
            $rows[] = implode(';', [
                preg_replace('/\D/', '', (string) ($p['bpjs_kesehatan_no'] ?? '')),
                preg_replace('/\D/', '', (string) $nik),
                $this->safeName($p['employee_name']),
                strtoupper((string) ($p['ptkp_status'] ?? 'TK0')),
                number_format((float) $p['basic_salary'], 0, '.', ''),
                number_format($emp, 0, '.', ''),
                number_format($com, 0, '.', ''),
                number_format($emp + $com, 0, '.', ''),
            ]);
        }

        $content = "\xEF\xBB\xBF" . implode("\r\n", $rows) . "\r\n";
        $filename = sprintf('eDabu_BPJSKes_%s_%s.csv',
            $this->slug($company['code'] ?? 'COMPANY'), $this->slug($period['code'] ?? ''));
        return ['filename' => $filename, 'mime' => 'text/csv', 'content' => $content];
    }

    /**
     * Bukti Potong PPh21 (1721-A1 / 1721-VI) HTML untuk satu payslip.
     * Caller bisa convert ke PDF via library yang sama dengan payslip.
     */
    public function buktiPotongHtml(int $payslipId): array
    {
        $db = \Config\Database::connect();
        $p = $db->table('payslips p')
            ->select('p.*, e.full_name, e.employee_number, e.npwp_encrypted, e.nik_encrypted,'
                . ' e.address, e.ptkp_status AS emp_ptkp, e.date_of_birth,'
                . ' c.name AS company_name, c.legal_name, c.npwp AS company_npwp,'
                . ' c.address AS company_address')
            ->join('employees e', 'e.id = p.employee_id')
            ->join('companies c', 'c.id = p.company_id')
            ->where('p.id', $payslipId)
            ->get()->getRowArray();
        if (! $p) {
            throw new \InvalidArgumentException('Payslip tidak ditemukan');
        }

        $crypt = \Config\Services::encryption();
        $npwp = '';
        $nik  = '';
        try {
            $npwp = $p['npwp_encrypted'] ? $crypt->decrypt($p['npwp_encrypted']) : '';
            $nik  = $p['nik_encrypted']  ? $crypt->decrypt($p['nik_encrypted'])  : '';
        } catch (\Throwable $e) { /* */ }

        $period = $db->table('payroll_periods')->where('id', $p['payroll_period_id'])->get()->getRowArray();
        $isFinal = (int) ($period['is_december_correction'] ?? 0) === 1;
        $formCode = $isFinal ? '1721-A1' : '1721-VI';

        $masa = str_pad((string) ($period['period_month'] ?? '-'), 2, '0', STR_PAD_LEFT);
        $tahun = $period['period_year'];

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Bukti Potong ' . $formCode . '</title>'
              . '<style>body{font-family:Arial,sans-serif;font-size:11px;color:#222;padding:20px}'
              . 'table{border-collapse:collapse;width:100%;margin-top:10px}'
              . 'th,td{border:1px solid #888;padding:6px 8px;vertical-align:top}'
              . 'th{background:#f3f4f6;text-align:left}'
              . '.right{text-align:right}.center{text-align:center}'
              . '.title{font-size:14px;font-weight:bold;text-align:center;margin-bottom:6px}'
              . '.subtitle{text-align:center;margin-bottom:14px}</style></head><body>';
        $html .= '<div class="title">BUKTI PEMOTONGAN PAJAK PENGHASILAN PASAL 21</div>';
        $html .= '<div class="subtitle">Formulir ' . $formCode . ' — Masa Pajak ' . $masa . ' / ' . $tahun . '</div>';

        $html .= '<table><tr><th colspan="2">A. Pemotong Pajak</th></tr>';
        $html .= '<tr><td style="width:30%">NPWP</td><td>' . htmlspecialchars($this->cleanNpwp($p['company_npwp'] ?? ''), ENT_QUOTES) . '</td></tr>';
        $html .= '<tr><td>Nama</td><td>' . htmlspecialchars((string) ($p['legal_name'] ?: $p['company_name']), ENT_QUOTES) . '</td></tr>';
        $html .= '<tr><td>Alamat</td><td>' . htmlspecialchars((string) ($p['company_address'] ?? '-'), ENT_QUOTES) . '</td></tr>';
        $html .= '</table>';

        $html .= '<table><tr><th colspan="2">B. Penerima Penghasilan</th></tr>';
        $html .= '<tr><td style="width:30%">NPWP</td><td>' . htmlspecialchars($this->cleanNpwp($npwp), ENT_QUOTES) . '</td></tr>';
        $html .= '<tr><td>NIK</td><td>' . htmlspecialchars((string) $nik, ENT_QUOTES) . '</td></tr>';
        $html .= '<tr><td>Nama</td><td>' . htmlspecialchars((string) $p['employee_name'], ENT_QUOTES) . '</td></tr>';
        $html .= '<tr><td>Status PTKP</td><td>' . htmlspecialchars((string) ($p['ptkp_status'] ?: $p['emp_ptkp']), ENT_QUOTES) . '</td></tr>';
        $html .= '<tr><td>Alamat</td><td>' . htmlspecialchars((string) ($p['address'] ?? '-'), ENT_QUOTES) . '</td></tr>';
        $html .= '</table>';

        $html .= '<table><tr><th colspan="2">C. Penghitungan PPh Pasal 21</th></tr>';
        $html .= '<tr><td>Penghasilan Bruto</td><td class="right">Rp ' . number_format((float) $p['gross_earning'], 0, ',', '.') . '</td></tr>';
        $html .= '<tr><td>Tarif Efektif Rata-rata (TER)</td><td class="right">' . number_format((float) ($p['ter_rate'] ?? 0) * 100, 4, ',', '.') . '%</td></tr>';
        $html .= '<tr><td>PPh 21 Dipotong</td><td class="right"><strong>Rp ' . number_format((float) $p['pph21_amount'], 0, ',', '.') . '</strong></td></tr>';
        $html .= '</table>';

        $html .= '<p style="margin-top:30px">Demikian bukti potong ini dibuat dengan sebenarnya.</p>';
        $html .= '<table style="border:none;margin-top:30px"><tr style="border:none">'
              . '<td style="border:none;width:60%"></td>'
              . '<td style="border:none" class="center">' . htmlspecialchars((string) ($p['city'] ?? 'Jakarta'), ENT_QUOTES) . ', ' . date('d M Y') . '<br><br><br><br>'
              . 'Pejabat Pemotong<br>(______________________)</td>'
              . '</tr></table>';
        $html .= '</body></html>';

        return [
            'filename' => sprintf('BuktiPotong_%s_%s_%s.html',
                $formCode, $this->slug((string) $p['employee_number']),
                $this->slug((string) $period['code'])),
            'mime'     => 'text/html',
            'content'  => $html,
        ];
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    private function loadPeriod(int $id): array
    {
        $db = \Config\Database::connect();
        $row = $db->table('payroll_periods')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            throw new \InvalidArgumentException("Payroll period #{$id} tidak ditemukan");
        }
        if (! in_array($row['status'], ['CALCULATED', 'APPROVED', 'PAID', 'CLOSED'], true)) {
            throw new \RuntimeException(
                "Payroll period status='{$row['status']}', wajib minimal CALCULATED untuk diekspor."
            );
        }
        return $row;
    }

    private function cleanNpwp(?string $v): string
    {
        // NPWP format: 99.999.999.9-999.999 atau 16 digit (NPWP baru).
        // Untuk e-SPT lebih aman pakai digit-only.
        return preg_replace('/\D/', '', (string) $v);
    }

    private function safeName(?string $name): string
    {
        $clean = preg_replace('/[;\r\n]+/', ' ', (string) $name);
        return mb_substr($clean, 0, 60);
    }

    private function slug(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9]+/', '_', $s);
        return trim($s, '_');
    }

    public static function supportedBanks(): array
    {
        return self::BANKS;
    }
}