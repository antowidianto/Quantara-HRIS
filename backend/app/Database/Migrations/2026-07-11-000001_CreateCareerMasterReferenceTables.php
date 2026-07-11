<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCareerMasterReferenceTables extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        $forge = $this->forge;

        if (! $db->tableExists('career_master_locations')) {
            $forge->addField([
                'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'province'   => ['type' => 'VARCHAR', 'constraint' => 100],
                'city'       => ['type' => 'VARCHAR', 'constraint' => 120],
                'district'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'postal_code'=> ['type' => 'VARCHAR', 'constraint' => 10],
                'source'     => ['type' => 'VARCHAR', 'constraint' => 80, 'default' => 'manual'],
                'source_ids' => ['type' => 'JSON', 'null' => true],
                'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['province', 'city', 'district', 'postal_code'], 'career_master_locations_unique');
            $forge->addKey(['province', 'city']);
            $forge->addKey('postal_code');
            $forge->createTable('career_master_locations');
        }

        if (! $db->tableExists('career_master_institutions')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'name'            => ['type' => 'VARCHAR', 'constraint' => 220],
                'type'            => [
                    'type' => 'ENUM',
                    'constraint' => ['SCHOOL', 'UNIVERSITY', 'OTHER'],
                    'default' => 'OTHER',
                ],
                'education_level' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'status'          => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'npsn'            => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'external_id'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'province'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'city'            => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'district'        => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'address'         => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'lat'             => ['type' => 'DECIMAL', 'constraint' => '11,8', 'null' => true],
                'lng'             => ['type' => 'DECIMAL', 'constraint' => '11,8', 'null' => true],
                'source'          => ['type' => 'VARCHAR', 'constraint' => 80, 'default' => 'manual'],
                'raw_payload'     => ['type' => 'JSON', 'null' => true],
                'is_active'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['type', 'name', 'province', 'city'], 'career_master_institutions_unique_name_area');
            $forge->addKey('npsn');
            $forge->addKey(['name', 'type']);
            $forge->addKey(['province', 'city']);
            $forge->createTable('career_master_institutions');
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('career_master_institutions')) {
            $this->forge->dropTable('career_master_institutions', true);
        }
        if ($this->db->tableExists('career_master_locations')) {
            $this->forge->dropTable('career_master_locations', true);
        }
    }
}
