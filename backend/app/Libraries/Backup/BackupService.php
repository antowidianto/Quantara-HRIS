<?php

namespace App\Libraries\Backup;

use Config\Services;

/**
 * S2.3 — Backup Service
 *
 * Wrapper untuk eksekusi backup database dengan opsi:
 * - mysqldump (full) → gzip → opsional GPG encrypt → upload S3 / local
 * - SHA-256 hash + size tracking di tabel `backup_runs`
 * - Retention class (DAILY/WEEKLY/MONTHLY) untuk lifecycle policy
 *
 * Pure helpers (`*Pure()`) bisa di-unit-test tanpa DB / shell.
 *
 * ENV yang dipakai:
 *   BACKUP_STORAGE     = LOCAL | S3       (default LOCAL)
 *   BACKUP_LOCAL_PATH  = path lokal backup (default writable/backups)
 *   BACKUP_S3_BUCKET   = bucket S3
 *   BACKUP_S3_REGION   = region
 *   BACKUP_GPG_ENABLED = 1 untuk enable GPG encrypt
 *   BACKUP_GPG_KEYID   = recipient key id
 *   BACKUP_RETENTION_DAILY   (default 7)
 *   BACKUP_RETENTION_WEEKLY  (default 4)
 *   BACKUP_RETENTION_MONTHLY (default 12)
 */
class BackupService
{
    /**
     * Tentukan retention class berdasarkan tanggal eksekusi.
     *
     * Logic:
     *  - Hari pertama bulan → MONTHLY
     *  - Hari Minggu      → WEEKLY
     *  - Selain itu       → DAILY
     *
     * Pure helper, deterministik berdasarkan input tanggal.
     *
     * @param string $date Y-m-d
     */
    public static function retentionClassPure(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return 'DAILY';
        }
        $day      = (int) date('j', $ts); // day of month
        $weekday  = (int) date('w', $ts); // 0 = Sunday

        if ($day === 1) {
            return 'MONTHLY';
        }
        if ($weekday === 0) {
            return 'WEEKLY';
        }
        return 'DAILY';
    }

    /**
     * Tentukan apakah file backup sudah expired berdasarkan retention class
     * dan policy hari (lihat ENV BACKUP_RETENTION_*).
     *
     * Pure helper untuk testability.
     *
     * @param string $retentionClass DAILY|WEEKLY|MONTHLY
     * @param string $createdAt      Y-m-d H:i:s
     * @param string $now            Y-m-d H:i:s
     * @param array  $policy         ['daily'=>7,'weekly'=>4,'monthly'=>12]
     */
    public static function isExpiredPure(
        string $retentionClass,
        string $createdAt,
        string $now,
        array $policy
    ): bool {
        $created = strtotime($createdAt);
        $current = strtotime($now);
        if ($created === false || $current === false) {
            return false;
        }
        $ageSec = $current - $created;
        if ($ageSec < 0) {
            return false;
        }
        $ageDays = (int) floor($ageSec / 86400);

        switch (strtoupper($retentionClass)) {
            case 'MONTHLY':
                $months = (int) ($policy['monthly'] ?? 12);
                return $ageDays > ($months * 30);
            case 'WEEKLY':
                $weeks = (int) ($policy['weekly'] ?? 4);
                return $ageDays > ($weeks * 7);
            case 'DAILY':
            default:
                $days = (int) ($policy['daily'] ?? 7);
                return $ageDays > $days;
        }
    }

    /**
     * Generate filename backup standar.
     *
     * Format: hris_backup_{env}_{YYYYMMDD_HHMMSS}_{retention}.sql.gz[.gpg]
     */
    public static function buildFilenamePure(
        string $env,
        string $datetime,
        string $retentionClass,
        bool $gpgEnabled
    ): string {
        $ts   = strtotime($datetime) ?: time();
        $stamp = date('Ymd_His', $ts);
        $base = "hris_backup_{$env}_{$stamp}_" . strtolower($retentionClass) . '.sql.gz';
        return $gpgEnabled ? $base . '.gpg' : $base;
    }

    /**
     * Buat record backup_runs status PENDING. Return ID.
     */
    public function startRun(string $kind = 'FULL'): int
    {
        $db   = \Config\Database::connect();
        $now  = date('Y-m-d H:i:s');
        $rc   = self::retentionClassPure(date('Y-m-d'));
        $env  = env('CI_ENVIRONMENT', 'production');
        $stor = strtoupper((string) env('BACKUP_STORAGE', 'LOCAL'));

        $db->table('backup_runs')->insert([
            'kind'            => $kind,
            'status'          => 'RUNNING',
            'storage_target'  => $stor,
            'retention_class' => $rc,
            'started_at'      => $now,
            'gpg_encrypted'   => env('BACKUP_GPG_ENABLED') ? 1 : 0,
            'created_at'      => $now,
            'metadata_json'   => json_encode(['env' => $env]),
        ]);
        return (int) $db->insertID();
    }

    /**
     * Tandai run sukses dengan metadata file.
     */
    public function completeRun(int $runId, array $info): void
    {
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $row = $db->table('backup_runs')->where('id', $runId)->get()->getRowArray();
        $duration = null;
        if ($row && ! empty($row['started_at'])) {
            $duration = max(0, strtotime($now) - strtotime($row['started_at']));
        }

        $db->table('backup_runs')->where('id', $runId)->update([
            'status'        => 'SUCCESS',
            'finished_at'   => $now,
            'duration_sec'  => $duration,
            'size_bytes'    => $info['size_bytes']    ?? null,
            'storage_path'  => $info['storage_path']  ?? null,
            'sha256'        => $info['sha256']        ?? null,
            'gpg_encrypted' => isset($info['gpg_encrypted']) ? (int) $info['gpg_encrypted'] : 0,
        ]);
    }

    /**
     * Tandai run gagal dengan error message.
     */
    public function failRun(int $runId, string $message): void
    {
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $row = $db->table('backup_runs')->where('id', $runId)->get()->getRowArray();
        $duration = null;
        if ($row && ! empty($row['started_at'])) {
            $duration = max(0, strtotime($now) - strtotime($row['started_at']));
        }

        $db->table('backup_runs')->where('id', $runId)->update([
            'status'        => 'FAILED',
            'finished_at'   => $now,
            'duration_sec'  => $duration,
            'error_message' => $message,
        ]);
    }

    /**
     * Tandai run sudah diverifikasi (oleh DbBackupVerify).
     */
    public function markVerified(int $runId): void
    {
        $db = \Config\Database::connect();
        $db->table('backup_runs')->where('id', $runId)->update([
            'status'      => 'VERIFIED',
            'verified_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Eksekusi mysqldump → gzip → (opsional GPG) → simpan ke storage.
     *
     * Return: [
     *   'storage_path' => string,
     *   'size_bytes'   => int,
     *   'sha256'       => string,
     *   'gpg_encrypted'=> bool,
     * ]
     *
     * @throws \RuntimeException kalau dump / encryption / upload gagal.
     */
    public function executeDump(): array
    {
        $cfg = config('Database')->default;
        $host = $cfg['hostname'];
        $port = $cfg['port'] ?? 3306;
        $user = $cfg['username'];
        $pass = $cfg['password'];
        $name = $cfg['database'];

        $env  = env('CI_ENVIRONMENT', 'production');
        $rc   = self::retentionClassPure(date('Y-m-d'));
        $gpg  = (bool) env('BACKUP_GPG_ENABLED');
        $file = self::buildFilenamePure($env, date('Y-m-d H:i:s'), $rc, $gpg);

        $localDir = rtrim((string) env('BACKUP_LOCAL_PATH', WRITEPATH . 'backups'), DIRECTORY_SEPARATOR);
        if (! is_dir($localDir)) {
            @mkdir($localDir, 0750, true);
        }
        $localPath = $localDir . DIRECTORY_SEPARATOR . $file;

        // Build mysqldump command — pakai temporary defaults file untuk hindari
        // password leak ke process list (ps aux). Permission 0600.
        $defaultsFile = $localDir . DIRECTORY_SEPARATOR . '.my.cnf.tmp';
        $iniContent   = "[client]\nuser={$user}\npassword={$pass}\nhost={$host}\nport={$port}\n";
        file_put_contents($defaultsFile, $iniContent);
        @chmod($defaultsFile, 0600);

        try {
            $mysqldump = (string) env('BACKUP_MYSQLDUMP_BIN', 'mysqldump');
            $gzip      = (string) env('BACKUP_GZIP_BIN', 'gzip');

            $dumpOpts = '--single-transaction --quick --routines --triggers --events --hex-blob --default-character-set=utf8mb4 --no-tablespaces';

            if ($gpg) {
                $gpgBin = (string) env('BACKUP_GPG_BIN', 'gpg');
                $keyId  = (string) env('BACKUP_GPG_KEYID', '');
                if ($keyId === '') {
                    throw new \RuntimeException('BACKUP_GPG_ENABLED=1 tapi BACKUP_GPG_KEYID kosong');
                }
                $cmd = sprintf(
                    '%s --defaults-extra-file=%s %s %s | %s -9 | %s --batch --yes --trust-model always --encrypt --recipient %s --output %s',
                    escapeshellcmd($mysqldump),
                    escapeshellarg($defaultsFile),
                    $dumpOpts,
                    escapeshellarg($name),
                    escapeshellcmd($gzip),
                    escapeshellcmd($gpgBin),
                    escapeshellarg($keyId),
                    escapeshellarg($localPath)
                );
            } else {
                $cmd = sprintf(
                    '%s --defaults-extra-file=%s %s %s | %s -9 > %s',
                    escapeshellcmd($mysqldump),
                    escapeshellarg($defaultsFile),
                    $dumpOpts,
                    escapeshellarg($name),
                    escapeshellcmd($gzip),
                    escapeshellarg($localPath)
                );
            }

            // 2>&1 supaya stderr ikut ke output (debug)
            $output = [];
            $code   = 0;
            exec($cmd . ' 2>&1', $output, $code);

            if ($code !== 0 || ! file_exists($localPath) || filesize($localPath) === 0) {
                throw new \RuntimeException('mysqldump gagal: code=' . $code . ' output=' . implode("\n", array_slice($output, -10)));
            }

            $size   = filesize($localPath) ?: 0;
            $sha256 = hash_file('sha256', $localPath);

            $finalPath = $localPath;
            $target    = strtoupper((string) env('BACKUP_STORAGE', 'LOCAL'));

            if ($target === 'S3') {
                $finalPath = $this->uploadS3($localPath, $file);
                @unlink($localPath); // hapus lokal setelah upload sukses
            }

            return [
                'storage_path'  => $finalPath,
                'size_bytes'    => $size,
                'sha256'        => $sha256,
                'gpg_encrypted' => $gpg,
            ];
        } finally {
            @unlink($defaultsFile);
        }
    }

    /**
     * Upload file backup ke S3 via AWS CLI (kalau tersedia).
     * Alternatif: pakai SDK AWS PHP — tapi hindari coupling kalau belum dipasang.
     */
    private function uploadS3(string $localPath, string $filename): string
    {
        $bucket = (string) env('BACKUP_S3_BUCKET', '');
        $region = (string) env('BACKUP_S3_REGION', 'ap-southeast-1');
        $prefix = trim((string) env('BACKUP_S3_PREFIX', 'db-backups/'), '/');
        if ($bucket === '') {
            throw new \RuntimeException('BACKUP_STORAGE=S3 tapi BACKUP_S3_BUCKET kosong');
        }

        $key = ($prefix !== '' ? $prefix . '/' : '') . date('Y/m/d') . '/' . $filename;
        $awsBin = (string) env('BACKUP_AWS_BIN', 'aws');

        $cmd = sprintf(
            '%s s3 cp %s s3://%s/%s --region %s --sse AES256',
            escapeshellcmd($awsBin),
            escapeshellarg($localPath),
            escapeshellarg($bucket),
            escapeshellarg($key),
            escapeshellarg($region)
        );

        $output = [];
        $code   = 0;
        exec($cmd . ' 2>&1', $output, $code);

        if ($code !== 0) {
            throw new \RuntimeException('Upload S3 gagal: ' . implode("\n", array_slice($output, -5)));
        }

        return "s3://{$bucket}/{$key}";
    }

    /**
     * Cari backup runs yang sudah expired untuk dihapus (cron janitor).
     *
     * @return array<int, array> baris backup_runs
     */
    public function findExpired(): array
    {
        $db   = \Config\Database::connect();
        $rows = $db->table('backup_runs')
            ->where('status', 'SUCCESS')
            ->orderBy('created_at', 'ASC')
            ->get()
            ->getResultArray();

        $policy = [
            'daily'   => (int) env('BACKUP_RETENTION_DAILY', 7),
            'weekly'  => (int) env('BACKUP_RETENTION_WEEKLY', 4),
            'monthly' => (int) env('BACKUP_RETENTION_MONTHLY', 12),
        ];
        $now = date('Y-m-d H:i:s');

        return array_values(array_filter($rows, static function ($r) use ($now, $policy) {
            return self::isExpiredPure((string) ($r['retention_class'] ?? 'DAILY'), (string) ($r['created_at'] ?? $now), $now, $policy);
        }));
    }
}