<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Seed default Onboarding & Offboarding task templates per perusahaan.
 * Idempotent: skip kalau code sudah ada.
 */
class TaskTemplatesSeeder extends Seeder
{
    public function run()
    {
        $companies = $this->db->table('companies')->where('is_active', 1)->get()->getResultArray();
        if (empty($companies)) {
            echo "  ⚠ Tidak ada company aktif. Skip TaskTemplatesSeeder.\n";
            return;
        }

        $now = date('Y-m-d H:i:s');
        $defaults = [
            // Onboarding
            [
                'code' => 'ONBOARD_DEFAULT',
                'name' => 'Onboarding Karyawan Baru',
                'type' => 'ONBOARD',
                'description' => 'Checklist standar untuk karyawan baru pada hari pertama hingga 30 hari.',
                'is_default' => 1,
                'tasks' => [
                    ['key' => 'WELCOME_MEET',    'name' => 'Sambutan & perkenalan tim',                'owner_role' => 'SUPERVISOR', 'due_offset_days' => 0,  'mandatory' => true,  'description' => 'Pertemuan singkat dengan atasan langsung & rekan sekerja.'],
                    ['key' => 'CONTRACT_SIGN',   'name' => 'Tanda tangan kontrak kerja',               'owner_role' => 'HR',         'due_offset_days' => 0,  'mandatory' => true,  'description' => 'Karyawan menerima & menandatangani PKWT/PKWTT.'],
                    ['key' => 'EMPLOYEE_HANDBOOK','name' => 'Baca peraturan perusahaan',                'owner_role' => 'EMPLOYEE',   'due_offset_days' => 1,  'mandatory' => true,  'description' => 'Baca peraturan perusahaan / employee handbook.'],
                    ['key' => 'SETUP_LAPTOP',    'name' => 'Setup laptop & email perusahaan',          'owner_role' => 'IT',         'due_offset_days' => 0,  'mandatory' => true,  'description' => 'Provisioning laptop, akun email, dan akses sistem.'],
                    ['key' => 'SETUP_HRIS',      'name' => 'Buat akun HRIS karyawan',                  'owner_role' => 'HR',         'due_offset_days' => 0,  'mandatory' => true,  'description' => 'Pastikan user HRIS sudah dibuat & terhubung ke employee.'],
                    ['key' => 'BPJS_REGISTER',   'name' => 'Pendaftaran BPJS Kesehatan & TK',          'owner_role' => 'HR',         'due_offset_days' => 7,  'mandatory' => true,  'description' => 'Registrasi BPJS atas nama karyawan baru.'],
                    ['key' => 'NPWP_VERIFY',     'name' => 'Verifikasi NPWP karyawan',                 'owner_role' => 'HR',         'due_offset_days' => 7,  'mandatory' => true],
                    ['key' => 'BANK_ACCOUNT',    'name' => 'Buat / verifikasi rekening bank',          'owner_role' => 'EMPLOYEE',   'due_offset_days' => 7,  'mandatory' => true],
                    ['key' => 'SUBMIT_DOCS',     'name' => 'Setor dokumen pribadi (KTP, NPWP, ijazah)','owner_role' => 'EMPLOYEE',   'due_offset_days' => 3,  'mandatory' => true],
                    ['key' => 'INDUCTION',       'name' => 'Mengikuti induksi K3 & company culture',   'owner_role' => 'HR',         'due_offset_days' => 7,  'mandatory' => true],
                    ['key' => 'TRAINING_ROLE',   'name' => 'Training spesifik peran',                  'owner_role' => 'SUPERVISOR', 'due_offset_days' => 14, 'mandatory' => false],
                    // Sprint 2 (ONB-1) — checkpoint review milestones
                    ['key' => 'MILESTONE_30D',   'name' => '30-Day Check-in (Onboarding milestone)',   'owner_role' => 'SUPERVISOR', 'due_offset_days' => 30, 'mandatory' => true,  'description' => 'Review awal 30 hari: cek adaptasi karyawan, blocker awal, kebutuhan training tambahan. Best practice modern HR.'],
                    ['key' => 'MILESTONE_60D',   'name' => '60-Day Review (Onboarding milestone)',     'owner_role' => 'SUPERVISOR', 'due_offset_days' => 60, 'mandatory' => true,  'description' => 'Review menengah 60 hari: assessment performa awal, feedback 2 arah, set goal probation review.'],
                    ['key' => 'MILESTONE_90D',   'name' => '90-Day Evaluation (Probation Decision)',   'owner_role' => 'SUPERVISOR', 'due_offset_days' => 90, 'mandatory' => true,  'description' => 'Evaluasi akhir probation: PASS/FAIL/EXTEND. Wajib UU 13/2003 Pasal 60. Submit keputusan via /probation/decide.'],
                ],
            ],
            // Offboarding
            [
                'code' => 'OFFBOARD_DEFAULT',
                'name' => 'Offboarding Karyawan Keluar',
                'type' => 'OFFBOARD',
                'description' => 'Checklist standar saat karyawan keluar (resign, PHK, pensiun).',
                'is_default' => 1,
                'tasks' => [
                    ['key' => 'EXIT_INTERVIEW',  'name' => 'Exit interview',                            'owner_role' => 'HR',         'due_offset_days' => 0,  'mandatory' => true,  'description' => 'Wawancara akhir untuk feedback & pelajaran organisasi.'],
                    ['key' => 'RETURN_LAPTOP',   'name' => 'Kembalikan laptop & perangkat IT',         'owner_role' => 'EMPLOYEE',   'due_offset_days' => 0,  'mandatory' => true,  'description' => 'Serahkan laptop, charger, mouse, ID card, dst.'],
                    ['key' => 'RETURN_ASSETS',   'name' => 'Kembalikan aset perusahaan lain',          'owner_role' => 'EMPLOYEE',   'due_offset_days' => 0,  'mandatory' => true,  'description' => 'Kunci, kendaraan, kartu akses, dokumen rahasia.'],
                    ['key' => 'ASSET_CLEARANCE', 'name' => 'Verifikasi semua aset sudah dikembalikan', 'owner_role' => 'HR',         'due_offset_days' => 1,  'mandatory' => true,  'description' => 'HR konfirmasi via modul Offboarding Clearance. Wajib OK sebelum paklaring & final settlement bisa diterbitkan.'],
                    ['key' => 'REVOKE_EMAIL',    'name' => 'Cabut akses email & sistem',               'owner_role' => 'IT',         'due_offset_days' => 0,  'mandatory' => true,  'description' => 'Disable akun email, VPN, dan aplikasi internal.'],
                    ['key' => 'REVOKE_HRIS',     'name' => 'Nonaktifkan akun HRIS',                    'owner_role' => 'HR',         'due_offset_days' => 1,  'mandatory' => true],
                    ['key' => 'PESANGON_CALC',   'name' => 'Hitung & bayar pesangon / hak akhir',      'owner_role' => 'HR',         'due_offset_days' => 7,  'mandatory' => true,  'description' => 'Generate severance calculation lewat modul Severance. Wajib bayar ≤ 7 hari dari resign_date (UU 13/2003 Pasal 156 ayat 4).'],
                    ['key' => 'CLEARANCE_FIN',   'name' => 'Clearance keuangan (kasbon, hutang, dll)', 'owner_role' => 'FINANCE',    'due_offset_days' => 7,  'mandatory' => true],
                    ['key' => 'BPJS_DEACTIVATE', 'name' => 'Deaktivasi BPJS Kesehatan & Ketenagakerjaan','owner_role'=> 'HR',         'due_offset_days' => 7,  'mandatory' => true,  'description' => 'Wajib dilakukan via e-Dabu (Kesehatan) & SiPP (Ketenagakerjaan) ≤ H+7 dari resign_date. Telat menyebabkan karyawan masih dipotong iuran walau sudah keluar.'],
                    ['key' => 'BPJS_RESIGN',     'name' => 'Pencairan BPJS Ketenagakerjaan',           'owner_role' => 'HR',         'due_offset_days' => 14, 'mandatory' => false, 'description' => 'Bantu karyawan claim JHT BPJS-TK (kalau dipilih).'],
                    ['key' => 'PAKLARING',       'name' => 'Surat keterangan kerja (paklaring)',       'owner_role' => 'HR',         'due_offset_days' => 7,  'mandatory' => true,  'description' => 'Wajib UU 13/2003 Pasal 153. Generate via modul Offboarding setelah asset clearance OK.'],
                    ['key' => 'FINAL_SETTLEMENT','name' => 'Final settlement (gaji prorata + UPH + pesangon)','owner_role'=>'HR', 'due_offset_days' => 7,  'mandatory' => true,  'description' => 'Bayar lewat payroll periode aktif atau transfer manual. Wajib ≤ H+7 dari resign_date.'],
                    ['key' => 'TAX_CLEARANCE',   'name' => 'Bukti potong PPh21 final',                 'owner_role' => 'HR',         'due_offset_days' => 14, 'mandatory' => true,  'description' => 'Generate dari modul Payroll Output (1721-A1).'],
                    ['key' => 'KNOWLEDGE_TFR',   'name' => 'Knowledge transfer ke pengganti',          'owner_role' => 'EMPLOYEE',   'due_offset_days' => 0,  'mandatory' => false],
                    ['key' => 'EMPLOYEE_DEACT',  'name' => 'Set status karyawan jadi tidak aktif',     'owner_role' => 'HR',         'due_offset_days' => 0,  'mandatory' => true],
                ],
            ],
        ];

        $totalCreated = 0;
        foreach ($companies as $c) {
            foreach ($defaults as $tpl) {
                $exists = $this->db->table('task_templates')
                    ->where('company_id', (int) $c['id'])
                    ->where('code', $tpl['code'])
                    ->countAllResults();
                if ($exists > 0) continue;

                $this->db->table('task_templates')->insert([
                    'company_id' => (int) $c['id'],
                    'code'       => $tpl['code'],
                    'name'       => $tpl['name'],
                    'type'       => $tpl['type'],
                    'description'=> $tpl['description'],
                    'is_default' => (int) $tpl['is_default'],
                    'is_active'  => 1,
                    'tasks'      => json_encode($tpl['tasks'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $totalCreated++;
            }
        }
        echo "  ✓ {$totalCreated} task templates seeded ke " . count($companies) . " perusahaan.\n";
    }
}