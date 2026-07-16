<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CorrectApprovalApproverRoleMappings extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('approval_chains') || ! $this->db->tableExists('approval_chain_steps')) return;

        $roles = [];
        foreach (['ceo', 'cfo', 'hr_director', 'it_admin', 'legal_compliance'] as $slug) {
            $row = $this->db->table('roles')->select('id')->where('slug', $slug)->get()->getRowArray();
            if (! $row) throw new \RuntimeException("Role system {$slug} belum tersedia");
            $roles[$slug] = (int) $row['id'];
        }

        $mappings = [
            ['BONUS-DEFAULT', 3, 'ceo'],
            ['PAYADJ-DEFAULT', 3, 'cfo'],
            ['COMP-DEFAULT', 3, 'hr_director'],
            ['TRAINING-DEFAULT', 5, 'hr_director'],
            ['OFFER-DEFAULT', 4, 'ceo'],
            ['CONTRACT-DEFAULT', 3, 'hr_director'],
            ['ASSET-DEFAULT', 4, 'cfo'],
            ['HIRE-DEFAULT', 2, 'hr_director'],
            ['EMPLOYEE-SENSITIVE-CHANGE-DEFAULT', 3, 'it_admin'],
            ['MASTER-RATE-CHANGE-DEFAULT', 2, 'cfo'],
            ['PRIVILEGED-ACTION-DEFAULT', 2, 'legal_compliance'],
            ['PRIVILEGED-ACTION-DEFAULT', 3, 'it_admin'],
            ['PRIVILEGED-ACTION-DEFAULT', 4, 'it_admin'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($mappings as [$code, $stepOrder, $roleSlug]) {
            $chains = $this->db->table('approval_chains')->select('id, company_id')
                ->where('code', $code)->where('deleted_at IS NULL')->get()->getResultArray();
            foreach ($chains as $chain) {
                $pending = $this->db->table('approval_requests')->where('chain_id', (int) $chain['id'])
                    ->where('status', 'PENDING')->countAllResults();
                if ($pending > 0) {
                    log_message('warning', "Role mapping {$code} step {$stepOrder} tidak diubah karena ada approval pending");
                    continue;
                }
                $this->db->table('approval_chain_steps')->where('chain_id', (int) $chain['id'])
                    ->where('company_id', (int) $chain['company_id'])->where('step_order', $stepOrder)
                    ->where('approver_type', 'ROLE')->update([
                        'approver_role_id' => $roles[$roleSlug], 'updated_at' => $now,
                    ]);
                $this->db->table('approval_chains')->where('id', (int) $chain['id'])->update(['updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // Do not restore high-risk approval ownership to Super Admin.
    }
}
