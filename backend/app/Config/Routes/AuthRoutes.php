<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class AuthRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // Auth (authenticated)
        $routes->post('auth/logout', 'Auth\AuthController::logout', ['filter' => 'ownership:account']);
        $routes->get('auth/me', 'Auth\AuthController::me', ['filter' => 'ownership:account']);
        $routes->post('auth/change-password', 'Auth\AuthController::changePassword', ['filter' => 'ownership:account']);
        $routes->post('auth/2fa/enable', 'Auth\AuthController::enableTwoFactor', ['filter' => 'ownership:account']);
        $routes->post('auth/2fa/disable', 'Auth\AuthController::disableTwoFactor', ['filter' => 'ownership:account']);
        // Sprint A / B5 — Active Sessions (device list + revoke per device)
        $routes->get('auth/sessions',                       'Auth\AuthController::listSessions', ['filter' => 'ownership:account']);
        $routes->post('auth/sessions/revoke-others',        'Auth\AuthController::revokeOtherSessions', ['filter' => 'ownership:account']);
        $routes->delete('auth/sessions/(:segment)',         'Auth\AuthController::revokeSession/$1', ['filter' => 'ownership:account']);

        // ─── Mobile Prep / MOB-1 — Device push token registry ──────
        // Mobile app pair FCM/APNs token ke user untuk push notif fanout.
        // Lihat docs/MOBILE_APP_PLAN.md.
        $routes->post('devices/register',          'Notification\DeviceController::register', ['filter' => 'ownership:account']);
        $routes->post('devices/unregister',        'Notification\DeviceController::unregisterByToken', ['filter' => 'ownership:account']);
        $routes->get('devices',                    'Notification\DeviceController::index', ['filter' => 'ownership:account']);
        $routes->delete('devices/(:num)',          'Notification\DeviceController::unregister/$1', ['filter' => 'ownership:account']);
        // Foto profil — self-service upload/hapus, JPEG/PNG/WebP ≤ 2 MB.
        // Sprint A / B3 — Origin guard untuk multipart upload.
        $routes->post('auth/me/avatar',   'Auth\AuthController::uploadAvatar', ['filter' => ['ownership:account', 'sameorigin']]);
        $routes->delete('auth/me/avatar', 'Auth\AuthController::deleteAvatar', ['filter' => 'ownership:account']);
    }
}
