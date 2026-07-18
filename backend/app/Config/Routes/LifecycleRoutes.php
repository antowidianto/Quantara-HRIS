<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class LifecycleRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // â”€â”€â”€ Business Trip / SPPD â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $routes->put('business-trip-expenses/(:num)',    'Attendance\BusinessTripController::updateExpense/$1', ['filter' => 'permission:business_trip.manage,business_trip.settle']);
        $routes->delete('business-trip-expenses/(:num)', 'Attendance\BusinessTripController::deleteExpense/$1', ['filter' => 'permission:business_trip.manage,business_trip.settle']);
        $routes->get('business-trips',                       'Attendance\BusinessTripController::index', ['filter' => 'permission:business_trip.view']);
        $routes->post('business-trips',                      'Attendance\BusinessTripController::create',
            ['filter' => ['permission:business_trip.manage', 'idempotency:required']]);
        $routes->get('business-trips/(:num)',                'Attendance\BusinessTripController::show/$1', ['filter' => 'permission:business_trip.view']);
        $routes->put('business-trips/(:num)',                'Attendance\BusinessTripController::update/$1', ['filter' => 'permission:business_trip.manage']);
        $routes->delete('business-trips/(:num)',             'Attendance\BusinessTripController::delete/$1', ['filter' => 'permission:business_trip.manage']);
        $routes->post('business-trips/(:num)/submit',        'Attendance\BusinessTripController::submit/$1', ['filter' => ['permission:business_trip.manage', 'idempotency:required']]);
        $routes->post('business-trips/(:num)/approve',       'Attendance\BusinessTripController::approve/$1', ['filter' => ['permission:business_trip.approve', 'idempotency:required']]);
        $routes->post('business-trips/(:num)/reject',        'Attendance\BusinessTripController::reject/$1', ['filter' => ['permission:business_trip.approve', 'idempotency:required']]);
        $routes->post('business-trips/(:num)/start',         'Attendance\BusinessTripController::start/$1', ['filter' => 'permission:business_trip.manage']);
        $routes->post('business-trips/(:num)/complete',      'Attendance\BusinessTripController::complete/$1', ['filter' => 'permission:business_trip.manage']);
        $routes->post('business-trips/(:num)/settle',        'Attendance\BusinessTripController::settle/$1', ['filter' => ['permission:business_trip.settle', 'idempotency:required']]);
        $routes->post('business-trips/(:num)/cancel',        'Attendance\BusinessTripController::cancel/$1', ['filter' => 'permission:business_trip.manage']);
        $routes->get('business-trips/(:num)/sppd',           'Attendance\BusinessTripController::sppd/$1', ['filter' => 'permission:business_trip.view']);
        $routes->get('business-trips/(:num)/expenses',       'Attendance\BusinessTripController::listExpenses/$1', ['filter' => 'permission:business_trip.view']);
        $routes->post('business-trips/(:num)/expenses',      'Attendance\BusinessTripController::addExpense/$1', ['filter' => 'permission:business_trip.manage,business_trip.settle']);

        // â”€â”€â”€ Asset / Inventory â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $routes->get('asset-assignments',                'Asset\AssetController::listAssignments', ['filter' => 'permission:asset.view']);
        $routes->get('asset-assignments/employee/(:num)','Asset\AssetController::forEmployee/$1', ['filter' => 'permission:asset.view']);
        $routes->get('asset-assignments/(:num)/bast',    'Asset\AssetController::bast/$1', ['filter' => 'permission:asset.view']);
        $routes->get('assets',                           'Asset\AssetController::index', ['filter' => 'permission:asset.view']);
        $routes->post('assets',                          'Asset\AssetController::create', ['filter' => 'permission:asset.manage']);
        $routes->get('assets/(:num)',                    'Asset\AssetController::show/$1', ['filter' => 'permission:asset.view']);
        $routes->put('assets/(:num)',                    'Asset\AssetController::update/$1', ['filter' => 'permission:asset.manage']);
        $routes->delete('assets/(:num)',                 'Asset\AssetController::delete/$1', ['filter' => 'permission:asset.manage']);
        $routes->post('assets/(:num)/assign',            'Asset\AssetController::assign/$1', ['filter' => ['permission:asset.manage', 'idempotency:required']]);
        $routes->post('assets/(:num)/return',            'Asset\AssetController::returnAsset/$1', ['filter' => ['permission:asset.manage', 'idempotency:required']]);
        $routes->post('assets/(:num)/retire',            'Asset\AssetController::retire/$1', ['filter' => ['permission:asset.manage', 'idempotency:required']]);

        // â”€â”€â”€ Onboarding & Offboarding Tasks â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Templates
        $routes->get('task-templates',                'Lifecycle\TaskTemplateController::index', ['filter' => 'permission:onboarding.view']);
        $routes->get('task-templates/(:num)',         'Lifecycle\TaskTemplateController::show/$1', ['filter' => 'permission:onboarding.view']);
        $routes->post('task-templates',               'Lifecycle\TaskTemplateController::create', ['filter' => 'permission:onboarding.manage']);
        $routes->put('task-templates/(:num)',         'Lifecycle\TaskTemplateController::update/$1', ['filter' => 'permission:onboarding.manage']);
        $routes->delete('task-templates/(:num)',      'Lifecycle\TaskTemplateController::delete/$1', ['filter' => 'permission:onboarding.manage']);
        // Employee tasks (instance)
        $routes->get('employee-tasks/board',          'Lifecycle\EmployeeTaskController::board', ['filter' => 'permission:onboarding.view']);
        $routes->get('employee-tasks/employee/(:num)','Lifecycle\EmployeeTaskController::forEmployee/$1', ['filter' => 'permission:onboarding.view']);
        $routes->post('employee-tasks/generate',      'Lifecycle\EmployeeTaskController::generate', ['filter' => ['permission:onboarding.manage', 'idempotency:required']]);
        $routes->get('employee-tasks',                'Lifecycle\EmployeeTaskController::index', ['filter' => 'permission:onboarding.view,onboarding.complete_self']);
        $routes->post('employee-tasks',               'Lifecycle\EmployeeTaskController::create', ['filter' => 'permission:onboarding.manage']);
        $routes->get('employee-tasks/(:num)',         'Lifecycle\EmployeeTaskController::show/$1', ['filter' => 'permission:onboarding.view,onboarding.complete_self']);
        $routes->put('employee-tasks/(:num)',         'Lifecycle\EmployeeTaskController::update/$1', ['filter' => 'permission:onboarding.manage,onboarding.complete_self']);
        $routes->delete('employee-tasks/(:num)',      'Lifecycle\EmployeeTaskController::delete/$1', ['filter' => 'permission:onboarding.manage']);
        $routes->post('employee-tasks/(:num)/complete','Lifecycle\EmployeeTaskController::complete/$1', ['filter' => ['permission:onboarding.manage,onboarding.complete_self', 'idempotency:required']]);

        // â”€â”€â”€ Bonus Engine (Sprint 16+) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Modul P3 baru untuk handle bonus selain THR & insentif (annual,
        // KPI, milestone, sign-on, retention). Workflow run:
        //   DRAFT -> CALCULATED -> PENDING_APPROVAL -> APPROVED -> PAID.
        $routes->post('bonus-schemes/preview',                  'Payroll\BonusController::previewScheme', ['filter' => 'permission:bonus.manage']);
        $routes->get('bonus-schemes',                           'Payroll\BonusController::listSchemes', ['filter' => 'permission:bonus.view']);
        $routes->post('bonus-schemes',                          'Payroll\BonusController::createScheme', ['filter' => 'permission:bonus.manage']);
        $routes->get('bonus-schemes/(:num)',                    'Payroll\BonusController::showScheme/$1', ['filter' => 'permission:bonus.view']);
        $routes->put('bonus-schemes/(:num)',                    'Payroll\BonusController::updateScheme/$1', ['filter' => 'permission:bonus.manage']);
        $routes->delete('bonus-schemes/(:num)',                 'Payroll\BonusController::deleteScheme/$1', ['filter' => 'permission:bonus.manage']);
        $routes->get('bonus-runs',                              'Payroll\BonusController::listRuns', ['filter' => 'permission:bonus.view']);
        $routes->post('bonus-runs',                             'Payroll\BonusController::createRun',
            ['filter' => ['permission:bonus.manage', 'idempotency:required']]);
        $routes->get('bonus-runs/(:num)',                       'Payroll\BonusController::showRun/$1', ['filter' => 'permission:bonus.view']);
        $routes->put('bonus-runs/(:num)',                       'Payroll\BonusController::updateRun/$1', ['filter' => 'permission:bonus.manage']);
        $routes->delete('bonus-runs/(:num)',                    'Payroll\BonusController::deleteRun/$1', ['filter' => 'permission:bonus.manage']);
        $routes->post('bonus-runs/(:num)/calculate',            'Payroll\BonusController::calculateRun/$1',
            ['filter' => ['permission:bonus.manage', 'idempotency:required']]);
        $routes->post('bonus-runs/(:num)/submit',               'Payroll\BonusController::submitRun/$1',
            ['filter' => ['permission:bonus.manage', 'idempotency:required']]);
        $routes->post('bonus-runs/(:num)/mark-paid',            'Payroll\BonusController::markRunPaid/$1',
            ['filter' => ['permission:bonus.approve', 'idempotency:required']]);
        $routes->post('bonus-runs/(:num)/cancel',               'Payroll\BonusController::cancelRun/$1',
            ['filter' => ['permission:bonus.manage', 'idempotency:required']]);
        $routes->get('bonus-runs/(:num)/recipients',            'Payroll\BonusController::listRecipients/$1', ['filter' => 'permission:bonus.view']);
        $routes->post('bonus-runs/(:num)/recipients/include-all', 'Payroll\BonusController::includeAllRecipients/$1',
            ['filter' => ['permission:bonus.manage', 'idempotency:required']]);
        $routes->put('bonus-recipients/(:num)',                 'Payroll\BonusController::overrideRecipient/$1', ['filter' => 'permission:bonus.manage']);
        // Sprint 20+ â€” Bonus history per karyawan (untuk profile tab + HR analytics)
        $routes->get('employees/(:num)/bonus-history',          'Payroll\BonusController::employeeHistory/$1', ['filter' => 'permission:bonus.view_self,bonus.view']);

        // â”€â”€â”€ Severance / Pesangon (PHK) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Endpoint khusus didefinisikan SEBELUM CRUD
        $routes->get('severance/reasons',                'Lifecycle\SeveranceController::reasons', ['filter' => 'permission:severance.view']);
        $routes->post('severance/calculate',             'Lifecycle\SeveranceController::preview', ['filter' => 'permission:severance.manage']);
        $routes->get('severance',                        'Lifecycle\SeveranceController::index', ['filter' => 'permission:severance.view']);
        $routes->post('severance',                       'Lifecycle\SeveranceController::create', ['filter' => 'permission:severance.manage']);
        $routes->get('severance/(:num)',                 'Lifecycle\SeveranceController::show/$1', ['filter' => 'permission:severance.view']);
        $routes->put('severance/(:num)',                 'Lifecycle\SeveranceController::update/$1', ['filter' => 'permission:severance.manage']);
        $routes->delete('severance/(:num)',              'Lifecycle\SeveranceController::delete/$1', ['filter' => 'permission:severance.manage']);
        $routes->post('severance/(:num)/finalize',       'Lifecycle\SeveranceController::finalize/$1', ['filter' => ['permission:severance.manage', 'idempotency:required']]);
        $routes->post('severance/(:num)/mark-paid',      'Lifecycle\SeveranceController::markPaid/$1', ['filter' => ['permission:severance.manage', 'idempotency:required']]);
        $routes->post('severance/(:num)/cancel',         'Lifecycle\SeveranceController::cancel/$1', ['filter' => 'permission:severance.manage']);
        $routes->get('severance/(:num)/breakdown',       'Lifecycle\SeveranceController::breakdown/$1', ['filter' => 'permission:severance.view']);

        // â”€â”€â”€ Disciplinary Records (Surat Peringatan) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Endpoint khusus didefinisikan SEBELUM CRUD agar path seperti
        // /disciplinary-records/categories tidak ke-trap sebagai id.
        $routes->get('disciplinary-records/categories',                  'Lifecycle\DisciplinaryController::listCategories', ['filter' => 'permission:disciplinary.view']);
        $routes->post('disciplinary-records/categories',                 'Lifecycle\DisciplinaryController::createCategory', ['filter' => 'permission:disciplinary.manage']);
        $routes->put('disciplinary-records/categories/(:num)',           'Lifecycle\DisciplinaryController::updateCategory/$1', ['filter' => 'permission:disciplinary.manage']);
        $routes->delete('disciplinary-records/categories/(:num)',        'Lifecycle\DisciplinaryController::deleteCategory/$1', ['filter' => 'permission:disciplinary.manage']);
        $routes->get('disciplinary-records/employee-summary/(:num)',     'Lifecycle\DisciplinaryController::employeeSummary/$1', ['filter' => 'permission:disciplinary.view']);
        $routes->get('disciplinary-records',                             'Lifecycle\DisciplinaryController::index', ['filter' => 'permission:disciplinary.view']);
        $routes->post('disciplinary-records',                            'Lifecycle\DisciplinaryController::create', ['filter' => 'permission:disciplinary.manage']);
        $routes->get('disciplinary-records/(:num)',                      'Lifecycle\DisciplinaryController::show/$1', ['filter' => 'permission:disciplinary.view']);
        $routes->put('disciplinary-records/(:num)',                      'Lifecycle\DisciplinaryController::update/$1', ['filter' => 'permission:disciplinary.manage']);
        $routes->delete('disciplinary-records/(:num)',                   'Lifecycle\DisciplinaryController::delete/$1', ['filter' => 'permission:disciplinary.manage']);
        $routes->post('disciplinary-records/(:num)/revoke',              'Lifecycle\DisciplinaryController::revoke/$1', ['filter' => ['permission:disciplinary.manage', 'idempotency:required']]);
        $routes->post('disciplinary-records/(:num)/acknowledge',         'Lifecycle\DisciplinaryController::acknowledge/$1', ['filter' => ['permission:disciplinary.view', 'idempotency:required']]);
        $routes->get('disciplinary-records/(:num)/letter',               'Lifecycle\DisciplinaryController::letter/$1', ['filter' => 'permission:disciplinary.view']);

        // â”€â”€â”€ Approval Delegations â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $routes->get('approval-delegations/eligible-users', 'Approval\ApprovalDelegationController::eligibleUsers', ['filter' => 'permission:delegation.view,delegation.manage_self']);
        $routes->get('approval-delegations',           'Approval\ApprovalDelegationController::index', ['filter' => 'permission:delegation.view,delegation.manage_self']);
        $routes->get('approval-delegations/(:num)',    'Approval\ApprovalDelegationController::show/$1', ['filter' => 'permission:delegation.view,delegation.manage_self']);
        $routes->post('approval-delegations',          'Approval\ApprovalDelegationController::create', ['filter' => 'permission:delegation.manage,delegation.manage_self']);
        $routes->put('approval-delegations/(:num)',    'Approval\ApprovalDelegationController::update/$1', ['filter' => 'permission:delegation.manage,delegation.manage_self']);
        $routes->delete('approval-delegations/(:num)', 'Approval\ApprovalDelegationController::delete/$1', ['filter' => 'permission:delegation.manage,delegation.manage_self']);

        // â”€â”€â”€ Approval Chains â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $routes->get('approval-chains',            'Approval\ApprovalChainController::index', ['filter' => 'permission:approval_chain.view']);
        $routes->get('approval-chains/(:num)',     'Approval\ApprovalChainController::show/$1', ['filter' => 'permission:approval_chain.view']);
        $routes->post('approval-chains',           'Approval\ApprovalChainController::create', ['filter' => 'permission:approval_chain.manage']);
        $routes->put('approval-chains/(:num)',     'Approval\ApprovalChainController::update/$1', ['filter' => 'permission:approval_chain.manage']);
        $routes->delete('approval-chains/(:num)',  'Approval\ApprovalChainController::delete/$1', ['filter' => 'permission:approval_chain.manage']);
        // Assignment rules â€” chain mana berlaku untuk dept/branch/grade tertentu
        $routes->get('approval-assignments',       'Approval\ApprovalChainController::listAssignments', ['filter' => 'permission:approval_chain.view']);
        $routes->post('approval-assignments',      'Approval\ApprovalChainController::createAssignment', ['filter' => 'permission:approval_chain.manage']);
        $routes->delete('approval-assignments/(:num)', 'Approval\ApprovalChainController::deleteAssignment/$1', ['filter' => 'permission:approval_chain.manage']);
        // Inbox + decision
        $routes->get('approval-requests/inbox',         'Approval\ApprovalChainController::inbox', ['filter' => 'ownership:assigned-approver']);
        $routes->get('approval-requests/history',       'Approval\ApprovalChainController::history', ['filter' => 'ownership:assigned-approver']);
        // Bulk endpoints (Sprint 7 / H1.1) â€” harus didefinisikan SEBELUM
        // /(:num)/approve & /(:num)/reject supaya path /bulk-approve tidak
        // di-resolve sebagai numeric id.
        $routes->post('approval-requests/bulk-approve', 'Approval\ApprovalChainController::bulkApprove',
            ['filter' => ['ownership:assigned-approver', 'idempotency:required']]);
        $routes->post('approval-requests/bulk-reject',  'Approval\ApprovalChainController::bulkReject',
            ['filter' => ['ownership:assigned-approver', 'idempotency:required']]);
        $routes->get('approval-requests/(:num)',        'Approval\ApprovalChainController::showRequest/$1', ['filter' => 'ownership:assigned-approver']);
        $routes->post('approval-requests/(:num)/approve','Approval\ApprovalChainController::approveRequest/$1',
            ['filter' => ['ownership:assigned-approver', 'idempotency:required']]);
        $routes->post('approval-requests/(:num)/reject', 'Approval\ApprovalChainController::rejectRequest/$1',
            ['filter' => ['ownership:assigned-approver', 'idempotency:required']]);

        // â”€â”€â”€ Company Regulations (klausul peraturan) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $routes->get('regulations',                'System\CompanyRegulationController::index', ['filter' => 'permission:regulation.view']);
        $routes->get('regulations/preview',        'System\CompanyRegulationController::preview', ['filter' => 'permission:regulation.view']);
        $routes->get('regulations/(:num)',         'System\CompanyRegulationController::show/$1', ['filter' => 'permission:regulation.view']);
        $routes->post('regulations',               'System\CompanyRegulationController::create', ['filter' => 'permission:regulation.manage']);
        $routes->put('regulations/(:num)',         'System\CompanyRegulationController::update/$1', ['filter' => 'permission:regulation.manage']);
        $routes->delete('regulations/(:num)',      'System\CompanyRegulationController::delete/$1', ['filter' => 'permission:regulation.manage']);

        // â”€â”€â”€ Contract Templates â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $routes->get('contract-templates',                       'Lifecycle\ContractTemplateController::index', ['filter' => 'permission:contract_template.view']);
        $routes->get('contract-templates/variables',             'Lifecycle\ContractTemplateController::variables', ['filter' => 'permission:contract_template.view']);
        $routes->get('contract-templates/(:num)',                'Lifecycle\ContractTemplateController::show/$1', ['filter' => 'permission:contract_template.view']);
        $routes->post('contract-templates',                      'Lifecycle\ContractTemplateController::create', ['filter' => 'permission:contract_template.manage']);
        $routes->put('contract-templates/(:num)',                'Lifecycle\ContractTemplateController::update/$1', ['filter' => 'permission:contract_template.manage']);
        $routes->delete('contract-templates/(:num)',             'Lifecycle\ContractTemplateController::delete/$1', ['filter' => 'permission:contract_template.manage']);
        $routes->post('contract-templates/(:num)/preview',       'Lifecycle\ContractTemplateController::preview/$1', ['filter' => 'permission:contract_template.view']);
        $routes->post('contract-templates/(:num)/generate',      'Lifecycle\ContractTemplateController::generate/$1', ['filter' => ['permission:contract.generate', 'idempotency:required']]);

        // â”€â”€â”€ Generic File Upload (Sprint 3 item I) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // POST /upload?context=ticket|candidate_doc|employee_doc|training_cert&entity_id=N
        // Validasi MIME + size + multi-tenant ownership di controller.
        // Sprint A / B3 â€” Origin guard untuk multipart upload.
        $routes->post('upload', 'System\FileUploadController::upload', ['filter' => ['ownership:account', 'sameorigin']]);

        // â”€â”€â”€ Pre-create attachment upload â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // POST /upload/attachment?context=leave|overtime|reimbursement|...
        // Untuk lampiran/bukti yang ditempel ke field `attachment_path`
        // di payload create form. Boleh dipanggil sebelum parent record
        // dibuat. Lihat System\FileUploadController::ATTACHMENT_CONTEXTS.
        // Sprint A / B3 â€” Origin guard untuk multipart upload.
        $routes->post('upload/attachment', 'System\FileUploadController::uploadAttachment',
            ['filter' => ['ownership:account', 'sameorigin']]);

        // â”€â”€â”€ Resignation Requests (self-service) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Karyawan ajukan surat resign â†’ atasan approve â†’ HR approve â†’
        // auto-trigger offboarding tasks (lihat docs/RESIGNATION.md).
        $routes->get('resignations/reasons',          'Lifecycle\ResignationController::reasons', ['filter' => 'permission:resignation.view_self,resignation.view']);
        $routes->get('resignations',                  'Lifecycle\ResignationController::index', ['filter' => 'permission:resignation.view_self,resignation.view']);
        $routes->post('resignations',                 'Lifecycle\ResignationController::create', ['filter' => ['permission:resignation.view_self,resignation.view', 'idempotency:required']]);
        $routes->get('resignations/(:num)',           'Lifecycle\ResignationController::show/$1', ['filter' => 'permission:resignation.view_self,resignation.view']);
        $routes->post('resignations/(:num)/approve',  'Lifecycle\ResignationController::approve/$1', ['filter' => ['permission:resignation.manage', 'idempotency:required']]);
        $routes->post('resignations/(:num)/reject',   'Lifecycle\ResignationController::reject/$1', ['filter' => ['permission:resignation.manage', 'idempotency:required']]);
        $routes->post('resignations/(:num)/cancel',   'Lifecycle\ResignationController::cancel/$1', ['filter' => ['permission:resignation.view_self,resignation.view,resignation.manage', 'idempotency:required']]);

        // â”€â”€â”€ Offboarding compliance (Sprint 1) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Asset clearance, BPJS deactivate, paklaring, dan final settlement
        // tracking. Lihat docs/LIFECYCLE_AUDIT_2026.md item OFF-2/3/4/6 +
        // HIRE-4 (BPJS SLA scanner di /offboarding/bpjs-overdue).
        $routes->get('resignations/(:num)/offboarding',                                 'Lifecycle\OffboardingController::status/$1', ['filter' => 'permission:offboarding.clearance.view']);
        $routes->post('resignations/(:num)/offboarding/asset-clearance',                'Lifecycle\OffboardingController::setAssetClearance/$1', ['filter' => 'permission:offboarding.clearance.manage']);
        $routes->post('resignations/(:num)/offboarding/bpjs-deactivated',               'Lifecycle\OffboardingController::setBpjsDeactivated/$1', ['filter' => 'permission:offboarding.clearance.manage']);
        $routes->post('resignations/(:num)/offboarding/paklaring',                      'Lifecycle\OffboardingController::generatePaklaring/$1', ['filter' => ['permission:paklaring.generate', 'idempotency:required']]);
        $routes->get('resignations/(:num)/offboarding/paklaring/(:num)',                'Lifecycle\OffboardingController::downloadPaklaring/$1/$2', ['filter' => 'permission:offboarding.clearance.view']);
        $routes->post('resignations/(:num)/offboarding/final-settlement',               'Lifecycle\OffboardingController::linkFinalSettlement/$1', ['filter' => 'permission:offboarding.clearance.manage']);
        $routes->post('resignations/(:num)/offboarding/final-settlement/paid',          'Lifecycle\OffboardingController::markFinalSettlementPaid/$1', ['filter' => ['permission:offboarding.clearance.manage', 'idempotency:required']]);
        $routes->get('offboarding/bpjs-overdue',                                        'Lifecycle\OffboardingController::bpjsOverdue', ['filter' => 'permission:offboarding.clearance.view']);

        // â”€â”€â”€ Job Information Change (Promosi/Demosi/Mutasi) â”€â”€â”€â”€â”€â”€â”€â”€
        // Workflow: Supervisor â†’ Dept Head â†’ HR â†’ BOD (kondisional) â†’
        // Employee Consent (kondisional, hanya DEMOTION non-disipliner) â†’
        // APPROVED â†’ IMPLEMENTED. Lihat docs/JIC.md.
        // Endpoint khusus harus didefinisikan SEBELUM /jic/(:num)
        // supaya path /jic/options tidak nyangkut sebagai id.
        $routes->get('jic/options',                  'Employee\JicController::options', ['filter' => 'permission:jic.view']);
        $routes->get('jic',                          'Employee\JicController::index', ['filter' => 'permission:jic.view']);
        $routes->post('jic',                         'Employee\JicController::create', ['filter' => ['permission:jic.create', 'idempotency:required']]);
        $routes->get('jic/(:num)',                   'Employee\JicController::show/$1', ['filter' => 'permission:jic.view']);
        $routes->put('jic/(:num)',                   'Employee\JicController::update/$1', ['filter' => 'permission:jic.create,jic.manage']);
        $routes->delete('jic/(:num)',                'Employee\JicController::delete/$1', ['filter' => 'permission:jic.manage']);
        $routes->post('jic/(:num)/submit',           'Employee\JicController::submit/$1', ['filter' => ['permission:jic.create,jic.manage', 'idempotency:required']]);
        $routes->post('jic/(:num)/approve',          'Employee\JicController::approve/$1', ['filter' => ['permission:jic.approve', 'idempotency:required']]);
        $routes->post('jic/(:num)/reject',           'Employee\JicController::reject/$1', ['filter' => ['permission:jic.approve', 'idempotency:required']]);
        $routes->post('jic/(:num)/consent',          'Employee\JicController::consent/$1', ['filter' => ['permission:jic.view', 'idempotency:required']]);
        $routes->post('jic/(:num)/cancel',           'Employee\JicController::cancel/$1', ['filter' => ['permission:jic.create,jic.manage', 'idempotency:required']]);
        $routes->post('jic/(:num)/implement',        'Employee\JicController::implement/$1', ['filter' => ['permission:jic.implement', 'idempotency:required']]);

        // Employee Transfer (Mutasi) â€” pemindahan karyawan antar
        // branch/department/posisi/work_location dengan workflow ringan
        // 1-tier HR approval. Beda dengan JIC: tidak menyentuh grade/upah.
        // Lihat docs/EMPLOYEE_TRANSFER.md.
        // Static routes harus didefinisikan SEBELUM /(:num) supaya
        // path /options tidak nyangkut sebagai id.
        $routes->get('employee-transfers/options',           'Employee\EmployeeTransferController::options', ['filter' => 'permission:employee_transfer.view']);
        // Endpoint khusus karyawan: lihat mutasi yang menyangkut diri sendiri
        // (sebagai subjek). Tidak butuh permission view â€” auto scope ke
        // tenant.employee_id. Sama pola dengan /leaves/my, /payslips/my, dst.
        $routes->get('employee-transfers/my',                'Employee\EmployeeTransferController::my', ['filter' => 'permission:employee_transfer.view_self,employee_transfer.view']);
        $routes->get('employee-transfers',                   'Employee\EmployeeTransferController::index', ['filter' => 'permission:employee_transfer.view']);
        $routes->post('employee-transfers',                  'Employee\EmployeeTransferController::create', ['filter' => ['permission:employee_transfer.create', 'idempotency:required']]);
        $routes->get('employee-transfers/(:num)',            'Employee\EmployeeTransferController::show/$1', ['filter' => 'permission:employee_transfer.view_self,employee_transfer.view']);
        $routes->put('employee-transfers/(:num)',            'Employee\EmployeeTransferController::update/$1', ['filter' => 'permission:employee_transfer.create']);
        $routes->delete('employee-transfers/(:num)',         'Employee\EmployeeTransferController::delete/$1', ['filter' => 'permission:employee_transfer.create']);
        $routes->post('employee-transfers/(:num)/submit',    'Employee\EmployeeTransferController::submit/$1', ['filter' => ['permission:employee_transfer.create', 'idempotency:required']]);
        $routes->post('employee-transfers/(:num)/approve',   'Employee\EmployeeTransferController::approve/$1', ['filter' => ['permission:employee_transfer.approve', 'idempotency:required']]);
        $routes->post('employee-transfers/(:num)/reject',    'Employee\EmployeeTransferController::reject/$1', ['filter' => ['permission:employee_transfer.approve', 'idempotency:required']]);
        $routes->post('employee-transfers/(:num)/cancel',    'Employee\EmployeeTransferController::cancel/$1', ['filter' => ['permission:employee_transfer.create', 'idempotency:required']]);
        $routes->post('employee-transfers/(:num)/implement', 'Employee\EmployeeTransferController::implement/$1', ['filter' => ['permission:employee_transfer.implement', 'idempotency:required']]);
        $routes->post('employee-transfers/(:num)/consent',   'Employee\EmployeeTransferController::consent/$1', ['filter' => ['permission:employee_transfer.view_self,employee_transfer.view', 'idempotency:required']]);

        // â”€â”€â”€ Generated Contracts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $routes->get('generated-contracts',                  'Lifecycle\GeneratedContractController::index', ['filter' => 'permission:contract.generate']);
        $routes->get('generated-contracts/(:num)',           'Lifecycle\GeneratedContractController::show/$1', ['filter' => 'permission:contract.generate']);
        $routes->get('generated-contracts/(:num)/html',      'Lifecycle\GeneratedContractController::html/$1', ['filter' => 'permission:contract.generate']);
        $routes->put('generated-contracts/(:num)/status',    'Lifecycle\GeneratedContractController::updateStatus/$1', ['filter' => ['permission:contract.generate', 'idempotency:required']]);
        $routes->delete('generated-contracts/(:num)',        'Lifecycle\GeneratedContractController::delete/$1', ['filter' => 'permission:contract.generate']);
        // Tanda tangan digital kontrak oleh karyawan
        $routes->post('generated-contracts/(:num)/sign',     'Lifecycle\GeneratedContractController::sign/$1', ['filter' => ['ownership:employee', 'idempotency:required']]);
        // Daftar kontrak yang menunggu tanda tangan untuk employee login
        $routes->get('employee/pending-contracts',           'Lifecycle\GeneratedContractController::pendingForEmployee', ['filter' => 'ownership:employee']);

        // â”€â”€â”€ Termination / PHK Formal Workflow (Sprint 4 / OFF-5) â”€â”€
        // Workflow: DRAFT â†’ PENDING_BIPARTITE â†’ PENDING_HR_DIRECTOR
        //   â†’ PENDING_BOD â†’ APPROVED â†’ EXECUTED. Lihat
        //   docs/SPRINT_4_TERMINATION_POLISH.md.
        // Endpoint khusus didefinisikan SEBELUM /(:num) supaya path
        // /options tidak nyangkut sebagai resource id.
        $routes->get('terminations/options',                 'Lifecycle\TerminationController::options', ['filter' => 'permission:termination.view']);
        $routes->get('terminations',                         'Lifecycle\TerminationController::index', ['filter' => 'permission:termination.view']);
        $routes->post('terminations',                        'Lifecycle\TerminationController::create', ['filter' => ['permission:termination.manage', 'idempotency:required']]);
        $routes->get('terminations/(:num)',                  'Lifecycle\TerminationController::show/$1', ['filter' => 'permission:termination.view']);
        $routes->put('terminations/(:num)',                  'Lifecycle\TerminationController::update/$1', ['filter' => 'permission:termination.manage']);
        $routes->delete('terminations/(:num)',               'Lifecycle\TerminationController::delete/$1', ['filter' => 'permission:termination.manage']);
        $routes->post('terminations/(:num)/submit',          'Lifecycle\TerminationController::submit/$1', ['filter' => ['permission:termination.manage', 'idempotency:required']]);
        $routes->post('terminations/(:num)/bipartite',       'Lifecycle\TerminationController::bipartite/$1', ['filter' => ['permission:termination.manage', 'idempotency:required']]);
        $routes->post('terminations/(:num)/approve',         'Lifecycle\TerminationController::approve/$1', ['filter' => ['permission:termination.approve', 'idempotency:required']]);
        $routes->post('terminations/(:num)/reject',          'Lifecycle\TerminationController::reject/$1', ['filter' => ['permission:termination.approve', 'idempotency:required']]);
        $routes->post('terminations/(:num)/cancel',          'Lifecycle\TerminationController::cancel/$1', ['filter' => ['permission:termination.manage', 'idempotency:required']]);
        $routes->post('terminations/(:num)/execute',         'Lifecycle\TerminationController::execute/$1', ['filter' => ['permission:termination.execute', 'idempotency:required']]);
        $routes->get('terminations/(:num)/letter',           'Lifecycle\TerminationController::letter/$1', ['filter' => 'permission:termination.view']);

        // â”€â”€â”€ Exit Interview (Sprint 4 / OFF-1) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Wawancara terstruktur saat resign / PHK.
        // Endpoint khusus didefinisikan SEBELUM /(:num).
        $routes->get('exit-interviews/options',              'Lifecycle\ExitInterviewController::options', ['filter' => 'permission:exit_interview.view']);
        $routes->get('exit-interviews/analytics',            'Lifecycle\ExitInterviewController::analytics', ['filter' => 'permission:exit_interview.view']);
        $routes->get('exit-interviews',                      'Lifecycle\ExitInterviewController::index', ['filter' => 'permission:exit_interview.view']);
        $routes->post('exit-interviews',                     'Lifecycle\ExitInterviewController::create', ['filter' => 'permission:exit_interview.manage']);
        $routes->get('exit-interviews/(:num)',               'Lifecycle\ExitInterviewController::show/$1', ['filter' => 'permission:exit_interview.view']);
        $routes->put('exit-interviews/(:num)',               'Lifecycle\ExitInterviewController::update/$1', ['filter' => 'permission:exit_interview.manage']);
        $routes->delete('exit-interviews/(:num)',            'Lifecycle\ExitInterviewController::delete/$1', ['filter' => 'permission:exit_interview.manage']);
        $routes->post('exit-interviews/(:num)/complete',     'Lifecycle\ExitInterviewController::complete/$1', ['filter' => ['permission:exit_interview.manage', 'idempotency:required']]);
        $routes->post('exit-interviews/(:num)/skip',         'Lifecycle\ExitInterviewController::skip/$1', ['filter' => ['permission:exit_interview.manage', 'idempotency:required']]);

        // â”€â”€â”€ Employee 360 View (Sprint 4 / CC-4) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Aggregator endpoint â€” 12 section per karyawan.
        $routes->get('employees/(:num)/360',                 'Employee\Employee360Controller::show/$1', ['filter' => 'permission:employee.view']);

        // â”€â”€â”€ Compliance Reports (Sprint 4 / CC-5) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // WLKP, BPJS, PKWT, PHK list, audit summary, turnover.
        // Throttle export berat (sama bucket dengan export lain).
        $routes->get('compliance-reports/types',             'Compliance\ComplianceReportController::types', ['filter' => 'permission:compliance.report.view']);
        $routes->get('compliance-reports/runs',              'Compliance\ComplianceReportController::runs', ['filter' => 'permission:compliance.report.view']);
        $routes->post('compliance-reports/generate',         'Compliance\ComplianceReportController::generate', ['filter' => ['permission:compliance.report.export', 'idempotency:required', 'privilegedapproval:PII_EXPORT']]);
        $routes->get('compliance-reports/runs/(:num)',       'Compliance\ComplianceReportController::showRun/$1', ['filter' => 'permission:compliance.report.view']);
        $routes->get('compliance-reports/runs/(:num)/csv',   'Compliance\ComplianceReportController::exportCsv/$1',
            ['filter' => ['permission:compliance.report.export', 'privilegedapproval:PII_EXPORT', 'throttle:export']]);
    }
}
