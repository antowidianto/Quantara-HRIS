<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration #066 — MPP overdue tracking + reminder.
 *
 * Latar belakang. MPP otomatis dibuat saat resignasi disetujui HR
 * (lihat ManpowerService::autoCreateForResignation), dengan
 * `target_start_date` = effective_date resign karyawan A. Tapi:
 *
 *   - Status MPP-nya cuma `HIRING` selama belum penuh — tidak peduli
 *     `target_start_date` sudah lewat atau belum. Tidak ada warning
 *     visual untuk HRD.
 *   - Tidak ada eskalasi otomatis kalau MPP tetap di DRAFT padahal
 *     mendekati / sudah lewat tanggal target.
 *
 * Solusi (lightweight, additive):
 *
 *   1. Kolom `overdue_warning_sent_at` — timestamp terakhir kali cron
 *      kirim reminder H-30 (untuk anti-spam supaya tidak banjir notif).
 *   2. Kolom `overdue_escalated_at` — timestamp eskalasi pertama kali
 *      ke Direktur saat MPP overdue (target_start_date < today + masih
 *      DRAFT/SUBMITTED/APPROVED/HIRING + belum FILLED).
 *   3. Kolom `vacancy_started_at` — saat MPP REPLACEMENT dibuat dari
 *      resignasi, ini di-set ke `effective_date` resigner. Jadi UI bisa
 *      hitung "sudah X hari kosong" tanpa join ulang ke resignations.
 *
 * Idempotent. Tidak menyentuh data existing — kolom baru semua nullable.
 */
class AddMppOverdueTracking extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('manpower_requests')) return;

        $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('manpower_requests'));
        $additions = [];

        if (! in_array('vacancy_started_at', $cols, true)) {
            $additions['vacancy_started_at'] = [
                'type'    => 'DATE',
                'null'    => true,
                'comment' => 'Tanggal posisi mulai kosong (= resigner.effective_date untuk REPLACEMENT). Hitung "X hari kosong".',
            ];
        }
        if (! in_array('overdue_warning_sent_at', $cols, true)) {
            $additions['overdue_warning_sent_at'] = [
                'type'    => 'DATETIME',
                'null'    => true,
                'comment' => 'Cron H-30 reminder terakhir kirim. Anti-spam: skip kalau < 7 hari yang lalu.',
            ];
        }
        if (! in_array('overdue_escalated_at', $cols, true)) {
            $additions['overdue_escalated_at'] = [
                'type'    => 'DATETIME',
                'null'    => true,
                'comment' => 'Eskalasi ke Direktur saat MPP overdue. Anti-spam: skip kalau sudah pernah dikirim minggu ini.',
            ];
        }

        if (! empty($additions)) {
            $forge->addColumn('manpower_requests', $additions);
        }

        // Backfill `vacancy_started_at` untuk MPP REPLACEMENT existing yang
        // belum punya nilai. Ambil effective_date dari karyawan yang
        // direplace (kalau dia sudah resign), atau target_start_date
        // sebagai fallback.
        if ($db->tableExists('resignation_requests')) {
            $db->query("
                UPDATE manpower_requests mr
                LEFT JOIN resignation_requests rr
                    ON rr.employee_id = mr.replacement_for_employee_id
                    AND rr.status = 'APPROVED'
                    AND rr.deleted_at IS NULL
                SET mr.vacancy_started_at = COALESCE(
                    rr.effective_date,
                    rr.last_working_date,
                    mr.target_start_date
                )
                WHERE mr.vacancy_started_at IS NULL
                  AND mr.replacement_for_employee_id IS NOT NULL
                  AND mr.deleted_at IS NULL
            ");
        }
        // Backfill non-REPLACEMENT (NEW_POSITION, EXPANSION, dll):
        // pakai target_start_date sebagai vacancy_started_at supaya logic
        // overdue konsisten (overdue = today > vacancy_started_at).
        $db->query("
            UPDATE manpower_requests
            SET vacancy_started_at = target_start_date
            WHERE vacancy_started_at IS NULL
              AND target_start_date IS NOT NULL
              AND deleted_at IS NULL
        ");

        // Index untuk cron query — scan MPP active by vacancy_started_at
        if (! $this->indexExists('manpower_requests', 'idx_mpp_vacancy_started')) {
            $db->query("CREATE INDEX idx_mpp_vacancy_started ON manpower_requests (vacancy_started_at, status)");
        }
    }

    public function down(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('manpower_requests')) return;

        if ($this->indexExists('manpower_requests', 'idx_mpp_vacancy_started')) {
            try { $db->query('DROP INDEX idx_mpp_vacancy_started ON manpower_requests'); } catch (\Throwable $e) {}
        }

        $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('manpower_requests'));
        foreach (['overdue_escalated_at', 'overdue_warning_sent_at', 'vacancy_started_at'] as $c) {
            if (in_array($c, $cols, true)) {
                try { $forge->dropColumn('manpower_requests', $c); } catch (\Throwable $e) {}
            }
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            foreach ($this->db->getIndexData($table) as $idx) {
                if ($idx->name === $indexName) return true;
            }
        } catch (\Throwable $e) {}
        return false;
    }
}