<?php

namespace App\Libraries;

/**
 * Telemetry — Sprint F+1 / G5.
 *
 * Lightweight wrapper untuk error reporting + custom event ke Sentry
 * (atau APM lain via driver pluggable). Tidak hard-depend ke Sentry SDK
 * supaya:
 *   1. App jalan normal kalau Sentry SDK belum ter-install
 *   2. Test environment tidak perlu mock Sentry
 *   3. Driver bisa di-swap ke OpenTelemetry/CloudWatch tanpa ubah caller
 *
 * Pola pakai:
 *
 *   $telemetry = service('telemetry');
 *   $telemetry->captureException($e, ['user_id' => 42]);
 *   $telemetry->captureMessage('Payroll calculate done', 'info', ['period_id' => 7]);
 *   $telemetry->setUser(['id' => $userId, 'email' => $email]);
 *   $telemetry->setTag('company_id', $companyId);
 *
 * Driver:
 *   - 'noop' (default) — diam, log lokal saja. Pakai di test/dev tanpa DSN.
 *   - 'sentry' — kirim ke Sentry kalau `sentry/sentry` package ter-install
 *               dan env `sentry.dsn` di-set. Detection auto via `class_exists`.
 *   - 'log'   — write ke CI4 log channel 'telemetry' (dev debugging tanpa external).
 *
 * PII redactor: payload `extra` di-scrub dari key sensitif (password,
 * nik, npwp, bank_account, otp, jwt) sebelum kirim. Ini contract baseline
 * supaya Sentry tidak kena breach UU PDP.
 */
class Telemetry
{
    public const DRIVER_NOOP   = 'noop';
    public const DRIVER_SENTRY = 'sentry';
    public const DRIVER_LOG    = 'log';

    /**
     * Pola key yang harus di-scrub sebelum kirim ke external sink.
     * Match case-insensitive substring.
     *
     * @var list<string>
     */
    private const PII_KEY_PATTERNS = [
        'password', 'pwd', 'token', 'secret', 'apikey', 'api_key',
        'nik', 'npwp', 'bank_account', 'cc_number', 'card',
        'otp', 'twofa', '2fa', 'authorization',
    ];

    private string $driver;

    /** Tag global yang attached ke setiap event. */
    private array $globalTags = [];

    /** User context (di-set oleh JwtAuthFilter setelah auth). */
    private ?array $userContext = null;

    public function __construct(?string $driver = null)
    {
        $this->driver = $driver ?? $this->resolveDriver();
    }

    /**
     * Capture exception dengan optional context.
     *
     * @param array<string,mixed> $extra
     */
    public function captureException(\Throwable $e, array $extra = []): void
    {
        $extra = $this->scrubExtra($extra);

        switch ($this->driver) {
            case self::DRIVER_SENTRY:
                $this->sendToSentry($e, $extra);
                break;
            case self::DRIVER_LOG:
                $this->safeLog('error', sprintf(
                    '[telemetry] %s: %s | extra=%s',
                    get_class($e),
                    $e->getMessage(),
                    json_encode($extra, JSON_UNESCAPED_UNICODE),
                ));
                break;
            case self::DRIVER_NOOP:
            default:
                // Diam — sengaja
                break;
        }
    }

    /**
     * Capture message non-exception (info/warning/error event).
     *
     * @param array<string,mixed> $extra
     */
    public function captureMessage(string $message, string $level = 'info', array $extra = []): void
    {
        $extra = $this->scrubExtra($extra);

        switch ($this->driver) {
            case self::DRIVER_SENTRY:
                if (function_exists('\Sentry\captureMessage')) {
                    /** @phpstan-ignore-next-line */
                    \Sentry\captureMessage($message, $this->levelToSentry($level));
                    if (! empty($extra)) {
                        /** @phpstan-ignore-next-line */
                        \Sentry\configureScope(function ($scope) use ($extra) {
                            foreach ($extra as $k => $v) {
                                $scope->setExtra((string) $k, $v);
                            }
                        });
                    }
                }
                break;
            case self::DRIVER_LOG:
                $this->safeLog($level, sprintf(
                    '[telemetry] %s | extra=%s',
                    $message,
                    json_encode($extra, JSON_UNESCAPED_UNICODE),
                ));
                break;
            case self::DRIVER_NOOP:
            default:
                break;
        }
    }

    /** Set user context (id + email). Auto-scrub email kalau perlu. */
    public function setUser(array $user): void
    {
        // Hanya keep field yang aman (no full PII)
        $this->userContext = [
            'id'    => $user['id']    ?? null,
            'email' => $user['email'] ?? null,
        ];

        if ($this->driver === self::DRIVER_SENTRY && function_exists('\Sentry\configureScope')) {
            /** @phpstan-ignore-next-line */
            \Sentry\configureScope(function ($scope): void {
                $scope->setUser($this->userContext);
            });
        }
    }

    /** Set tag global yang attached ke setiap event berikutnya. */
    public function setTag(string $key, string|int|float|bool $value): void
    {
        $this->globalTags[$key] = $value;

        if ($this->driver === self::DRIVER_SENTRY && function_exists('\Sentry\configureScope')) {
            /** @phpstan-ignore-next-line */
            \Sentry\configureScope(function ($scope) use ($key, $value): void {
                $scope->setTag($key, (string) $value);
            });
        }
    }

    /** Driver yang sedang aktif (debug). */
    public function driver(): string
    {
        return $this->driver;
    }

    // ─── Internal ─────────────────────────────────────────────────

    /**
     * Resolve driver dari env. Auto-fallback ke noop kalau Sentry SDK
     * tidak ter-install meski env minta sentry.
     */
    private function resolveDriver(): string
    {
        $configured = strtolower(trim((string) $this->safeEnv('telemetry.driver', self::DRIVER_NOOP)));

        if ($configured === self::DRIVER_SENTRY) {
            // Cek SDK ada
            if (! function_exists('\Sentry\init')) {
                $this->safeLog('warning', '[telemetry] sentry driver requested but SDK not installed; falling back to noop');
                return self::DRIVER_NOOP;
            }
            // Cek DSN configured
            $dsn = (string) $this->safeEnv('sentry.dsn', '');
            if ($dsn === '') {
                $this->safeLog('warning', '[telemetry] sentry driver but sentry.dsn empty; falling back to noop');
                return self::DRIVER_NOOP;
            }
            // Init SDK (idempotent)
            try {
                /** @phpstan-ignore-next-line */
                \Sentry\init([
                    'dsn'         => $dsn,
                    'environment' => (string) ($this->safeEnv('CI_ENVIRONMENT', 'production') ?: 'production'),
                    'release'     => (string) $this->safeEnv('app.version', 'unknown'),
                    'sample_rate' => (float) $this->safeEnv('sentry.sampleRate', 1.0),
                ]);
            } catch (\Throwable $e) {
                $this->safeLog('error', '[telemetry] sentry init failed: ' . $e->getMessage());
                return self::DRIVER_NOOP;
            }
            return self::DRIVER_SENTRY;
        }

        if ($configured === self::DRIVER_LOG || $configured === self::DRIVER_NOOP) {
            return $configured;
        }

        return self::DRIVER_NOOP;
    }

    /**
     * Wrap CI4 `env()` helper supaya library bisa di-test tanpa bootstrap
     * framework. Test environment tidak punya env() — return default.
     */
    private function safeEnv(string $key, mixed $default = null): mixed
    {
        if (function_exists('env')) {
            return env($key, $default);
        }
        // Fallback: cek $_ENV langsung
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    private function sendToSentry(\Throwable $e, array $extra): void
    {
        if (! function_exists('\Sentry\captureException')) {
            return;
        }
        if (! empty($extra) && function_exists('\Sentry\configureScope')) {
            /** @phpstan-ignore-next-line */
            \Sentry\configureScope(function ($scope) use ($extra): void {
                foreach ($extra as $k => $v) {
                    $scope->setExtra((string) $k, $v);
                }
            });
        }
        /** @phpstan-ignore-next-line */
        \Sentry\captureException($e);
    }

    /**
     * Scrub PII keys dari array. Recursive.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function scrubExtra(array $data): array
    {
        foreach ($data as $k => $v) {
            $key = strtolower((string) $k);
            $isSensitive = false;
            foreach (self::PII_KEY_PATTERNS as $pattern) {
                if (str_contains($key, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }
            if ($isSensitive) {
                $data[$k] = '[REDACTED]';
                continue;
            }
            if (is_array($v)) {
                $data[$k] = $this->scrubExtra($v);
            }
        }
        return $data;
    }

    /**
     * Wrap CI4 `log_message()` dengan function_exists check supaya
     * library bisa dipakai di unit test tanpa bootstrap framework
     * (PHPUnit standalone). Di production, CI4 helper sudah loaded
     * automatically sehingga function ini selalu tersedia.
     */
    private function safeLog(string $level, string $message): void
    {
        if (function_exists('log_message')) {
            log_message($level, $message);
        }
    }

    private function levelToSentry(string $level): string
    {
        return match (strtolower($level)) {
            'debug', 'info' => 'info',
            'warning'       => 'warning',
            'error'         => 'error',
            'critical'      => 'fatal',
            default         => 'info',
        };
    }
}