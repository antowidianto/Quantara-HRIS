<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Koreksi kalender libur nasional 2026 sesuai SKB 3 Menteri.
 *
 * Hanya baris global (`company_id IS NULL`) tahun 2026 yang dinormalisasi.
 * Kalender khusus tenant tidak disentuh. Cuti bersama sengaja tidak dicampur
 * ke daftar ini karena implementasinya mengikuti kebijakan perusahaan.
 */
final class CorrectNationalHolidays2026 extends Migration
{
    /** @var array<string, array{0:string,1:int}> */
    private const HOLIDAYS = [
        '2026-01-01' => ['Tahun Baru 2026 Masehi', 0],
        '2026-01-16' => ['Isra Mikraj Nabi Muhammad SAW', 1],
        '2026-02-17' => ['Tahun Baru Imlek 2577 Kongzili', 1],
        '2026-03-19' => ['Hari Suci Nyepi (Tahun Baru Saka 1948)', 1],
        '2026-03-21' => ['Idulfitri 1447 Hijriah (Hari Pertama)', 1],
        '2026-03-22' => ['Idulfitri 1447 Hijriah (Hari Kedua)', 1],
        '2026-04-03' => ['Wafat Yesus Kristus', 1],
        '2026-04-05' => ['Kebangkitan Yesus Kristus (Paskah)', 1],
        '2026-05-01' => ['Hari Buruh Internasional', 0],
        '2026-05-14' => ['Kenaikan Yesus Kristus', 1],
        '2026-05-27' => ['Iduladha 1447 Hijriah', 1],
        '2026-05-31' => ['Hari Raya Waisak 2570 BE', 1],
        '2026-06-01' => ['Hari Lahir Pancasila', 0],
        '2026-06-16' => ['1 Muharam Tahun Baru Islam 1448 Hijriah', 1],
        '2026-08-17' => ['Proklamasi Kemerdekaan', 0],
        '2026-08-25' => ['Maulid Nabi Muhammad SAW', 1],
        '2026-12-25' => ['Kelahiran Yesus Kristus', 1],
    ];

    public function up()
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('holidays')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $db->transStart();

        // Buang seluruh baseline global 2026 agar tanggal lama yang salah
        // (20 Maret, 28/29 Mei, 17 Juni, 26 Agustus) tidak tertinggal.
        $db->table('holidays')
            ->where('company_id IS NULL')
            ->where('date >=', '2026-01-01')
            ->where('date <=', '2026-12-31')
            ->delete();

        foreach (self::HOLIDAYS as $date => [$name, $isReligious]) {
            $db->table('holidays')->insert([
                'company_id' => null,
                'date' => $date,
                'name' => $name,
                'is_national' => 1,
                'is_religious' => $isReligious,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $db->transComplete();
    }

    public function down()
    {
        // Kalender legal tidak dikembalikan ke baseline yang diketahui salah.
    }
}