<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add payroll- & identity-supporting fields ke candidates table.
 *
 * Latar belakang
 * --------------
 * Saat kandidat diterima & dikonversi ke employee, payroll engine butuh
 * data minimum supaya periode pertama bisa dikalkulasi & dibayar tanpa
 * HR harus chase karyawan baru:
 *   - Rekening bank penerimaan gaji (untuk export bank-transfer batch)
 *   - PTKP status (untuk PPh21 monthly TER)
 *   - Kontak darurat (HR compliance + safety)
 *   - Kode pos & alamat sesuai KTP (e-SPT 1721 butuh kolom ini)
 *
 * Field-field ini dikumpulkan opsional di Career Portal application form
 * step "Identitas & Payroll" supaya begitu OFFER → HIRE, profil employee
 * langsung lengkap. Tetap nullable supaya kandidat yang belum punya
 * (mis. fresh graduate tanpa rekening bank) tidak ke-block.
 *
 * Defensive
 * ---------
 * - Hanya tambah kolom yang belum ada (idempotent untuk env yang sudah
 *   pernah hand-add lewat hotfix).
 * - Reverse `down()` drop hanya kolom yang kita tambah.
 */
class AddCandidatePayrollFields extends Migration
{
    /** @var list<array{name:string,spec:array<string,mixed>}> */
    private const FIELDS = [
        ['name' => 'postal_code', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 10, 'null' => true,
            'comment' => 'Kode pos sesuai KTP — wajib untuk e-SPT 1721',
        ]],
        ['name' => 'bank_name', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 50, 'null' => true,
            'comment' => 'Nama bank rekening penerimaan gaji (BCA/Mandiri/...)',
        ]],
        ['name' => 'bank_account_number', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 32, 'null' => true,
            'comment' => 'No rekening (plain di candidates; di-encrypt saat ke employees)',
        ]],
        ['name' => 'bank_account_holder', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
            'comment' => 'Nama pemilik rekening sesuai buku tabungan',
        ]],
        ['name' => 'ptkp_status', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 5, 'null' => true,
            'comment' => 'PTKP code: TK0/TK1/.../K3/HB0... (otomatis dari marital_status + dependents kalau diisi)',
        ]],
        ['name' => 'dependents_count', 'spec' => [
            'type' => 'TINYINT', 'unsigned' => true, 'null' => true, 'default' => 0,
            'comment' => 'Jumlah tanggungan (anak / ortu) max 3 untuk PTKP',
        ]],
        ['name' => 'emergency_contact_name', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
        ]],
        ['name' => 'emergency_contact_phone', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 32, 'null' => true,
        ]],
        ['name' => 'emergency_contact_relation', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 50, 'null' => true,
            'comment' => 'Hubungan: ORANG_TUA / PASANGAN / SAUDARA / ANAK / LAINNYA',
        ]],
    ];

    public function up()
    {
        if (! $this->db->tableExists('candidates')) return;

        $existing = $this->db->getFieldNames('candidates');
        $add = [];
        foreach (self::FIELDS as $f) {
            if (! in_array($f['name'], $existing, true)) {
                $add[$f['name']] = $f['spec'];
            }
        }
        if (! empty($add)) {
            $this->forge->addColumn('candidates', $add);
        }
    }

    public function down()
    {
        if (! $this->db->tableExists('candidates')) return;
        $existing = $this->db->getFieldNames('candidates');
        foreach (self::FIELDS as $f) {
            if (in_array($f['name'], $existing, true)) {
                try {
                    $this->forge->dropColumn('candidates', $f['name']);
                } catch (\Throwable $e) {
                    // ignore — beberapa engine tidak support drop column atomic
                }
            }
        }
    }
}