<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class EvolutionRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // â”€â”€â”€ Horizon 2 (Sprint 11â€“13) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // H2.4 Candidate composite scoring
        $routes->post('candidates/(:num)/recalculate-score',
            'System\Horizon2Controller::recalcCandidateScore/$1', ['filter' => ['permission:recruitment.manage,candidate.manage', 'idempotency:required']]);
        $routes->post('job-postings/(:num)/recalculate-scores',
            'System\Horizon2Controller::recalcJobPostingScores/$1', ['filter' => ['permission:recruitment.manage,candidate.manage', 'idempotency:required']]);

        // H2.2 Goals cascading
        $routes->get('performance/goals/tree',
            'System\Horizon2Controller::goalsTree', ['filter' => 'permission:performance.view,performance.manage']);
        $routes->post('performance/goals/(:num)/recompute-rollup',
            'System\Horizon2Controller::recomputeGoalRollup/$1', ['filter' => ['permission:performance.manage', 'idempotency:required']]);

        // H2.3 360Â° peer feedback
        $routes->post('performance/cycles/(:num)/peer-nominations',
            'System\Horizon2Controller::nominatePeer/$1', ['filter' => ['permission:performance.view,performance.manage', 'idempotency:required']]);
        $routes->get('performance/cycles/(:num)/peer-rating',
            'System\Horizon2Controller::peerRating/$1', ['filter' => 'permission:performance.view,performance.manage']);

        // H2.5 Employee Referral
        $routes->post('referrals/links',          'System\Horizon2Controller::createReferralLink', ['filter' => ['ownership:employee', 'idempotency:required']]);
        $routes->get('referrals/links/me',        'System\Horizon2Controller::myReferralLinks', ['filter' => 'ownership:employee']);
        $routes->get('referrals/top-referrers',   'System\Horizon2Controller::topReferrers', ['filter' => 'ownership:employee']);

        // H2.6 Turnover Analytics
        $routes->get('turnover/snapshot',         'System\Horizon2Controller::turnoverSnapshot', ['filter' => 'permission:hr_analytics.view,compliance.report.view']);
        $routes->post('turnover/snapshot',        'System\Horizon2Controller::turnoverSnapshotPersist', ['filter' => ['permission:hr_analytics.manage', 'idempotency:required']]);
        $routes->get('turnover/trend',            'System\Horizon2Controller::turnoverTrend', ['filter' => 'permission:hr_analytics.view,compliance.report.view']);
        $routes->get('employees/data-quality',    'Employee\EmployeeDataQualityController::index', ['filter' => 'permission:hr_analytics.view,compliance.report.view']);

        // H2.7 Payroll Simulasi (in-memory; no persist)
        $routes->post('payroll/simulate',         'System\Horizon2Controller::simulatePayroll', ['filter' => 'permission:payroll.simulate,payroll.manage,recruitment.manage']);
        $routes->post('payroll/simulate/compare', 'System\Horizon2Controller::simulatePayrollCompare', ['filter' => 'permission:payroll.simulate,payroll.manage,recruitment.manage']);
        $routes->post('payroll/simulate/reverse', 'System\Horizon2Controller::simulatePayrollReverse', ['filter' => 'permission:payroll.simulate,payroll.manage,recruitment.manage']);

        // H2.8 Reimbursement OCR â€” extract from attachment_path
        $routes->post('reimbursements/(:num)/ocr-extract',
            'System\Horizon2Controller::ocrExtractReimbursement/$1', ['filter' => 'permission:reimbursement.manage,reimbursement.create']);

        // â”€â”€â”€ Horizon 3 (Sprint 14 + 17) â€” Talent Management â”€â”€â”€â”€â”€â”€â”€â”€
        // H3.1 Competency Library
        $routes->get('competencies',                          'System\Horizon3Controller::listCompetencies', ['filter' => 'permission:competency.view,competency.manage']);
        $routes->post('competencies',                         'System\Horizon3Controller::createCompetency', ['filter' => ['permission:competency.manage', 'idempotency:required']]);
        $routes->get('positions/(:num)/competency-profile',
            'System\Horizon3Controller::listPositionProfile/$1', ['filter' => 'permission:competency.view,competency.manage']);
        $routes->put('positions/(:num)/competency-profile',
            'System\Horizon3Controller::setPositionProfile/$1', ['filter' => 'permission:competency.manage']);
        $routes->get('employees/(:num)/competency-gap',
            'System\Horizon3Controller::competencyGap/$1', ['filter' => 'permission:competency.view,competency.manage,competency.assess_self']);
        $routes->get('employees/(:num)/training-recommendations',
            'System\Horizon3Controller::recommendTraining/$1', ['filter' => 'permission:competency.view,competency.manage,competency.assess_self']);
        $routes->post('employee-competency-assessments',
            'System\Horizon3Controller::recordAssessment', ['filter' => ['permission:competency.manage,competency.assess_self', 'idempotency:required']]);

        // H3.2 Succession Planning
        $routes->get('critical-positions',                    'System\Horizon3Controller::listCriticalPositions', ['filter' => 'permission:succession.view,succession.manage']);
        $routes->post('critical-positions',                   'System\Horizon3Controller::createCriticalPosition', ['filter' => ['permission:succession.manage', 'idempotency:required']]);
        $routes->post('critical-positions/(:num)/successors',
            'System\Horizon3Controller::nominateSuccessor/$1', ['filter' => ['permission:succession.manage', 'idempotency:required']]);
        $routes->get('critical-positions/(:num)/successors',
            'System\Horizon3Controller::listSuccessors/$1', ['filter' => 'permission:succession.view,succession.manage']);
        $routes->post('succession-candidates/(:num)/recompute-readiness',
            'System\Horizon3Controller::recomputeReadiness/$1', ['filter' => 'permission:succession.manage']);
        $routes->get('succession/coverage',                   'System\Horizon3Controller::successionCoverage', ['filter' => 'permission:succession.view']);

        // H3.6 Talent Pool & Internal Marketplace
        $routes->get('employees/(:num)/skills',               'System\Horizon3Controller::listEmployeeSkills/$1', ['filter' => 'permission:talent_pool.view,talent_pool.manage,competency.assess_self']);
        $routes->post('employees/(:num)/skills',              'System\Horizon3Controller::declareSkill/$1', ['filter' => ['permission:talent_pool.manage,competency.assess_self', 'idempotency:required']]);
        $routes->post('employee-skills/(:num)/validate',
            'System\Horizon3Controller::validateSkill/$1', ['filter' => ['permission:talent_pool.manage', 'idempotency:required']]);
        $routes->get('internal-job-postings',                 'System\Horizon3Controller::listInternalPostings', ['filter' => 'permission:talent_pool.view,talent_pool.manage,internal_job.apply']);
        $routes->post('internal-job-postings',                'System\Horizon3Controller::createInternalPosting', ['filter' => ['permission:talent_pool.manage', 'idempotency:required']]);
        $routes->post('internal-job-postings/(:num)/apply',
            'System\Horizon3Controller::applyInternal/$1', ['filter' => ['permission:internal_job.apply', 'ownership:employee', 'idempotency:required']]);
        $routes->get('internal-job-postings/(:num)/applications',
            'System\Horizon3Controller::listInternalApplications/$1', ['filter' => 'permission:talent_pool.view,talent_pool.manage']);
        $routes->get('internal-job-postings/(:num)/recommended-candidates',
            'System\Horizon3Controller::recommendInternalCandidates/$1', ['filter' => 'permission:talent_pool.view,talent_pool.manage']);
        $routes->get('talent-pool/9-box',                     'System\Horizon3Controller::nineBoxGrid', ['filter' => 'permission:talent_pool.view']);

        // H3.6 â€” Employee Aspirations (career goal CRUD self-service + HR view)
        $routes->get('employees/(:num)/aspirations',
            'System\Horizon3Controller::listAspirations/$1', ['filter' => 'permission:talent_pool.view,talent_pool.manage,competency.assess_self']);
        $routes->post('employees/(:num)/aspirations',
            'System\Horizon3Controller::upsertAspiration/$1', ['filter' => ['permission:talent_pool.manage,competency.assess_self', 'idempotency:required']]);
        $routes->patch('employees/(:num)/aspirations/(:num)',
            'System\Horizon3Controller::upsertAspiration/$1/$2', ['filter' => 'permission:talent_pool.manage,competency.assess_self']);
        $routes->delete('employees/(:num)/aspirations/(:num)',
            'System\Horizon3Controller::deleteAspiration/$1/$2', ['filter' => 'permission:talent_pool.manage,competency.assess_self']);

        // â”€â”€â”€ Horizon 4 (Sprint 19+) â€” Platform Evolution â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // H4.1 AI HR Copilot
        $routes->get('ai/sessions',                           'System\Horizon4Controller::listAiSessions', ['filter' => 'permission:ai_assistant.use']);
        $routes->post('ai/sessions',                          'System\Horizon4Controller::startAiSession', ['filter' => 'permission:ai_assistant.use']);
        $routes->get('ai/sessions/(:num)/messages',           'System\Horizon4Controller::getAiMessages/$1', ['filter' => 'permission:ai_assistant.use']);
        $routes->post('ai/sessions/(:num)/messages',          'System\Horizon4Controller::sendAiMessage/$1', ['filter' => 'permission:ai_assistant.use']);
        $routes->post('ai/sessions/(:num)/archive',           'System\Horizon4Controller::archiveAiSession/$1', ['filter' => 'permission:ai_assistant.use']);

        // H4.3 Workforce Planning
        $routes->get('workforce-scenarios',                   'System\Horizon4Controller::listScenarios', ['filter' => 'permission:workforce_planning.view,workforce_planning.manage']);
        $routes->post('workforce-scenarios',                  'System\Horizon4Controller::createScenario', ['filter' => ['permission:workforce_planning.manage', 'idempotency:required']]);
        $routes->post('workforce-scenarios/(:num)/run',       'System\Horizon4Controller::runScenario/$1', ['filter' => ['permission:workforce_planning.manage', 'idempotency:required']]);
        $routes->post('workforce-scenarios/compare',          'System\Horizon4Controller::compareScenarios', ['filter' => 'permission:workforce_planning.manage']);

        // H4.4 Employee Wellbeing
        $routes->get('pulse-surveys',                         'System\Horizon4Controller::listSurveys', ['filter' => 'ownership:employee']);
        $routes->post('pulse-surveys',                        'System\Horizon4Controller::createSurvey', ['filter' => 'permission:pulse_survey.manage']);
        $routes->post('pulse-surveys/(:num)/open',            'System\Horizon4Controller::openSurvey/$1', ['filter' => ['permission:pulse_survey.manage', 'idempotency:required']]);
        $routes->get('pulse-surveys/(:num)/questions',        'System\Horizon4Controller::getSurveyQuestions/$1', ['filter' => 'ownership:employee']);
        $routes->post('pulse-surveys/(:num)/respond',         'System\Horizon4Controller::respondSurvey/$1', ['filter' => ['ownership:employee', 'idempotency:required']]);
        $routes->get('pulse-surveys/(:num)/aggregates',       'System\Horizon4Controller::surveyAggregates/$1', ['filter' => 'permission:pulse_survey.manage']);
        $routes->post('pulse-surveys/(:num)/refresh-aggregates',
            'System\Horizon4Controller::refreshSurveyAggregates/$1', ['filter' => 'permission:pulse_survey.manage']);

        // H4.5 Compensation Intelligence
        $routes->get('compensation/compa-ratio',              'System\Horizon4Controller::compaRatio', ['filter' => 'permission:compensation_intelligence.view,compensation_intelligence.manage']);
        $routes->get('compensation/gender-equity',            'System\Horizon4Controller::genderEquity', ['filter' => 'permission:compensation_intelligence.view,compensation_intelligence.manage']);
        $routes->get('compensation/band-recommendations',     'System\Horizon4Controller::bandRecommendations', ['filter' => 'permission:compensation_intelligence.view,compensation_intelligence.manage']);
        $routes->get('salary-benchmarks',                     'System\Horizon4Controller::listBenchmarks', ['filter' => 'permission:compensation_intelligence.view,compensation_intelligence.manage']);
        $routes->post('salary-benchmarks',                    'System\Horizon4Controller::createBenchmark', ['filter' => ['permission:compensation_intelligence.manage', 'idempotency:required']]);

        // â”€â”€â”€ H3.4 â€” ERP Journal Integration â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Connection management (per company Ã— provider)
        $routes->get('erp/connections',                       'Erp\ErpJournalController::listConnections', ['filter' => 'permission:erp.mapping.manage']);
        $routes->post('erp/connections',                      'Erp\ErpJournalController::createConnection', ['filter' => 'permission:erp.mapping.manage']);
        $routes->put('erp/connections/(:num)',                'Erp\ErpJournalController::updateConnection/$1', ['filter' => 'permission:erp.mapping.manage']);
        $routes->delete('erp/connections/(:num)',             'Erp\ErpJournalController::deleteConnection/$1', ['filter' => 'permission:erp.mapping.manage']);
        $routes->post('erp/connections/(:num)/test',          'Erp\ErpJournalController::testConnection/$1', ['filter' => 'permission:erp.mapping.manage']);

        // COA mapping per cost_center Ã— account_type
        $routes->get('erp/mappings',                          'Erp\ErpJournalController::listMappings', ['filter' => 'permission:erp.mapping.manage']);
        $routes->post('erp/mappings',                         'Erp\ErpJournalController::createMapping', ['filter' => 'permission:erp.mapping.manage']);
        $routes->put('erp/mappings/(:num)',                   'Erp\ErpJournalController::updateMapping/$1', ['filter' => 'permission:erp.mapping.manage']);
        $routes->delete('erp/mappings/(:num)',                'Erp\ErpJournalController::deleteMapping/$1', ['filter' => 'permission:erp.mapping.manage']);

        // Journal exports (preview, persist, post, reverse, download CSV)
        $routes->get('erp/journal-exports',                   'Erp\ErpJournalController::listExports', ['filter' => 'permission:erp.journal.view']);
        $routes->post('erp/journal-exports/preview',          'Erp\ErpJournalController::previewExport', ['filter' => 'permission:erp.journal.view']);
        $routes->post('erp/journal-exports',                  'Erp\ErpJournalController::createExport', ['filter' => ['permission:erp.journal.post', 'idempotency:required']]);
        $routes->get('erp/journal-exports/(:num)',            'Erp\ErpJournalController::showExport/$1', ['filter' => 'permission:erp.journal.view']);
        $routes->get('erp/journal-exports/(:num)/csv',        'Erp\ErpJournalController::downloadCsv/$1', ['filter' => ['permission:erp.journal.view', 'throttle:export']]);
        $routes->post('erp/journal-exports/(:num)/post',      'Erp\ErpJournalController::postExport/$1', ['filter' => ['permission:erp.journal.post', 'idempotency:required']]);
        $routes->post('erp/journal-exports/(:num)/reverse',   'Erp\ErpJournalController::reverseExport/$1', ['filter' => ['permission:erp.journal.post', 'idempotency:required']]);

        // â”€â”€â”€ H3.7 â€” Digital Signing PSrE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Provider config (PrivyID/Vida/Peruri/MOCK_DEV)
        $routes->get('signing/providers',                     'Signing\SigningController::listProviders', ['filter' => 'permission:signing.provider.manage']);
        $routes->post('signing/providers',                    'Signing\SigningController::createProvider', ['filter' => 'permission:signing.provider.manage']);
        $routes->put('signing/providers/(:num)',              'Signing\SigningController::updateProvider/$1', ['filter' => 'permission:signing.provider.manage']);
        $routes->delete('signing/providers/(:num)',           'Signing\SigningController::deleteProvider/$1', ['filter' => 'permission:signing.provider.manage']);

        // Envelope lifecycle
        $routes->get('signing/envelopes',                     'Signing\SigningController::listEnvelopes', ['filter' => 'permission:signing.envelope.view']);
        $routes->post('signing/envelopes',                    'Signing\SigningController::createEnvelope', ['filter' => ['permission:signing.envelope.create', 'idempotency:required']]);
        $routes->get('signing/envelopes/(:num)',              'Signing\SigningController::showEnvelope/$1', ['filter' => 'permission:signing.envelope.view']);
        $routes->post('signing/envelopes/(:num)/signers',     'Signing\SigningController::addSigner/$1', ['filter' => 'permission:signing.envelope.create']);
        $routes->post('signing/envelopes/(:num)/send',        'Signing\SigningController::send/$1', ['filter' => ['permission:signing.envelope.create', 'idempotency:required']]);
        $routes->post('signing/envelopes/(:num)/cancel',      'Signing\SigningController::cancel/$1', ['filter' => ['permission:signing.envelope.create', 'idempotency:required']]);
        $routes->post('signing/envelopes/(:num)/wet-sign',    'Signing\SigningController::wetSignFallback/$1',
            ['filter' => ['permission:signing.envelope.create', 'sameorigin', 'idempotency:required']]);
        $routes->get('signing/envelopes/(:num)/audit',        'Signing\SigningController::audit/$1', ['filter' => 'permission:signing.envelope.view']);
        // Callback PSrE (production via webhook + HMAC; dev via direct call)
        $routes->post('signing/envelopes/(:num)/signers/(:num)/event',
            'Signing\SigningController::recordSignerEvent/$1/$2', ['filter' => 'permission:signing.envelope.create']);

        // â”€â”€â”€ H3.8 â€” Open API & Developer Portal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $routes->get('api-scopes',                            'Api\ApiKeyController::listScopes', ['filter' => 'permission:api_key.view']);
        $routes->get('api-keys',                              'Api\ApiKeyController::listKeys', ['filter' => 'permission:api_key.view']);
        $routes->post('api-keys',                             'Api\ApiKeyController::createKey', ['filter' => 'permission:api_key.manage']);
        $routes->get('api-keys/(:num)',                       'Api\ApiKeyController::showKey/$1', ['filter' => 'permission:api_key.view']);
        $routes->post('api-keys/(:num)/rotate',               'Api\ApiKeyController::rotateKey/$1', ['filter' => 'permission:api_key.manage']);
        $routes->post('api-keys/(:num)/revoke',               'Api\ApiKeyController::revokeKey/$1', ['filter' => 'permission:api_key.manage']);
        $routes->get('api-keys/(:num)/usage',                 'Api\ApiKeyController::usage/$1', ['filter' => 'permission:api_key.view']);

        // â”€â”€â”€ Project Timesheet (jasa profesional / konsultan) â”€â”€â”€â”€â”€â”€â”€â”€
        // Project master + assignments. burn-rate harus di atas /(:num)
        // generic agar tidak nyangkut sebagai resource id.
        $routes->get('projects/(:num)/burn-rate',             'Timesheet\ProjectController::burnRate/$1', ['filter' => 'permission:timesheet.report']);
        $routes->get('projects/(:num)/assignments',           'Timesheet\ProjectController::listAssignments/$1', ['filter' => 'permission:project.manage']);
        $routes->post('projects/(:num)/assignments',          'Timesheet\ProjectController::createAssignment/$1', ['filter' => 'permission:project.manage']);
        $routes->get('projects',                              'Timesheet\ProjectController::index', ['filter' => 'permission:project.manage,timesheet.submit']);
        $routes->post('projects',                             'Timesheet\ProjectController::create', ['filter' => 'permission:project.manage']);
        $routes->get('projects/(:num)',                       'Timesheet\ProjectController::show/$1', ['filter' => 'permission:project.manage,timesheet.submit']);
        $routes->put('projects/(:num)',                       'Timesheet\ProjectController::update/$1', ['filter' => 'permission:project.manage']);
        $routes->delete('projects/(:num)',                    'Timesheet\ProjectController::delete/$1', ['filter' => 'permission:project.manage']);
        $routes->put('project-assignments/(:num)',            'Timesheet\ProjectController::updateAssignment/$1', ['filter' => 'permission:project.manage']);
        $routes->delete('project-assignments/(:num)',         'Timesheet\ProjectController::deleteAssignment/$1', ['filter' => 'permission:project.manage']);

        // Timesheet entries â€” endpoint khusus didahulukan
        $routes->get('timesheet/my',                          'Timesheet\TimesheetController::my', ['filter' => ['permission:timesheet.submit', 'ownership:employee']]);
        $routes->get('timesheet/utilization',                 'Timesheet\TimesheetController::utilization', ['filter' => 'permission:timesheet.report']);
        $routes->get('timesheet',                             'Timesheet\TimesheetController::index', ['filter' => 'permission:timesheet.approve,timesheet.report']);
        $routes->post('timesheet',                            'Timesheet\TimesheetController::upsert', ['filter' => 'permission:timesheet.submit']);
        $routes->post('timesheet/(:num)/submit',              'Timesheet\TimesheetController::submit/$1', ['filter' => ['permission:timesheet.submit', 'idempotency:required']]);
        $routes->post('timesheet/(:num)/approve',             'Timesheet\TimesheetController::approve/$1', ['filter' => ['permission:timesheet.approve', 'idempotency:required']]);
        $routes->post('timesheet/(:num)/reject',              'Timesheet\TimesheetController::reject/$1', ['filter' => ['permission:timesheet.approve', 'idempotency:required']]);

        // â”€â”€â”€ License & Certification Expiry Tracker â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Master license types per company
        $routes->get('license-types',                         'License\LicenseController::listTypes', ['filter' => 'permission:employee_license.view']);
        $routes->post('license-types',                        'License\LicenseController::createType', ['filter' => 'permission:license_type.manage']);
        $routes->put('license-types/(:num)',                  'License\LicenseController::updateType/$1', ['filter' => 'permission:license_type.manage']);
        $routes->delete('license-types/(:num)',               'License\LicenseController::deleteType/$1', ['filter' => 'permission:license_type.manage']);

        // Karyawan â†” license â€” endpoint khusus didahulukan
        $routes->get('licenses/expiring-dashboard',           'License\LicenseController::expiringDashboard', ['filter' => 'permission:employee_license.view,employee_license.manage']);
        $routes->post('licenses/scan-now',                    'License\LicenseController::scanNow', ['filter' => ['permission:employee_license.manage', 'idempotency:required']]);
        $routes->get('employees/(:num)/license-compliance',   'License\LicenseController::compliance/$1', ['filter' => 'permission:employee_license.view']);
        $routes->get('employee-licenses',                     'License\LicenseController::listLicenses', ['filter' => 'permission:employee_license.view']);
        $routes->post('employee-licenses',                    'License\LicenseController::createLicense', ['filter' => 'permission:employee_license.manage']);
        $routes->get('employee-licenses/(:num)',              'License\LicenseController::showLicense/$1', ['filter' => 'permission:employee_license.view']);
        $routes->put('employee-licenses/(:num)',              'License\LicenseController::updateLicense/$1', ['filter' => 'permission:employee_license.manage']);
        $routes->post('employee-licenses/(:num)/renew',       'License\LicenseController::renewLicense/$1', ['filter' => ['permission:employee_license.manage', 'idempotency:required']]);
        $routes->post('employee-licenses/(:num)/revoke',      'License\LicenseController::revokeLicense/$1', ['filter' => ['permission:employee_license.manage', 'idempotency:required']]);
    }
}
