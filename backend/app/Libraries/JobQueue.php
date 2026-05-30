<?php

namespace App\Libraries;

use Config\Services;

/**
 * JobQueue — Sprint D+2 / D7.
 *
 * Antrian job sederhana berbasis tabel `job_queue` (lihat migration di
 * Sprint D+2 batch). Dipakai untuk pindahkan operasi blocking dari HTTP
 * request ke worker background:
 *
 *   - `payroll-periods/(:num)/calculate` (5K karyawan = 30s+ blocking)
 *   - `bonus-runs/(:num)/calculate` (full company × multiple bonuses)
 *   - `imports/(:num)/process` (xlsx 10K rows)
 *
 * Filosofi keputusan teknis (lihat ADR carry-over):
 *   - **Bukan Redis-pure queue** (BullMQ/Sidekiq style) — kita tidak mau
 *     dependency Redis untuk operasi mission-critical kayak payroll.
 *     Redis-only queue rentan kehilangan job kalau Redis crash sebelum
 *     persist ke disk.
 *   - **Storage di MySQL** (`job_queue` table) — konsisten dengan storage
 *     utama, transaksional, mudah inspect via SQL. Trade-off: throughput
 *     lebih rendah dibanding Redis (cukup untuk HRIS — bukan trading).
 *   - **Worker pakai `php spark queue:work`** (Sprint D+2 implementasi
 *     command) yang loop polling tabel dengan `FOR UPDATE SKIP LOCKED`
 *     supaya multi-worker safe.
 *   - **Idempotent jobs** — handler harus tahan re-run kalau worker crash
 *     setelah kerja tapi sebelum mark done.
 *
 * API publik: `enqueue()`, `dequeue()`, `markDone()`, `markFailed()`,
 * `pendingForCompany()`, `cancel()`. Worker loop di-implementasi di
 * `app/Commands/QueueWork.php` (carry-over Sprint D+3).
 *
 * Status workflow:
 *   PENDING → RUNNING → DONE
 *   PENDING → RUNNING → FAILED (auto retry kalau attempts < max_attempts)
 *   PENDING → CANCELLED (admin cancel sebelum eksekusi)
 *
 * Pure-ish (DB layer di-injeksi sebagai object duck-typed) untuk testability.
 */
class JobQueue
{
    public const STATUS_PENDING   = 'PENDING';
    public const STATUS_RUNNING   = 'RUNNING';
    public const STATUS_DONE      = 'DONE';
    public const STATUS_FAILED    = 'FAILED';
    public const STATUS_CANCELLED = 'CANCELLED';

    /** Default max attempts sebelum mark FAILED permanen. */
    public const DEFAULT_MAX_ATTEMPTS = 3;

    /** Default backoff exponential base (detik). */
    public const RETRY_BASE_SECONDS = 30;

    private object $db;

    public function __construct(?object $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Enqueue job baru. Tidak block — return ID job untuk caller track.
     *
     * @param string             $jobClass    FQCN class job handler
     *                                        (mis. App\Jobs\PayrollCalculateJob)
     * @param array<string,mixed> $payload    Argumen yang di-passing ke handler
     * @param int                $companyId   Tenant scope
     * @param array{
     *   max_attempts?:int,
     *   run_at?:string,
     *   priority?:int,
     *   idempotency_key?:string,
     * } $options
     * @return int ID job yang baru di-insert (atau existing kalau idempotency_key match)
     */
    public function enqueue(
        string $jobClass,
        array $payload,
        int $companyId,
        array $options = [],
    ): int {
        // Idempotency: kalau caller pass idempotency_key dan sudah ada
        // PENDING/RUNNING dengan key tsb, return ID yang existing
        $idempotencyKey = $options['idempotency_key'] ?? null;
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->db->table('job_queue')
                ->where('idempotency_key', $idempotencyKey)
                ->where('company_id', $companyId)
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_RUNNING])
                ->get()->getRowArray();
            if (! empty($existing) && isset($existing['id'])) {
                return (int) $existing['id'];
            }
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'company_id'      => $companyId,
            'job_class'       => $jobClass,
            'payload'         => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'          => self::STATUS_PENDING,
            'priority'        => (int) ($options['priority'] ?? 0),
            'attempts'        => 0,
            'max_attempts'    => (int) ($options['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS),
            'run_at'          => $options['run_at'] ?? $now,
            'idempotency_key' => $idempotencyKey,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        $this->db->table('job_queue')->insert($row);
        $id = method_exists($this->db, 'insertID') ? (int) $this->db->insertID() : 0;
        return $id;
    }

    /**
     * Worker call: ambil 1 job yang ready run, mark RUNNING. Pakai
     * row-level lock (`FOR UPDATE SKIP LOCKED` di driver yang support)
     * untuk konkurensi multi-worker.
     *
     * Return null kalau tidak ada job ready.
     *
     * @return array<string,mixed>|null
     */
    public function dequeue(?int $companyId = null): ?array
    {
        $now = date('Y-m-d H:i:s');

        // Note: `FOR UPDATE SKIP LOCKED` butuh transaction & MySQL 8+.
        // Kalau tidak tersedia, fallback ke double-check optimistic lock.
        // Kita pakai pattern simple: SELECT id → UPDATE WHERE status=PENDING.
        // Loser di race UPDATE balikkan affected_rows=0, retry.

        $b = $this->db->table('job_queue')
            ->where('status', self::STATUS_PENDING)
            ->where('run_at <=', $now)
            ->orderBy('priority', 'DESC')
            ->orderBy('id', 'ASC')
            ->limit(1);

        if ($companyId !== null) {
            $b->where('company_id', $companyId);
        }

        $candidate = $b->get()->getRowArray();
        if (empty($candidate) || ! isset($candidate['id'])) {
            return null;
        }

        $id = (int) $candidate['id'];

        // Optimistic acquire: only update kalau status masih PENDING
        $update = $this->db->table('job_queue')
            ->where('id', $id)
            ->where('status', self::STATUS_PENDING)
            ->update([
                'status'      => self::STATUS_RUNNING,
                'attempts'    => (int) ($candidate['attempts'] ?? 0) + 1,
                'started_at'  => $now,
                'updated_at'  => $now,
            ]);

        $affected = method_exists($this->db, 'affectedRows') ? (int) $this->db->affectedRows() : 0;
        if ($affected === 0) {
            // Lost race ke worker lain — caller bisa loop retry
            return null;
        }

        $candidate['status']    = self::STATUS_RUNNING;
        $candidate['attempts']  = (int) ($candidate['attempts'] ?? 0) + 1;
        $candidate['started_at'] = $now;
        return $candidate;
    }

    /**
     * Worker mark job sukses. Idempotent — kalau sudah DONE, no-op.
     */
    public function markDone(int $id, mixed $result = null): bool
    {
        $now = date('Y-m-d H:i:s');
        $resultJson = $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE);

        $this->db->table('job_queue')
            ->where('id', $id)
            ->whereIn('status', [self::STATUS_RUNNING, self::STATUS_PENDING])
            ->update([
                'status'      => self::STATUS_DONE,
                'finished_at' => $now,
                'result'      => $resultJson,
                'last_error'  => null,
                'updated_at'  => $now,
            ]);

        return method_exists($this->db, 'affectedRows') ? $this->db->affectedRows() > 0 : true;
    }

    /**
     * Worker mark job gagal. Kalau attempts < max_attempts, kembalikan ke
     * PENDING dengan run_at = now + backoff exponential.
     */
    public function markFailed(int $id, string $errorMessage): bool
    {
        $now = date('Y-m-d H:i:s');

        $row = $this->db->table('job_queue')
            ->where('id', $id)
            ->get()->getRowArray();

        if (empty($row)) {
            return false;
        }

        $attempts    = (int) ($row['attempts'] ?? 1);
        $maxAttempts = (int) ($row['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS);

        if ($attempts >= $maxAttempts) {
            // Permanent failure
            $this->db->table('job_queue')
                ->where('id', $id)
                ->update([
                    'status'      => self::STATUS_FAILED,
                    'finished_at' => $now,
                    'last_error'  => $this->truncate($errorMessage, 2000),
                    'updated_at'  => $now,
                ]);
        } else {
            // Re-queue dengan backoff: 30s, 60s, 120s, ...
            $backoff = self::RETRY_BASE_SECONDS * (2 ** ($attempts - 1));
            $nextRun = date('Y-m-d H:i:s', time() + $backoff);

            $this->db->table('job_queue')
                ->where('id', $id)
                ->update([
                    'status'      => self::STATUS_PENDING,
                    'run_at'      => $nextRun,
                    'last_error'  => $this->truncate($errorMessage, 2000),
                    'updated_at'  => $now,
                ]);
        }

        return method_exists($this->db, 'affectedRows') ? $this->db->affectedRows() > 0 : true;
    }

    /**
     * Admin cancel job yang masih PENDING. Job RUNNING tidak bisa di-cancel
     * mid-flight (worker yang putuskan untuk mengizinkan interrupt atau tidak).
     */
    public function cancel(int $id): bool
    {
        $this->db->table('job_queue')
            ->where('id', $id)
            ->where('status', self::STATUS_PENDING)
            ->update([
                'status'      => self::STATUS_CANCELLED,
                'finished_at' => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);

        return method_exists($this->db, 'affectedRows') ? $this->db->affectedRows() > 0 : true;
    }

    /**
     * List job pending+running untuk satu company. Untuk dashboard ops.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingForCompany(int $companyId, int $limit = 50): array
    {
        return $this->db->table('job_queue')
            ->where('company_id', $companyId)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_RUNNING])
            ->orderBy('priority', 'DESC')
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * Snapshot status counter untuk metrics endpoint.
     *
     * @return array<string,int>
     */
    public function snapshot(?int $companyId = null): array
    {
        $b = $this->db->table('job_queue')
            ->select('status, COUNT(*) AS cnt')
            ->groupBy('status');
        if ($companyId !== null) {
            $b->where('company_id', $companyId);
        }
        $rows = $b->get()->getResultArray();

        $out = [
            self::STATUS_PENDING   => 0,
            self::STATUS_RUNNING   => 0,
            self::STATUS_DONE      => 0,
            self::STATUS_FAILED    => 0,
            self::STATUS_CANCELLED => 0,
        ];
        foreach ($rows as $r) {
            $key = (string) ($r['status'] ?? '');
            if (isset($out[$key])) {
                $out[$key] = (int) $r['cnt'];
            }
        }
        return $out;
    }

    private function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) return $s;
        return substr($s, 0, $max - 3) . '...';
    }
}