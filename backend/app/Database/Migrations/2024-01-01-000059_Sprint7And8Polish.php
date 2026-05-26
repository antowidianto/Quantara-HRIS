<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint7And8Polish (Sprint 7 H1.2 polish + Sprint 8 H1.5/H1.6/H1.7).
 *
 * Migration kombinasi untuk:
 *
 *   1. Sprint 8 / H1.5 — WFH Mode di attendance:
 *      - attendances.work_type ENUM(ONSITE, WFH, OFFSITE)
 *      - tabel `wfh_requests` (id, company_id, employee_id, date, status,
 *        approver_user_id, approved_at, reason)
 *
 *   2. Sprint 8 / H1.6 — Overtime pre-approval:
 *      - overtimes: tambah ENUM PLANNED
 *      - kolom `planned_hours`, `planned_at`, `actual_hours`, `variance_note`
 *      - kolom `pre_approval_required` di settings (cek lewat company_settings,
 *        bukan migration kolom — diabaikan kalau preferensi global)
 *
 *   3. Sprint 8 / H1.7 — Helpdesk SLA polish:
 *      - hr_tickets: tambah `escalated_at`, `escalated_to_user_id`,
 *        `near_sla_notified_at`
 *
 * Idempotent: cek kolom/tabel sebelum tambah supaya aman re-run.
 */
class Sprint7And8Polish extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        $forge = $this->forge;

        // ─── H1.5: attendances.work_type ────────────────────────────
        if ($db->tableExists('attendances')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('attendances'));
            if (! in_array('work_type', $cols, true)) {
                $forge->addColumn('attendances', [
                    'work_type' => [
                        'type'       => 'ENUM',
                        'constraint' => ['ONSITE', 'WFH', 'OFFSITE'],
                        'default'    => 'ONSITE',
                        'after'      => 'shift_id',
                        'comment'    => 'Sprint 8 H1.5: mode kerja saat clock-in',
                    ],
                ]);
            }
        }

        // ─── H1.5: tabel wfh_requests ───────────────────────────────
        if (! $db->tableExists('wfh_requests')) {
            $forge->addField([
                'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'        => ['type' => 'INT', 'unsigned' => true],
                'employee_id'       => ['type' => 'INT', 'unsigned' => true],
                'work_date'         => ['type' => 'DATE'],
                'reason'            => ['type' => 'TEXT'],
                'status' => [
                    'type'       => 'ENUM',
                    'constraint' => ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'],
                    'default'    => 'PENDING',
                ],
                'approver_user_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'approved_at'       => ['type' => 'DATETIME', 'null' => true],
                'rejection_note'    => ['type' => 'TEXT', 'null' => true],
                'created_at'        => ['type' => 'DATETIME', 'null' => true],
                'updated_at'        => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['employee_id', 'work_date'], 'uniq_wfh_emp_date');
            $forge->addKey(['company_id', 'status']);
            $forge->addKey(['company_id', 'work_date']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('wfh_requests');
        }

        // ─── H1.6: overtimes pre-approval ──────────────────────────
        if ($db->tableExists('overtimes')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('overtimes'));
            $newCols = [];
            if (! in_array('planned_hours', $cols, true)) {
                $newCols['planned_hours'] = [
                    'type'       => 'DECIMAL',
                    'constraint' => '6,2',
                    'null'       => true,
                    'after'      => 'hours',
                    'comment'    => 'H1.6: jam lembur yang direncanakan saat pre-approval.',
                ];
            }
            if (! in_array('planned_at', $cols, true)) {
                $newCols['planned_at'] = [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'comment' => 'H1.6: kapan rencana di-submit.',
                ];
            }
            if (! in_array('variance_note', $cols, true)) {
                $newCols['variance_note'] = [
                    'type'    => 'TEXT',
                    'null'    => true,
                    'comment' => 'H1.6: catatan kalau actual hours ≠ planned hours.',
                ];
            }
            if (! empty($newCols)) {
                $forge->addColumn('overtimes', $newCols);
            }

            // Tambah PLANNED ke ENUM status. MySQL ALTER ENUM butuh raw SQL
            // karena Forge tidak punya helper langsung.
            // Cek dulu apakah PLANNED sudah ada di ENUM via DESCRIBE.
            try {
                $row = $db->query(
                    "SHOW COLUMNS FROM overtimes WHERE Field = 'status'"
                )->getRowArray();
                if ($row && stripos((string) ($row['Type'] ?? ''), 'planned') === false) {
                    $db->query(
                        "ALTER TABLE overtimes MODIFY status ENUM("
                        . "'PLANNED','PENDING','APPROVED','REJECTED','REALIZED','PAID','CANCELLED'"
                        . ") NOT NULL DEFAULT 'PENDING'"
                    );
                }
            } catch (\Throwable $e) {
                log_message('warning', '[Sprint7And8Polish] gagal alter overtimes.status: ' . $e->getMessage());
            }
        }

        // ─── H1.7: helpdesk SLA polish ─────────────────────────────
        if ($db->tableExists('hr_tickets')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('hr_tickets'));
            $newCols = [];
            if (! in_array('escalated_at', $cols, true)) {
                $newCols['escalated_at'] = [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'comment' => 'H1.7: kapan ticket di-eskalasi karena lewat SLA.',
                ];
            }
            if (! in_array('escalated_to_user_id', $cols, true)) {
                $newCols['escalated_to_user_id'] = [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => true,
                    'comment'  => 'H1.7: user_id supervisor agen yang menerima eskalasi.',
                ];
            }
            if (! in_array('near_sla_notified_at', $cols, true)) {
                $newCols['near_sla_notified_at'] = [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'comment' => 'H1.7: jaga-jaga supaya warning H-2 jam tidak duplikat.',
                ];
            }
            if (! empty($newCols)) {
                $forge->addColumn('hr_tickets', $newCols);
            }
        }
    }

    public function down(): void
    {
        $db = $this->db;
        $forge = $this->forge;

        if ($db->tableExists('hr_tickets')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('hr_tickets'));
            foreach (['escalated_at', 'escalated_to_user_id', 'near_sla_notified_at'] as $c) {
                if (in_array($c, $cols, true)) $forge->dropColumn('hr_tickets', $c);
            }
        }

        if ($db->tableExists('overtimes')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('overtimes'));
            foreach (['planned_hours', 'planned_at', 'variance_note'] as $c) {
                if (in_array($c, $cols, true)) $forge->dropColumn('overtimes', $c);
            }
            // Revert ENUM status — kembalikan ke set lama (best effort)
            try {
                $db->query(
                    "ALTER TABLE overtimes MODIFY status ENUM("
                    . "'PENDING','APPROVED','REJECTED','CANCELLED'"
                    . ") NOT NULL DEFAULT 'PENDING'"
                );
            } catch (\Throwable $e) { /* best-effort */ }
        }

        if ($db->tableExists('wfh_requests')) {
            $forge->dropTable('wfh_requests', true);
        }

        if ($db->tableExists('attendances')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('attendances'));
            if (in_array('work_type', $cols, true)) {
                $forge->dropColumn('attendances', 'work_type');
            }
        }
    }
}