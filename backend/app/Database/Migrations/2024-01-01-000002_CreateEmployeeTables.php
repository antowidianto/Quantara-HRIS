<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateEmployeeTables extends Migration
{
    public function up(): void
    {
        // ─── EMPLOYEES ─────────────────────────────────────────────────
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'          => ['type' => 'INT', 'unsigned' => true],
            'branch_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'department_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'position_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'job_grade_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'manager_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'self ref'],
            'user_id'             => ['type' => 'INT', 'unsigned' => true, 'null' => true],

            'employee_number'     => ['type' => 'VARCHAR', 'constraint' => 32],
            'first_name'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'last_name'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'full_name'           => ['type' => 'VARCHAR', 'constraint' => 200],
            'gender'              => ['type' => 'ENUM', 'constraint' => ['M', 'F'], 'null' => true],
            'religion'            => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'marital_status'      => ['type' => 'ENUM', 'constraint' => ['TK0','TK1','TK2','TK3','K0','K1','K2','K3','HB0','HB1','HB2','HB3'], 'null' => true, 'comment' => 'PTKP code'],
            'place_of_birth'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'date_of_birth'       => ['type' => 'DATE', 'null' => true],
            'blood_type'          => ['type' => 'VARCHAR', 'constraint' => 5, 'null' => true],

            // Encrypted at rest fields - stored as longer varchar to hold ciphertext
            'nik_encrypted'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'comment' => 'NIK KTP - encrypted'],
            'nik_hash'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'comment' => 'sha256 for lookup'],
            'npwp_encrypted'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'bpjs_kesehatan_no'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'bpjs_tk_no'          => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'bank_name'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'bank_account_encrypted' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'bank_account_holder' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],

            // Contact
            'email'               => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'phone'               => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'address'             => ['type' => 'TEXT', 'null' => true],
            'province'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'city'                => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'postal_code'         => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'emergency_contact_name' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'emergency_contact_phone'=> ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'emergency_contact_rel'  => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],

            // Employment
            'employment_status'   => ['type' => 'ENUM', 'constraint' => ['PKWT','PKWTT','PROBATION','HARIAN','MAGANG','OUTSOURCING'], 'default' => 'PKWTT'],
            'employment_type'     => ['type' => 'ENUM', 'constraint' => ['FULL_TIME','PART_TIME','CONTRACT','INTERN'], 'default' => 'FULL_TIME'],
            'join_date'           => ['type' => 'DATE'],
            'probation_end_date'  => ['type' => 'DATE', 'null' => true],
            'contract_end_date'   => ['type' => 'DATE', 'null' => true],
            'resign_date'         => ['type' => 'DATE', 'null' => true],
            'resign_reason'       => ['type' => 'TEXT', 'null' => true],
            'is_active'           => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],

            // Salary
            'basic_salary'        => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'tax_method'          => ['type' => 'ENUM', 'constraint' => ['GROSS','NETT','GROSS_UP'], 'default' => 'GROSS'],
            'ptkp_status'         => ['type' => 'ENUM', 'constraint' => ['TK0','TK1','TK2','TK3','K0','K1','K2','K3','HB0','HB1','HB2','HB3'], 'default' => 'TK0'],
            'pph21_method'        => ['type' => 'ENUM', 'constraint' => ['TER','GROSS_UP'], 'default' => 'TER'],

            'avatar_path'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'custom_fields'       => ['type' => 'JSON', 'null' => true],

            'created_at'          => ['type' => 'DATETIME', 'null' => true],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'employee_number']);
        $this->forge->addKey('company_id');
        $this->forge->addKey('branch_id');
        $this->forge->addKey('department_id');
        $this->forge->addKey('position_id');
        $this->forge->addKey('manager_id');
        $this->forge->addKey('user_id');
        $this->forge->addKey('nik_hash');
        $this->forge->addKey('is_active');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('branch_id', 'branches', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('department_id', 'departments', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('position_id', 'positions', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('employees');

        // ─── EMPLOYEE CONTRACTS ───────────────────────────────────────
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'      => ['type' => 'INT', 'unsigned' => true],
            'employee_id'     => ['type' => 'INT', 'unsigned' => true],
            'contract_number' => ['type' => 'VARCHAR', 'constraint' => 64],
            'contract_type'   => ['type' => 'ENUM', 'constraint' => ['PKWT','PKWTT','PROBATION','PKHL','MAGANG'], 'default' => 'PKWTT'],
            'start_date'      => ['type' => 'DATE'],
            'end_date'        => ['type' => 'DATE', 'null' => true],
            'basic_salary'    => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'position_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'department_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'document_path'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'notes'           => ['type' => 'TEXT', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['company_id', 'employee_id']);
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('employee_contracts');

        // ─── EMPLOYEE DOCUMENTS ───────────────────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'   => ['type' => 'INT', 'unsigned' => true],
            'employee_id'  => ['type' => 'INT', 'unsigned' => true],
            'doc_type'     => ['type' => 'VARCHAR', 'constraint' => 50, 'comment' => 'KTP/NPWP/IJAZAH/CV/etc'],
            'title'        => ['type' => 'VARCHAR', 'constraint' => 200],
            'file_path'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'file_mime'    => ['type' => 'VARCHAR', 'constraint' => 100],
            'file_size'    => ['type' => 'INT', 'unsigned' => true],
            'expires_at'   => ['type' => 'DATE', 'null' => true],
            'uploaded_by'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['company_id', 'employee_id']);
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('employee_documents');

        // ─── EMPLOYEE FAMILIES ────────────────────────────────────────
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'    => ['type' => 'INT', 'unsigned' => true],
            'employee_id'   => ['type' => 'INT', 'unsigned' => true],
            'relationship'  => ['type' => 'ENUM', 'constraint' => ['SPOUSE','CHILD','PARENT','SIBLING'], 'default' => 'CHILD'],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 200],
            'gender'        => ['type' => 'ENUM', 'constraint' => ['M','F'], 'null' => true],
            'date_of_birth' => ['type' => 'DATE', 'null' => true],
            'nik_encrypted' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'is_dependent'  => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1, 'comment' => 'tanggungan PTKP'],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('employee_id');
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('employee_families');

        // ─── EMPLOYEE EDUCATIONS ──────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'INT', 'unsigned' => true],
            'employee_id'    => ['type' => 'INT', 'unsigned' => true],
            'level'          => ['type' => 'VARCHAR', 'constraint' => 50, 'comment' => 'SD/SMP/SMA/D3/S1/S2/S3'],
            'institution'    => ['type' => 'VARCHAR', 'constraint' => 200],
            'major'          => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'graduation_year'=> ['type' => 'YEAR', 'null' => true],
            'gpa'            => ['type' => 'DECIMAL', 'constraint' => '4,2', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('employee_id');
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('employee_educations');
    }

    public function down(): void
    {
        $this->forge->dropTable('employee_educations', true);
        $this->forge->dropTable('employee_families', true);
        $this->forge->dropTable('employee_documents', true);
        $this->forge->dropTable('employee_contracts', true);
        $this->forge->dropTable('employees', true);
    }
}