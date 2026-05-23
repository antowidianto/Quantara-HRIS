<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 22+ — Tambah kolom `fixed_allowance` di tabel `employees`.
 *
 * Konteks:
 *   Beberapa modul (BonusService, BonusCalculator, SeveranceController)
 *   sudah merefer kolom `e.fixed_allowance` untuk hitung gross =
 *   basic + fixed_allowance. Tapi kolom belum pernah di-migrate di
 *   `2024-01-01-000002_CreateEmployeeTables.php` (cuma `basic_salary`).
 *
 *   Akibat: query gagal dengan
 *     "Unknown column 'e.fixed_allowance' in 'field list'"
 *   yang muncul saat:
 *     - Bonus run calculate (BonusService line 86)
 *     - Bonus scheme preview (BonusController previewScheme)
 *     - Severance compute fallback
 *
 * Migration ini additive-only sesuai ADR 0004:
 *   - Tambah kolom DECIMAL(15,2) NOT NULL DEFAULT 0 (compatible dengan
 *     existing rows — tidak perlu data migration).
 *   - Position setelah `basic_salary` supaya secara visual berdekatan.
 *   - Reversible via `down()` untuk dev cleanup, production tidak
 *     pakai down() (lihat ADR 0004).
 */
class AddEmployeeFixedAllowance extends Migration
{
    public function up(): void
    {
        // Defensive: kalau kolom sudah ada (dari custom migration di tier
        // tertentu), skip supaya migration ini idempotent.
        if ($this->columnExists('employees', 'fixed_allowance')) {
            return;
        }

        $this->forge->addColumn('employees', [
            'fixed_allowance' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'default'    => 0,
                'null'       => false,
                'after'      => 'basic_salary',
                'comment'    => 'Tunjangan tetap bulanan (gross = basic + fixed_allowance)',
            ],
        ]);
    }

    public function down(): void
    {
        if (! $this->columnExists('employees', 'fixed_allowance')) {
            return;
        }
        $this->forge->dropColumn('employees', 'fixed_allowance');
    }

    private function columnExists(string $table, string $column): bool
    {
        $db = \Config\Database::connect();
        try {
            $fields = $db->getFieldNames($table);
            return in_array($column, $fields, true);
        } catch (\Throwable $e) {
            return false;
        }
    }
}