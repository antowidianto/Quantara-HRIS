<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 16+ — Bonus Engine foundation.
 *
 * Modul P3 baru untuk handle bonus selain THR & insentif:
 *   - End-of-year bonus (tahunan)
 *   - KPI / performance bonus
 *   - Milestone bonus (tenure 5 tahun, project completion, dst)
 *   - Sign-on / retention bonus
 *
 * Beda dengan THR (yang sudah handled di payroll_periods type=THR):
 *   - THR fixed schedule sekali setahun, formula proporsional masa kerja
 *   - Bonus engine: HR define skema sendiri (formula + criteria), bisa
 *     ad-hoc atau scheduled, eligibility per departemen / grade / posisi
 *
 * Tabel:
 *   1. `bonus_schemes` — definisi skema (mis. "Tahun-Baru-2027",
 *      "KPI-Q1-Sales"). Field penting:
 *        - kind: ANNUAL/KPI/MILESTONE/SIGNON/RETENTION/DISCRETIONARY
 *        - formula_type: FIXED_AMOUNT / PCT_OF_BASIC / PCT_OF_GROSS /
 *                        TENURE_BASED / KPI_SCORE / FORMULA_EXPR
 *        - eligibility_json: filter karyawan (departments[], branches[],
 *                            grades[], min_tenure_months, employment_status[])
 *        - cap_amount: max nominal per karyawan (anti-error pengetikan)
 *        - tax_treatment: GROSS / NET / NON_TAXABLE
 *
 *   2. `bonus_runs` — instance pelaksanaan skema. Status workflow:
 *      DRAFT → CALCULATED → APPROVED → PAID / CANCELLED.
 *      Setiap run berhubungan ke `payroll_period_id` opsional supaya
 *      bonus bisa di-merge ke payslip bulan tertentu (kalau diinginkan).
 *
 *   3. `bonus_recipients` — line items per karyawan untuk satu run.
 *      Snapshot full: monthly_salary saat hitung, formula_used, breakdown.
 *      HR boleh edit amount per-orang (ada cap_amount enforcement).
 *
 * Multi-tenant: scoped ke company. Permission terpisah:
 *   `bonus.view`   → list/show
 *   `bonus.manage` → create/calculate/edit recipient
 *   `bonus.approve` → approve run + mark paid
 */
class CreateBonusEngine extends Migration
{
    public function up()
    {
        // ─── bonus_schemes ──────────────────────────────────────────
        if (! $this->db->tableExists('bonus_schemes')) {
            $this->forge->addField([
                'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'         => ['type' => 'INT', 'unsigned' => true],
                'code'               => ['type' => 'VARCHAR', 'constraint' => 50,
                                         'comment' => 'Unik per company (mis. ANN-2027)'],
                'name'               => ['type' => 'VARCHAR', 'constraint' => 200],
                'kind'               => ['type' => 'ENUM',
                                         'constraint' => ['ANNUAL','KPI','MILESTONE','SIGNON','RETENTION','DISCRETIONARY'],
                                         'default' => 'DISCRETIONARY'],
                'formula_type'       => ['type' => 'ENUM',
                                         'constraint' => ['FIXED_AMOUNT','PCT_OF_BASIC','PCT_OF_GROSS','TENURE_BASED','KPI_SCORE','FORMULA_EXPR'],
                                         'default' => 'FIXED_AMOUNT'],
                'formula_value'      => ['type' => 'DECIMAL', 'constraint' => '15,4', 'null' => true,
                                         'comment' => 'FIXED_AMOUNT: nominal IDR; PCT_*: persen 0-100; TENURE_BASED: amount per tahun'],
                'formula_expr'       => ['type' => 'TEXT', 'null' => true,
                                         'comment' => 'Untuk type FORMULA_EXPR: ekspresi simple, mis. "basic * 0.5 + tenure_months * 100000"'],
                'cap_amount'         => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true,
                                         'comment' => 'Max nominal per karyawan; null = no cap'],
                'eligibility_json'   => ['type' => 'TEXT', 'null' => true,
                                         'comment' => 'JSON filter: {department_ids:[], branch_ids:[], grade_ids:[], position_ids:[], employment_status:[], min_tenure_months:N, exclude_resigned:bool}'],
                'tax_treatment'      => ['type' => 'ENUM',
                                         'constraint' => ['GROSS','NET','NON_TAXABLE'],
                                         'default' => 'GROSS'],
                'description'        => ['type' => 'TEXT', 'null' => true],
                'is_active'          => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_by'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'         => ['type' => 'DATETIME'],
                'updated_at'         => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'         => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey(['company_id', 'code']);
            $this->forge->addKey(['company_id', 'is_active']);
            $this->forge->addKey('kind');
            $this->forge->createTable('bonus_schemes');
        }

        // ─── bonus_runs ─────────────────────────────────────────────
        if (! $this->db->tableExists('bonus_runs')) {
            $this->forge->addField([
                'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'         => ['type' => 'INT', 'unsigned' => true],
                'bonus_scheme_id'    => ['type' => 'INT', 'unsigned' => true],
                'payroll_period_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                                         'comment' => 'Optional — kalau bonus mau di-merge ke payslip periode ini'],
                'name'               => ['type' => 'VARCHAR', 'constraint' => 200],
                'reference_date'     => ['type' => 'DATE',
                                         'comment' => 'Tanggal cutoff (untuk hitung tenure & eligibility)'],
                'pay_date'           => ['type' => 'DATE'],
                'period_year'        => ['type' => 'SMALLINT', 'unsigned' => true],
                'status'             => ['type' => 'ENUM',
                                         'constraint' => ['DRAFT','CALCULATED','APPROVED','PAID','CANCELLED'],
                                         'default' => 'DRAFT'],
                'total_amount'       => ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0],
                'recipient_count'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'notes'              => ['type' => 'TEXT', 'null' => true],
                // Audit trail status transitions
                'calculated_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'calculated_at'      => ['type' => 'DATETIME', 'null' => true],
                'approved_by'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'approved_at'        => ['type' => 'DATETIME', 'null' => true],
                'paid_by'            => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'paid_at'            => ['type' => 'DATETIME', 'null' => true],
                'cancelled_by'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'cancelled_at'       => ['type' => 'DATETIME', 'null' => true],
                'cancellation_reason'=> ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_by'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'         => ['type' => 'DATETIME'],
                'updated_at'         => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'         => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['company_id', 'status']);
            $this->forge->addKey('bonus_scheme_id');
            $this->forge->addKey(['company_id', 'period_year']);
            $this->forge->createTable('bonus_runs');
        }

        // ─── bonus_recipients ───────────────────────────────────────
        if (! $this->db->tableExists('bonus_recipients')) {
            $this->forge->addField([
                'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'bonus_run_id'       => ['type' => 'INT', 'unsigned' => true],
                'company_id'         => ['type' => 'INT', 'unsigned' => true],
                'employee_id'        => ['type' => 'INT', 'unsigned' => true],
                // Snapshot saat hitung (immune dari perubahan master data)
                'employee_number'    => ['type' => 'VARCHAR', 'constraint' => 32],
                'employee_name'      => ['type' => 'VARCHAR', 'constraint' => 200],
                'department_name'    => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'position_name'      => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'tenure_months'      => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
                'monthly_salary'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
                // Calculation result
                'amount'             => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
                'amount_capped'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                                         'comment' => '1 = di-cap oleh scheme.cap_amount'],
                'amount_overridden'  => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                                         'comment' => '1 = HR manual edit, 0 = formula default'],
                'override_reason'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'breakdown_json'     => ['type' => 'TEXT', 'null' => true,
                                         'comment' => 'JSON: {formula:"...", inputs:{...}, steps:[...]}'],
                'is_excluded'        => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                                         'comment' => 'HR exclude karyawan dari run ini (mis. resign baru)'],
                'exclude_reason'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_at'         => ['type' => 'DATETIME'],
                'updated_at'         => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey(['bonus_run_id', 'employee_id']);
            $this->forge->addKey(['company_id', 'employee_id']);
            $this->forge->addKey('bonus_run_id');
            $this->forge->createTable('bonus_recipients');
        }
    }

    public function down()
    {
        if ($this->db->tableExists('bonus_recipients')) $this->forge->dropTable('bonus_recipients');
        if ($this->db->tableExists('bonus_runs'))       $this->forge->dropTable('bonus_runs');
        if ($this->db->tableExists('bonus_schemes'))    $this->forge->dropTable('bonus_schemes');
    }
}