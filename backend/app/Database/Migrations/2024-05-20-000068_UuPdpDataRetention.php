<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UuPdpDataRetention extends Migration
{
    public function up()
    {
        $this->forge->addColumn('employees', [
            'anonymized_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'after'   => 'deleted_at',
                'comment' => 'Timestamp saat data PII di-masking secara permanen untuk UU PDP'
            ]
        ]);
        
        $this->forge->addColumn('users', [
            'anonymized_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'after'   => 'deleted_at'
            ]
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('employees', 'anonymized_at');
        $this->forge->dropColumn('users', 'anonymized_at');
    }
}