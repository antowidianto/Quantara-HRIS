<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateJobsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'queue' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => 'default',
            ],
            'payload' => [
                'type' => 'LONGTEXT',
            ],
            'attempts' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'reserved_at' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'null' => true,
            ],
            'available_at' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
            ],
            'created_at' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
            ],
        ]);
        
        $this->forge->addKey('id', true);
        $this->forge->addKey(['queue', 'reserved_at']);
        
        $this->forge->createTable('jobs');

        // Create failed_jobs table as well
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'connection' => [
                'type' => 'TEXT',
            ],
            'queue' => [
                'type' => 'TEXT',
            ],
            'payload' => [
                'type' => 'LONGTEXT',
            ],
            'exception' => [
                'type' => 'LONGTEXT',
            ],
            'failed_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('failed_jobs');
    }

    public function down()
    {
        $this->forge->dropTable('jobs');
        $this->forge->dropTable('failed_jobs');
    }
}