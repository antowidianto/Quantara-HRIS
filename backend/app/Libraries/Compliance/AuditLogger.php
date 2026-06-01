<?php

namespace App\Libraries\Compliance;

use Config\Services;

/**
 * AuditLogger
 *
 * Pencatat audit terstruktur untuk semua transaksi penting (CRUD, otorisasi,
 * approval, login, perubahan konfigurasi). Menghasilkan diff before/after,
 * deskripsi naratif Bahasa Indonesia, dan korelasi multi-step via request_id.
 *
 * Pemakaian umum:
 *
 *   Services::audit()
 *     ->forModule('EMPLOYEE')
 *     ->logCreate('employee', $id, $insertData, 'Karyawan baru: ' . $name);
 *
 *   Services::audit()
 *     ->forModule('LEAVE')
 *     ->logUpdate('leave', $id, $existing, $update);
 *
 *   Services::audit()
 *     ->forModule('AUTH')
 *     ->logFailure('auth.login', null, null, 'INVALID_CREDENTIALS', 'Login gagal: ' . $email);
 */
class AuditLogger
{
    /** Daftar field yang harus di-redact sebelum disimpan. */
    private const SENSITIVE_FIELDS = [
        'password', 'password_hash', 'password_old', 'password_new',
        'twofa_secret', 'twofa_recovery_codes',
        'nik_encrypted', 'npwp_encrypted', 'bank_account_encrypted',
        'token', 'refresh_token', 'access_token', 'api_key', 'secret',
    ];

    /** Field plaintext sensitif yang harus dimasking (bukan di-redact total). */
    private const PII_FIELDS = ['nik', 'npwp', 'bank_account', 'phone', 'email'];

    private string $module = 'GENERAL';
    private string $severity = 'INFO';
    private string $source = 'WEB';
    private ?string $requestId = null;
    private ?float $startedAt = null;

    /** Cache deteksi schema (kolom-kolom enhanced yang ada di DB). */
    private static ?array $schemaCols = null;

    /**
     * Field yang termasuk dalam canonical payload untuk hash chain.
     * Urutan ini permanen — verifier juga akan baca dengan urutan sama.
     * Jangan tambah/hapus tanpa migration data + dokumentasi.
     */
    private const HASH_FIELDS = [
        'company_id', 'user_id', 'action', 'severity', 'module',
        'entity', 'entity_id',
        'before_data', 'after_data', 'changes',
        'success', 'error_code',
        'created_at',
    ];

    /**
     * Deteksi kolom yang ada di tabel audit_logs sehingga bisa
     * gracefully bekerja dengan schema lama (sebelum migrasi #007).
     */
    private function availableColumns(): array
    {
        if (self::$schemaCols !== null) return self::$schemaCols;
        try {
            $db = \Config\Database::connect();
            $fields = $db->getFieldData('audit_logs');
            self::$schemaCols = array_map(static fn ($f) => strtolower($f->name), $fields);
        } catch (\Throwable $e) {
            self::$schemaCols = [];
        }
        return self::$schemaCols;
    }

    /**
     * Opsi method-chaining: set module untuk semua log selanjutnya pada
     * instance ini.
     */
    public function forModule(string $module): self
    {
        $this->module = strtoupper($module);
        return $this;
    }

    public function withSeverity(string $severity): self
    {
        $this->severity = strtoupper($severity);
        return $this;
    }

    public function withSource(string $source): self
    {
        $this->source = strtoupper($source);
        return $this;
    }

    /** Mulai timer untuk mengukur durasi operasi (opsional). */
    public function start(): self
    {
        $this->startedAt = microtime(true);
        return $this;
    }

    /** Korelasikan beberapa log ke satu transaksi multi-step. */
    public function withRequestId(string $id): self
    {
        $this->requestId = $id;
        return $this;
    }

    public function getRequestId(): string
    {
        if ($this->requestId === null) {
            $this->requestId = $this->generateUuid();
        }
        return $this->requestId;
    }

    // ─── Shorthand untuk operasi CRUD umum ──────────────────────────────

    public function logCreate(string $entity, $entityId, array $after, ?string $description = null, array $metadata = []): void
    {
        $this->log(
            action: $entity . '.create',
            entity: $entity,
            entityId: $entityId,
            before: null,
            after: $after,
            description: $description ?? "Membuat {$entity} #{$entityId}",
            metadata: $metadata,
        );
    }

    public function logUpdate(string $entity, $entityId, array $before, array $after, ?string $description = null, array $metadata = []): void
    {
        $changes = $this->diff($before, $after);
        $this->log(
            action: $entity . '.update',
            entity: $entity,
            entityId: $entityId,
            before: $before,
            after: $after,
            changes: $changes,
            description: $description ?? sprintf(
                "Mengubah %s #%s (%d field berubah)",
                $entity, $entityId, count($changes)
            ),
            metadata: $metadata,
        );
    }

    public function logDelete(string $entity, $entityId, array $before, ?string $description = null, array $metadata = []): void
    {
        $this->log(
            action: $entity . '.delete',
            entity: $entity,
            entityId: $entityId,
            before: $before,
            after: null,
            description: $description ?? "Menghapus {$entity} #{$entityId}",
            severity: 'NOTICE',
            metadata: $metadata,
        );
    }

    public function logApprove(string $entity, $entityId, array $before, ?string $description = null, array $metadata = []): void
    {
        $this->log(
            action: $entity . '.approve',
            entity: $entity,
            entityId: $entityId,
            before: $before,
            after: ['status' => 'APPROVED'],
            description: $description ?? "Menyetujui {$entity} #{$entityId}",
            severity: 'NOTICE',
            metadata: $metadata,
        );
    }

    public function logReject(string $entity, $entityId, array $before, string $reason, array $metadata = []): void
    {
        $this->log(
            action: $entity . '.reject',
            entity: $entity,
            entityId: $entityId,
            before: $before,
            after: ['status' => 'REJECTED', 'reason' => $reason],
            description: "Menolak {$entity} #{$entityId}: {$reason}",
            severity: 'NOTICE',
            metadata: $metadata,
        );
    }

    public function logFailure(string $action, ?string $entity = null, $entityId = null, ?string $errorCode = null, ?string $description = null, array $metadata = []): void
    {
        $this->log(
            action: $action,
            entity: $entity,
            entityId: $entityId,
            description: $description ?? "Gagal: {$action}",
            severity: 'WARNING',
            success: false,
            errorCode: $errorCode,
            metadata: $metadata,
        );
    }

    public function logCritical(string $action, ?string $entity = null, $entityId = null, string $description = '', array $metadata = []): void
    {
        $this->log(
            action: $action,
            entity: $entity,
            entityId: $entityId,
            description: $description ?: "Kejadian kritis: {$action}",
            severity: 'CRITICAL',
            metadata: $metadata,
        );
    }

    /**
     * API utama. Tetap kompatibel dengan signature lama (positional args
     * tetap bekerja untuk pemanggilan legacy: log($action, $entity, $id,
     * $before, $after, $metadata)).
     */
    public function log(
        string $action,
        ?string $entity = null,
        $entityId = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?array $changes = null,
        ?string $description = null,
        ?string $severity = null,
        ?string $module = null,
        ?string $source = null,
        bool $success = true,
        ?string $errorCode = null,
    ): void {
        try {
            $tenant  = Services::tenant();
            $request = Services::request();
            $db      = \Config\Database::connect();

            // Diff otomatis kalau belum disediakan
            if ($changes === null && $before !== null && $after !== null) {
                $changes = $this->diff($before, $after);
            }

            $duration = $this->startedAt !== null
                ? (int) round((microtime(true) - $this->startedAt) * 1000)
                : null;

            $row = [
                'company_id'  => $tenant->getCompanyId() ?: null,
                'user_id'     => $tenant->getUserId() ?: null,
                'action'      => $action,
                'severity'    => $severity ?? $this->severity,
                'module'      => $module ?? $this->guessModule($action),
                'description' => $description ? $this->trim($description, 500) : null,
                'entity'      => $entity,
                'entity_id'   => $entityId !== null ? (string) $entityId : null,
                'before_data' => $before ? json_encode($this->sanitize($before), JSON_UNESCAPED_UNICODE) : null,
                'after_data'  => $after  ? json_encode($this->sanitize($after),  JSON_UNESCAPED_UNICODE) : null,
                'changes'     => $changes ? json_encode($this->sanitize($changes, true), JSON_UNESCAPED_UNICODE) : null,
                'metadata'    => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
                // CLI request (mis. spark migrate, scheduled command) tidak
                // punya method getUserAgent()/getUri() — guard pakai
                // method_exists supaya audit dari CLI tetap tercatat tanpa
                // memicu warning. Lihat docs/AUDIT.md.
                'ip_address'  => ($request && method_exists($request, 'getIPAddress'))
                    ? $request->getIPAddress() : null,
                'user_agent'  => ($request && method_exists($request, 'getUserAgent'))
                    ? $this->trim((string) $request->getUserAgent(), 255) : null,
                'source'      => $source ?? $this->detectSource($request),
                'request_id'  => $this->getRequestId(),
                'http_method' => ($request && method_exists($request, 'getMethod'))
                    ? strtoupper((string) $request->getMethod()) : null,
                'http_path'   => ($request && method_exists($request, 'getUri'))
                    ? $this->trim($request->getUri()->getPath(), 255) : null,
                'success'     => $success ? 1 : 0,
                'error_code'  => $errorCode,
                'duration_ms' => $duration,
                'created_at'  => date('Y-m-d H:i:s'),
            ];

            // Buang kolom yang belum ada di skema (mendukung DB yang belum migrasi #007)
            $cols = $this->availableColumns();
            if (! empty($cols)) {
                $row = array_intersect_key($row, array_flip($cols));
            }

            // ─── Hash chain (Sprint A / B6) ─────────────────────────
            // Kalau kolom hash chain ada (migration #072 sudah jalan),
            // hitung previous_hash + entry_hash supaya audit log immutable.
            // Skip di SQLite test env (belum tentu ada index, tapi kolomnya
            // tidak masalah — tetap hitung kalau kolomnya ada).
            if (in_array('entry_hash', $cols, true)) {
                $chainId = $this->resolveChainId($row);
                $prevHash = $this->fetchLatestHash($db, $chainId);
                $row['chain_id']      = $chainId;
                $row['previous_hash'] = $prevHash;
                $row['entry_hash']    = $this->computeEntryHash($row, $prevHash);
            }

            $db->table('audit_logs')->insert($row);
        } catch (\Throwable $e) {
            // Audit gagal tidak boleh menggagalkan request bisnis
            log_message('error', 'AuditLogger.log failed: ' . $e->getMessage());
        }
    }

    /**
     * Hitung selisih before vs after. Hanya menyertakan field yang berubah.
     * Format: ['field' => ['from' => x, 'to' => y]]
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        foreach ($keys as $k) {
            $b = $before[$k] ?? null;
            $a = $after[$k]  ?? null;
            // Bandingkan loose untuk angka string vs int
            if ($b instanceof \DateTimeInterface) $b = $b->format('Y-m-d H:i:s');
            if ($a instanceof \DateTimeInterface) $a = $a->format('Y-m-d H:i:s');
            if ((string) $b !== (string) $a) {
                $changes[$k] = ['from' => $b, 'to' => $a];
            }
        }
        return $changes;
    }

    /**
     * Hapus/masking field sensitif dari payload sebelum disimpan.
     * Mode "deep" untuk struktur diff: {field: {from, to}}.
     */
    public function sanitize(array $data, bool $deep = false): array
    {
        foreach ($data as $k => $v) {
            $lk = strtolower((string) $k);

            // Field rahasia: redact total
            if (in_array($lk, self::SENSITIVE_FIELDS, true)) {
                if ($deep && is_array($v) && array_key_exists('from', $v)) {
                    $data[$k] = ['from' => '***REDACTED***', 'to' => '***REDACTED***'];
                } else {
                    $data[$k] = '***REDACTED***';
                }
                continue;
            }

            // Field PII: masking
            if (in_array($lk, self::PII_FIELDS, true)) {
                if ($deep && is_array($v) && array_key_exists('from', $v)) {
                    $data[$k] = [
                        'from' => $this->mask((string) ($v['from'] ?? '')),
                        'to'   => $this->mask((string) ($v['to'] ?? '')),
                    ];
                } else {
                    $data[$k] = $this->mask((string) $v);
                }
                continue;
            }

            // Recurse ke array nested (kecuali yang sudah jadi diff struktur)
            if (is_array($v) && ! ($deep && isset($v['from']))) {
                $data[$k] = $this->sanitize($v, $deep);
            }
        }
        return $data;
    }

    private function mask(string $value): string
    {
        if ($value === '') return '';
        $len = strlen($value);
        if ($len <= 4) return str_repeat('*', $len);
        if ($len <= 8) return substr($value, 0, 2) . str_repeat('*', $len - 4) . substr($value, -2);
        return substr($value, 0, 3) . str_repeat('*', $len - 6) . substr($value, -3);
    }

    private function trim(string $s, int $max): string
    {
        return strlen($s) <= $max ? $s : substr($s, 0, $max - 3) . '...';
    }

    private function guessModule(string $action): string
    {
        $prefix = strtoupper(strtok($action, '.') ?: '');
        return match (true) {
            str_starts_with($prefix, 'AUTH') => 'AUTH',
            $prefix === 'EMPLOYEE'           => 'EMPLOYEE',
            $prefix === 'ATTENDANCE'         => 'ATTENDANCE',
            $prefix === 'LEAVE'              => 'LEAVE',
            $prefix === 'OVERTIME'           => 'OVERTIME',
            $prefix === 'PAYROLL_PERIOD'
                || $prefix === 'PAYSLIP'
                || $prefix === 'PAYROLL'     => 'PAYROLL',
            $prefix === 'LOAN'               => 'LOAN',
            $prefix === 'REIMBURSEMENT'      => 'REIMBURSEMENT',
            $prefix === 'SETTINGS'
                || $prefix === 'SETTING'     => 'SETTING',
            $prefix === 'USER'
                || $prefix === 'ROLE'
                || $prefix === 'PERMISSION'  => 'ADMIN',
            default                          => $this->module,
        };
    }

    private function detectSource(?\CodeIgniter\HTTP\IncomingRequest $request): string
    {
        if (! $request) return 'SYSTEM';
        $ua = strtolower((string) $request->getUserAgent());
        if ($ua === '' || str_contains($ua, 'cli')) return 'CRON';
        if (str_contains($ua, 'okhttp') || str_contains($ua, 'dart') || str_contains($ua, 'flutter')) return 'MOBILE';
        if (str_contains($ua, 'mozilla') || str_contains($ua, 'chrome') || str_contains($ua, 'safari')) return 'WEB';
        return $this->source;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // ─── Hash chain helpers (Sprint A / B6) ──────────────────────────

    /**
     * Resolve chain_id untuk row ini. Strategi: per (company_id, year-month)
     * supaya chain tidak monolitik (verifier cukup load 1 segmen, dan
     * arsip lama bisa di-archive per segmen tanpa break verifikasi).
     *
     * Format: "c{companyId}-{YYYYMM}". Untuk row tanpa company (mis.
     * super admin global action) pakai "global-{YYYYMM}".
     */
    private function resolveChainId(array $row): string
    {
        $cid    = (int) ($row['company_id'] ?? 0);
        $period = substr((string) ($row['created_at'] ?? date('Y-m-d')), 0, 7); // YYYY-MM
        $period = str_replace('-', '', $period);
        $prefix = $cid > 0 ? ('c' . $cid) : 'global';
        return $prefix . '-' . $period;
    }

    /**
     * Hitung entry_hash kanonik. Pakai SHA256 dari concat:
     *   <previous_hash> || "\x1F" || <field1>=<v1> || "\x1F" || <field2>=<v2> ...
     * Separator 0x1F (Unit Separator) supaya tidak collide dengan content.
     * Field di-serialize jadi string deterministik (null → "", bool/int → as-is).
     */
    private function computeEntryHash(array $row, ?string $previousHash): string
    {
        $parts = [(string) ($previousHash ?? '')];
        foreach (self::HASH_FIELDS as $f) {
            $v = $row[$f] ?? null;
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            } elseif (is_bool($v)) {
                $v = $v ? '1' : '0';
            } else {
                $v = $v === null ? '' : (string) $v;
            }
            $parts[] = $f . '=' . $v;
        }
        return hash('sha256', implode("\x1F", $parts));
    }

    /**
     * Ambil hash terakhir dari chain. NULL kalau chain kosong (genesis row).
     */
    private function fetchLatestHash($db, string $chainId): ?string
    {
        try {
            $row = $db->table('audit_logs')
                ->select('entry_hash')
                ->where('chain_id', $chainId)
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()
                ->getRowArray();
            return $row['entry_hash'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Verifier helper — recompute hash row tertentu.
     * Dipakai oleh `php spark audit:verify-chain`.
     */
    public static function recomputeHash(array $row, ?string $previousHash): string
    {
        $logger = new self();
        return $logger->computeEntryHash($row, $previousHash);
    }
}
