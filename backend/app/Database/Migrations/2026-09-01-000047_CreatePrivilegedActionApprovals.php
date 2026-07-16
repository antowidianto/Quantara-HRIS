<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreatePrivilegedActionApprovals extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('privileged_action_requests')) return;
        $this->forge->addField([
            'id' => ['type'=>'INT','unsigned'=>true,'auto_increment'=>true],
            'company_id' => ['type'=>'INT','unsigned'=>true],
            'request_number' => ['type'=>'VARCHAR','constraint'=>64],
            'action_group' => ['type'=>'VARCHAR','constraint'=>32],
            'http_method' => ['type'=>'VARCHAR','constraint'=>10],
            'request_path' => ['type'=>'VARCHAR','constraint'=>255],
            'request_hash' => ['type'=>'CHAR','constraint'=>64],
            'status' => ['type'=>'VARCHAR','constraint'=>32,'default'=>'PENDING_APPROVAL'],
            'submitted_by' => ['type'=>'INT','unsigned'=>true], 'submitted_at' => ['type'=>'DATETIME'],
            'approved_by' => ['type'=>'INT','unsigned'=>true,'null'=>true], 'approved_at' => ['type'=>'DATETIME','null'=>true],
            'expires_at' => ['type'=>'DATETIME','null'=>true], 'used_at' => ['type'=>'DATETIME','null'=>true],
            'rejected_by' => ['type'=>'INT','unsigned'=>true,'null'=>true], 'rejected_at' => ['type'=>'DATETIME','null'=>true],
            'rejection_reason' => ['type'=>'TEXT','null'=>true],
            'created_at' => ['type'=>'DATETIME'], 'updated_at' => ['type'=>'DATETIME'], 'deleted_at' => ['type'=>'DATETIME','null'=>true],
        ]);
        $this->forge->addPrimaryKey('id'); $this->forge->addUniqueKey(['company_id','request_number']);
        $this->forge->addKey(['company_id','submitted_by','request_hash','status']);
        $this->forge->addForeignKey('company_id','companies','id','CASCADE','CASCADE');
        $this->forge->createTable('privileged_action_requests');
    }
    public function down(): void { /* Keep privileged-access evidence. */ }
}
