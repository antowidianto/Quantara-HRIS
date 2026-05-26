<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add extended profile fields ke candidates (Sprint 23+).
 *
 * Latar belakang
 * --------------
 * Lanjutan dari migration 000049 (payroll fields). Setelah review HR,
 * masih ada field standar HR Indonesia yang berguna dikumpulkan saat
 * apply via Career Portal supaya saat hire profil employee benar-benar
 * lengkap. Fokus: data yang biasanya HR baru chase setelah karyawan
 * masuk → kalau sudah ada di candidate, bisa langsung copy ke profile.
 *
 * Cakupan field
 * -------------
 * Profil & identitas tambahan:
 *   - blood_type            — golongan darah (untuk emergency)
 *   - driving_license       — kepemilikan SIM (A/B1/B2/C/D), comma-separated
 *   - vehicle_owned         — kendaraan pribadi (MOTOR/MOBIL), comma-separated
 *   - passport_number       — paspor (untuk WNA / perjalanan dinas)
 *   - disability_status     — info disabilitas (HR compliance)
 *   - disability_notes      — detail / akomodasi yg dibutuhkan
 *
 * Keluarga (untuk PTKP & BPJS family inclusion):
 *   - spouse_name           — nama pasangan
 *   - spouse_occupation     — pekerjaan pasangan
 *   - spouse_workplace      — tempat kerja pasangan (relevan PTKP HB)
 *   - children_count        — jumlah anak (bisa beda dari dependents PTKP)
 *
 * Kesehatan (opt-in, sensitive):
 *   - health_conditions     — riwayat alergi/penyakit kronis
 *
 * Skill & referensi:
 *   - languages             — bahasa yang dikuasai + level (JSON or comma)
 *   - professional_skills   — keahlian teknis / soft skill (text)
 *   - certifications        — sertifikasi profesional (text)
 *   - reference_contacts    — referensi profesional (JSON array)
 *
 * Lainnya:
 *   - referral_source       — narasi sumber lamaran detail (mis. nama
 *                             karyawan yg refer kalau referral program)
 *   - github_url            — github profile (untuk role IT)
 *
 * Defensive: hanya tambah kolom yang belum ada.
 */
class AddCandidateExtendedProfile extends Migration
{
    /** @var list<array{name:string,spec:array<string,mixed>}> */
    private const FIELDS = [
        // Profil & identitas
        ['name' => 'blood_type', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 5, 'null' => true,
            'comment' => 'Golongan darah: A/B/AB/O + Rh (mis. A+, O-)',
        ]],
        ['name' => 'driving_license', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 50, 'null' => true,
            'comment' => 'SIM yang dimiliki, comma-separated: A,B1,B2,C,D',
        ]],
        ['name' => 'vehicle_owned', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 50, 'null' => true,
            'comment' => 'Kendaraan pribadi, comma-separated: MOTOR,MOBIL,SEPEDA',
        ]],
        ['name' => 'passport_number', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 32, 'null' => true,
            'comment' => 'Nomor paspor (untuk WNA atau perjalanan dinas)',
        ]],
        ['name' => 'disability_status', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 30, 'null' => true,
            'comment' => 'NONE/PHYSICAL/SENSORY/INTELLECTUAL/MENTAL/MULTIPLE',
        ]],
        ['name' => 'disability_notes', 'spec' => [
            'type' => 'TEXT', 'null' => true,
            'comment' => 'Detail kondisi & akomodasi kerja yang dibutuhkan',
        ]],

        // Keluarga
        ['name' => 'spouse_name', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
        ]],
        ['name' => 'spouse_occupation', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
        ]],
        ['name' => 'spouse_workplace', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
            'comment' => 'Tempat kerja pasangan (relevan untuk PTKP HB)',
        ]],
        ['name' => 'children_count', 'spec' => [
            'type' => 'TINYINT', 'unsigned' => true, 'null' => true, 'default' => 0,
        ]],

        // Kesehatan (opt-in, sensitive)
        ['name' => 'health_conditions', 'spec' => [
            'type' => 'TEXT', 'null' => true,
            'comment' => 'Riwayat alergi/penyakit kronis (opt-in, untuk emergency)',
        ]],

        // Skills & references
        ['name' => 'languages', 'spec' => [
            'type' => 'TEXT', 'null' => true,
            'comment' => 'Bahasa + level, format: "Indonesia: native; English: advanced"',
        ]],
        ['name' => 'professional_skills', 'spec' => [
            'type' => 'TEXT', 'null' => true,
            'comment' => 'Keahlian teknis & soft skills',
        ]],
        ['name' => 'certifications', 'spec' => [
            'type' => 'TEXT', 'null' => true,
            'comment' => 'Sertifikasi profesional yang dimiliki',
        ]],
        ['name' => 'reference_contacts', 'spec' => [
            'type' => 'TEXT', 'null' => true,
            'comment' => 'JSON array: [{name, position, company, phone, relation}]',
        ]],

        // Lain-lain
        ['name' => 'referral_source_detail', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 255, 'null' => true,
            'comment' => 'Narasi sumber lamaran (nama referrer, dst)',
        ]],
        ['name' => 'github_url', 'spec' => [
            'type' => 'VARCHAR', 'constraint' => 255, 'null' => true,
            'comment' => 'Github profile (untuk role IT/developer)',
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
                    // ignore
                }
            }
        }
    }
}