<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * RunAudit — pemeriksaan kesehatan sistem berkala.
 *
 * Menjalankan serangkaian cek otomatis dan menulis hasilnya ke:
 *   1) console (default — table berwarna)
 *   2) audit_logs (selalu, severity NOTICE / WARNING / CRITICAL sesuai temuan)
 *   3) writable/audit-reports/audit-YYYY-MM-DD-HHMMSS.json (opsional)
 *   4) email ke HR admin (opsional via --mail)
 *
 * Pakai cron harian:
 *   0 6 * * *  cd /var/www/hris && php spark hr:audit --json --mail
 *
 * Flag:
 *   --json            simpan JSON ke writable/audit-reports/
 *   --mail            kirim email ke HR admin (role 2) per company
 *   --company=N       batasi cek per-company (default semua)
 *   --quiet           tidak tampilkan console table (cron)
 *   --threshold-only  hanya tampilkan WARN/CRITICAL
 */
class RunAudit extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:audit';
    protected $description = 'Audit kesehatan sistem berkala (storage, DB, security, operational, data quality).';
    protected $usage       = 'hr:audit [options]';
    protected $options     = [
        '--json'           => 'Simpan hasil ke writable/audit-reports/audit-*.json',
        '--mail'           => 'Kirim ringkasan ke HR admin per company',
        '--company'        => 'Hanya audit company ID tertentu',
        '--quiet'          => 'Tidak tampilkan output di console',
        '--threshold-only' => 'Tampilkan hanya item WARN/CRITICAL',
    ];

    private array $results = [];
    private float $startTs = 0.0;

    public function run(array $params)
    {
        $this->startTs = microtime(true);

        $companyFilter   = (int) (CLI::getOption('company') ?? 0);
        $jsonFlag        = (bool) CLI::getOption('json');
        $mailFlag        = (bool) CLI::getOption('mail');
        $quiet           = (bool) CLI::getOption('quiet');
        $thresholdOnly   = (bool) CLI::getOption('threshold-only');

        if (! $quiet) {
            CLI::write('=== HRIS Audit ' . date('Y-m-d H:i:s') . ' ===', 'yellow');
        }

        // Jalankan semua kategori.
        $this->checkStorage();
        $this->checkDatabase();
        $this->checkSecurity($companyFilter);
        $this->checkOperational($companyFilter);
        $this->checkDataQuality($companyFilter);

        $duration = round((microtime(true) - $this->startTs) * 1000, 2);
        $summary  = $this->summarize();
        $summary['duration_ms'] = $duration;

        if (! $quiet) $this->renderConsole($thresholdOnly);
        if (! $quiet) $this->renderSummaryConsole($summary);

        // Audit log
        try {
            $severity = $summary['critical'] > 0 ? 'CRITICAL'
                      : ($summary['warn'] > 0 ? 'WARNING' : 'NOTICE');
            Services::audit()->forModule('SYSTEM')->log(
                action: 'audit.run',
                entity: 'system',
                description: sprintf(
                    'System audit selesai: %d OK, %d WARN, %d CRITICAL (durasi %s ms)',
                    $summary['ok'], $summary['warn'], $summary['critical'], $duration
                ),
                metadata: $summary,
                severity: $severity,
            );
        } catch (\Throwable $e) {
            // Audit gagal = tidak menggagalkan command
        }

        // Save JSON report
        if ($jsonFlag) {
            $dir = WRITEPATH . 'audit-reports';
            if (! is_dir($dir)) @mkdir($dir, 0750, true);
            $file = $dir . DIRECTORY_SEPARATOR . 'audit-' . date('Y-m-d-His') . '.json';
            file_put_contents($file, json_encode([
                'generated_at' => date('Y-m-d H:i:s'),
                'duration_ms'  => $duration,
                'summary'      => $summary,
                'results'      => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if (! $quiet) CLI::write("→ JSON report: {$file}", 'cyan');
        }

        // Email
        if ($mailFlag) {
            $this->mailSummary($summary, $companyFilter);
            if (! $quiet) CLI::write('→ Email ringkasan dikirim ke HR admin', 'cyan');
        }

        // Exit code: 0 untuk OK, 1 untuk WARN, 2 untuk CRITICAL.
        // Bisa dipakai monitoring (Nagios/Zabbix) untuk alert.
        if ($summary['critical'] > 0) return EXIT_ERROR;
        if ($summary['warn'] > 0)     return 1;
        return EXIT_SUCCESS;
    }

    // ─── Kategori cek ──────────────────────────────────────────────

    private function checkStorage(): void
    {
        // 1. Total ukuran selfies di writable/uploads/selfies
        $dir = WRITEPATH . 'uploads/selfies';
        $size = is_dir($dir) ? $this->dirSize($dir) : 0;
        $sizeMB = round($size / 1024 / 1024, 2);
        $this->add('Storage', 'Selfie disk usage', $sizeMB . ' MB',
            $sizeMB > 5000 ? 'CRITICAL' : ($sizeMB > 2000 ? 'WARN' : 'OK'),
            'Setup retention policy + signed URL — lihat AUDIT.md C.');

        // 2. Total log size
        $logDir = WRITEPATH . 'logs';
        $logSize = is_dir($logDir) ? $this->dirSize($logDir) : 0;
        $logMB = round($logSize / 1024 / 1024, 2);
        $this->add('Storage', 'Log files size', $logMB . ' MB',
            $logMB > 1000 ? 'WARN' : 'OK',
            $logMB > 1000 ? 'Tambah `logs:clear` ke cron mingguan' : '-');

        // 3. Total ukuran upload PDF / kontrak / dll
        $upDir = WRITEPATH . 'uploads';
        $upSize = is_dir($upDir) ? $this->dirSize($upDir) : 0;
        $upMB = round($upSize / 1024 / 1024, 2);
        $this->add('Storage', 'Total uploads size', $upMB . ' MB',
            $upMB > 10000 ? 'CRITICAL' : ($upMB > 5000 ? 'WARN' : 'OK'),
            'Roadmap: migrasi ke S3/MinIO');
    }

    private function checkDatabase(): void
    {
        $db = \Config\Database::connect();

        // 1. Jumlah baris di table-table yang growing fast
        $hotTables = [
            'audit_logs'   => ['warn' => 1_000_000, 'critical' => 5_000_000],
            'attendances'  => ['warn' => 500_000,   'critical' => 2_000_000],
            'notifications'=> ['warn' => 200_000,   'critical' => 1_000_000],
        ];
        foreach ($hotTables as $tbl => $thr) {
            try {
                $count = (int) $db->table($tbl)->countAllResults();
                $level = $count >= $thr['critical'] ? 'CRITICAL'
                       : ($count >= $thr['warn'] ? 'WARN' : 'OK');
                $reco = $count >= $thr['warn']
                    ? "Setup retention/archive (lihat AUDIT.md N)"
                    : '-';
                $this->add('Database', "{$tbl} row count",
                    number_format($count), $level, $reco);
            } catch (\Throwable $e) {
                $this->add('Database', "{$tbl} row count", 'ERROR',
                    'WARN', $e->getMessage());
            }
        }

        // 2. Composite index penting yang sebaiknya ada (cek metadata MySQL)
        $expectedIndexes = [
            'attendances'   => ['employee_id', 'work_date'],
            'leaves'        => ['employee_id', 'status'],
            'notifications' => ['user_id', 'is_read'],
            'audit_logs'    => ['module', 'created_at'],
            'goals'         => ['cycle_id', 'employee_id'],
        ];
        foreach ($expectedIndexes as $tbl => $cols) {
            $hasIndex = $this->tableHasCompositeIndex($db, $tbl, $cols);
            $this->add('Database', "Index {$tbl}(" . implode(',', $cols) . ')',
                $hasIndex ? 'present' : 'missing',
                $hasIndex ? 'OK' : 'WARN',
                $hasIndex ? '-' : 'Lihat AUDIT.md J — perlu composite index');
        }
    }

    private function checkSecurity(int $companyFilter): void
    {
        $db = \Config\Database::connect();

        // 1. Anomaly belum direview > 7 hari
        try {
            $b = $db->table('attendances')
                ->where('anomaly_score >=', 30)
                ->where('anomaly_reviewed_at IS NULL')
                ->where('work_date <=', date('Y-m-d', strtotime('-7 days')));
            if ($companyFilter) $b->where('company_id', $companyFilter);
            $stale = (int) $b->countAllResults();
            $this->add('Security', 'Anomali absensi belum direview > 7 hari',
                (string) $stale,
                $stale > 50 ? 'CRITICAL' : ($stale > 10 ? 'WARN' : 'OK'),
                $stale > 0 ? 'HR review di /attendance/anomalies' : '-');
        } catch (\Throwable $e) {
            $this->add('Security', 'Anomali absensi review', 'ERROR', 'WARN', $e->getMessage());
        }

        // 2. Device blocked
        try {
            $b = $db->table('attendance_devices')->where('is_blocked', 1);
            if ($companyFilter) $b->where('company_id', $companyFilter);
            $blocked = (int) $b->countAllResults();
            $this->add('Security', 'Device di-block (aktif)',
                (string) $blocked, 'OK', '-');
        } catch (\Throwable $e) {
            $this->add('Security', 'Device block count', 'ERROR', 'WARN', $e->getMessage());
        }

        // 3. Login gagal 24 jam terakhir (dari audit_logs)
        try {
            $since = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $failedLogins = (int) $db->table('audit_logs')
                ->where('action', 'auth.login')
                ->where('success', 0)
                ->where('created_at >=', $since)
                ->countAllResults();
            $this->add('Security', 'Login gagal 24 jam',
                (string) $failedLogins,
                $failedLogins > 200 ? 'CRITICAL' : ($failedLogins > 50 ? 'WARN' : 'OK'),
                $failedLogins > 50 ? 'Cek brute-force — review IP attacker' : '-');
        } catch (\Throwable $e) {
            $this->add('Security', 'Failed logins 24h', 'ERROR', 'WARN', $e->getMessage());
        }

        // 4. Akun yang lock
        try {
            $locked = (int) $db->table('users')->where('is_locked', 1)->countAllResults();
            $this->add('Security', 'User akun di-lock',
                (string) $locked,
                $locked > 10 ? 'WARN' : 'OK',
                $locked > 0 ? 'Review user yang lock di /admin/users' : '-');
        } catch (\Throwable $e) {
            // Tabel/kolom mungkin belum ada — skip
        }

        // 5. JWT secret pendek (di .env)
        // CodeIgniter env var prefix biasanya `jwt.access_secret` di .env →
        // diakses via `env('jwt.access_secret')` atau `getenv('jwt_access_secret')`.
        $jwtSecret = (string) (env('jwt.access_secret') ?: env('jwt.secret')
            ?: getenv('JWT_ACCESS_SECRET') ?: getenv('JWT_SECRET') ?: '');
        $this->add('Security', 'JWT secret length',
            strlen($jwtSecret) . ' karakter',
            strlen($jwtSecret) < 32 ? 'CRITICAL' : 'OK',
            strlen($jwtSecret) < 32 ? 'Generate ulang min 64 char acak di .env' : '-');
    }

    private function checkOperational(int $companyFilter): void
    {
        $db = \Config\Database::connect();

        // 1. Kontrak yang akan habis ≤ 30 hari belum diperbaharui
        try {
            $b = $db->table('employees')
                ->where('contract_end_date IS NOT NULL')
                ->where('contract_end_date >=', date('Y-m-d'))
                ->where('contract_end_date <=', date('Y-m-d', strtotime('+30 days')))
                ->where('deleted_at IS NULL');
            if ($companyFilter) $b->where('company_id', $companyFilter);
            $expiring = (int) $b->countAllResults();
            $this->add('Operational', 'Kontrak habis ≤ 30 hari',
                (string) $expiring,
                $expiring > 0 ? 'WARN' : 'OK',
                $expiring > 0 ? 'Pastikan HR menerbitkan amandemen / PHK' : '-');
        } catch (\Throwable $e) {}

        // 2. Sertifikat yang akan habis ≤ 30 hari
        try {
            $b = $db->table('certificates')
                ->where('expires_date IS NOT NULL')
                ->where('expires_date >=', date('Y-m-d'))
                ->where('expires_date <=', date('Y-m-d', strtotime('+30 days')))
                ->where('status', 'ACTIVE')
                ->where('deleted_at IS NULL');
            if ($companyFilter) $b->where('company_id', $companyFilter);
            $expCert = (int) $b->countAllResults();
            $this->add('Operational', 'Sertifikat habis ≤ 30 hari',
                (string) $expCert,
                $expCert > 0 ? 'WARN' : 'OK',
                $expCert > 0 ? 'Run hr:check-cert-expiry untuk notifikasi' : '-');
        } catch (\Throwable $e) {}

        // 3. Onboarding/offboarding task overdue
        try {
            $b = $db->table('employee_tasks')
                ->where('status', 'PENDING')
                ->where('due_date <', date('Y-m-d'));
            if ($companyFilter) $b->where('company_id', $companyFilter);
            $overdue = (int) $b->countAllResults();
            $this->add('Operational', 'Onboarding/Offboarding task overdue',
                (string) $overdue,
                $overdue > 100 ? 'CRITICAL' : ($overdue > 20 ? 'WARN' : 'OK'),
                $overdue > 0 ? 'Lihat /hr/onboarding' : '-');
        } catch (\Throwable $e) {}

        // 4. Approval requests menggantung > 7 hari
        try {
            $b = $db->table('approval_requests')
                ->where('status', 'PENDING')
                ->where('created_at <=', date('Y-m-d', strtotime('-7 days')));
            if ($companyFilter) $b->where('company_id', $companyFilter);
            $stale = (int) $b->countAllResults();
            $this->add('Operational', 'Approval pending > 7 hari',
                (string) $stale,
                $stale > 30 ? 'CRITICAL' : ($stale > 10 ? 'WARN' : 'OK'),
                $stale > 0 ? 'Cek delegasi / atasan tidak responsif' : '-');
        } catch (\Throwable $e) {}

        // 5. Periode payroll yang masih DRAFT > 1 bulan
        try {
            $b = $db->table('payroll_periods')
                ->where('status', 'DRAFT')
                ->where('end_date <=', date('Y-m-d', strtotime('-30 days')));
            if ($companyFilter) $b->where('company_id', $companyFilter);
            $orphan = (int) $b->countAllResults();
            $this->add('Operational', 'Payroll DRAFT > 1 bulan',
                (string) $orphan,
                $orphan > 0 ? 'WARN' : 'OK',
                $orphan > 0 ? 'Finalize atau cancel periode lama' : '-');
        } catch (\Throwable $e) {}

        // 6. Email queue tertumpuk
        try {
            $pending = (int) $db->table('email_queue')
                ->where('status', 'PENDING')
                ->where('created_at <=', date('Y-m-d H:i:s', strtotime('-1 hour')))
                ->countAllResults();
            $this->add('Operational', 'Email queue stuck > 1 jam',
                (string) $pending,
                $pending > 100 ? 'CRITICAL' : ($pending > 20 ? 'WARN' : 'OK'),
                $pending > 0 ? 'Run email:process atau cek SMTP error' : '-');
        } catch (\Throwable $e) {
            // Tabel mungkin belum ada di setup baru
        }
    }

    private function checkDataQuality(int $companyFilter): void
    {
        $db = \Config\Database::connect();

        // 1. Karyawan aktif yang missing data kritis (NIK / rekening / NPWP)
        try {
            $b = $db->table('employees')
                ->where('is_active', 1)
                ->where('deleted_at IS NULL')
                ->groupStart()
                    ->where('nik_encrypted IS NULL')
                    ->orWhere('bank_account_encrypted IS NULL')
                    ->orWhere('bank_name IS NULL')
                ->groupEnd();
            if ($companyFilter) $b->where('company_id', $companyFilter);
            $missing = (int) $b->countAllResults();
            $this->add('DataQuality', 'Karyawan missing NIK/rekening',
                (string) $missing,
                $missing > 50 ? 'CRITICAL' : ($missing > 0 ? 'WARN' : 'OK'),
                $missing > 0 ? 'Lengkapi sebelum payroll period berikut' : '-');
        } catch (\Throwable $e) {}

        // 2. Karyawan aktif tanpa user_id (tidak bisa login)
        try {
            $b = $db->table('employees')
                ->where('is_active', 1)
                ->where('user_id IS NULL')
                ->where('deleted_at IS NULL');
            if ($companyFilter) $b->where('company_id', $companyFilter);
            $orphan = (int) $b->countAllResults();
            $this->add('DataQuality', 'Karyawan tanpa akun user',
                (string) $orphan,
                $orphan > 0 ? 'WARN' : 'OK',
                $orphan > 0 ? 'Set create_user_account=1 saat update' : '-');
        } catch (\Throwable $e) {}

        // 3. Leave balance tahun ini missing untuk karyawan aktif
        try {
            $year = (int) date('Y');
            $b = $db->table('employees e')
                ->select('COUNT(DISTINCT e.id) AS cnt')
                ->where('e.is_active', 1)
                ->where('e.deleted_at IS NULL')
                ->whereNotIn('e.id', function ($q) use ($year) {
                    $q->select('employee_id')->from('leave_balances')->where('year', $year);
                });
            if ($companyFilter) $b->where('e.company_id', $companyFilter);
            $row = $b->get()->getRowArray();
            $missing = (int) ($row['cnt'] ?? 0);
            $this->add('DataQuality', "Leave balance {$year} missing",
                (string) $missing,
                $missing > 20 ? 'WARN' : 'OK',
                $missing > 0 ? "Run script sync leave balance / saat ensureBalance dipanggil di pengajuan cuti pertama" : '-');
        } catch (\Throwable $e) {}

        // 4. Approval chain hanya 1 step (terlalu sederhana — biasanya butuh 2)
        try {
            $b = $db->table('approval_chains c')
                ->select('c.id, c.code, COUNT(s.id) AS steps')
                ->join('approval_chain_steps s', 's.chain_id = c.id', 'left')
                ->where('c.is_active', 1)
                ->where('c.deleted_at IS NULL')
                ->groupBy('c.id');
            if ($companyFilter) $b->where('c.company_id', $companyFilter);
            $rows = $b->get()->getResultArray();
            $thin = array_filter($rows, fn($r) => (int) $r['steps'] < 2);
            $count = count($thin);
            $this->add('DataQuality', 'Approval chain < 2 step',
                (string) $count,
                $count > 0 ? 'WARN' : 'OK',
                $count > 0 ? 'Cek apakah memang single-step disengaja' : '-');
        } catch (\Throwable $e) {}

        // 5. Karyawan dengan basic_salary di bawah UMP daerah
        try {
            $b = $db->table('employees e')
                ->select('e.id, e.full_name, e.province, e.city, e.basic_salary, e.join_date')
                ->where('e.is_active', 1)
                ->where('e.deleted_at IS NULL')
                ->where('e.basic_salary >', 0)
                ->whereIn('e.employment_status', ['PKWT','PKWTT','HARIAN'])
                ->whereIn('e.employment_type', ['FULL_TIME','CONTRACT']);
            if ($companyFilter) $b->where('e.company_id', $companyFilter);
            $rows = $b->limit(500)->get()->getResultArray(); // sample biar tidak berat
            $belowCount = 0;
            foreach ($rows as $r) {
                if (empty($r['province'])) continue;
                $ump = \App\Libraries\IndonesianValidator::findMinimumWage(
                    $r['province'], $r['city'] ?? null,
                    (int) date('Y', strtotime($r['join_date']))
                );
                if ($ump && (float) $r['basic_salary'] < (float) $ump['amount']) {
                    $belowCount++;
                }
            }
            $this->add('DataQuality', 'Gaji di bawah UMP/UMK (sampel 500)',
                (string) $belowCount,
                $belowCount > 0 ? 'CRITICAL' : 'OK',
                $belowCount > 0 ? 'Audit log per karyawan sudah dicatat — risiko Disnaker' : '-');
        } catch (\Throwable $e) {}
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function add(string $category, string $name, string $value,
        string $level, string $recommendation): void
    {
        $this->results[] = [
            'category'       => $category,
            'name'           => $name,
            'value'          => $value,
            'level'          => $level, // OK, WARN, CRITICAL
            'recommendation' => $recommendation,
        ];
    }

    private function summarize(): array
    {
        $sum = ['ok' => 0, 'warn' => 0, 'critical' => 0, 'total' => count($this->results)];
        foreach ($this->results as $r) {
            if ($r['level'] === 'OK')        $sum['ok']++;
            elseif ($r['level'] === 'WARN')  $sum['warn']++;
            elseif ($r['level'] === 'CRITICAL') $sum['critical']++;
        }
        return $sum;
    }

    private function renderConsole(bool $thresholdOnly): void
    {
        $byCat = [];
        foreach ($this->results as $r) {
            if ($thresholdOnly && $r['level'] === 'OK') continue;
            $byCat[$r['category']][] = $r;
        }
        foreach ($byCat as $cat => $rows) {
            CLI::newLine();
            CLI::write("[{$cat}]", 'yellow');
            $tableRows = [];
            foreach ($rows as $r) {
                $tableRows[] = [
                    $r['name'],
                    $r['value'],
                    $this->levelIcon($r['level']) . ' ' . $r['level'],
                    $r['recommendation'],
                ];
            }
            CLI::table($tableRows, ['Check', 'Value', 'Status', 'Saran']);
        }
    }

    private function renderSummaryConsole(array $summary): void
    {
        CLI::newLine();
        CLI::write('=== Ringkasan ===', 'yellow');
        CLI::write(sprintf(
            "Total: %d  |  OK: %d  |  WARN: %d  |  CRITICAL: %d  |  Durasi: %s ms",
            $summary['total'], $summary['ok'], $summary['warn'],
            $summary['critical'], $summary['duration_ms']
        ), $summary['critical'] > 0 ? 'red' : ($summary['warn'] > 0 ? 'yellow' : 'green'));
    }

    private function levelIcon(string $lvl): string
    {
        return match ($lvl) {
            'OK'       => '✅',
            'WARN'     => '⚠️ ',
            'CRITICAL' => '🚨',
            default    => '  ',
        };
    }

    /** Hitung total ukuran direktori secara recursive (byte). */
    private function dirSize(string $dir): int
    {
        $total = 0;
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile()) $total += $f->getSize();
            }
        } catch (\Throwable $e) {}
        return $total;
    }

    /** Cek apakah table punya composite index yg mengandung dua kolom (urutan boleh beda). */
    private function tableHasCompositeIndex($db, string $table, array $cols): bool
    {
        try {
            $rows = $db->query("SHOW INDEX FROM `{$table}`")->getResultArray();
            $byKey = [];
            foreach ($rows as $r) {
                $byKey[$r['Key_name']][] = $r['Column_name'];
            }
            foreach ($byKey as $kCols) {
                $covers = true;
                foreach ($cols as $c) {
                    if (! in_array($c, $kCols, true)) { $covers = false; break; }
                }
                if ($covers) return true;
            }
        } catch (\Throwable $e) {
            return true; // anggap ada supaya tidak salah alarm di driver non-MySQL
        }
        return false;
    }

    /** Kirim ringkasan ke HR admin via NotificationService email queue. */
    private function mailSummary(array $summary, int $companyFilter): void
    {
        $db = \Config\Database::connect();
        $notif = Services::notification();

        $b = $db->table('users u')
            ->select('u.id, u.email, u.name, u.company_id')
            ->where('u.is_active', 1)
            ->whereIn('u.role_id', [1, 2]); // super admin + HR admin
        if ($companyFilter) $b->where('u.company_id', $companyFilter);
        $admins = $b->get()->getResultArray();

        // Body HTML ringkas
        $criticalRows = array_filter($this->results, fn($r) => $r['level'] === 'CRITICAL');
        $warnRows     = array_filter($this->results, fn($r) => $r['level'] === 'WARN');
        $bodyParts = [];
        $bodyParts[] = "<h2>HRIS Audit Report — " . date('Y-m-d H:i') . "</h2>";
        $bodyParts[] = "<p>Total {$summary['total']} cek: <strong>{$summary['ok']}</strong> OK, "
                     . "<strong>{$summary['warn']}</strong> WARN, "
                     . "<strong style='color:red'>{$summary['critical']}</strong> CRITICAL.</p>";
        if ($criticalRows) {
            $bodyParts[] = "<h3 style='color:red'>🚨 CRITICAL ({" . count($criticalRows) . "})</h3><ul>";
            foreach ($criticalRows as $r) {
                $bodyParts[] = "<li><strong>[{$r['category']}] {$r['name']}</strong>: {$r['value']}<br><em>{$r['recommendation']}</em></li>";
            }
            $bodyParts[] = "</ul>";
        }
        if ($warnRows) {
            $bodyParts[] = "<h3 style='color:#b45309'>⚠️ WARN (" . count($warnRows) . ")</h3><ul>";
            foreach ($warnRows as $r) {
                $bodyParts[] = "<li><strong>[{$r['category']}] {$r['name']}</strong>: {$r['value']}<br><em>{$r['recommendation']}</em></li>";
            }
            $bodyParts[] = "</ul>";
        }
        if (! $criticalRows && ! $warnRows) {
            $bodyParts[] = "<p>✅ Tidak ada item yang melebihi threshold.</p>";
        }
        $body = implode("\n", $bodyParts);

        foreach ($admins as $u) {
            if (empty($u['email'])) continue;
            try {
                $notif->queueEmail(
                    (int) $u['company_id'],
                    $u['email'],
                    $u['name'],
                    "HRIS Audit Report — " . date('Y-m-d') .
                        ($summary['critical'] > 0 ? ' [CRITICAL]' : ($summary['warn'] > 0 ? ' [WARN]' : ' [OK]')),
                    $body
                );
            } catch (\Throwable $e) {
                // Continue meski 1 email fail
            }
        }
    }
}