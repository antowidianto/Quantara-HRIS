<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class HardenCandidateOfferApproval extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('candidate_offers')) {
            $fields = [];
            if (! $this->db->fieldExists('approval_rejected_by', 'candidate_offers')) {
                $fields['approval_rejected_by'] = ['type' => 'INT', 'unsigned' => true, 'null' => true];
            }
            if (! $this->db->fieldExists('approval_rejected_at', 'candidate_offers')) {
                $fields['approval_rejected_at'] = ['type' => 'DATETIME', 'null' => true];
            }
            if (! $this->db->fieldExists('approval_rejection_reason', 'candidate_offers')) {
                $fields['approval_rejection_reason'] = ['type' => 'TEXT', 'null' => true];
            }
            if ($fields !== []) $this->forge->addColumn('candidate_offers', $fields);
            $this->widenOfferStatus();
        }
        if ($this->db->tableExists('approval_chain_steps')) $this->widenApproverType();
    }

    private function widenOfferStatus(): void
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query("ALTER TABLE candidate_offers MODIFY status ENUM('DRAFT','PENDING_APPROVAL','APPROVAL_REJECTED','SENT','ACCEPTED','REJECTED','EXPIRED','WITHDRAWN') NOT NULL DEFAULT 'DRAFT'");
            return;
        }
        $this->forge->modifyColumn('candidate_offers', [
            'status' => ['name' => 'status', 'type' => 'VARCHAR', 'constraint' => 32, 'default' => 'DRAFT'],
        ]);
    }

    private function widenApproverType(): void
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query("ALTER TABLE approval_chain_steps MODIFY approver_type ENUM('SUPERVISOR','MANAGER','DEPARTMENT_HEAD','BRANCH_HEAD','HR','FINANCE','ROLE','SPECIFIC_USER','SPECIFIC_EMPLOYEE','HIRING_MANAGER') NOT NULL DEFAULT 'SUPERVISOR'");
            return;
        }
        $this->forge->modifyColumn('approval_chain_steps', [
            'approver_type' => ['name' => 'approver_type', 'type' => 'VARCHAR', 'constraint' => 32, 'default' => 'SUPERVISOR'],
        ]);
    }

    public function down(): void
    {
        // Status/type values are intentionally retained to avoid truncating
        // live approval history during rollback.
    }
}
