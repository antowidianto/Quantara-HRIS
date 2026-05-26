<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 3 — OD Loop.
 *
 * 1. **OD-1 Review → JIC auto-generate** — tambah field di `performance_reviews`:
 *    - `recommendation_action_id` — FK ke `job_information_changes` row
 *      yang di-generate dari recommendation review (closing the loop).
 *
 * 2. **OD-2 IDP framework** — tabel baru `individual_development_plans`:
 *    Per karyawan per cycle, isi gap analysis, recommended trainings,
 *    target competencies. Status DRAFT → ACTIVE → COMPLETED → REVIEWED.
 *    Auto-create dari `performance_reviews.improvements` field saat review
 *    di-finalize.
 *
 * 3. Permissions baru:
 *    - `idp.view`, `idp.manage` — HR + manager
 *    - `idp.view_own` — karyawan biasa
 *
 * Idempotent.
 */
class ODLoopSprint3 extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // ─── 1. performance_reviews: recommendation_action_id ────────
        if ($db->tableExists('performance_reviews')) {
            $existing = $db->getFieldNames('performance_reviews');
            if (! in_array('recommendation_action_id', $existing, true)) {
                $forge->addColumn('performance_reviews', [
                    'recommendation_action_id' => [
                        'type' => 'INT', 'unsigned' => true, 'null' => true,
                        'comment' => 'FK ke job_information_changes — JIC yang di-generate dari rekomendasi review (closing the loop OD-1)',
                    ],
                ]);
            }
        }

        // ─── 2. individual_development_plans ─────────────────────────
        if (! $db->tableExists('individual_development_plans')) {
            $forge->addField([
                'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'    => ['type' => 'INT', 'unsigned' => true],
                'employee_id'   => ['type' => 'INT', 'unsigned' => true],
                'cycle_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'FK ke performance_cycles. NULL = standalone IDP'],
                'review_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'FK ke performance_reviews kalau auto-create dari review.improvements'],

                'plan_period_start' => ['type' => 'DATE'],
                'plan_period_end'   => ['type' => 'DATE'],

                // Gap & target — JSON arrays of strings/objects
                'gaps_json'         => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Array of {area, current_level, target_level, evidence}'],
                'target_competencies_json' => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Array of {name, target_level, deadline}'],
                'recommended_trainings_json' => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Array of {training_id?, title, provider, target_completion_date}'],
                'action_items_json' => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Array of {action, owner, due_date, status}'],

                'manager_notes'     => ['type' => 'TEXT', 'null' => true],
                'employee_notes'    => ['type' => 'TEXT', 'null' => true],

                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT', 'ACTIVE', 'COMPLETED', 'REVIEWED', 'CANCELLED'],
                    'default' => 'DRAFT',
                ],
                'progress_percent' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],

                'finalized_at'  => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Timestamp saat status berubah dari DRAFT → ACTIVE'],
                'completed_at'  => ['type' => 'DATETIME', 'null' => true],

                'created_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'    => ['type' => 'DATETIME', 'null' => true],
                'updated_at'    => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'    => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addKey(['employee_id', 'status']);
            $forge->addKey(['cycle_id']);
            $forge->addForeignKey('company_id',  'companies',  'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees',  'id', 'CASCADE', 'CASCADE');
            $forge->createTable('individual_development_plans');
        }

        // ─── 3. Permissions baru ─────────────────────────────────────
        $newPermissions = [
            ['slug' => 'idp.view',     'name' => 'View Individual Development Plans (HR/manager)', 'group' => 'hr'],
            ['slug' => 'idp.manage',   'name' => 'Manage IDP (create, edit, finalize)',            'group' => 'hr'],
            ['slug' => 'idp.view_own', 'name' => 'View Own IDP',                                  'group' => 'hr'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }
        $grantMap = [
            1 => ['idp.view', 'idp.manage', 'idp.view_own'],
            2 => ['idp.view', 'idp.manage', 'idp.view_own'],
            3 => ['idp.view', 'idp.view_own'], // manager bisa lihat IDP team-nya
            4 => ['idp.view_own'],              // karyawan biasa
            5 => ['idp.view_own'],
        ];
        foreach ($grantMap as $roleId => $slugs) {
            foreach ($slugs as $slug) {
                $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
                if (! $perm) continue;
                $exists = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($exists > 0) continue;
                $db->table('role_permissions')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => (int) $perm['id'],
                    'created_at'    => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $db = $this->db;
        if ($db->tableExists('individual_development_plans')) {
            $this->forge->dropTable('individual_development_plans', true);
        }
        if ($db->tableExists('performance_reviews')) {
            $existing = $db->getFieldNames('performance_reviews');
            if (in_array('recommendation_action_id', $existing, true)) {
                try { $this->forge->dropColumn('performance_reviews', 'recommendation_action_id'); } catch (\Throwable $e) {}
            }
        }
    }
}