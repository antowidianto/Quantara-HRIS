<?php

declare(strict_types=1);

/**
 * Reviewed unique constraints on direct tenant tables that intentionally do
 * not repeat company_id. Values are either globally addressable/security
 * identifiers or keys already bounded by a tenant-owned parent identifier.
 */
return [
    'approval_outbox_events:approval_request_id' => 'Approval request parent is tenant-bound and emits once.',
    'approval_outbox_events:event_id' => 'Globally generated immutable outbox event identifier.',
    'api_keys:key_prefix' => 'Globally addressable secret prefix.',
    'application_track_tokens:token' => 'Globally addressable public security token.',
    'attendance_change_requests:request_number' => 'Globally generated immutable business number.',
    'attendance_devices:user_id,device_id' => 'User parent is tenant-bound.',
    'attendances:employee_id,work_date' => 'Employee parent is tenant-bound.',
    'bonus_recipients:bonus_run_id,employee_id' => 'Both parents are tenant-bound.',
    'cost_center_budgets:cost_center_id,period_year,period_type,period_no,category' => 'Cost center parent is tenant-bound.',
    'device_tokens:token' => 'Globally unique provider token.',
    'disciplinary_records:reference_number' => 'Globally generated immutable business number.',
    'email_events:provider,provider_event_key' => 'Provider event idempotency key is global.',
    'employee_allowance_assignments:employee_id,allowance_type_id' => 'Employee parent is tenant-bound.',
    'employee_benefit_balances:employee_id,policy_id,year' => 'Employee and benefit policy parents are tenant-bound.',
    'employee_skills:employee_id,skill_slug' => 'Employee parent is tenant-bound.',
    'inbound_webhook_events:inbound_id,source_event_id' => 'Inbound webhook parent is tenant-bound.',
    'inbound_webhooks:slug' => 'Globally addressable inbound endpoint.',
    'internal_applications:internal_job_posting_id,employee_id' => 'Both parents are tenant-bound.',
    'leave_balances:employee_id,leave_type_id,period_year' => 'Employee parent is tenant-bound.',
    'leaves:request_number' => 'Globally generated immutable business number.',
    'loans:loan_number' => 'Globally generated immutable business number.',
    'manpower_request_postings:manpower_request_id,job_posting_id' => 'Both parents are tenant-bound.',
    'onboarding_progress:user_id,step_key' => 'User parent is tenant-bound.',
    'online_test_assignments:invitation_token' => 'Globally addressable public security token.',
    'overtimes:request_number' => 'Globally generated immutable business number.',
    'payroll_adjustments:reversal_of_id' => 'Referenced adjustment is tenant-bound and may be reversed once.',
    'payroll_period_approvals:payroll_period_id,approved_by,approval_type' => 'Payroll period parent is tenant-bound.',
    'payslips:payroll_period_id,employee_id' => 'Both parents are tenant-bound.',
    'payslips:slip_number' => 'Globally generated immutable business number.',
    'peer_review_nominations:cycle_id,reviewee_id,reviewer_id' => 'Cycle and employee parents are tenant-bound.',
    'performance_reviews:cycle_id,employee_id,reviewer_id,review_type' => 'Cycle and employee parents are tenant-bound.',
    'position_competency_profiles:position_id,competency_id' => 'Position and competency parents are tenant-bound.',
    'recruitment_pipeline_stages:template_id,code' => 'Template parent is tenant-bound.',
    'referral_links:slug' => 'Globally addressable referral URL.',
    'reimbursements:request_number' => 'Globally generated immutable business number.',
    'shift_schedules:employee_id,work_date' => 'Employee parent is tenant-bound.',
    'succession_candidates:critical_position_id,employee_id' => 'Both parents are tenant-bound.',
    'training_registrations:training_id,employee_id' => 'Both parents are tenant-bound.',
    'users:email' => 'Authentication identity is global across tenants.',
    'webhook_deliveries:webhook_id,event_id' => 'Webhook parent is tenant-bound.',
    'wfh_requests:employee_id,work_date' => 'Employee parent is tenant-bound.',
];
