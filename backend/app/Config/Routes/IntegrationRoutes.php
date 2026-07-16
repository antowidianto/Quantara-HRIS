<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class IntegrationRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // Sprint 11+ — Webhook outbound (admin-managed integrations).
        // Endpoint khusus didefinisikan SEBELUM /webhooks/(:num) supaya
        // path /event-types tidak nyangkut sebagai resource id.
        $routes->get('webhooks/event-types',                 'Webhook\WebhookController::eventTypes', ['filter' => 'permission:webhook.view']);
        $routes->get('webhooks',                             'Webhook\WebhookController::index', ['filter' => 'permission:webhook.view']);
        $routes->post('webhooks',                            'Webhook\WebhookController::create', ['filter' => ['permission:webhook.manage', 'idempotency:required', 'privilegedapproval:INTEGRATION']]);
        $routes->get('webhooks/(:num)',                      'Webhook\WebhookController::show/$1', ['filter' => 'permission:webhook.view']);
        $routes->put('webhooks/(:num)',                      'Webhook\WebhookController::update/$1', ['filter' => ['permission:webhook.manage', 'idempotency:required', 'privilegedapproval:INTEGRATION']]);
        $routes->delete('webhooks/(:num)',                   'Webhook\WebhookController::delete/$1', ['filter' => ['permission:webhook.manage', 'idempotency:required', 'privilegedapproval:INTEGRATION']]);
        $routes->post('webhooks/(:num)/rotate-secret',       'Webhook\WebhookController::rotateSecret/$1', ['filter' => ['permission:webhook.manage', 'idempotency:required', 'privilegedapproval:INTEGRATION']]);
        $routes->get('webhooks/(:num)/deliveries',           'Webhook\WebhookController::deliveries/$1', ['filter' => 'permission:webhook.view']);

        // Sprint 14+ — Inbound Webhook admin CRUD (counterpart untuk
        // outbound /webhooks). Receiver-nya public di atas (lihat
        // /api/v1/inbound/{slug}). Pattern routing sama: endpoint khusus
        // (events) di atas pattern /(:num).
        $routes->get('inbound-webhooks',                     'Webhook\InboundWebhookController::index', ['filter' => 'permission:webhook.view']);
        $routes->post('inbound-webhooks',                    'Webhook\InboundWebhookController::create', ['filter' => ['permission:webhook.manage', 'idempotency:required', 'privilegedapproval:INTEGRATION']]);
        $routes->get('inbound-webhooks/(:num)',              'Webhook\InboundWebhookController::show/$1', ['filter' => 'permission:webhook.view']);
        $routes->put('inbound-webhooks/(:num)',              'Webhook\InboundWebhookController::update/$1', ['filter' => ['permission:webhook.manage', 'idempotency:required', 'privilegedapproval:INTEGRATION']]);
        $routes->delete('inbound-webhooks/(:num)',           'Webhook\InboundWebhookController::delete/$1', ['filter' => ['permission:webhook.manage', 'idempotency:required', 'privilegedapproval:INTEGRATION']]);
        $routes->post('inbound-webhooks/(:num)/rotate-secret','Webhook\InboundWebhookController::rotateSecret/$1', ['filter' => ['permission:webhook.manage', 'idempotency:required', 'privilegedapproval:INTEGRATION']]);
        $routes->get('inbound-webhooks/(:num)/events',       'Webhook\InboundWebhookController::events/$1', ['filter' => 'permission:webhook.view']);
    }
}
