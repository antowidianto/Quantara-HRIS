<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * RosterSeeder
 *
 * Seeder demo untuk fitur roster:
 *  - Update shifts existing supaya punya color, working_days, branch_id.
 *  - Tambah 1 default shift (kalau belum ada).
 *  - Tambah 3 shift pabrik: Pagi, Sore, Malam.
 *  - Buat 2 pola: "5-2 Pagi" dan "3 Shift Pabrik (siklus 21 hari)".
 *  - Idempotent: aman dijalankan ulang.
 *
 * Jalankan:
 *   php spark db:seed RosterSeeder
 */
class RosterSeeder extends Seeder
{
    public function run(): void
    {
        $db = $this->db;

        // Kerjakan untuk semua company aktif
        $companies = $db->table('companies')->where('is_active', 1)->get()->getResultArray();
        foreach ($companies as $c) {
            $this->seedForCompany((int) $c['id'], $c['name']);
        }
        echo "RosterSeeder selesai.\n";
    }

    private function seedForCompany(int $companyId, string $companyName): void
    {
        $db = $this->db;
        $now = date('Y-m-d H:i:s');

        echo "▶ Roster untuk: {$companyName} (#{$companyId})\n";

        // Branch utama (kantor) — pakai yg pertama kalau ada
        $branch = $db->table('branches')
            ->where('company_id', $companyId)
            ->orderBy('id', 'ASC')
            ->limit(1)
            ->get()->getRowArray();

        // ─── 1. Update shifts existing: kasih color & working_days ──
        $existing = $db->table('shifts')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();
        foreach ($existing as $s) {
            $update = ['updated_at' => $now];
            if (empty($s['color'])) {
                $update['color'] = $this->guessColor($s['code']);
            }
            if (empty($s['working_days'])) {
                $update['working_days'] = '1,2,3,4,5'; // Senin-Jumat default
            }
            if (count($update) > 1) {
                $db->table('shifts')->where('id', $s['id'])->update($update);
            }
        }

        // ─── 2. Pastikan ada default office shift ───────────────────
        $office = $this->upsertShift($companyId, [
            'code'            => 'OFFICE',
            'name'            => 'Kantor (08:00–17:00)',
            'start_time'      => '08:00:00',
            'end_time'        => '17:00:00',
            'break_minutes'   => 60,
            'late_tolerance_minutes' => 15,
            'is_default'      => 1,
            'color'           => '#2563eb',
            'working_days'    => '1,2,3,4,5',
            'branch_id'       => $branch ? (int) $branch['id'] : null,
            'description'     => 'Default kantor 5 hari kerja',
        ]);

        // ─── 3. Tambah 3 shift pabrik (untuk demo rotasi) ───────────
        $pagi  = $this->upsertShift($companyId, [
            'code'        => 'FACTORY_PAGI',
            'name'        => 'Pabrik Pagi (06:00–14:00)',
            'start_time'  => '06:00:00',
            'end_time'    => '14:00:00',
            'break_minutes' => 30,
            'late_tolerance_minutes' => 5,
            'color'       => '#22c55e',
            'working_days'=> '', // semua hari
            'branch_id'   => $branch ? (int) $branch['id'] : null,
            'description' => 'Shift pertama pabrik',
        ]);
        $sore  = $this->upsertShift($companyId, [
            'code'        => 'FACTORY_SORE',
            'name'        => 'Pabrik Sore (14:00–22:00)',
            'start_time'  => '14:00:00',
            'end_time'    => '22:00:00',
            'break_minutes' => 30,
            'color'       => '#f59e0b',
            'working_days'=> '',
            'branch_id'   => $branch ? (int) $branch['id'] : null,
            'description' => 'Shift kedua pabrik',
        ]);
        $malam = $this->upsertShift($companyId, [
            'code'        => 'FACTORY_MALAM',
            'name'        => 'Pabrik Malam (22:00–06:00)',
            'start_time'  => '22:00:00',
            'end_time'    => '06:00:00',
            'break_minutes' => 30,
            'crosses_midnight' => 1,
            'color'       => '#7c3aed',
            'working_days'=> '',
            'branch_id'   => $branch ? (int) $branch['id'] : null,
            'description' => 'Shift malam pabrik',
        ]);

        // ─── 4. Pattern: 5-2 Pagi (Senin-Jumat OFFICE, Sabtu-Minggu OFF) ──
        $pattern52 = $this->upsertPattern($companyId, [
            'code'        => 'P_5_2_OFFICE',
            'name'        => '5-2 Kantor (Sen–Jum)',
            'description' => 'Pola standar 5 hari kerja kantor + 2 hari libur',
            'cycle_days'  => 7,
            'pattern_data'=> json_encode([
                ['shift_id' => $office, 'is_off' => false, 'is_holiday' => false, 'label' => 'Senin'],
                ['shift_id' => $office, 'is_off' => false, 'is_holiday' => false, 'label' => 'Selasa'],
                ['shift_id' => $office, 'is_off' => false, 'is_holiday' => false, 'label' => 'Rabu'],
                ['shift_id' => $office, 'is_off' => false, 'is_holiday' => false, 'label' => 'Kamis'],
                ['shift_id' => $office, 'is_off' => false, 'is_holiday' => false, 'label' => 'Jumat'],
                ['shift_id' => null,    'is_off' => true,  'is_holiday' => false, 'label' => 'Sabtu'],
                ['shift_id' => null,    'is_off' => true,  'is_holiday' => false, 'label' => 'Minggu'],
            ]),
        ]);

        // ─── 5. Pattern: 3 Shift Pabrik 21 hari ────────────────────
        // Minggu 1: Pagi, Minggu 2: Sore, Minggu 3: Malam (sesuaikan praktik
        // pabrik yang umum di Indonesia, rotasi mingguan).
        $three = [];
        for ($i = 0; $i < 7; $i++) $three[] = ['shift_id' => $pagi,  'is_off' => $i === 6, 'is_holiday' => false];
        for ($i = 0; $i < 7; $i++) $three[] = ['shift_id' => $sore,  'is_off' => $i === 6, 'is_holiday' => false];
        for ($i = 0; $i < 7; $i++) $three[] = ['shift_id' => $malam, 'is_off' => $i === 6, 'is_holiday' => false];
        $this->upsertPattern($companyId, [
            'code'        => 'P_3SHIFT_PABRIK',
            'name'        => '3 Shift Pabrik (Rotasi Mingguan)',
            'description' => 'Pagi-Sore-Malam, rotasi tiap 7 hari, 1 hari off per minggu',
            'cycle_days'  => 21,
            'pattern_data'=> json_encode($three),
        ]);

        // ─── 6. Pattern: 4-2 (4 hari kerja, 2 hari libur) ──────────
        $fourTwo = [];
        for ($i = 0; $i < 4; $i++) $fourTwo[] = ['shift_id' => $office, 'is_off' => false, 'is_holiday' => false];
        for ($i = 0; $i < 2; $i++) $fourTwo[] = ['shift_id' => null,    'is_off' => true,  'is_holiday' => false];
        $this->upsertPattern($companyId, [
            'code'        => 'P_4_2',
            'name'        => '4-2 Office (rolling)',
            'description' => '4 hari kerja, 2 hari libur — siklus 6 hari',
            'cycle_days'  => 6,
            'pattern_data'=> json_encode($fourTwo),
        ]);

        echo "  ✓ Shifts disinkronkan\n";
        echo "  ✓ Pola: 5-2 Office, 3 Shift Pabrik, 4-2 Office\n";

        // ─── 7. Buat shift_assignments dasar ────────────────────────
        // Kalau ada branch utama → office shift jadi default branch tsb.
        if ($branch) {
            $this->upsertAssignment($companyId, [
                'shift_id'  => $pattern52, // ← bukan ini, bug! harusnya office
            ]);
        }
        // Sebenarnya `is_default=1` di office shift sudah cukup; kita tidak
        // perlu insert baris assignment lagi. Skip.
    }

    private function upsertShift(int $companyId, array $data): int
    {
        $db = $this->db;
        $existing = $db->table('shifts')
            ->where('company_id', $companyId)
            ->where('code', $data['code'])
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();

        $now = date('Y-m-d H:i:s');
        $row = array_merge([
            'company_id' => $companyId,
            'is_active'  => 1,
            'updated_at' => $now,
        ], $data);

        if ($existing) {
            $db->table('shifts')->where('id', $existing['id'])->update($row);
            return (int) $existing['id'];
        }
        $row['created_at'] = $now;
        $db->table('shifts')->insert($row);
        return (int) $db->insertID();
    }

    private function upsertPattern(int $companyId, array $data): int
    {
        $db = $this->db;
        $existing = $db->table('shift_patterns')
            ->where('company_id', $companyId)
            ->where('code', $data['code'])
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();

        $now = date('Y-m-d H:i:s');
        $row = array_merge([
            'company_id' => $companyId,
            'is_active'  => 1,
            'updated_at' => $now,
        ], $data);

        if ($existing) {
            $db->table('shift_patterns')->where('id', $existing['id'])->update($row);
            return (int) $existing['id'];
        }
        $row['created_at'] = $now;
        $db->table('shift_patterns')->insert($row);
        return (int) $db->insertID();
    }

    private function upsertAssignment(int $companyId, array $data): void
    {
        // Disabled: kita pakai shifts.is_default sebagai mekanisme default.
    }

    private function guessColor(string $code): string
    {
        $code = strtoupper($code);
        if (str_contains($code, 'PAGI') || str_contains($code, 'MORNING')) return '#22c55e';
        if (str_contains($code, 'SORE') || str_contains($code, 'AFTERNOON') || str_contains($code, 'EVENING')) return '#f59e0b';
        if (str_contains($code, 'MALAM') || str_contains($code, 'NIGHT')) return '#7c3aed';
        if (str_contains($code, 'OFFICE') || str_contains($code, 'KANTOR')) return '#2563eb';
        return '#64748b';
    }
}