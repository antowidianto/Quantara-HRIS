<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class RecruitmentRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // ─── Recruitment / ATS ─────────────────────────────────────
        $routes->get('recruitment/pipeline-stats',  'Recruitment\RecruitmentController::pipelineStats', ['filter' => 'permission:recruitment.view']);
        // Sprint 9 / H1.4 — Time-in-Stage tracking
        $routes->get('recruitment/funnel-metrics',   'Recruitment\RecruitmentController::funnelMetrics', ['filter' => 'permission:recruitment.view']);
        $routes->get('recruitment/stale-candidates', 'Recruitment\RecruitmentController::staleCandidates', ['filter' => 'permission:recruitment.view']);
        $routes->get('job-postings',                'Recruitment\RecruitmentController::indexJobs', ['filter' => 'permission:recruitment.view,referral.create_self']);
        $routes->post('job-postings',               'Recruitment\RecruitmentController::createJob', ['filter' => 'permission:recruitment.manage']);
        $routes->get('job-postings/(:num)',         'Recruitment\RecruitmentController::showJob/$1', ['filter' => 'permission:recruitment.view']);
        $routes->put('job-postings/(:num)',         'Recruitment\RecruitmentController::updateJob/$1', ['filter' => 'permission:recruitment.manage']);
        $routes->delete('job-postings/(:num)',      'Recruitment\RecruitmentController::deleteJob/$1', ['filter' => 'permission:recruitment.manage']);
        $routes->post('job-postings/(:num)/open',   'Recruitment\RecruitmentController::openJob/$1', ['filter' => ['permission:recruitment.manage', 'idempotency:required']]);
        $routes->post('job-postings/(:num)/close',  'Recruitment\RecruitmentController::closeJob/$1', ['filter' => ['permission:recruitment.manage', 'idempotency:required']]);
        // Career portal — publish/unpublish posting + settings
        $routes->post('job-postings/(:num)/publish',   'Recruitment\CareerController::publishJob/$1', ['filter' => ['permission:recruitment.manage', 'idempotency:required']]);
        $routes->post('job-postings/(:num)/unpublish', 'Recruitment\CareerController::unpublishJob/$1', ['filter' => ['permission:recruitment.manage', 'idempotency:required']]);
        $routes->get('career-page',                       'Recruitment\CareerController::show', ['filter' => 'permission:recruitment.view']);
        $routes->put('career-page',                       'Recruitment\CareerController::update', ['filter' => 'permission:recruitment.manage']);
        $routes->post('career-page/regenerate-slug',      'Recruitment\CareerController::regenerateSlug', ['filter' => 'permission:recruitment.manage']);
        $routes->get('career-applicants',                 'Recruitment\CareerController::applicants', ['filter' => 'permission:recruitment.view']);
        $routes->get('candidates',                          'Recruitment\RecruitmentController::indexCandidates', ['filter' => 'permission:recruitment.view']);
        $routes->post('candidates',                         'Recruitment\RecruitmentController::createCandidate', ['filter' => 'permission:recruitment.manage']);
        $routes->get('candidates/(:num)',                   'Recruitment\RecruitmentController::showCandidate/$1', ['filter' => 'permission:recruitment.view']);
        $routes->put('candidates/(:num)',                   'Recruitment\RecruitmentController::updateCandidate/$1', ['filter' => 'permission:recruitment.manage']);
        $routes->delete('candidates/(:num)',                'Recruitment\RecruitmentController::deleteCandidate/$1', ['filter' => 'permission:recruitment.manage']);
        $routes->post('candidates/(:num)/move-stage',       'Recruitment\RecruitmentController::moveStage/$1', ['filter' => ['permission:recruitment.manage', 'idempotency:required']]);
        $routes->post('candidates/(:num)/reject',           'Recruitment\RecruitmentController::rejectCandidate/$1', ['filter' => ['permission:recruitment.manage', 'idempotency:required']]);
        $routes->post('candidates/(:num)/hire',             'Recruitment\RecruitmentController::hireCandidate/$1', ['filter' => ['permission:recruitment.hire', 'idempotency:required']]);

        // ─── Candidate Offers (Sprint 2 / REC-1) ───────────────────
        // Letter-of-offer untuk kandidat di stage OFFER. Workflow:
        //   DRAFT → SENT → ACCEPTED/REJECTED/EXPIRED/WITHDRAWN.
        $routes->get('candidates/(:num)/offers',            'Recruitment\OfferController::listForCandidate/$1', ['filter' => 'permission:recruitment.offer.view']);
        $routes->post('candidates/(:num)/offers',           'Recruitment\OfferController::createForCandidate/$1', ['filter' => ['permission:recruitment.offer.manage', 'idempotency:required']]);
        $routes->get('offers',                              'Recruitment\OfferController::index', ['filter' => 'permission:recruitment.offer.view']);
        $routes->get('offers/(:num)',                       'Recruitment\OfferController::show/$1', ['filter' => 'permission:recruitment.offer.view']);
        $routes->put('offers/(:num)',                       'Recruitment\OfferController::update/$1', ['filter' => 'permission:recruitment.offer.manage']);
        $routes->delete('offers/(:num)',                    'Recruitment\OfferController::delete/$1', ['filter' => 'permission:recruitment.offer.manage']);
        $routes->post('offers/(:num)/send',                 'Recruitment\OfferController::send/$1', ['filter' => ['permission:recruitment.offer.manage', 'idempotency:required']]);
        $routes->post('offers/(:num)/respond',              'Recruitment\OfferController::respond/$1',
            ['filter' => ['permission:recruitment.offer.respond', 'idempotency:required']]);
        $routes->post('offers/(:num)/withdraw',             'Recruitment\OfferController::withdraw/$1', ['filter' => ['permission:recruitment.offer.manage', 'idempotency:required']]);

        // ─── Probation (Sprint 2 / HIRE-1) ─────────────────────────
        // PKWTT auto-tracking H+90 dengan reminder & decision flow.
        $routes->get('probation/expiring',                  'Employee\ProbationController::expiring', ['filter' => 'permission:probation.manage']);
        $routes->get('probation/expired',                   'Employee\ProbationController::expired', ['filter' => 'permission:probation.manage']);
        $routes->post('employees/(:num)/probation/decide',  'Employee\ProbationController::decide/$1', ['filter' => ['permission:probation.manage', 'idempotency:required']]);
    }
}
