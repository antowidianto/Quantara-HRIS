<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCoreTables extends Migration
{
    public function up(): void
    {
        // ─── COMPANIES (Business Units / Tenants) ─────────────────────
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'code'               => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'               => ['type' => 'VARCHAR', 'constraint' => 200],
            'legal_name'         => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'npwp'               => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'phone'              => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'email'              => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'address'            => ['type' => 'TEXT', 'null' => true],
            'province'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'city'               => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'postal_code'        => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'logo_path'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'timezone'           => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => 'Asia/Jakarta'],
            'currency'           => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'IDR'],
            'fiscal_year_start'  => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1, 'comment' => 'month 1-12'],
            'is_active'          => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'settings'           => ['type' => 'JSON', 'null' => true, 'comment' => 'per-company custom settings'],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->addKey('is_active');
        $this->forge->createTable('companies');

        // ─── BRANCHES (per company) ───────────────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'   => ['type' => 'INT', 'unsigned' => true],
            'code'         => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 200],
            'address'      => ['type' => 'TEXT', 'null' => true],
            'province'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'city'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'phone'        => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'lat'          => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'lng'          => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'geofence_radius_m' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'is_active'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'code']);
        $this->forge->addKey('company_id');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('branches');

        // ─── DEPARTMENTS ──────────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'INT', 'unsigned' => true],
            'parent_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'code'           => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 200],
            'description'    => ['type' => 'TEXT', 'null' => true],
            'manager_employee_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'is_active'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'code']);
        $this->forge->addKey('company_id');
        $this->forge->addKey('parent_id');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('departments');

        // ─── JOB GRADES ───────────────────────────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'   => ['type' => 'INT', 'unsigned' => true],
            'code'         => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 100],
            'level'        => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'min_salary'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'max_salary'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'is_active'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'code']);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('job_grades');

        // ─── POSITIONS ────────────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'INT', 'unsigned' => true],
            'department_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'job_grade_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'code'           => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 200],
            'description'    => ['type' => 'TEXT', 'null' => true],
            'is_active'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'code']);
        $this->forge->addKey('department_id');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('positions');

        // ─── ROLES ────────────────────────────────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'NULL = system role'],
            'slug'         => ['type' => 'VARCHAR', 'constraint' => 50],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 100],
            'description'  => ['type' => 'TEXT', 'null' => true],
            'is_system'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'slug']);
        $this->forge->createTable('roles');

        // ─── PERMISSIONS ──────────────────────────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'slug'         => ['type' => 'VARCHAR', 'constraint' => 100],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 150],
            'group'        => ['type' => 'VARCHAR', 'constraint' => 50],
            'description'  => ['type' => 'TEXT', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('permissions');

        // ─── ROLE_PERMISSIONS ─────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'role_id'        => ['type' => 'INT', 'unsigned' => true],
            'permission_id'  => ['type' => 'INT', 'unsigned' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['role_id', 'permission_id']);
        $this->forge->addForeignKey('role_id', 'roles', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('permission_id', 'permissions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('role_permissions');

        // ─── USERS ────────────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'NULL for super admin'],
            'role_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'employee_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'email'             => ['type' => 'VARCHAR', 'constraint' => 191],
            'password_hash'     => ['type' => 'VARCHAR', 'constraint' => 255],
            'name'              => ['type' => 'VARCHAR', 'constraint' => 150],
            'avatar_path'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'twofa_secret'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'twofa_enabled'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'last_login_at'     => ['type' => 'DATETIME', 'null' => true],
            'last_login_ip'     => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'failed_attempts'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'locked_until'      => ['type' => 'DATETIME', 'null' => true],
            'password_changed_at' => ['type' => 'DATETIME', 'null' => true],
            'must_change_password' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'is_active'         => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');
        $this->forge->addKey('company_id');
        $this->forge->addKey('role_id');
        $this->forge->addKey('is_active');
        $this->forge->createTable('users');

        // ─── REFRESH TOKENS ───────────────────────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'jti'          => ['type' => 'VARCHAR', 'constraint' => 64],
            'user_id'      => ['type' => 'INT', 'unsigned' => true],
            'expires_at'   => ['type' => 'DATETIME'],
            'revoked'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'revoked_at'   => ['type' => 'DATETIME', 'null' => true],
            'user_agent'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'ip_address'   => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('jti');
        $this->forge->addKey('user_id');
        $this->forge->addKey('expires_at');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('refresh_tokens');

        // ─── PASSWORD RESETS ──────────────────────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'      => ['type' => 'INT', 'unsigned' => true],
            'token_hash'   => ['type' => 'VARCHAR', 'constraint' => 191],
            'expires_at'   => ['type' => 'DATETIME'],
            'used_at'      => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('password_resets');
    }

    public function down(): void
    {
        $this->forge->dropTable('password_resets', true);
        $this->forge->dropTable('refresh_tokens', true);
        $this->forge->dropTable('users', true);
        $this->forge->dropTable('role_permissions', true);
        $this->forge->dropTable('permissions', true);
        $this->forge->dropTable('roles', true);
        $this->forge->dropTable('positions', true);
        $this->forge->dropTable('job_grades', true);
        $this->forge->dropTable('departments', true);
        $this->forge->dropTable('branches', true);
        $this->forge->dropTable('companies', true);
    }
}