<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Seed kategori pelanggaran umum yang lazim dipakai di perusahaan
 * Indonesia. Idempotent — kalau code sudah ada untuk company tsb akan
 * di-skip.
 */
class DisciplinaryCategoriesSeeder extends Seeder
{
    public function run()
    {
        $companies = $this->db->table('companies')->where('is_active', 1)->get()->getResultArray();
        if (empty($companies)) {
            echo "  ⚠ Tidak ada company aktif. Skip seeder kategori SP.\n";
            return;
        }

        $defaults = [
            ['DISC',     'Indisipliner / pelanggaran tata tertib',           'MINOR',     'VERBAL'],
            ['LATE',     'Terlambat berulang',                                'MINOR',     'SP1'],
            ['ABSENT',   'Tidak masuk tanpa keterangan',                      'MODERATE',  'SP1'],
            ['ABSENT5',  'Mangkir 5 hari berturut-turut tanpa keterangan',    'SEVERE',    'TERMINATION'],
            ['ETIKA',    'Pelanggaran etika & sopan santun',                  'MINOR',     'SP1'],
            ['CONFL',    'Konflik / perkelahian dengan rekan kerja',          'MAJOR',     'SP2'],
            ['K3',       'Pelanggaran K3 (Keselamatan Kerja)',                'MAJOR',     'SP2'],
            ['SOP',      'Tidak mengikuti SOP / prosedur kerja',              'MODERATE',  'SP1'],
            ['ASSET',    'Penyalahgunaan aset perusahaan',                    'MAJOR',     'SP2'],
            ['INTEGRITY','Pelanggaran integritas (kecurangan, manipulasi)',   'SEVERE',    'SP3'],
            ['THEFT',    'Pencurian / penggelapan',                           'SEVERE',    'TERMINATION'],
            ['LEAK',     'Membocorkan rahasia perusahaan',                    'SEVERE',    'TERMINATION'],
            ['DRUG',     'Penyalahgunaan narkotika / minuman keras',          'SEVERE',    'TERMINATION'],
            ['PERF',     'Performa kerja di bawah standar',                   'MODERATE',  'SP1'],
            ['INSUB',    'Insubordinasi / membangkang perintah atasan',       'MAJOR',     'SP2'],
        ];

        $now = date('Y-m-d H:i:s');
        $totalInserted = 0;
        foreach ($companies as $c) {
            foreach ($defaults as [$code, $name, $severity, $level]) {
                $exists = $this->db->table('disciplinary_categories')
                    ->where('company_id', (int) $c['id'])
                    ->where('code', $code)
                    ->countAllResults();
                if ($exists > 0) continue;

                $this->db->table('disciplinary_categories')->insert([
                    'company_id'        => (int) $c['id'],
                    'code'              => $code,
                    'name'              => $name,
                    'severity'          => $severity,
                    'recommended_level' => $level,
                    'is_active'         => 1,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
                $totalInserted++;
            }
        }
        echo "  ✓ {$totalInserted} kategori SP ditambahkan untuk " . count($companies) . " perusahaan.\n";
    }
}