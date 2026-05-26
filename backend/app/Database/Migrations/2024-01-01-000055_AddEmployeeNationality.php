<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tambah kolom demografi yang sudah ada di tabel `candidates` tapi belum
 * pernah ditambahkan ke `employees` — `nationality` & `birth_place`.
 *
 * Latar belakang:
 *   - Migration 000030 (CreateCareerPortalTables) menambahkan `nationality`
 *     dan `birth_place` ke `candidates` (default WNI).
 *   - `RecruitmentService::convertToEmployee()` mencoba carry-over field ini
 *     tapi diam-diam di-drop CodeIgniter karena kolom tidak ada di `employees`.
 *   - `ComplianceReportService::generateWlkpAnnual()` query `e.nationality`
 *     untuk hitung WNI vs WNA — gagal dengan "Unknown column 'e.nationality'".
 *
 * Migrasi ini idempotent (cek getFieldNames sebelum addColumn).
 */
class AddEmployeeNationality extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        if (! $db->tableExists('employees')) {
            return;
        }

        $cols = $db->getFieldNames('employees');
        $add  = [];

        if (! in_array('nationality', $cols, true)) {
            $add['nationality'] = [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'WNI',
                'comment'    => 'WNI/WNA atau nama negara — untuk WLKP report',
            ];
        }
        if (! in_array('birth_place', $cols, true)) {
            $add['birth_place'] = [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'comment'    => 'Tempat lahir (sesuai KTP) — untuk paklaring & dokumen formal',
            ];
        }

        if ($add) {
            $this->forge->addColumn('employees', $add);
        }

        // Backfill nationality dari candidate untuk employee yang sudah ada
        // (best effort — kalau tidak ada link candidate, biarkan default 'WNI')
        if ($db->tableExists('candidates') && in_array('nationality', $db->getFieldNames('employees'), true)) {
            $candCols = $db->getFieldNames('candidates');
            // Cari pair employee.email ↔ candidate.email (cara paling aman)
            if (in_array('email', $candCols, true)) {
                try {
                    $db->query(
                        'UPDATE employees e
                         INNER JOIN candidates c ON c.email = e.email
                         SET e.nationality = COALESCE(c.nationality, e.nationality, "WNI"),
                             e.birth_place = COALESCE(c.birth_place, e.birth_place)
                         WHERE (e.nationality IS NULL OR e.nationality = "WNI")
                           AND c.deleted_at IS NULL'
                    );
                } catch (\Throwable $e) {
                    // Non-fatal — backfill is opportunistic. Default tetap WNI.
                    log_message('warning', 'AddEmployeeNationality backfill skipped: ' . $e->getMessage());
                }
            }
        }
    }

    public function down(): void
    {
        $db = $this->db;
        if (! $db->tableExists('employees')) {
            return;
        }
        $cols = $db->getFieldNames('employees');
        foreach (['nationality', 'birth_place'] as $c) {
            if (in_array($c, $cols, true)) {
                try {
                    $this->forge->dropColumn('employees', $c);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }
    }
}