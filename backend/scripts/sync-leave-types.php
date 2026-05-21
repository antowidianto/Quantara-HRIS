<?php

/**
 * Sync default leave types ke seluruh perusahaan yang sudah ada.
 *
 * Aman dipanggil berkali-kali (idempotent): hanya menambah tipe cuti
 * dengan code yang belum ada di perusahaan tersebut. Mengakses SQLite
 * langsung supaya tidak butuh bootstrap CodeIgniter.
 *
 * Jalankan dari root backend:
 *   php scripts/sync-leave-types.php
 */

$dbPath = __DIR__ . '/../writable/hris.sqlite';
if (! file_exists($dbPath)) {
    fwrite(STDERR, "Database tidak ditemukan: {$dbPath}\n");
    exit(1);
}
$db  = new SQLite3($dbPath);
$now = date('Y-m-d H:i:s');

$leaveTypes = [
    ['code' => 'CT',  'name' => 'Cuti Tahunan',                       'category' => 'ANNUAL',     'default_days' => 12, 'min_tenure_months' => 12, 'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '12 hari per tahun setelah masa kerja 12 bulan (UU 13/2003 Pasal 79).'],
    ['code' => 'CS',  'name' => 'Cuti Sakit',                         'category' => 'SICK',       'default_days' => 0,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 1, 'gender_restriction' => 'ALL', 'description' => 'Memerlukan surat dokter. Pembayaran upah mengikuti UU 13/2003 Pasal 93.'],
    ['code' => 'CM',  'name' => 'Cuti Melahirkan (3 bulan)',          'category' => 'MATERNITY',  'default_days' => 90, 'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'F',   'description' => '1,5 bulan sebelum dan 1,5 bulan sesudah melahirkan (UU 13/2003 Pasal 82). Khusus karyawan perempuan.'],
    ['code' => 'CPM', 'name' => 'Cuti Pendamping Melahirkan',         'category' => 'PATERNITY',  'default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'M',   'description' => '2 hari mendampingi istri melahirkan / keguguran (UU 13/2003 Pasal 93). Khusus karyawan laki-laki yang menikah.'],
    ['code' => 'CB',  'name' => 'Cuti Besar (per 6 tahun)',           'category' => 'SPECIAL',    'default_days' => 30, 'min_tenure_months' => 72, 'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => 'Cuti panjang setelah masa kerja 6 tahun.'],
    ['code' => 'CH',  'name' => 'Cuti Haid',                          'category' => 'MENSTRUAL',  'default_days' => 24, 'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'F',   'description' => 'Pekerja perempuan yang merasa sakit pada hari pertama dan kedua masa haid (UU 13/2003 Pasal 81).'],
    ['code' => 'CN',  'name' => 'Cuti Menikah',                       'category' => 'MARRIAGE',   'default_days' => 3,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '3 hari saat pernikahan pekerja sendiri (UU 13/2003 Pasal 93).'],
    ['code' => 'CMA', 'name' => 'Cuti Menikahkan Anak',               'category' => 'SPECIAL',    'default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '2 hari saat menikahkan anak (UU 13/2003 Pasal 93).'],
    ['code' => 'CKA', 'name' => 'Cuti Khitanan / Baptis Anak',        'category' => 'SPECIAL',    'default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '2 hari saat mengkhitankan / membaptiskan anak (UU 13/2003 Pasal 93).'],
    ['code' => 'CKL', 'name' => 'Cuti Kelahiran Anak',                'category' => 'SPECIAL',    'default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '2 hari saat istri pekerja melahirkan / keguguran (UU 13/2003 Pasal 93).'],
    ['code' => 'CD',  'name' => 'Cuti Duka (Keluarga Inti Meninggal)','category' => 'BEREAVEMENT','default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '2 hari saat suami/istri/orang tua/mertua/anak/menantu meninggal (UU 13/2003 Pasal 93).'],
    ['code' => 'CDR', 'name' => 'Cuti Duka (Anggota Keluarga Serumah)','category' => 'BEREAVEMENT','default_days' => 1,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '1 hari saat anggota keluarga dalam satu rumah meninggal (UU 13/2003 Pasal 93).'],
    ['code' => 'CIH', 'name' => 'Cuti Ibadah Haji',                   'category' => 'HAJJ',       'default_days' => 40, 'min_tenure_months' => 12, 'is_paid' => 1, 'requires_doc' => 1, 'gender_restriction' => 'ALL', 'description' => 'Sekali seumur masa kerja untuk menunaikan ibadah haji (UU 13/2003 Pasal 80).'],
    ['code' => 'IB',  'name' => 'Izin Khusus (Anak Sakit, dll)',      'category' => 'SPECIAL',    'default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => 'Izin tidak masuk dengan keperluan keluarga mendesak.'],
    ['code' => 'CTB', 'name' => 'Cuti Tidak Berbayar',                'category' => 'UNPAID',     'default_days' => 30, 'min_tenure_months' => 0,  'is_paid' => 0, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => 'Unpaid leave dengan persetujuan khusus dari HR.'],
];

$companies = [];
$res = $db->query('SELECT id, name FROM companies WHERE deleted_at IS NULL');
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $companies[] = $r;

$totalCreated = 0;
$insertSql = 'INSERT INTO leave_types
    (company_id, code, name, category, default_days, min_tenure_months,
     is_paid, requires_doc, gender_restriction, reset_yearly, max_days,
     description, is_active, created_at, updated_at)
    VALUES (:cid, :code, :name, :cat, :dd, :tm, :ip, :rd, :gr, 1, NULL,
            :desc, 1, :now, :now)';

foreach ($companies as $c) {
    echo "\n→ Sinkronisasi tipe cuti untuk: {$c['name']} (id={$c['id']})\n";
    foreach ($leaveTypes as $lt) {
        $check = $db->prepare('SELECT COUNT(*) AS c FROM leave_types WHERE company_id = :cid AND code = :code AND deleted_at IS NULL');
        $check->bindValue(':cid', (int) $c['id'], SQLITE3_INTEGER);
        $check->bindValue(':code', $lt['code'], SQLITE3_TEXT);
        $exists = (int) $check->execute()->fetchArray(SQLITE3_ASSOC)['c'];
        if ($exists > 0) {
            echo "   • {$lt['code']} sudah ada, skip.\n";
            continue;
        }
        $stmt = $db->prepare($insertSql);
        $stmt->bindValue(':cid',  (int) $c['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':code', $lt['code']);
        $stmt->bindValue(':name', $lt['name']);
        $stmt->bindValue(':cat',  $lt['category']);
        $stmt->bindValue(':dd',   $lt['default_days'], SQLITE3_INTEGER);
        $stmt->bindValue(':tm',   $lt['min_tenure_months'], SQLITE3_INTEGER);
        $stmt->bindValue(':ip',   $lt['is_paid'], SQLITE3_INTEGER);
        $stmt->bindValue(':rd',   $lt['requires_doc'], SQLITE3_INTEGER);
        $stmt->bindValue(':gr',   $lt['gender_restriction']);
        $stmt->bindValue(':desc', $lt['description']);
        $stmt->bindValue(':now',  $now);
        $stmt->execute();
        $totalCreated++;
        echo "   + {$lt['code']} {$lt['name']}\n";
    }
}

echo "\nSelesai. Total tipe cuti baru ditambahkan: {$totalCreated}.\n";