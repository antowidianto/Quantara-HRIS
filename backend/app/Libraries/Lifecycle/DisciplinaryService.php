<?php

namespace App\Libraries\Lifecycle;

use Config\Services;

/**
 * DisciplinaryService
 *
 * Logika domain Surat Peringatan & disiplin karyawan.
 *
 * Tanggung jawab:
 *  1. Generate `reference_number` unik per perusahaan dengan format
 *     `<LEVEL>/<COMPANY_CODE>/<YEAR>/<seq>` (mis. SP1/PT-ABC/2026/00007)
 *  2. Hitung default `expires_at` mengikuti kebijakan: SP1/SP2/SP3 = 6 bulan,
 *     VERBAL/SUSPEND/TERMINATION = NULL (tidak berakhir otomatis)
 *  3. Sarankan level berikutnya saat HR membuat SP baru
 *     berdasarkan SP aktif terakhir (`suggestNextLevel`)
 *  4. Render surat HTML dari template `contract_templates` dengan tipe
 *     `SP` atau `PHK`, atau pakai default template build-in kalau tidak ada
 *  5. `expireOverdue()` — tandai SP yg sudah lewat `expires_at` jadi EXPIRED
 *     (dipanggil dari command harian)
 *  6. `summary($employeeId)` — ringkasan SP per karyawan (untuk tampil di
 *     profil)
 */
class DisciplinaryService
{
    private const DEFAULT_VALIDITY_MONTHS = 6;

    /**
     * Hitung level SP berikutnya yang seharusnya berlaku untuk karyawan ini
     * mengikuti praktik umum: kalau ada SP1 aktif → next = SP2, dst.
     */
    public function suggestNextLevel(int $employeeId): string
    {
        $db = \Config\Database::connect();
        $today = date('Y-m-d');
        // Ambil semua SP aktif untuk karyawan ini, lalu pilih level
        // tertinggi di sisi PHP (lebih portable lintas DB engine).
        $rows = $db->table('disciplinary_records')
            ->where('employee_id', $employeeId)
            ->where('status', 'ACTIVE')
            ->where('deleted_at IS NULL')
            ->groupStart()
                ->where('expires_at IS NULL')
                ->orWhere('expires_at >=', $today)
            ->groupEnd()
            ->whereIn('level', ['SP1', 'SP2', 'SP3'])
            ->get()->getResultArray();
        if (empty($rows)) return 'SP1';

        $rank = ['SP1' => 1, 'SP2' => 2, 'SP3' => 3];
        $highest = 'SP1';
        $highestRank = 0;
        foreach ($rows as $r) {
            $cur = $rank[$r['level']] ?? 0;
            if ($cur > $highestRank) {
                $highestRank = $cur;
                $highest = $r['level'];
            }
        }
        return match ($highest) {
            'SP1' => 'SP2',
            'SP2' => 'SP3',
            'SP3' => 'TERMINATION',
            default => 'SP1',
        };
    }

    /**
     * Generate reference_number unik. Format default:
     *   <LEVEL>/<COMPANY_CODE_OR_HR>/<YEAR>/<5-digit-seq>
     *
     * Sequence dihitung dari max(id) berbasis tahun + level + company.
     * Tidak menjamin gapless (kalau ada record yang dihapus, seq tidak rapat),
     * tapi cukup untuk human-readable.
     */
    public function nextReferenceNumber(int $companyId, string $level): string
    {
        $db = \Config\Database::connect();
        $year = date('Y');
        $company = $db->table('companies')->where('id', $companyId)->get()->getRowArray();
        $code = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', (string) ($company['code'] ?? 'HR')));
        if ($code === '') $code = 'HR';

        $likeStart = "{$level}/{$code}/{$year}/";
        $count = $db->table('disciplinary_records')
            ->where('company_id', $companyId)
            ->like('reference_number', $likeStart, 'after')
            ->countAllResults();
        $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);

        $candidate = "{$likeStart}{$seq}";
        // Edge case: kalau seq sudah dipakai (mis. race), naikkan sampai unique
        $tries = 0;
        while ($tries < 50) {
            $exists = $db->table('disciplinary_records')->where('reference_number', $candidate)->countAllResults();
            if ($exists === 0) return $candidate;
            $tries++;
            $count++;
            $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
            $candidate = "{$likeStart}{$seq}";
        }
        return $candidate;
    }

    /**
     * Hitung tanggal kedaluwarsa default untuk level tertentu.
     */
    public function defaultExpiresAt(string $level, string $effectiveDate): ?string
    {
        if (! in_array($level, ['SP1', 'SP2', 'SP3'], true)) {
            return null; // VERBAL/SUSPEND/TERMINATION tidak otomatis expire
        }
        return date('Y-m-d', strtotime($effectiveDate . ' +' . self::DEFAULT_VALIDITY_MONTHS . ' months'));
    }

    /**
     * Render surat SP/PHK ke HTML. Pakai template kalau tersedia, atau
     * fallback ke template default build-in.
     *
     * @return string HTML lengkap (dari header→body→footer + variabel substituted)
     */
    public function renderLetter(array $record, ?int $templateId = null): string
    {
        $db = \Config\Database::connect();

        // Cari template dgn tipe yg sesuai. Kalau templateId diberikan, pakai
        // itu. Kalau tidak, ambil default template SP/PHK perusahaan.
        $templateType = match ($record['level']) {
            'TERMINATION' => 'PHK',
            default       => 'SP',
        };

        $template = null;
        if ($templateId) {
            $template = $db->table('contract_templates')->where('id', $templateId)->get()->getRowArray();
        }
        if (! $template) {
            $template = $db->table('contract_templates')
                ->where('company_id', $record['company_id'])
                ->where('contract_type', $templateType)
                ->where('is_active', 1)
                ->orderBy('is_default', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()->getRowArray();
        }
        if (! $template) {
            $template = $this->buildFallbackTemplate($templateType);
        }

        $renderer = Services::contractRenderer();
        $context = $renderer->buildContext((int) $record['employee_id'], null, [
            'sp' => [
                'level'             => $record['level'],
                'reference_number'  => $record['reference_number'] ?? '',
                'subject'           => $record['subject'] ?? '',
                'description'       => $record['description'] ?? '',
                'incident_date'     => $record['incident_date'] ?? '',
                'incident_date_id'  => $this->fmtDateId($record['incident_date'] ?? null),
                'effective_date'    => $record['effective_date'] ?? '',
                'effective_date_id' => $this->fmtDateId($record['effective_date'] ?? null),
                'expires_at'        => $record['expires_at'] ?? '',
                'expires_at_id'     => $this->fmtDateId($record['expires_at'] ?? null),
                'category'          => $record['category_name'] ?? '',
            ],
        ]);

        return $renderer->render($template, $context);
    }

    /**
     * Kembalikan ringkasan SP per karyawan untuk dirender di UI.
     *
     * @return array{
     *   active_level: string|null,
     *   active_count: int,
     *   active_records: array,
     *   total_records: int,
     *   suggested_next_level: string,
     *   timeline: array
     * }
     */
    public function summary(int $employeeId): array
    {
        $db = \Config\Database::connect();
        $today = date('Y-m-d');

        // Refresh status terlebih dahulu — supaya yang sudah expired ter-update
        $this->expireOverdueForEmployee($employeeId);

        $all = $db->table('disciplinary_records')
            ->where('employee_id', $employeeId)
            ->where('deleted_at IS NULL')
            ->orderBy('effective_date', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        $active = array_values(array_filter($all, fn ($r) => $r['status'] === 'ACTIVE'));
        $highest = null;
        $rank = ['SP3' => 3, 'SP2' => 2, 'SP1' => 1, 'SUSPEND' => 4, 'TERMINATION' => 5, 'VERBAL' => 0];
        foreach ($active as $r) {
            $cur = $rank[$r['level']] ?? 0;
            if ($highest === null || $cur > ($rank[$highest] ?? 0)) $highest = $r['level'];
        }

        return [
            'active_level'       => $highest,
            'active_count'       => count($active),
            'active_records'     => $active,
            'total_records'      => count($all),
            'suggested_next_level'=> $this->suggestNextLevel($employeeId),
            'timeline'           => array_map(function ($r) use ($today) {
                return [
                    'id'              => (int) $r['id'],
                    'level'           => $r['level'],
                    'reference_number'=> $r['reference_number'],
                    'subject'         => $r['subject'],
                    'effective_date'  => $r['effective_date'],
                    'expires_at'      => $r['expires_at'],
                    'status'          => $r['status'],
                    'is_active_today' => $r['status'] === 'ACTIVE'
                        && (empty($r['expires_at']) || $r['expires_at'] >= $today),
                ];
            }, $all),
        ];
    }

    /**
     * Tandai SP yang sudah lewat `expires_at` jadi EXPIRED. Idempotent.
     * Kembalikan jumlah record yang ter-update.
     */
    public function expireOverdue(?int $companyId = null): int
    {
        $db = \Config\Database::connect();
        $today = date('Y-m-d');
        $builder = $db->table('disciplinary_records')
            ->where('status', 'ACTIVE')
            ->where('expires_at IS NOT NULL')
            ->where('expires_at <', $today);
        if ($companyId) $builder->where('company_id', $companyId);
        $rows = $builder->get()->getResultArray();
        if (empty($rows)) return 0;

        $ids = array_column($rows, 'id');
        $db->table('disciplinary_records')->whereIn('id', $ids)->update([
            'status'     => 'EXPIRED',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return count($ids);
    }

    private function expireOverdueForEmployee(int $employeeId): void
    {
        $db = \Config\Database::connect();
        $today = date('Y-m-d');
        $rows = $db->table('disciplinary_records')
            ->where('employee_id', $employeeId)
            ->where('status', 'ACTIVE')
            ->where('expires_at IS NOT NULL')
            ->where('expires_at <', $today)
            ->get()->getResultArray();
        if (empty($rows)) return;
        $ids = array_column($rows, 'id');
        $db->table('disciplinary_records')->whereIn('id', $ids)->update([
            'status'     => 'EXPIRED',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Cek apakah karyawan ini SUDAH ada SP3 aktif. Kalau ya, dan HR mau
     * menerbitkan SP lagi, sistem akan meng-flag bahwa pelanggaran berikutnya
     * sudah memenuhi syarat untuk diproses ke PHK (sesuai PP 35/2021).
     */
    public function shouldSuggestTermination(int $employeeId): bool
    {
        $db = \Config\Database::connect();
        $today = date('Y-m-d');
        $count = $db->table('disciplinary_records')
            ->where('employee_id', $employeeId)
            ->where('status', 'ACTIVE')
            ->where('level', 'SP3')
            ->groupStart()
                ->where('expires_at IS NULL')
                ->orWhere('expires_at >=', $today)
            ->groupEnd()
            ->countAllResults();
        return $count > 0;
    }

    // ────────────────────────────────────────────────────────────────
    // Fallback template build-in (untuk perusahaan yang belum
    // membuat template kustom)
    // ────────────────────────────────────────────────────────────────

    private function buildFallbackTemplate(string $type): array
    {
        $title = $type === 'PHK' ? 'PEMUTUSAN HUBUNGAN KERJA' : 'SURAT PERINGATAN';
        $body = '<div style="font-family:Arial,sans-serif;font-size:12px;line-height:1.6">'
              . '<h2 style="text-align:center;margin:0">{{company.name}}</h2>'
              . '<div style="text-align:center;margin-bottom:18px">{{company.address}}</div>'
              . '<hr>'
              . '<h3 style="text-align:center;text-decoration:underline">' . $title . ' {{sp.level}}</h3>'
              . '<div style="text-align:center;margin-bottom:18px">No: {{sp.reference_number}}</div>'
              . '<p>Yang bertanda tangan di bawah ini, atas nama Manajemen <strong>{{company.legal_name}}</strong>, '
              . 'dengan ini memberikan ' . $title . ' kepada:</p>'
              . '<table style="margin-left:20px;margin-bottom:12px">'
              . '<tr><td style="width:140px">Nama</td><td>: <strong>{{employee.full_name}}</strong></td></tr>'
              . '<tr><td>NIK / NIP</td><td>: {{employee.employee_number}}</td></tr>'
              . '<tr><td>Departemen</td><td>: {{department.name}}</td></tr>'
              . '<tr><td>Jabatan</td><td>: {{position.name}}</td></tr>'
              . '</table>'
              . '<p>Sehubungan dengan pelanggaran dengan rincian sebagai berikut:</p>'
              . '<table style="margin-left:20px;margin-bottom:12px">'
              . '<tr><td style="width:140px;vertical-align:top">Hal</td><td>: {{sp.subject}}</td></tr>'
              . '<tr><td style="vertical-align:top">Tanggal Kejadian</td><td>: {{sp.incident_date_id}}</td></tr>'
              . '<tr><td style="vertical-align:top">Uraian</td><td>: {{sp.description}}</td></tr>'
              . '</table>'
              . ($type === 'PHK'
                  ? '<p>Maka dengan ini hubungan kerja Saudara/i dengan ' . '{{company.name}} dinyatakan'
                    . ' diakhiri terhitung sejak <strong>{{sp.effective_date_id}}</strong>. Hak-hak Saudara/i akan dibayarkan'
                    . ' sesuai ketentuan UU No 6 Tahun 2023 dan PP 35/2021.</p>'
                  : '<p>Maka dengan ini Saudara/i diberikan ' . $title . ' {{sp.level}} terhitung'
                    . ' sejak <strong>{{sp.effective_date_id}}</strong> dan berlaku sampai <strong>{{sp.expires_at_id}}</strong>.'
                    . ' Apabila dalam masa berlakunya surat peringatan ini Saudara/i melakukan pelanggaran kembali, maka'
                    . ' perusahaan berhak memberikan sanksi yang lebih berat sesuai peraturan perusahaan.</p>')
              . '<p>Demikian surat ini dibuat untuk diperhatikan dan ditindaklanjuti seperlunya.</p>'
              . '<table style="width:100%;margin-top:36px">'
              . '<tr>'
              . '<td style="width:50%;text-align:center;vertical-align:top">'
              . '<div>{{company.city}}, {{today_long}}</div>'
              . '<div>HR / Manajemen,</div>'
              . '<div style="height:60px"></div>'
              . '<div style="text-decoration:underline"><strong>______________________</strong></div>'
              . '</td>'
              . '<td style="width:50%;text-align:center;vertical-align:top">'
              . '<div>Mengetahui & Menerima,</div>'
              . '<div>Karyawan</div>'
              . '<div style="height:60px"></div>'
              . '<div style="text-decoration:underline"><strong>{{employee.full_name}}</strong></div>'
              . '</td>'
              . '</tr></table>'
              . '</div>';

        return [
            'header_html' => '',
            'body_html'   => $body,
            'footer_html' => '',
            'include_regulations' => 0,
        ];
    }

    private function fmtDateId(?string $d): string
    {
        if (! $d) return '-';
        $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                   'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $ts = strtotime($d);
        return date('j', $ts) . ' ' . $months[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    }
}