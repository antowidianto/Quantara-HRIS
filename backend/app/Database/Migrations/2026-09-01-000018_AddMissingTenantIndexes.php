<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;

/**
 * Ensure every table with an explicit company_id tenant boundary has an index
 * containing that column. This keeps tenant predicates from becoming full
 * table scans and is enforced by the fresh-schema migration gate.
 */
final class AddMissingTenantIndexes extends Migration
{
    /** @var array<string, string> */
    private const INDEXES = [
        'employee_families' => 'idx_employee_families_company',
        'employee_educations' => 'idx_employee_educations_company',
        'leave_balances' => 'idx_leave_balances_company',
        'email_queue' => 'idx_email_queue_company',
        'employee_work_locations' => 'idx_employee_work_locations_company',
        'approval_chain_steps' => 'idx_approval_chain_steps_company',
        'approval_requests' => 'idx_approval_requests_company',
        'approval_decisions' => 'idx_approval_decisions_company',
        'business_trip_expenses' => 'idx_business_trip_expenses_company',
        'candidate_stages' => 'idx_candidate_stages_company',
        'candidate_documents' => 'idx_candidate_documents_company',
        'hr_ticket_comments' => 'idx_hr_ticket_comments_company',
        'goal_check_ins' => 'idx_goal_check_ins_company',
        'manpower_request_approvals' => 'idx_mpp_approvals_company',
        'manpower_request_postings' => 'idx_mpp_postings_company',
        'privacy_consents' => 'idx_privacy_consents_company',
        'job_posting_assets' => 'idx_job_posting_assets_company',
        'peer_review_nominations' => 'idx_peer_review_nominations_company',
        'recruitment_pipeline_stages' => 'idx_recruitment_pipeline_stages_company',
        'benefit_policies' => 'idx_benefit_policies_company',
        'email_events' => 'idx_email_events_company',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $index) {
            if (! $this->db->tableExists($table) || $this->hasCompanyIndex($this->db, $table)) {
                continue;
            }
            $this->db->query(sprintf(
                'CREATE INDEX `%s` ON `%s` (`company_id`)',
                $index,
                $table,
            ));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $index) {
            if (! $this->db->tableExists($table) || ! $this->indexExists($this->db, $table, $index)) {
                continue;
            }
            if ($this->db->DBDriver === 'SQLite3') {
                $this->db->query(sprintf('DROP INDEX `%s`', $index));
            } else {
                $this->db->query(sprintf('DROP INDEX `%s` ON `%s`', $index, $table));
            }
        }
    }

    private function hasCompanyIndex(BaseConnection $db, string $table): bool
    {
        foreach ($db->getIndexData($table) as $index) {
            if (in_array('company_id', $index->fields, true)) {
                return true;
            }
        }

        return false;
    }

    private function indexExists(BaseConnection $db, string $table, string $name): bool
    {
        return array_key_exists($name, $db->getIndexData($table));
    }
}
