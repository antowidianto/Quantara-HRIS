<?php

namespace App\Libraries\System;

use Config\Database;
use Config\Services;
use Throwable;

/**
 * S2.1 — Health Checker (Roadmap SaaS)
 *
 * Library yang melakukan health check ke berbagai dependency aplikasi.
 * Setiap check punya signature konsisten:
 *   ['ok' => bool, 'duration_ms' => int, 'critical' => bool, 'detail' => string|array]
 *
 * Critical check (DB) → kalau down, status code 503.
 * Non-critical (queue depth, S3) → tetap report tapi tidak menggagalkan
 * health endpoint utama (degraded mode masih bisa serve traffic minimal).
 */
class HealthChecker
{
    /**
     * Jalankan semua check dan kumpulkan hasil terstruktur.
     *
     * @return array<string, array{name:string,ok:bool,duration_ms:int,critical:bool,detail:mixed}>
     */
    public function all(): array
    {
        return [
            'database'      => $this->wrap('database',     true,  fn () => $this->checkDatabase()),
            'cache'         => $this->wrap('cache',        false, fn () => $this->checkCache()),
            'queue'         => $this->wrap('queue',        false, fn () => $this->checkQueue()),
            'storage'       => $this->wrap('storage',      false, fn () => $this->checkStorage()),
            'subscriptions' => $this->wrap('subscriptions',false, fn () => $this->checkSubscriptions()),
        ];
    }

    /**
     * Cek database via SELECT 1 + table count untuk validasi schema.
     */
    public function checkDatabase(): array
    {
        try {
            $db = Database::connect();
            $db->query('SELECT 1');
            // Validasi minimal: tabel companies harus ada
            if (! $db->tableExists('companies')) {
                return ['ok' => false, 'error' => 'Schema not migrated (companies table missing)'];
            }
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cek cache backend (file/memcached/redis sesuai config).
     */
    public function checkCache(): array
    {
        try {
            $cache = Services::cache();
            $key = 'health_check_' . bin2hex(random_bytes(4));
            $cache->save($key, time(), 60);
            $val = $cache->get($key);
            $cache->delete($key);
            return ['ok' => $val !== null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cek queue worker via tabel `jobs` last heartbeat.
     * Non-critical: kalau tidak ada job, anggap healthy (artinya idle).
     */
    public function checkQueue(): array
    {
        try {
            $db = Database::connect();
            if (! $db->tableExists('jobs')) {
                return ['ok' => true, 'detail' => 'Queue not configured'];
            }

            // Hitung pending vs failed
            $pending = (int) $db->table('jobs')->where('status', 'PENDING')->countAllResults();
            $failed = (int) $db->table('jobs')->where('status', 'FAILED')->countAllResults();
            // Stale: ada job RUNNING > 30 menit (kemungkinan worker crash)
            $stale = (int) $db->table('jobs')
                ->where('status', 'RUNNING')
                ->where('started_at <', date('Y-m-d H:i:s', strtotime('-30 minutes')))
                ->countAllResults();

            $issues = [];
            if ($failed > 50) $issues[] = "{$failed} failed jobs";
            if ($stale > 0)   $issues[] = "{$stale} stale RUNNING jobs (worker crashed?)";

            return [
                'ok'      => empty($issues),
                'detail'  => [
                    'pending' => $pending,
                    'failed'  => $failed,
                    'stale'   => $stale,
                    'issues'  => $issues,
                ],
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cek storage / file system (writable directory).
     */
    public function checkStorage(): array
    {
        try {
            $dir = WRITEPATH . 'uploads';
            if (! is_dir($dir)) mkdir($dir, 0750, true);

            // Coba write+read+delete file kecil
            $file = $dir . '/.health_' . bin2hex(random_bytes(4));
            $bytes = file_put_contents($file, 'health-check');
            if ($bytes === false) {
                return ['ok' => false, 'error' => 'Storage not writable: ' . $dir];
            }
            $content = file_get_contents($file);
            unlink($file);

            // Disk space check
            $freeBytes = @disk_free_space(WRITEPATH);
            $totalBytes = @disk_total_space(WRITEPATH);
            $usedPct = $totalBytes > 0 ? round((1 - $freeBytes / $totalBytes) * 100, 1) : null;

            $issues = [];
            if ($usedPct !== null && $usedPct > 90) {
                $issues[] = "Disk usage {$usedPct}% (>90%)";
            }

            return [
                'ok'     => $content === 'health-check' && empty($issues),
                'detail' => [
                    'writable'      => true,
                    'free_bytes'    => $freeBytes,
                    'used_pct'      => $usedPct,
                    'issues'        => $issues,
                ],
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * S2.1 SaaS-specific — sanity check `subscriptions` table.
     * Non-critical: kalau tabel belum ada (migration belum run), treat as no-op.
     */
    public function checkSubscriptions(): array
    {
        try {
            $db = Database::connect();
            if (! $db->tableExists('subscriptions')) {
                return ['ok' => true, 'detail' => 'Subscription module not yet migrated'];
            }

            $active = (int) $db->table('subscriptions')
                ->whereIn('status', ['TRIAL', 'ACTIVE'])
                ->countAllResults();
            $pastDue = (int) $db->table('subscriptions')
                ->where('status', 'PAST_DUE')
                ->countAllResults();
            $suspended = (int) $db->table('subscriptions')
                ->where('status', 'SUSPENDED')
                ->countAllResults();

            return [
                'ok'     => true,
                'detail' => [
                    'active'    => $active,
                    'past_due'  => $pastDue,
                    'suspended' => $suspended,
                ],
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Bungkus pure check function dengan timing + critical flag.
     */
    private function wrap(string $name, bool $critical, callable $fn): array
    {
        $start = microtime(true);
        try {
            $result = $fn();
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }
        $duration = (int) round((microtime(true) - $start) * 1000);

        return array_merge([
            'name'        => $name,
            'critical'    => $critical,
            'duration_ms' => $duration,
        ], $result);
    }
}