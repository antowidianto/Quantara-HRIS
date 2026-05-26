<?php

namespace App\Libraries;

use Config\Database;
use Config\Services;

/**
 * PaklaringService — Surat Keterangan Kerja (Paklaring) generator.
 *
 * Sesuai UU 13/2003 Pasal 153 ayat 1 & 2: setiap karyawan yang berhenti
 * (resign / PHK / kontrak habis / pensiun) berhak mendapatkan surat
 * keterangan kerja dari pengusaha. Berisi minimal:
 *   - Nama lengkap karyawan
 *   - Periode bekerja (join_date → resign_date)
 *   - Jabatan terakhir
 *   - Pernyataan tidak ada kewajiban yang tertinggal
 *   - Tanda tangan pejabat berwenang perusahaan
 *
 * Service ini:
 *   1. Auto-generate paklaring dari template
 *      `contract_templates.contract_type=PAKLARING` (atau fallback ke
 *      template default minimal kalau belum ada).
 *   2. Snapshot HTML disimpan di `generated_contracts` supaya tidak
 *      berubah meski template di-edit setelahnya.
 *   3. Link ke `resignation_requests.paklaring_id` + set timestamp.
 *   4. Audit log setiap penerbitan.
 *   5. Notifikasi ke karyawan dengan link download.
 *
 * Pre-condition (di-enforce sebelum render):
 *   - Resignation status = APPROVED
 *   - Asset clearance OK (resignation_requests.assets_returned_at NOT NULL)
 *     atau ada catatan exception (assets_clearance_note diisi)
 *   - resign_date karyawan sudah ≤ hari ini
 */
class PaklaringService
{
    /**
     * Generate paklaring untuk satu resignation_request.
     *
     * @return array{
     *   generated_contract_id: int,
     *   contract_number: string,
     *   already_existed: bool,
     *   employee_name: string,
     * }
     * @throws \RuntimeException kalau pre-condition tidak terpenuhi
     */
    public function generate(int $resignationId, int $userId): array
    {
        $db = Database::connect();
        $resign = $db->table('resignation_requests')
            ->where('id', $resignationId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $resign) {
            throw new \InvalidArgumentException("Resignation request #{$resignationId} tidak ditemukan");
        }

        $emp = $this->loadEmployee((int) $resign['employee_id']);
        if (! $emp) {
            throw new \RuntimeException("Employee #{$resign['employee_id']} tidak ditemukan");
        }

        // Idempotent: kalau sudah pernah generate dan masih ada → return existing
        if (! empty($resign['paklaring_id'])) {
            $existing = $db->table('generated_contracts')
                ->where('id', (int) $resign['paklaring_id'])
                ->where('deleted_at IS NULL')
                ->get()->getRowArray();
            if ($existing) {
                return [
                    'generated_contract_id' => (int) $existing['id'],
                    'contract_number'       => (string) $existing['contract_number'],
                    'already_existed'       => true,
                    'employee_name'         => (string) ($emp['full_name'] ?? '#' . $resign['employee_id']),
                ];
            }
            // ID lama dangling (mis. di-soft-delete) → fall through generate ulang
        }

        // Pre-condition: status APPROVED
        if ($resign['status'] !== 'APPROVED') {
            throw new \RuntimeException(
                "Paklaring hanya bisa diterbitkan untuk resignation status APPROVED. "
                . "Status saat ini: {$resign['status']}"
            );
        }

        // Pre-condition: asset clearance — hard gate untuk OFF-4
        $this->ensureAssetClearance($db, $resign, $emp);

        // Pre-condition: resign_date ≤ hari ini
        $resignDate = $emp['resign_date'] ?? $resign['effective_date'] ?? $resign['last_working_date'];
        if (! $resignDate) {
            throw new \RuntimeException("resign_date karyawan belum di-set. Tidak bisa terbitkan paklaring.");
        }
        if (strtotime((string) $resignDate) > strtotime(date('Y-m-d'))) {
            throw new \RuntimeException(
                "Paklaring belum bisa diterbitkan — tanggal efektif resign ({$resignDate}) belum tercapai."
            );
        }

        // Resolve template
        $companyId = (int) $emp['company_id'];
        $template = $this->resolveTemplate($db, $companyId);

        // Render HTML
        $renderer = new ContractRenderer();
        $context = $renderer->buildContext((int) $emp['id']);
        $context['paklaring'] = $this->buildPaklaringContext($emp, $resign);

        if ($template) {
            $renderedHtml = $renderer->render($template, $context);
        } else {
            $renderedHtml = $this->renderFallbackTemplate($context);
        }

        // Generate contract_number: PKLR-<YEAR>-<5digit>
        $now = date('Y-m-d H:i:s');
        $contractNumber = $this->nextContractNumber($db, $companyId);

        // Insert ke generated_contracts. Status SENT karena paklaring
        // bukan dokumen 2-pihak yang perlu signature flow — langsung
        // valid begitu HR menerbitkan.
        $db->table('generated_contracts')->insert([
            'company_id'           => $companyId,
            'employee_id'          => (int) $emp['id'],
            'employee_contract_id' => null,
            'template_id'          => $template ? (int) $template['id'] : null,
            'contract_number'      => $contractNumber,
            'subject'              => 'Surat Keterangan Kerja (Paklaring) — ' . ($emp['full_name'] ?? ''),
            'rendered_html'        => $renderedHtml,
            'variables_snapshot'   => json_encode($context, JSON_UNESCAPED_UNICODE),
            'status'               => 'SENT',
            'sent_at'              => $now,
            'created_by'           => $userId,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);
        $generatedId = (int) $db->insertID();

        // Update resignation request
        $db->table('resignation_requests')->where('id', $resignationId)->update([
            'paklaring_id'           => $generatedId,
            'paklaring_generated_at' => $now,
            'updated_at'             => $now,
        ]);

        // Notifikasi karyawan
        if (! empty($emp['user_id'])) {
            try {
                Services::notification()->send(
                    (int) $emp['user_id'],
                    $companyId,
                    'paklaring.issued',
                    'Surat Keterangan Kerja telah diterbitkan',
                    "Paklaring Anda dengan nomor {$contractNumber} telah terbit. "
                    . "Silakan unduh dari profil karyawan / hubungi HR untuk salinan fisik.",
                    [
                        'paklaring_id'    => $generatedId,
                        'contract_number' => $contractNumber,
                        'resignation_id'  => $resignationId,
                    ],
                    null,
                    true,
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Paklaring notification failed: ' . $e->getMessage());
            }
        }

        // Audit dengan severity NOTICE — paklaring dokumen resmi yang
        // referensiable ke perusahaan baru karyawan, harus ada trace.
        try {
            Services::audit()->forModule('OFFBOARDING')->log(
                action: 'paklaring.generate',
                entity: 'generated_contracts',
                entityId: $generatedId,
                description: "Terbitkan paklaring {$contractNumber} untuk "
                    . ($emp['full_name'] ?? '#' . $emp['id'])
                    . " (resign {$resignDate}).",
                severity: 'NOTICE',
                metadata: [
                    'resignation_id'  => $resignationId,
                    'employee_id'     => (int) $emp['id'],
                    'employee_number' => $emp['employee_number'] ?? null,
                ],
            );
        } catch (\Throwable $e) {
            log_message('warning', 'Paklaring audit failed: ' . $e->getMessage());
        }

        return [
            'generated_contract_id' => $generatedId,
            'contract_number'       => $contractNumber,
            'already_existed'       => false,
            'employee_name'         => (string) ($emp['full_name'] ?? '#' . $emp['id']),
        ];
    }

    /**
     * Hard gate untuk OFF-4: asset clearance harus selesai.
     *
     * Aturan:
     *   - Kalau `assets_returned_at` sudah di-set → lolos.
     *   - Kalau ada `assets_clearance_note` (non-empty) → lolos (HR sudah
     *     dokumentasi exception, mis. laptop hilang dengan laporan polisi).
     *   - Kalau tidak keduanya: cek apakah ada aset yang masih ASSIGNED.
     *     Kalau tidak ada → auto-set clearance OK + lolos.
     *     Kalau ada → throw RuntimeException dengan daftar aset.
     */
    private function ensureAssetClearance(
        \CodeIgniter\Database\BaseConnection $db,
        array &$resign,
        array $emp,
    ): void {
        $assetsReturned = ! empty($resign['assets_returned_at']);
        $hasException   = ! empty(trim((string) ($resign['assets_clearance_note'] ?? '')));
        if ($assetsReturned || $hasException) return;

        $stillAssigned = $db->table('assets')
            ->select('asset_number, name, category')
            ->where('current_employee_id', (int) $emp['id'])
            ->where('status', 'ASSIGNED')
            ->where('deleted_at IS NULL')
            ->orderBy('id', 'ASC')
            ->limit(20)
            ->get()->getResultArray();

        if (empty($stillAssigned)) {
            // Tidak ada aset assigned → set clearance otomatis. Hindari
            // blok unnecessary kalau memang karyawan tidak pegang aset.
            $now = date('Y-m-d H:i:s');
            $db->table('resignation_requests')->where('id', $resign['id'])->update([
                'assets_returned_at'   => $now,
                'assets_clearance_note'=> 'Tidak ada aset yang ter-assign ke karyawan ybs (auto-clear).',
                'updated_at'           => $now,
            ]);
            $resign['assets_returned_at']   = $now;
            $resign['assets_clearance_note']= 'Tidak ada aset yang ter-assign ke karyawan ybs (auto-clear).';
            return;
        }

        $names = array_map(
            fn ($a) => sprintf('%s (%s, %s)', $a['name'], $a['asset_number'], $a['category']),
            $stillAssigned,
        );
        $count = count($names);
        $shown = array_slice($names, 0, 5);
        $more  = $count > 5 ? sprintf(' (+%d lainnya)', $count - 5) : '';
        throw new \RuntimeException(
            "Belum bisa terbitkan paklaring: masih ada {$count} aset yang belum dikembalikan: "
            . implode('; ', $shown) . $more . '. '
            . 'Selesaikan return asset di modul Asset terlebih dahulu, atau '
            . 'tandai clearance dengan catatan exception (mis. laporan polisi '
            . 'untuk laptop hilang) lewat endpoint clearance.'
        );
    }

    private function loadEmployee(int $id): ?array
    {
        return Database::connect()->table('employees')
            ->where('id', $id)->get()->getRowArray() ?: null;
    }

    /**
     * Build paklaring-specific variables yang tidak ada di
     * ContractRenderer::buildContext default.
     */
    private function buildPaklaringContext(array $emp, array $resign): array
    {
        $joinDate   = $emp['join_date'] ?? null;
        $resignDate = $emp['resign_date'] ?? $resign['effective_date'] ?? $resign['last_working_date'] ?? null;

        // Hitung masa kerja
        $tenure = '';
        if ($joinDate && $resignDate) {
            try {
                $start = new \DateTimeImmutable((string) $joinDate);
                $end   = new \DateTimeImmutable((string) $resignDate);
                $diff  = $start->diff($end);
                $parts = [];
                if ($diff->y > 0) $parts[] = $diff->y . ' tahun';
                if ($diff->m > 0) $parts[] = $diff->m . ' bulan';
                if ($diff->d > 0 && empty($parts)) $parts[] = $diff->d . ' hari';
                $tenure = implode(' ', $parts) ?: 'kurang dari 1 hari';
            } catch (\Throwable $e) {
                $tenure = '';
            }
        }

        $reasonLabels = [
            'PERSONAL'   => 'alasan pribadi',
            'CAREER'     => 'pengembangan karir',
            'HEALTH'     => 'alasan kesehatan',
            'FAMILY'     => 'alasan keluarga',
            'EDUCATION'  => 'melanjutkan pendidikan',
            'RETIREMENT' => 'memasuki masa pensiun',
            'OTHER'      => 'alasan lainnya',
        ];
        $reasonLabel = $reasonLabels[$resign['reason_category'] ?? ''] ?? 'alasan pribadi';

        return [
            'tenure'           => $tenure,
            'reason_label'     => $reasonLabel,
            'reason_category'  => $resign['reason_category'] ?? 'OTHER',
            'last_working_date'=> $resign['last_working_date'] ?? null,
            'effective_date'   => $resign['effective_date'] ?? null,
            'resign_date'      => $resignDate,
            'join_date'        => $joinDate,
        ];
    }

    /**
     * Resolve template paklaring untuk company. Prioritas:
     *   1. is_default=1 dan contract_type=PAKLARING
     *   2. contract_type=PAKLARING terbaru (any active)
     *   3. null → caller pakai fallback
     */
    private function resolveTemplate(\CodeIgniter\Database\BaseConnection $db, int $companyId): ?array
    {
        // Schema lama (sebelum migration 51) belum punya enum PAKLARING.
        // Catch error untuk graceful fallback.
        try {
            $row = $db->table('contract_templates')
                ->where('company_id', $companyId)
                ->where('contract_type', 'PAKLARING')
                ->where('is_active', 1)
                ->where('deleted_at IS NULL')
                ->orderBy('is_default', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()->getRowArray();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Generate contract_number unik. Format: PKLR-<YEAR>-<5digit>
     */
    public function nextContractNumber(\CodeIgniter\Database\BaseConnection $db, int $companyId): string
    {
        $year = date('Y');
        $prefix = "PKLR-{$year}-";
        $count = $db->table('generated_contracts')
            ->where('company_id', $companyId)
            ->like('contract_number', $prefix, 'after')
            ->countAllResults();
        $tries = 0;
        while ($tries < 50) {
            $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
            $candidate = $prefix . $seq;
            $exists = $db->table('generated_contracts')
                ->where('company_id', $companyId)
                ->where('contract_number', $candidate)
                ->countAllResults();
            if ($exists === 0) return $candidate;
            $count++; $tries++;
        }
        return $prefix . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Fallback template kalau company belum punya template PAKLARING.
     * Berupa HTML minimal yang sah secara substansi UU 13/2003 Pasal 153
     * (memuat: identitas pemberi/penerima, periode kerja, jabatan, pernyataan
     * tidak ada kewajiban tertinggal). HR boleh override dengan template
     * resmi perusahaan kapan saja.
     */
    private function renderFallbackTemplate(array $context): string
    {
        $emp        = $context['employee'] ?? [];
        $company    = $context['company'] ?? [];
        $position   = $context['position'] ?? [];
        $department = $context['department'] ?? [];
        $paklaring  = $context['paklaring'] ?? [];

        $esc = static fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $longDate = static function (?string $d): string {
            if (! $d) return '-';
            $ts = strtotime($d);
            if (! $ts) return $d;
            $months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
            return date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
        };

        $companyName  = $esc($company['name']  ?? 'Perusahaan');
        $companyAddr  = $esc($company['address'] ?? '');
        $companyCity  = $esc($company['city']  ?? '');
        $signerName   = $esc($company['signer_name']  ?? 'HR Director'); // company table mungkin punya field ini
        $signerTitle  = $esc($company['signer_title'] ?? 'Human Resources');

        $empName     = $esc($emp['full_name'] ?? '');
        $empNumber   = $esc($emp['employee_number'] ?? '');
        $empNik      = $esc($emp['nik'] ?? '');
        $empBirth    = $esc($emp['date_of_birth'] ?? null);
        $empBirthLong= $longDate($emp['date_of_birth'] ?? null);
        $empAddr     = $esc($emp['address'] ?? $emp['ktp_address'] ?? '');

        $posName     = $esc($position['name'] ?? '-');
        $deptName    = $esc($department['name'] ?? '-');

        $joinDate    = $longDate($paklaring['join_date']    ?? null);
        $resignDate  = $longDate($paklaring['resign_date']  ?? null);
        $tenure      = $esc($paklaring['tenure']            ?? '-');
        $reasonLabel = $esc($paklaring['reason_label']      ?? 'alasan pribadi');

        $today       = $longDate(date('Y-m-d'));

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Surat Keterangan Kerja</title>
<style>
  body { font-family: 'Times New Roman', serif; font-size: 12pt; line-height: 1.6; color: #111; padding: 24px; }
  h1 { font-size: 14pt; text-align: center; margin: 0 0 4px; text-transform: uppercase; letter-spacing: 1px; }
  h2 { font-size: 12pt; text-align: center; margin: 0 0 32px; font-weight: normal; text-decoration: underline; }
  table.id-table { width: 80%; margin: 8px 0 16px 24px; }
  table.id-table td { vertical-align: top; padding: 2px 6px; }
  table.id-table td.label { width: 30%; }
  .signature-block { margin-top: 56px; }
  .signature-block .right { text-align: left; margin-left: auto; width: 280px; }
  .footer-note { margin-top: 32px; font-size: 10pt; color: #666; text-align: center; }
</style>
</head>
<body>

<h1>Surat Keterangan Kerja</h1>
<h2>Nomor: {{contract.number}}</h2>

<p>Yang bertanda tangan di bawah ini, atas nama manajemen <strong>{$companyName}</strong>, dengan ini menerangkan bahwa:</p>

<table class="id-table">
  <tr><td class="label">Nama</td><td>: <strong>{$empName}</strong></td></tr>
  <tr><td class="label">Nomor Induk Karyawan</td><td>: {$empNumber}</td></tr>
  <tr><td class="label">NIK</td><td>: {$empNik}</td></tr>
  <tr><td class="label">Tanggal Lahir</td><td>: {$empBirthLong}</td></tr>
  <tr><td class="label">Alamat</td><td>: {$empAddr}</td></tr>
</table>

<p>Adalah benar pernah bekerja di perusahaan kami {$companyName} dengan rincian sebagai berikut:</p>

<table class="id-table">
  <tr><td class="label">Departemen</td><td>: {$deptName}</td></tr>
  <tr><td class="label">Jabatan Terakhir</td><td>: {$posName}</td></tr>
  <tr><td class="label">Mulai Bekerja</td><td>: {$joinDate}</td></tr>
  <tr><td class="label">Berakhir</td><td>: {$resignDate}</td></tr>
  <tr><td class="label">Total Masa Kerja</td><td>: {$tenure}</td></tr>
</table>

<p>Selama bekerja di perusahaan kami, yang bersangkutan telah menjalankan tugas
dan tanggung jawabnya dengan baik. Pemutusan hubungan kerja terjadi atas
{$reasonLabel} dari yang bersangkutan, dengan tata cara yang sesuai
ketentuan internal perusahaan dan peraturan perundang-undangan yang berlaku.</p>

<p>Yang bersangkutan dinyatakan tidak memiliki ikatan kewajiban (finansial maupun
non-finansial) yang tertinggal dengan perusahaan, dan kami sampaikan terima
kasih atas kontribusinya selama masa kerja.</p>

<p>Demikian surat keterangan ini kami buat dengan sebenarnya, dapat dipergunakan
sebagaimana mestinya.</p>

<div class="signature-block">
  <div class="right">
    <p>{$companyCity}, {$today}<br>
    <strong>{$companyName}</strong></p>
    <br><br><br>
    <p style="border-top: 1px solid #111; padding-top: 4px;">
      <strong>{$signerName}</strong><br>
      {$signerTitle}
    </p>
  </div>
</div>

<p class="footer-note">Dokumen ini diterbitkan secara digital melalui sistem HRIS
dan sah tanpa cap basah.</p>

</body>
</html>
HTML;
    }
}