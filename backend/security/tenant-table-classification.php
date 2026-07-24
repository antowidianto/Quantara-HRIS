<?php

declare(strict_types=1);

/**
 * Reviewed classification for tables that deliberately do not carry a direct
 * company_id column. Tenant-owned leaf tables inherit their boundary through
 * the declared parent; everything else is explicitly global/infrastructure.
 */
return [
    'global' => [
        'api_scopes' => 'Platform API-scope catalogue.',
        'backup_runs' => 'Platform backup execution ledger; optional company_id is not its ownership key.',
        'career_master_institutions' => 'Shared reference catalogue.',
        'career_master_locations' => 'Shared reference catalogue.',
        'companies' => 'Tenant root table.',
        'failed_jobs' => 'Queue infrastructure.',
        'feature_flags' => 'Platform feature catalogue.',
        'jobs' => 'Queue infrastructure.',
        'login_attempts' => 'Pre-authentication security ledger.',
        'migrations' => 'Framework migration history.',
        'permissions' => 'Platform permission catalogue.',
        'plan_features' => 'Subscription-plan feature mapping.',
        'pph21_brackets' => 'National tax reference data.',
        'ptkp_rates' => 'National tax reference data.',
        'regional_minimum_wages' => 'National/regional statutory reference data.',
        'release_announcements' => 'Platform-wide release communication.',
        'restore_drills' => 'Platform disaster-recovery evidence ledger linked to a backup run.',
        'signup_attempts' => 'Pre-tenant signup security ledger.',
        'subscription_plans' => 'Platform subscription catalogue.',
        'ter_rates' => 'National tax reference data.',
    ],
    'tenant_via_parent' => [
        'ai_chat_messages' => ['parent' => 'ai_chat_sessions', 'foreign_key' => true],
        'announcement_reads' => ['parent' => 'users', 'foreign_key' => false],
        'api_key_scopes' => ['parent' => 'api_keys', 'foreign_key' => true],
        'candidate_password_resets' => ['parent' => 'candidate_accounts', 'foreign_key' => true],
        'candidate_refresh_tokens' => ['parent' => 'candidate_accounts', 'foreign_key' => true],
        'competency_levels' => ['parent' => 'competencies', 'foreign_key' => true],
        'job_posting_scoring_criteria' => ['parent' => 'job_postings', 'foreign_key' => true],
        'online_test_answers' => ['parent' => 'online_test_attempts', 'foreign_key' => true],
        'online_test_attempts' => ['parent' => 'online_test_assignments', 'foreign_key' => true],
        'online_test_events' => ['parent' => 'online_test_attempts', 'foreign_key' => true],
        'online_test_questions' => ['parent' => 'online_tests', 'foreign_key' => true],
        'password_history' => ['parent' => 'users', 'foreign_key' => false],
        'password_resets' => ['parent' => 'users', 'foreign_key' => true],
        'payslip_details' => ['parent' => 'payslips', 'foreign_key' => true],
        'project_assignments' => ['parent' => 'projects', 'foreign_key' => true],
        'pulse_survey_aggregates' => ['parent' => 'pulse_surveys', 'foreign_key' => true],
        'pulse_survey_questions' => ['parent' => 'pulse_surveys', 'foreign_key' => true],
        'pulse_survey_responses' => ['parent' => 'pulse_surveys', 'foreign_key' => true],
        'refresh_tokens' => ['parent' => 'users', 'foreign_key' => true],
        'role_permissions' => ['parent' => 'roles', 'foreign_key' => true],
        'signing_envelope_events' => ['parent' => 'signing_envelopes', 'foreign_key' => true],
        'signing_envelope_signers' => ['parent' => 'signing_envelopes', 'foreign_key' => true],
        'twofa_backup_codes' => ['parent' => 'users', 'foreign_key' => false],
        'user_notification_preferences' => ['parent' => 'users', 'foreign_key' => true],
        'workforce_scenario_runs' => ['parent' => 'workforce_scenarios', 'foreign_key' => true],
    ],
];
