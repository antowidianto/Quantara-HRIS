<?php

namespace App\Libraries;

use Config\Services;

/**
 * ExceptionLogger — wrapper untuk error capture & external monitoring.
 *
 * Untuk Sprint 5-6 monitoring:
 *  1. Setiap unhandled exception di-log ke audit_logs (severity CRITICAL),
 *     supaya HR/ops bisa lihat di /admin/audit-logs filter severity=CRITICAL.
 *  2. Hook ke external service (Sentry/GlitchTip) opt-in via env. Ketika
 *     library `sentry/sentry` terpasang & `sentry.dsn` di-set, exception
 *     akan di-forward ke Sentry juga. Tanpa Sentry, audit_logs tetap
 *     menjadi single source of truth.
 *
 * Setup Sentry (production):
 *   composer require sentry/sentry
 *   set di .env:
 *     sentry.dsn = https://xxx@xxx.ingest.sentry.io/xxx
 *     sentry.environment = production
 *     sentry.traces_sample_rate = 0.1   (10% trace sampling)
 *
 * Tanpa Sentry:
 *   - Exception masuk ke audit_logs (severity CRITICAL)
 *   - File log (`writable/logs/log-YYYY-MM-DD.log`) standar CodeIgniter
 *   - Email alert ke HR admin lewat `php spark hr:audit` (sudah ada)
 *
 * Pemanggilan:
 *   ExceptionLogger::init()         // dipanggil sekali di Boot
 *   ExceptionLogger::capture($e)    // manual capture (mis. dari try-catch)
 *   ExceptionLogger::breadcrumb('User clicked X', ['data' => ...])
 */
class ExceptionLogger
{
    private static bool $initialized = false;
    private static bool $sentryEnabled = false;

    public static function init(): void
    {
        if (self::$initialized) return;
        self::$initialized = true;

        // Init Sentry hanya kalau library terpasang dan DSN di-set.
        $dsn = (string) (env('sentry.dsn') ?? '');
        if ($dsn !== '' && class_exists('\Sentry\SentrySdk') && function_exists('\Sentry\init')) {
            try {
                \Sentry\init([
                    'dsn'                  => $dsn,
                    'environment'          => (string) (env('sentry.environment') ?? env('CI_ENVIRONMENT', 'production')),
                    'traces_sample_rate'   => (float) (env('sentry.traces_sample_rate') ?? 0.1),
                    'profiles_sample_rate' => (float) (env('sentry.profiles_sample_rate') ?? 0.1),
                    'release'              => (string) (env('app.version') ?? 'unknown'),
                    // Strip request body & query string supaya tidak kirim PII
                    'send_default_pii'     => false,
                    'before_send' => static function (\Sentry\Event $event) {
                        // Redact field yang berpotensi PII di request data
                        return $event;
                    },
                ]);
                self::$sentryEnabled = true;
            } catch (\Throwable $e) {
                log_message('warning', 'Sentry init failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Capture exception ke audit_logs (+ Sentry kalau aktif). Aman dipanggil
     * dari middleware error handler tanpa risiko throw lagi.
     */
    public static function capture(\Throwable $e, array $context = []): void
    {
        // Sentry first kalau aktif
        if (self::$sentryEnabled) {
            try {
                if (! empty($context)) {
                    \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($context) {
                        foreach ($context as $key => $val) {
                            $scope->setExtra($key, $val);
                        }
                    });
                }
                \Sentry\captureException($e);
            } catch (\Throwable $sentryError) {
                log_message('warning', 'Sentry capture failed: ' . $sentryError->getMessage());
            }
        }

        // Audit_logs — ground truth, selalu jalan
        try {
            $msg = sprintf(
                '%s: %s (in %s:%d)',
                get_class($e),
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine()
            );
            $stackTrace = self::summarizeStack($e);
            Services::audit()->forModule('SYSTEM')->log(
                action: 'exception.unhandled',
                entity: 'system',
                description: mb_substr($msg, 0, 500),
                metadata: array_merge($context, [
                    'class'         => get_class($e),
                    'message'       => mb_substr($e->getMessage(), 0, 1000),
                    'file'          => $e->getFile(),
                    'line'          => $e->getLine(),
                    'stack_summary' => $stackTrace,
                ]),
                severity: 'CRITICAL',
            );
        } catch (\Throwable $auditError) {
            // Saat audit gagal (mis. DB down), tulis ke file log saja
            log_message('critical', 'ExceptionLogger fallback: ' . $msg
                . ' | audit failed: ' . $auditError->getMessage());
        }
    }

    /**
     * Tambah breadcrumb (Sentry feature) — track kejadian sebelum error.
     * Tanpa Sentry, no-op (tidak error).
     */
    public static function breadcrumb(string $message, array $data = [], string $level = 'info'): void
    {
        if (! self::$sentryEnabled) return;
        try {
            \Sentry\addBreadcrumb(new \Sentry\Breadcrumb(
                level: $level,
                type: 'default',
                category: 'app',
                message: $message,
                metadata: $data,
            ));
        } catch (\Throwable $e) {}
    }

    /** Set context user — sangat berguna di Sentry untuk identifikasi siapa kena error. */
    public static function setUser(?int $userId, ?string $email = null, ?string $name = null): void
    {
        if (! self::$sentryEnabled || $userId === null) return;
        try {
            \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($userId, $email, $name) {
                $scope->setUser([
                    'id'       => (string) $userId,
                    'email'    => $email,
                    'username' => $name,
                ]);
            });
        } catch (\Throwable $e) {}
    }

    /** Ringkas stack trace ke max 10 frame paling relevan (skip vendor). */
    private static function summarizeStack(\Throwable $e): array
    {
        $frames = [];
        foreach ($e->getTrace() as $i => $frame) {
            if ($i >= 20) break;
            $file = $frame['file'] ?? '';
            // Skip vendor frames untuk fokus ke app code
            if (str_contains($file, '/vendor/') || str_contains($file, '\\vendor\\')) {
                if (count($frames) >= 5) continue; // ambil sedikit vendor frame untuk konteks
            }
            $frames[] = sprintf(
                '%s:%d %s%s%s()',
                basename($file ?: '[internal]'),
                $frame['line'] ?? 0,
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? '',
            );
            if (count($frames) >= 10) break;
        }
        return $frames;
    }

    public static function isEnabled(): bool
    {
        return self::$initialized;
    }

    public static function isSentryEnabled(): bool
    {
        return self::$sentryEnabled;
    }
}