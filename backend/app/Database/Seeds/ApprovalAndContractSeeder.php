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
        $ceoRoleId = $this->roleId('ceo');
        $cfoRoleId = $this->roleId('cfo');
        $hrDirectorRoleId = $this->roleId('hr_director');
        $itAdminRoleId = $this->roleId('it_admin');
        // Default ke Legal/Compliance yang tersedia luas; tenant dapat mengganti
        // step ini ke role data_privacy_officer ketika pejabat DPO sudah ditunjuk.
        $privacyRoleId = $this->roleId('legal_compliance');
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
                'name' => 'Lembur Hari Libur (> 4 jam) — tambah HR',
                'module' => 'OVERTIME',
                'description' => 'Hari kerja tetap hard stop 4 jam. Step HR ini untuk lembur hari istirahat/libur resmi lebih dari 4 jam.',
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
            // TRANSAKSI FINANSIAL
            [
                'code' => 'REIMBURSE-DEFAULT',
                'name' => 'Reimbursement (Atasan -> Finance jika > Rp1 juta)',
                'module' => 'REIMBURSEMENT',
                'description' => 'Four-eyes untuk klaim; Finance wajib untuk nilai material.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Persetujuan Atasan', 'approver_type' => 'SUPERVISOR'],
                    ['name' => 'Verifikasi Finance', 'approver_type' => 'FINANCE',
                        'condition_field' => 'amount', 'condition_op' => '>', 'condition_value' => 1000000],
                ],
            ],
            [
                'code' => 'LOAN-DEFAULT',
                'name' => 'Pinjaman Karyawan (Atasan -> HR -> Finance)',
                'module' => 'LOAN',
                'description' => 'Atasan menilai kebutuhan, HR eligibility, Finance kapasitas dan jadwal potong.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Persetujuan Atasan', 'approver_type' => 'SUPERVISOR'],
                    ['name' => 'Verifikasi Eligibility HR', 'approver_type' => 'HR'],
                    ['name' => 'Persetujuan Finance', 'approver_type' => 'FINANCE'],
                ],
            ],
            [
                'code' => 'SPPD-DEFAULT',
                'name' => 'Perjalanan Dinas (Atasan -> Dept Head -> Finance)',
                'module' => 'BUSINESS_TRIP',
                'description' => 'Chain konservatif FMCG untuk otorisasi tugas, budget owner, dan uang muka.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Persetujuan Atasan', 'approver_type' => 'SUPERVISOR'],
                    ['name' => 'Persetujuan Department Head', 'approver_type' => 'DEPARTMENT_HEAD'],
                    ['name' => 'Verifikasi Advance Finance', 'approver_type' => 'FINANCE'],
                ],
            ],
            [
                'code' => 'BONUS-DEFAULT',
                'name' => 'Bonus FMCG (HR -> Finance -> Direksi jika material)',
                'module' => 'BONUS',
                'description' => 'Maker-checker bonus: HR memverifikasi populasi, Finance memverifikasi budget, direksi untuk nilai material.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Verifikasi HR', 'approver_type' => 'HR'],
                    ['name' => 'Persetujuan Finance', 'approver_type' => 'FINANCE'],
                    ['name' => 'Persetujuan Direksi', 'approver_type' => 'ROLE', 'approver_role_id' => $ceoRoleId,
                        'condition_field' => 'amount', 'condition_op' => '>', 'condition_value' => 100000000],
                ],
            ],
            [
                'code' => 'PAYADJ-DEFAULT',
                'name' => 'Koreksi Payroll (HR -> Finance -> Direksi jika material)',
                'module' => 'PAYROLL_ADJUSTMENT',
                'description' => 'Koreksi payroll historis dan reversal wajib maker-checker serta validasi periode ulang.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Verifikasi HR Payroll', 'approver_type' => 'HR'],
                    ['name' => 'Persetujuan Finance', 'approver_type' => 'FINANCE'],
                    ['name' => 'Persetujuan Direksi', 'approver_type' => 'ROLE', 'approver_role_id' => $cfoRoleId,
                        'condition_field' => 'amount', 'condition_op' => '>', 'condition_value' => 10000000],
                ],
            ],
            [
                'code' => 'COMP-DEFAULT',
                'name' => 'Perubahan Kompensasi (HR -> Finance -> Direksi jika material)',
                'module' => 'COMPENSATION_REVIEW',
                'description' => 'Perubahan upah memakai snapshot proposal, kontrol budget, dan effective-dated history.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Verifikasi HR C&B', 'approver_type' => 'HR'],
                    ['name' => 'Persetujuan Finance', 'approver_type' => 'FINANCE'],
                    ['name' => 'Persetujuan Direksi', 'approver_type' => 'ROLE', 'approver_role_id' => $hrDirectorRoleId,
                        'condition_field' => 'amount', 'condition_op' => '>', 'condition_value' => 2000000],
                ],
            ],
            [
                'code' => 'TRAINING-DEFAULT',
                'name' => 'Registrasi Training FMCG (Atasan -> Budget Owner -> HR/Finance)',
                'module' => 'TRAINING_REGISTRATION',
                'description' => 'Atasan menyetujui kebutuhan; training eksternal dan biaya material mendapat kontrol tambahan.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Persetujuan Atasan', 'approver_type' => 'SUPERVISOR'],
                    ['name' => 'Persetujuan Department Head', 'approver_type' => 'DEPARTMENT_HEAD',
                        'condition_field' => 'is_external', 'condition_op' => '=', 'condition_value' => 1],
                    ['name' => 'Verifikasi HR L&D', 'approver_type' => 'HR',
                        'condition_field' => 'is_external', 'condition_op' => '=', 'condition_value' => 1],
                    ['name' => 'Verifikasi Finance', 'approver_type' => 'FINANCE',
                        'condition_field' => 'amount', 'condition_op' => '>', 'condition_value' => 5000000],
                    ['name' => 'Persetujuan Direksi', 'approver_type' => 'ROLE', 'approver_role_id' => $hrDirectorRoleId,
                        'condition_field' => 'amount', 'condition_op' => '>', 'condition_value' => 20000000],
                ],
            ],
            [
                'code' => 'TIMESHEET-DEFAULT',
                'name' => 'Timesheet Project (Atasan -> Dept Head jika overtime)',
                'module' => 'TIMESHEET',
                'description' => 'Atasan memverifikasi jam dan project; Department Head memeriksa pencatatan overtime.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Persetujuan Atasan', 'approver_type' => 'SUPERVISOR'],
                    ['name' => 'Verifikasi Department Head', 'approver_type' => 'DEPARTMENT_HEAD',
                        'condition_field' => 'is_overtime', 'condition_op' => '=', 'condition_value' => 1],
                ],
            ],
            [
                'code' => 'OFFER-DEFAULT',
                'name' => 'Candidate Offer (Hiring Manager -> HR -> Finance/Director jika exception)',
                'module' => 'CANDIDATE_OFFER',
                'description' => 'Offer dalam salary band memerlukan Hiring Manager dan HR; salary/joining bonus exception menambah Finance dan Direksi.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Persetujuan Hiring Manager', 'approver_type' => 'HIRING_MANAGER'],
                    ['name' => 'Verifikasi HR', 'approver_type' => 'HR'],
                    ['name' => 'Verifikasi Finance', 'approver_type' => 'FINANCE',
                        'condition_field' => 'is_policy_exception', 'condition_op' => '=', 'condition_value' => 1],
                    ['name' => 'Persetujuan Direksi', 'approver_type' => 'ROLE', 'approver_role_id' => $ceoRoleId,
                        'condition_field' => 'is_policy_exception', 'condition_op' => '=', 'condition_value' => 1],
                ],
            ],
            [
                'code' => 'CONTRACT-DEFAULT',
                'name' => 'Kontrak Kerja (HR -> Finance jika perubahan gaji -> Direksi jika exception)',
                'module' => 'GENERATED_CONTRACT',
                'description' => 'Pelepasan dan pencabutan kontrak memakai snapshot dokumen serta maker-checker berdasarkan dampak gaji.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Verifikasi HR', 'approver_type' => 'HR'],
                    ['name' => 'Verifikasi Finance', 'approver_type' => 'FINANCE',
                        'condition_field' => 'is_salary_change', 'condition_op' => '=', 'condition_value' => 1],
                    ['name' => 'Persetujuan Direksi', 'approver_type' => 'ROLE', 'approver_role_id' => $hrDirectorRoleId,
                        'condition_field' => 'is_policy_exception', 'condition_op' => '=', 'condition_value' => 1],
                ],
            ],
            [
                'code' => 'ASSET-DEFAULT',
                'name' => 'Assignment/Return Asset (Atasan -> HR/Finance untuk hilang atau rusak)',
                'module' => 'ASSET_TRANSACTION',
                'description' => 'Transaksi standar disetujui atasan; kehilangan/kerusakan menambah HR dan Finance, serta Direksi untuk nilai tinggi.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Persetujuan Atasan', 'approver_type' => 'SUPERVISOR'],
                    ['name' => 'Verifikasi HR/Asset Custodian', 'approver_type' => 'HR',
                        'condition_field' => 'is_loss_or_damage', 'condition_op' => '=', 'condition_value' => 1],
                    ['name' => 'Verifikasi Finance', 'approver_type' => 'FINANCE',
                        'condition_field' => 'is_loss_or_damage', 'condition_op' => '=', 'condition_value' => 1],
                    ['name' => 'Persetujuan Direksi', 'approver_type' => 'ROLE', 'approver_role_id' => $cfoRoleId,
                        'condition_field' => 'is_high_value_loss', 'condition_op' => '=', 'condition_value' => 1],
                ],
            ],
            [
                'code' => 'HIRE-DEFAULT',
                'name' => 'Konversi Kandidat (HR checker -> Direksi jika exception)',
                'module' => 'HIRE_CONVERSION',
                'description' => 'Employee, payroll identity, kontrak, dan onboarding baru dibentuk setelah checker final.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Verifikasi HR', 'approver_type' => 'HR'],
                    ['name' => 'Persetujuan Direksi', 'approver_type' => 'ROLE', 'approver_role_id' => $hrDirectorRoleId,
                        'condition_field' => 'is_policy_exception', 'condition_op' => '=', 'condition_value' => 1],
                ],
            ],
            [
                'code' => 'PERFORMANCE-GOAL-DEFAULT',
                'name' => 'Performance Goal (Atasan Langsung)',
                'module' => 'PERFORMANCE_GOAL',
                'description' => 'Pemilik goal mengajukan target dan bobot; atasan langsung memverifikasi alignment serta ekspektasi kerja.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Persetujuan Atasan', 'approver_type' => 'SUPERVISOR'],
                ],
            ],
            [
                'code' => 'IDP-ACTIVATION-DEFAULT',
                'name' => 'Aktivasi IDP (HR Talent Checker)',
                'module' => 'IDP_ACTIVATION',
                'description' => 'Manager/HR menyusun IDP; HR Talent yang berbeda memverifikasi action plan sebelum aktif.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Verifikasi HR Talent', 'approver_type' => 'HR'],
                ],
            ],
            [
                'code' => 'EMPLOYEE-SENSITIVE-CHANGE-DEFAULT',
                'name' => 'Perubahan Data Sensitif Employee (HR -> Finance/IT Risk)',
                'module' => 'EMPLOYEE_SENSITIVE_CHANGE',
                'description' => 'Maker tidak boleh menjadi checker; HR memverifikasi identitas, Finance memverifikasi data payroll, dan perubahan login identifier mendapat kontrol tambahan.',
                'is_default' => 1,
                'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Verifikasi HR', 'approver_type' => 'HR'],
                    ['name' => 'Verifikasi Finance', 'approver_type' => 'FINANCE',
                        'condition_field' => 'is_payroll_sensitive', 'condition_op' => '=', 'condition_value' => 1],
                    ['name' => 'Verifikasi Login Identifier', 'approver_type' => 'ROLE', 'approver_role_id' => $itAdminRoleId,
                        'condition_field' => 'is_login_identifier_change', 'condition_op' => '=', 'condition_value' => 1],
                ],
            ],
            [
                'code' => 'MASTER-RATE-CHANGE-DEFAULT',
                'name' => 'Master Rate Payroll (Finance Checker -> Direksi untuk delete)',
                'module' => 'MASTER_RATE_CHANGE',
                'description' => 'Tarif tunjangan dan komponen payroll baru berlaku setelah Finance checker; penghapusan master mendapat persetujuan Direksi.',
                'is_default' => 1, 'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Verifikasi Finance', 'approver_type' => 'FINANCE'],
                    ['name' => 'Persetujuan Penghapusan', 'approver_type' => 'ROLE', 'approver_role_id' => $cfoRoleId,
                        'condition_field' => 'is_delete', 'condition_op' => '=', 'condition_value' => 1],
                ],
            ],
            [
                'code' => 'PRIVILEGED-ACTION-DEFAULT',
                'name' => 'Privileged Action (Security/HR -> DPO/Finance sesuai risiko)',
                'module' => 'PRIVILEGED_ACTION',
                'description' => 'RBAC, ekspor PII lintas karyawan, dan konfigurasi integration/secret memerlukan approval sekali pakai, terikat method/path/query/body, berlaku 24 jam.',
                'is_default' => 1, 'fallback_strategy' => 'BLOCK',
                'steps' => [
                    ['name' => 'Security Checker', 'approver_type' => 'HR'],
                    ['name' => 'DPO/Compliance untuk Ekspor PII', 'approver_type' => 'ROLE', 'approver_role_id' => $privacyRoleId,
                        'condition_field' => 'is_pii_export', 'condition_op' => '=', 'condition_value' => 1],
                    ['name' => 'Security Owner untuk RBAC/Secret', 'approver_type' => 'ROLE', 'approver_role_id' => $itAdminRoleId,
                        'condition_field' => 'is_rbac', 'condition_op' => '=', 'condition_value' => 1],
                    ['name' => 'Integration Secret Owner', 'approver_type' => 'ROLE', 'approver_role_id' => $itAdminRoleId,
                        'condition_field' => 'is_integration_secret', 'condition_op' => '=', 'condition_value' => 1],
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
            $chain['fallback_strategy'] = $chain['fallback_strategy'] ?? 'HR_FALLBACK';
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
            [
                // Sprint 2 polish — default OFFER_LETTER template untuk
                // surat penawaran kerja kandidat. Pakai placeholder yang
                // di-build oleh OfferService::buildLetterContext().
                'code'         => 'TPL-OFFER',
                'name'         => 'Surat Penawaran Kerja (Offer Letter)',
                'contract_type'=> 'OFFER_LETTER',
                'is_default'   => 1,
                'description'  => 'Template default surat penawaran kerja untuk kandidat di stage OFFER.',
                'header_html'  => '<h1>SURAT PENAWARAN KERJA</h1>'
                    . '<p style="text-align:center"><strong>Nomor: {{offer.number}}</strong></p>',
                'body_html'    => self::offerLetterBody(),
                'footer_html'  => self::offerLetterFooter(),
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

    /**
     * Body offer letter — placeholder mengikuti `OfferService::buildLetterContext()`:
     *   {{candidate.*}}, {{company.*}}, {{offer.*}}
     * Allowances rendering pakai loop sederhana (kalau ContractRenderer
     * support `{{#each}}`); kalau tidak, HR boleh edit manual lewat UI
     * template editor untuk fine-tune format.
     */
    private static function offerLetterBody(): string
    {
        return <<<HTML
<p>Pada hari ini, {{today_long}}, kami dari <strong>{{company.legal_name}}</strong>
dengan senang hati menyampaikan penawaran kerja kepada:</p>

<table style="margin: 8px 0 16px 24px; width: 80%;">
  <tr><td style="width:30%">Nama</td><td>: <strong>{{candidate.full_name|upper}}</strong></td></tr>
  <tr><td>Email</td><td>: {{candidate.email}}</td></tr>
  <tr><td>Telepon</td><td>: {{candidate.phone}}</td></tr>
</table>

<p>Berdasarkan hasil seleksi yang telah dilakukan, kami menawarkan posisi kerja
dengan rincian sebagai berikut:</p>

<h2>1. Jabatan & Lokasi Kerja</h2>
<table style="margin: 4px 0 8px 24px; width: 80%;">
  <tr><td style="width:35%">Jabatan</td><td>: <strong>{{position.name}}</strong></td></tr>
  <tr><td>Departemen</td><td>: {{department.name}}</td></tr>
  <tr><td>Cabang / Lokasi</td><td>: {{branch.name}}</td></tr>
</table>

<h2>2. Status Hubungan Kerja</h2>
<table style="margin: 4px 0 8px 24px; width: 80%;">
  <tr><td style="width:35%">Tipe Kontrak</td><td>: <strong>{{offer.employment_status}}</strong></td></tr>
  <tr><td>Tanggal Mulai Kerja</td><td>: {{offer.proposed_join_date|date_id}}</td></tr>
  <tr><td>Masa Percobaan</td><td>: {{offer.probation_months}} bulan
      <em>(sesuai UU 13/2003 Pasal 60, max 3 bulan)</em></td></tr>
</table>

<h2>3. Kompensasi</h2>
<table style="margin: 4px 0 8px 24px; width: 80%;">
  <tr><td style="width:35%">Gaji Pokok</td>
      <td>: <strong>{{offer.basic_salary|currency_id}}</strong> per bulan</td></tr>
  <tr><td>Bonus Bergabung</td>
      <td>: {{offer.joining_bonus|currency_id}}</td></tr>
</table>

<p>Tunjangan dan benefit lain mengikuti kebijakan perusahaan yang berlaku, yang
akan dijelaskan lebih lanjut pada hari pertama bekerja, antara lain mencakup:</p>
<ul>
  <li>BPJS Kesehatan & BPJS Ketenagakerjaan sesuai ketentuan UU SJSN</li>
  <li>Tunjangan Hari Raya (THR) sesuai PP 36/2021</li>
  <li>Cuti tahunan 12 hari kerja setelah masa kerja 12 bulan</li>
  <li>Tunjangan lain mengikuti grade & departemen masing-masing</li>
</ul>

<h2>4. Persetujuan & Tindak Lanjut</h2>
<p>Apabila Bapak/Ibu menerima penawaran ini, mohon konfirmasi paling lambat
<strong>{{offer.response_deadline|date_id}}</strong> melalui portal kandidat
atau menghubungi tim rekrutmen kami. Setelah konfirmasi diterima, kami akan
mengirimkan dokumen perjanjian kerja resmi untuk ditandatangani.</p>

<p>Kami sangat mengharapkan Bapak/Ibu bergabung dan berkontribusi bersama tim
kami. Atas perhatian dan kerja sama Bapak/Ibu, kami sampaikan terima kasih.</p>
HTML;
    }

    /**
     * Footer offer letter — beda dari kontrak biasa karena hanya ditandatangani
     * oleh perusahaan (kandidat respond via portal, bukan tanda tangan basah
     * di awal).
     */
    private function roleId(string $slug): int
    {
        $role = $this->db->table('roles')->select('id')->where('slug', $slug)->get()->getRowArray();
        if (! $role) throw new \RuntimeException("Role system {$slug} belum tersedia");
        return (int) $role['id'];
    }

    private static function offerLetterFooter(): string
    {
        return <<<HTML
<p>&nbsp;</p>
<table style="border:none; margin-top: 32px;">
  <tr style="border:none">
    <td style="border:none; width:50%"></td>
    <td style="border:none; text-align:left; width:50%">
      Hormat kami,<br>
      <strong>{{company.legal_name}}</strong>
      <br><br><br><br>
      <strong>Tim Rekrutmen / HR</strong>
    </td>
  </tr>
</table>

<p style="margin-top: 24px; font-size: 10pt; color: #666; text-align: center;">
Surat penawaran ini diterbitkan secara digital melalui sistem HRIS dan akan
dikonfirmasi oleh kandidat lewat portal. Status penawaran:
<strong>{{offer.number}}</strong>.
</p>
HTML;
    }
}
