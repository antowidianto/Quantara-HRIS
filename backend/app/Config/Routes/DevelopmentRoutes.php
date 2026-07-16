<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class DevelopmentRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // Compensation review cycle: read access for HR/payroll, mutations for employee managers.
        $routes->get('compensation-reviews', 'Compensation\CompensationReviewController::index', ['filter' => 'permission:employee.view,payroll.view']);
        $routes->get('compensation-reviews/(:num)', 'Compensation\CompensationReviewController::show/$1', ['filter' => 'permission:employee.view,payroll.view']);
        $routes->post('compensation-reviews', 'Compensation\CompensationReviewController::create', ['filter' => ['permission:employee.manage', 'idempotency:required']]);
        $routes->put('compensation-reviews/(:num)/budgets', 'Compensation\CompensationReviewController::budgets/$1', ['filter' => 'permission:employee.manage']);
        $routes->post('compensation-reviews/(:num)/open', 'Compensation\CompensationReviewController::open/$1', ['filter' => ['permission:employee.manage', 'idempotency:required']]);
        $routes->post('compensation-reviews/(:num)/proposals', 'Compensation\CompensationReviewController::propose/$1', ['filter' => ['permission:employee.manage', 'idempotency:required']]);
        $routes->get('compensation-proposals/(:num)/letter', 'Compensation\CompensationReviewController::letter/$1', ['filter' => 'permission:employee.view,payroll.view']);

        // â”€â”€â”€ Individual Development Plan (Sprint 3 / OD-2) â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Closing-the-loop performance review â†’ development plan.
        // Endpoint khusus didefinisikan SEBELUM /(:num) supaya path
        // /idp/my dan /idp/from-review tidak nyangkut sebagai resource id.
        $routes->get('idp/my',                              'Performance\IdpController::my', ['filter' => ['permission:idp.view_own', 'ownership:employee']]);
        $routes->post('idp/from-review/(:num)',             'Performance\IdpController::createFromReview/$1', ['filter' => ['permission:idp.manage', 'idempotency:required']]);
        $routes->get('idp',                                 'Performance\IdpController::index', ['filter' => 'permission:idp.view,idp.manage']);
        $routes->post('idp',                                'Performance\IdpController::create', ['filter' => ['permission:idp.manage', 'idempotency:required']]);
        $routes->get('idp/(:num)',                          'Performance\IdpController::show/$1', ['filter' => 'permission:idp.view_own,idp.view,idp.manage']);
        $routes->put('idp/(:num)',                          'Performance\IdpController::update/$1', ['filter' => 'permission:idp.view_own,idp.manage']);
        $routes->delete('idp/(:num)',                       'Performance\IdpController::delete/$1', ['filter' => 'permission:idp.manage']);
        $routes->post('idp/(:num)/finalize',                'Performance\IdpController::finalize/$1', ['filter' => ['permission:idp.manage', 'idempotency:required']]);
        $routes->post('idp/(:num)/complete',                'Performance\IdpController::complete/$1', ['filter' => ['permission:idp.manage', 'idempotency:required']]);
        $routes->post('idp/(:num)/cancel',                  'Performance\IdpController::cancel/$1', ['filter' => ['permission:idp.manage', 'idempotency:required']]);

        // â”€â”€â”€ Online Tests (HR admin) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Lihat docs/ONLINE_TEST.md. Kandidat-side endpoint terpisah
        // (filter: candidatejwt) â€” yang ini full-feature untuk HR.

        // Master kategori test (Migration #065) â€” built-in 14 kategori
        // populer di HR Indonesia (TPA, Pauli, Kraepelin, DISC, MBTI,
        // Wartegg, Holland, dll). HR boleh tambah kategori custom.
        // Endpoint khusus didefinisikan SEBELUM (:num).
        $routes->get('online-test-categories/picker',         'Recruitment\OnlineTestCategoryController::picker', ['filter' => 'permission:online_test_category.manage']);
        $routes->get('online-test-categories',                'Recruitment\OnlineTestCategoryController::index', ['filter' => 'permission:online_test_category.manage']);
        $routes->post('online-test-categories',               'Recruitment\OnlineTestCategoryController::create', ['filter' => 'permission:online_test_category.manage']);
        $routes->get('online-test-categories/(:num)',         'Recruitment\OnlineTestCategoryController::show/$1', ['filter' => 'permission:online_test_category.manage']);
        $routes->put('online-test-categories/(:num)',         'Recruitment\OnlineTestCategoryController::update/$1', ['filter' => 'permission:online_test_category.manage']);
        $routes->delete('online-test-categories/(:num)',      'Recruitment\OnlineTestCategoryController::delete/$1', ['filter' => 'permission:online_test_category.manage']);

        // Master pipeline rekrutmen â€” HR bisa custom urutan stages
        // (mis. ONLINE_TEST sebelum/sesudah HR Interview, FGD, Psikotest, dst).
        // Lihat docs/RECRUITMENT_PIPELINE.md.
        $routes->get('recruitment-pipelines/picker',          'Recruitment\RecruitmentPipelineController::picker', ['filter' => 'permission:recruitment_pipeline.view']);
        $routes->get('recruitment-pipelines',                 'Recruitment\RecruitmentPipelineController::index', ['filter' => 'permission:recruitment_pipeline.view']);
        $routes->post('recruitment-pipelines',                'Recruitment\RecruitmentPipelineController::create', ['filter' => 'permission:recruitment_pipeline.manage']);
        $routes->get('recruitment-pipelines/(:num)',          'Recruitment\RecruitmentPipelineController::show/$1', ['filter' => 'permission:recruitment_pipeline.view']);
        $routes->put('recruitment-pipelines/(:num)',          'Recruitment\RecruitmentPipelineController::update/$1', ['filter' => 'permission:recruitment_pipeline.manage']);
        $routes->delete('recruitment-pipelines/(:num)',       'Recruitment\RecruitmentPipelineController::delete/$1', ['filter' => 'permission:recruitment_pipeline.manage']);
        $routes->post('recruitment-pipelines/(:num)/duplicate','Recruitment\RecruitmentPipelineController::duplicate/$1', ['filter' => 'permission:recruitment_pipeline.manage']);

        // Endpoint khusus didefinisikan SEBELUM (:num) supaya path
        // /categories tidak nyangkut sebagai resource id.
        $routes->get('online-test-questions-bank/template',   'Recruitment\OnlineTestController::bankTemplate', ['filter' => 'permission:online_tests.view']);
        $routes->post('online-test-questions-bank/import',    'Recruitment\OnlineTestController::importBankTemplate', ['filter' => 'permission:online_tests.manage']);
        $routes->get('online-test-questions-bank/categories', 'Recruitment\OnlineTestController::bankCategories', ['filter' => 'permission:online_tests.view']);
        $routes->get('online-test-questions-bank',            'Recruitment\OnlineTestController::listBank', ['filter' => 'permission:online_tests.view']);
        $routes->post('online-test-questions-bank',           'Recruitment\OnlineTestController::createBank', ['filter' => 'permission:online_tests.manage']);
        $routes->put('online-test-questions-bank/(:num)',     'Recruitment\OnlineTestController::updateBank/$1', ['filter' => 'permission:online_tests.manage']);
        $routes->delete('online-test-questions-bank/(:num)',  'Recruitment\OnlineTestController::deleteBank/$1', ['filter' => 'permission:online_tests.manage']);

        $routes->get('online-tests',                          'Recruitment\OnlineTestController::listTests', ['filter' => 'permission:online_tests.view']);
        $routes->post('online-tests',                         'Recruitment\OnlineTestController::createTest', ['filter' => 'permission:online_tests.manage']);
        $routes->get('online-tests/(:num)',                   'Recruitment\OnlineTestController::showTest/$1', ['filter' => 'permission:online_tests.view']);
        $routes->put('online-tests/(:num)',                   'Recruitment\OnlineTestController::updateTest/$1', ['filter' => 'permission:online_tests.manage']);
        $routes->delete('online-tests/(:num)',                'Recruitment\OnlineTestController::deleteTest/$1', ['filter' => 'permission:online_tests.manage']);
        $routes->post('online-tests/(:num)/seed-template',    'Recruitment\OnlineTestController::seedTemplate/$1', ['filter' => 'permission:online_tests.manage']);
        $routes->get('online-tests/(:num)/questions',         'Recruitment\OnlineTestController::listQuestions/$1', ['filter' => 'permission:online_tests.view']);
        $routes->post('online-tests/(:num)/questions',        'Recruitment\OnlineTestController::createQuestion/$1', ['filter' => 'permission:online_tests.manage']);
        $routes->post('online-tests/(:num)/import-from-bank', 'Recruitment\OnlineTestController::importFromBank/$1', ['filter' => 'permission:online_tests.manage']);

        $routes->put('online-test-questions/(:num)',          'Recruitment\OnlineTestController::updateQuestion/$1', ['filter' => 'permission:online_tests.manage']);
        $routes->delete('online-test-questions/(:num)',       'Recruitment\OnlineTestController::deleteQuestion/$1', ['filter' => 'permission:online_tests.manage']);

        $routes->get('online-test-assignments',               'Recruitment\OnlineTestController::listAssignments', ['filter' => 'permission:online_tests.view']);
        $routes->post('online-test-assignments',              'Recruitment\OnlineTestController::createAssignment', ['filter' => 'permission:online_tests.manage']);
        $routes->get('online-test-assignments/(:num)',        'Recruitment\OnlineTestController::showAssignment/$1', ['filter' => 'permission:online_tests.view']);
        $routes->put('online-test-assignments/(:num)',        'Recruitment\OnlineTestController::updateAssignment/$1', ['filter' => 'permission:online_tests.manage']);
        $routes->delete('online-test-assignments/(:num)',     'Recruitment\OnlineTestController::deleteAssignment/$1', ['filter' => 'permission:online_tests.manage']);
        $routes->post('online-test-assignments/(:num)/cancel','Recruitment\OnlineTestController::cancelAssignment/$1', ['filter' => ['permission:online_tests.manage', 'idempotency:required']]);

        $routes->get('online-test-attempts/(:num)',           'Recruitment\OnlineTestController::showAttempt/$1', ['filter' => 'permission:online_tests.view']);
        $routes->post('online-test-attempts/(:num)/grade',    'Recruitment\OnlineTestController::gradeAnswer/$1', ['filter' => ['permission:online_tests.grade', 'idempotency:required']]);

    }
}
