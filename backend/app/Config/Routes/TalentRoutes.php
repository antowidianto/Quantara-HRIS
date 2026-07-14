<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class TalentRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // ─── Fase 4: Talent Management ──────────────────────────

        // 360-Degree Feedback Review
        $routes->get('review-360/cycles',                       'HR\Review360Controller::cycles', ['filter' => 'permission:performance.view']);
        $routes->get('review-360/cycles/(:num)',                'HR\Review360Controller::showCycle/$1', ['filter' => 'permission:performance.view']);
        $routes->post('review-360/cycles',                     'HR\Review360Controller::createCycle', ['filter' => 'permission:performance.manage']);
        $routes->put('review-360/cycles/(:num)',               'HR\Review360Controller::updateCycle/$1', ['filter' => 'permission:performance.manage']);
        $routes->post('review-360/nominations',                'HR\Review360Controller::addNomination', ['filter' => 'permission:performance.nominate_peer']);
        $routes->delete('review-360/nominations/(:num)',       'HR\Review360Controller::removeNomination/$1', ['filter' => 'permission:performance.nominate_peer']);
        $routes->post('review-360/nominations/(:num)/feedback','HR\Review360Controller::submitFeedback/$1', ['filter' => ['permission:review.conduct', 'idempotency:required']]);
        $routes->get('review-360/cycles/(:num)/aggregate',     'HR\Review360Controller::aggregateScores/$1', ['filter' => 'permission:review.view_team']);

        // Nine-Box Matrix (Talent Assessment)
        $routes->get('talent-assessments',                     'HR\TalentAssessmentController::index', ['filter' => 'permission:talent_pool.view,talent_pool.manage']);
        $routes->post('talent-assessments',                    'HR\TalentAssessmentController::upsert', ['filter' => ['permission:talent_pool.manage', 'idempotency:required']]);
        $routes->delete('talent-assessments/(:num)',           'HR\TalentAssessmentController::delete/$1', ['filter' => 'permission:talent_pool.manage']);

        // Succession Planning
        $routes->get('succession/positions',                   'HR\SuccessionPlanController::positions', ['filter' => 'permission:succession.view']);
        $routes->get('succession/positions/(:num)',            'HR\SuccessionPlanController::showPosition/$1', ['filter' => 'permission:succession.view']);
        $routes->post('succession/positions',                  'HR\SuccessionPlanController::createPosition', ['filter' => 'permission:succession.manage']);
        $routes->put('succession/positions/(:num)',            'HR\SuccessionPlanController::updatePosition/$1', ['filter' => 'permission:succession.manage']);
        $routes->delete('succession/positions/(:num)',         'HR\SuccessionPlanController::deletePosition/$1', ['filter' => 'permission:succession.manage']);
        $routes->post('succession/candidates',                'HR\SuccessionPlanController::addCandidate', ['filter' => 'permission:succession.manage']);
        $routes->put('succession/candidates/(:num)',          'HR\SuccessionPlanController::updateCandidate/$1', ['filter' => 'permission:succession.manage']);
        $routes->delete('succession/candidates/(:num)',       'HR\SuccessionPlanController::removeCandidate/$1', ['filter' => 'permission:succession.manage']);
    }
}
