<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * ApprovalAndContractSeeder — bibit data untuk fitur:
 *   - Work locations contoh
 *   - Approval chains default per modul (Cuti, Lembur, Perubahan Absen)
 *   - Klausul peraturan perusahaan baseline
 *   - Template kontrak PKWT & PKWTT default
 *
 * Idempotent: cek dulu sebelum insert.
 */
class ApprovalAndContractSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        $companies = $this->db->table('companies')->where('deleted_at IS NULL')->get()->getResultArray();
        if (empty($companies)) {
            echo "  Tidak ada company. Lewati ApprovalAndContractSeeder.\n";
            return;
        }
        foreach ($companies as $c) {
            $cid = (int) $c['id'];
            echo "  [{$c['name']}] seeding approval & kontrak...\n";

            $this->seedWorkLocations($cid, $c, $now);
            $this->seedApprovalChains($cid, $now);
            $this->seedRegulations($cid, $now);
            $this->seedContractTemplates($cid, $c, $now);
        }
    }

    private function seedWorkLocations(int $companyId, array $company, string $now): void
    {
        // Coba lihat branches sebagai sumber lokasi awal
        $branches = $this->db->table('branches')->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getResultArray();
        $sample = [
            [
                'code' => 'HQ',
                'name' => 'Kantor Pusat',
                'type' => 'HEAD_OFFICE',
                'address' => $company['address'] ?? null,
                'city'    => $company['city'] ?? null,
                'province'=> $company['province'] ?? null,
            ],
            [
                'code' => 'WFH',
                'name' => 'Work From Home',
                'type' => 'WFH',
                'address' => 'Lokasi rumah karyawan masing-masing',
                'allow_clock_here' => 1,
            ],
        ];
        // Tambahkan satu lokasi per branch yang ada
        foreach ($branches as $b) {
            $sample[] = [
                'code'      => 'BR-' . $b['code'],
                'name'      => $b['name'],
                'type'      => 'BRANCH_OFFICE',
                'branch_id' => (int) $b['id'],
                'address'   => $b['address'] ?? null,
                'city'      => $b['city'] ?? null,
                'province'  => $b['province'] ?? null,
                'lat'       => $b['lat'] ?? null,
                'lng'       => $b['lng'] ?? null,
                'geofence_radius_m' => $b['geofence_radius_m'] ?? null,
            ];
        }
        foreach ($sample as $row) {
            $exists = $this->db->table('work_locations')
                ->where('company_id', $companyId)
                ->where('code', $row['code'])
                ->where('deleted_at IS NULL')
                ->countAllResults();
            if ($exists > 0) continue;
            $row['company_id'] = $companyId;
            $row['allow_clock_here'] = $row['allow_clock_here'] ?? 1;
            $row['is_active']  = 1;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('work_locations')->insert($row);
        }
    }

    private function seedApprovalChains(int $companyId, string $now): void
    {
        $chains = [
            // CUTI
            [
                'code' => 'LEAVE-DEFAULT',
                'name' => 'Cuti Standar (Atasan → HR)',
                'module' => 'LEAVE',
                'description' => 'Pengajuan cuti karyawan: atasan langsung lalu HR.',
                'is_default' => 1,
                'steps' => [
                    ['name' => 'Persetujuan Atasan', 'approver_type' => 'SUPERVISOR'],
                    ['name' => 'Verifikasi HR', 'approver_type' => 'HR'],
                ],
            ],
            [
                'code' => 'LEAVE-LONG',
                'name' => 'Cuti Panjang (≥ 5 hari) — tambah Direktur',
                'module' => 'LEAVE',
                'description' => 'Untuk pengajuan cuti ≥ 5 hari kerja, ditambah persetujuan Department Head.',
                'is_default' => 0,
                'steps' => [
                    ['name' => 'Persetujuan Atasan', 'approver_type' => 'SUPERVISOR'],
                    ['name' => 'Department Head', 'approver_type' => 'DEPARTMENT_HEAD',
                        'condition_field' => 'days', 'condition_op' => '>=', 'condition_value' => 5],
                    ['name' => 'Verifikasi HR', 'approver_type' => 'HR'],
                ],
            ],
            // LEMBUR
            [
                'code' => 'OT-DEFAULT',
                'name' => 'Lembur Standar (Atasan)',
                'module' => 'OVERTIME',
                'description' => 'Pengajuan lembur cukup ke atasan langsung.',
                'is_default' => 1,
                'steps' => [
                    ['name' => 'Persetujuan Atasan', 'approver_type' => 'SUPERVISOR'],
                ],
            ],
            [
                'code' => 'OT-LONG',
                'name' => 'Lembur Panjang (> 4 jam) — tambah HR',
                'module' => 'OVERTIME',
                'description' => 'Lembur lebih dari 4 jam butuh persetujuan HR.',
                'is_default' => 0,
                'steps' => [
                    ['name' => 'Persetujuan Atasan', 'approver_type' => 'SUPERVISOR'],
                    ['name' => 'Verifikasi HR', 'approver_type' => 'HR',
                        'condition_field' => 'hours', 'condition_op' => '>', 'condition_value' => 4],
                ],
            ],
            // PERUBAHAN ABSEN
            [
                'code' => 'ATT-DEFAULT',
                'name' => 'Perubahan Absen Standar',
                'module' => 'ATTENDANCE_CHANGE',
                'description' => 'Atasan menyetujui perubahan, HR memverifikasi.',
                'is_default' => 1,
                'steps' => [
                    ['name' => 'Persetujuan Atasan', 'approver_type' => 'SUPERVISOR'],
                    ['name' => 'Verifikasi HR', 'approver_type' => 'HR'],
                ],
            ],
        ];
        foreach ($chains as $chain) {
            $exists = $this->db->table('approval_chains')
                ->where('company_id', $companyId)
                ->where('code', $chain['code'])
                ->where('deleted_at IS NULL')
                ->countAllResults();
            if ($exists > 0) continue;
            $steps = $chain['steps'];
            unset($chain['steps']);
            $chain['company_id']        = $companyId;
            $chain['fallback_strategy'] = 'HR_FALLBACK';
            $chain['is_active']         = 1;
            $chain['created_at']        = $now;
            $chain['updated_at']        = $now;
            $this->db->table('approval_chains')->insert($chain);
            $chainId = (int) $this->db->insertID();
            foreach ($steps as $i => $s) {
                $row = [
                    'company_id'           => $companyId,
                    'chain_id'             => $chainId,
                    'step_order'           => $i + 1,
                    'name'                 => $s['name'],
                    'approver_type'        => $s['approver_type'],
                    'approver_role_id'     => $s['approver_role_id'] ?? null,
                    'approver_user_id'     => $s['approver_user_id'] ?? null,
                    'approver_employee_id' => $s['approver_employee_id'] ?? null,
                    'condition_field'      => $s['condition_field'] ?? null,
                    'condition_op'         => $s['condition_op'] ?? null,
                    'condition_value'      => $s['condition_value'] ?? null,
                    'is_required'          => 1,
                    'allow_skip_if_self'   => 1,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ];
                $this->db->table('approval_chain_steps')->insert($row);
            }
        }
    }

    private function seedRegulations(int $companyId, string $now): void
    {
        $regs = [
            ['category' => 'WORKING_HOURS', 'order_no' => 10, 'title' => 'Jam Kerja',
                'body_html' => '<p>Hari kerja Senin sampai Jumat, pukul 08.00 sampai 17.00 WIB dengan istirahat 1 jam. '
                    . 'Karyawan wajib hadir tepat waktu dan melakukan absensi sesuai sistem yang berlaku.</p>'],
            ['category' => 'LEAVE', 'order_no' => 20, 'title' => 'Cuti Tahunan',
                'body_html' => '<p>Karyawan tetap berhak atas 12 hari cuti tahunan setelah masa kerja minimal 12 bulan, '
                    . 'sesuai UU Cipta Kerja. Pengajuan cuti minimal H-3 melalui sistem HRIS.</p>'],
            ['category' => 'OVERTIME', 'order_no' => 30, 'title' => 'Lembur',
                'body_html' => '<p>Lembur dihitung sesuai PP 35/2021. Wajib mendapatkan persetujuan atasan langsung sebelum dilakukan. '
                    . 'Tarif lembur: 1,5x untuk jam pertama hari kerja, 2x untuk jam berikutnya, dan 2x untuk hari libur.</p>'],
            ['category' => 'SALARY', 'order_no' => 40, 'title' => 'Pengupahan',
                'body_html' => '<p>Pembayaran gaji dilakukan setiap akhir bulan. Slip gaji dapat diakses melalui aplikasi HRIS. '
                    . 'Komponen gaji terdiri dari Gaji Pokok, Tunjangan Tetap, dan Tunjangan Tidak Tetap sesuai UU 13/2003.</p>'],
            ['category' => 'DISCIPLINE', 'order_no' => 50, 'title' => 'Disiplin Kerja',
                'body_html' => '<p>Karyawan wajib menjaga ketertiban, etika, dan profesionalisme. Pelanggaran akan ditindak '
                    . 'dengan teguran lisan, tertulis, sampai PHK sesuai tingkat pelanggaran.</p>'],
            ['category' => 'CONFIDENTIALITY', 'order_no' => 60, 'title' => 'Kerahasiaan',
                'body_html' => '<p>Karyawan wajib menjaga kerahasiaan informasi perusahaan, data karyawan lain, dan data klien '
                    . 'baik selama masa kerja maupun setelah berakhirnya hubungan kerja.</p>'],
            ['category' => 'RESIGNATION', 'order_no' => 70, 'title' => 'Pengunduran Diri',
                'body_html' => '<p>Karyawan yang ingin mengundurkan diri wajib mengajukan pemberitahuan tertulis paling lambat 30 hari '
                    . 'sebelum tanggal efektif pengunduran diri. Serah terima pekerjaan wajib dilakukan.</p>'],
            ['category' => 'BENEFITS', 'order_no' => 80, 'title' => 'Tunjangan & Fasilitas',
                'body_html' => '<p>Perusahaan mendaftarkan karyawan tetap pada BPJS Kesehatan dan BPJS Ketenagakerjaan. '
                    . 'Tunjangan transport diberikan sesuai aturan jarak rumah ke lokasi kerja yang berlaku.</p>'],
        ];
        foreach ($regs as $r) {
            $exists = $this->db->table('company_regulations')
                ->where('company_id', $companyId)
                ->where('title', $r['title'])
                ->where('deleted_at IS NULL')
                ->countAllResults();
            if ($exists > 0) continue;
            $r['company_id'] = $companyId;
            $r['is_active']  = 1;
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
            $this->db->table('company_regulations')->insert($r);
        }
    }

    private function seedContractTemplates(int $companyId, array $company, string $now): void
    {
        $companyName = $company['legal_name'] ?? $company['name'];
        $templates = [
            [
                'code'         => 'TPL-PKWTT',
                'name'         => 'Perjanjian Kerja Waktu Tidak Tertentu (PKWTT)',
                'contract_type'=> 'PKWTT',
                'is_default'   => 1,
                'description'  => 'Template default kontrak karyawan tetap.',
                'header_html'  => '<h1>PERJANJIAN KERJA WAKTU TIDAK TERTENTU</h1>'
                    . '<p style="text-align:center"><strong>Nomor: {{contract.contract_number}}</strong></p>',
                'body_html'    => self::pkwttBody(),
                'footer_html'  => self::footerBody(),
            ],
            [
                'code'         => 'TPL-PKWT',
                'name'         => 'Perjanjian Kerja Waktu Tertentu (PKWT)',
                'contract_type'=> 'PKWT',
                'is_default'   => 1,
                'description'  => 'Template default kontrak karyawan kontrak.',
                'header_html'  => '<h1>PERJANJIAN KERJA WAKTU TERTENTU</h1>'
                    . '<p style="text-align:center"><strong>Nomor: {{contract.contract_number}}</strong></p>',
                'body_html'    => self::pkwtBody(),
                'footer_html'  => self::footerBody(),
            ],
            [
                'code'         => 'TPL-PROBATION',
                'name'         => 'Perjanjian Masa Percobaan',
                'contract_type'=> 'PROBATION',
                'is_default'   => 1,
                'description'  => 'Template kontrak masa percobaan 3 bulan.',
                'header_html'  => '<h1>PERJANJIAN KERJA MASA PERCOBAAN</h1>'
                    . '<p style="text-align:center"><strong>Nomor: {{contract.contract_number}}</strong></p>',
                'body_html'    => self::probationBody(),
                'footer_html'  => self::footerBody(),
            ],
        ];
        foreach ($templates as $t) {
            $exists = $this->db->table('contract_templates')
                ->where('company_id', $companyId)
                ->where('code', $t['code'])
                ->where('deleted_at IS NULL')
                ->countAllResults();
            if ($exists > 0) continue;
            $t['company_id']          = $companyId;
            $t['language']            = 'id';
            $t['include_regulations'] = 1;
            $t['is_active']           = 1;
            $t['created_at']          = $now;
            $t['updated_at']          = $now;
            $this->db->table('contract_templates')->insert($t);
        }
    }

    // ────────────────────────────────────────────────────────────────
    // Body templates — placeholder ditulis Mustache-like {{...}}.
    // ────────────────────────────────────────────────────────────────

    private static function pkwttBody(): string
    {
        return <<<HTML
<p>Pada hari ini, {{today_long}}, telah dibuat dan ditandatangani perjanjian kerja antara:</p>
<ol>
  <li><strong>{{company.legal_name}}</strong>, beralamat di {{company.address}},
      diwakili oleh manajemen yang berwenang, selanjutnya disebut <em>"PERUSAHAAN"</em>.</li>
  <li><strong>{{employee.full_name|upper}}</strong>, NIK {{employee.nik}}, tempat/tanggal lahir
      {{employee.place_of_birth}}, {{employee.date_of_birth|date_id}}, beralamat di {{employee.ktp_address}},
      selanjutnya disebut <em>"KARYAWAN"</em>.</li>
</ol>
<p>Para pihak sepakat mengikatkan diri dalam perjanjian dengan ketentuan sebagai berikut:</p>

<h2>Pasal 1 — Jabatan dan Lokasi Kerja</h2>
<p>KARYAWAN diangkat sebagai <strong>{{position.name}}</strong> di departemen
{{department.name}}, ditempatkan di {{work_location.name}}.</p>

<h2>Pasal 2 — Masa Kerja</h2>
<p>Hubungan kerja ini bersifat tidak tertentu (PKWTT) terhitung sejak {{contract.start_date|date_id}}.</p>

<h2>Pasal 3 — Pengupahan</h2>
<p>PERUSAHAAN memberikan gaji pokok sebesar {{salary.basic|currency_id}} per bulan, ditambah tunjangan
sesuai kebijakan PERUSAHAAN. Gaji dibayarkan setiap akhir bulan ke rekening
{{employee.bank_name}} a.n. {{employee.bank_account_holder}} - {{employee.bank_account}}.</p>

<h2>Pasal 4 — Peraturan Perusahaan</h2>
<p>KARYAWAN tunduk dan patuh pada Peraturan Perusahaan yang berlaku, yang merupakan bagian tidak
terpisahkan dari perjanjian ini.</p>

{{regulations}}
HTML;
    }

    private static function pkwtBody(): string
    {
        return <<<HTML
<p>Pada hari ini, {{today_long}}, telah dibuat dan ditandatangani perjanjian kerja waktu tertentu antara:</p>
<ol>
  <li><strong>{{company.legal_name}}</strong>, beralamat di {{company.address}}, selanjutnya disebut <em>"PERUSAHAAN"</em>.</li>
  <li><strong>{{employee.full_name|upper}}</strong>, NIK {{employee.nik}}, beralamat di {{employee.ktp_address}}, selanjutnya disebut <em>"KARYAWAN"</em>.</li>
</ol>

<h2>Pasal 1 — Jabatan dan Lokasi</h2>
<p>KARYAWAN diangkat sebagai <strong>{{position.name}}</strong> di {{department.name}}, ditempatkan di {{work_location.name}}.</p>

<h2>Pasal 2 — Masa Kerja</h2>
<p>Perjanjian ini berlaku terbatas waktu, sejak <strong>{{contract.start_date|date_id}}</strong>
sampai dengan <strong>{{contract.end_date|date_id}}</strong>. Perjanjian dapat diperpanjang sesuai
ketentuan UU 6/2023 tentang Cipta Kerja.</p>

<h2>Pasal 3 — Pengupahan</h2>
<p>PERUSAHAAN memberikan gaji pokok sebesar {{salary.basic|currency_id}} per bulan.</p>

<h2>Pasal 4 — Hak dan Kewajiban</h2>
<p>KARYAWAN tunduk pada Peraturan Perusahaan dan ketentuan ketenagakerjaan yang berlaku.</p>

{{regulations}}
HTML;
    }

    private static function probationBody(): string
    {
        return <<<HTML
<p>Pada hari ini, {{today_long}}, dibuat dan ditandatangani perjanjian kerja masa percobaan antara
<strong>{{company.legal_name}}</strong> dan <strong>{{employee.full_name|upper}}</strong>.</p>

<h2>Pasal 1 — Jabatan dan Masa Percobaan</h2>
<p>KARYAWAN diangkat sebagai <strong>{{position.name}}</strong> dalam masa percobaan selama
3 (tiga) bulan, terhitung mulai {{contract.start_date|date_id}}.</p>

<h2>Pasal 2 — Pengupahan</h2>
<p>Selama masa percobaan, KARYAWAN memperoleh gaji pokok sebesar {{salary.basic|currency_id}} per bulan.</p>

<h2>Pasal 3 — Hasil Evaluasi</h2>
<p>Apabila KARYAWAN dinilai memenuhi ekspektasi, KARYAWAN akan diangkat menjadi karyawan tetap
melalui PKWTT. Apabila tidak, hubungan kerja berakhir tanpa kompensasi tambahan.</p>

{{regulations.WORKING_HOURS}}
{{regulations.DISCIPLINE}}
HTML;
    }

    private static function footerBody(): string
    {
        return <<<HTML
<p>&nbsp;</p>
<table style="border:none">
  <tr style="border:none">
    <td style="border:none; text-align:center; width:50%">
      <strong>PERUSAHAAN</strong><br>
      {{company.legal_name}}
      <br><br><br><br>
      ( ............................. )
    </td>
    <td style="border:none; text-align:center; width:50%">
      <strong>KARYAWAN</strong>
      <br><br><br><br>
      ( {{employee.full_name|upper}} )
    </td>
  </tr>
</table>
HTML;
    }
}