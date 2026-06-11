<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * S2.2 — Security scan untuk multi-tenant leak risks.
 *
 * Heuristic static analysis di codebase backend:
 *   1. `db->table('xxx')` di Controller/Service tanpa `where('company_id', …)`
 *   2. `Model::find(*)` tanpa scope (lihat juga `findScoped()` BaseModel)
 *   3. `setTable()` / SQL raw yang lewati BaseModel
 *   4. Endpoint controller tanpa filter JWT (cross-check Routes.php)
 *
 * Output:
 *   - CLI summary (color-coded by severity)
 *   - JSON report di writable/security/tenant-leak-audit.json
 *
 * Usage:
 *   php spark security:scan-tenant-leaks
 *   php spark security:scan-tenant-leaks --strict  # fail (exit 1) kalau ada finding
 *   php spark security:scan-tenant-leaks --json    # only emit JSON path
 *
 * Run di CI sebagai pre-merge check:
 *   - script: php spark security:scan-tenant-leaks --strict
 *   - allowed-baseline: writable/security/tenant-leak-baseline.json (whitelist)
 */
class SecurityScanTenantLeaks extends BaseCommand
{
    protected $group       = 'Security';
    protected $name        = 'security:scan-tenant-leaks';
    protected $description = 'Static scan untuk multi-tenant isolation leaks (S2.2 Roadmap SaaS)';
    protected $usage       = 'security:scan-tenant-leaks [--strict] [--json]';
    protected $options     = [
        '--strict' => 'Exit code 1 kalau ada finding non-baseline (untuk CI gate)',
        '--json'   => 'Output hanya path JSON report (suppress stdout summary)',
    ];

    /**
     * Tabel yang aman tanpa company_id filter — global / cross-tenant
     * intentionally (mis. master tabel, audit pribadi user, system).
     *
     * @var array<int, string>
     */
    private array $globalTables = [
        'companies',
        'subscription_plans',
        'plan_features',
        'feature_flags',
        'company_feature_overrides',
        'subscriptions',
        'subscription_events',
        'system_settings',
        'jobs',
        'failed_jobs',
        'migrations',
        'backup_runs',
        'data_export_requests',
        'sessions',
        'rate_limits',
        'idempotency_keys',
    ];

    /**
     * Direktori yang di-scan.
     */
    private array $targetDirs = [
        'app/Controllers',
        'app/Libraries',
        'app/Models',
    ];

    public function run(array $params)
    {
        $strict   = in_array('--strict', $params, true);
        $jsonOnly = in_array('--json', $params, true);

        $base = ROOTPATH; // backend/

        $findings = [];
        $scanned  = 0;

        foreach ($this->targetDirs as $rel) {
            $dir = $base . $rel;
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $scanned++;
                $findings = array_merge($findings, $this->scanFile($file->getPathname(), $base));
            }
        }

        // Apply baseline (skip findings yang sudah di-whitelist)
        $baselinePath = WRITEPATH . 'security/tenant-leak-baseline.json';
        $baseline = [];
        if (file_exists($baselinePath)) {
            $baseline = json_decode((string) file_get_contents($baselinePath), true) ?: [];
        }
        $newFindings = $this->filterBaseline($findings, $baseline);

        // Tulis report JSON
        $reportDir = WRITEPATH . 'security';
        if (! is_dir($reportDir)) {
            @mkdir($reportDir, 0750, true);
        }
        $reportPath = $reportDir . '/tenant-leak-audit.json';
        $report = [
            'scanned_at'        => date('Y-m-d H:i:s'),
            'files_scanned'     => $scanned,
            'total_findings'    => count($findings),
            'baseline_skipped'  => count($findings) - count($newFindings),
            'new_findings'      => count($newFindings),
            'findings_by_rule'  => $this->groupByRule($findings),
            'new'               => $newFindings,
            'all'               => $findings,
        ];
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($jsonOnly) {
            CLI::write($reportPath);
            if ($strict && count($newFindings) > 0) {
                exit(1);
            }
            return;
        }

        // Pretty CLI summary
        CLI::write('');
        CLI::write('=== Tenant Isolation Scan Report ===', 'cyan');
        CLI::write('Files scanned        : ' . $scanned, 'white');
        CLI::write('Total findings       : ' . count($findings), 'white');
        CLI::write('Baseline-skipped     : ' . (count($findings) - count($newFindings)), 'yellow');
        CLI::write('New findings         : ' . count($newFindings),
            count($newFindings) > 0 ? 'red' : 'green');
        CLI::write('Report file          : ' . $reportPath, 'white');
        CLI::write('');

        if (! empty($report['findings_by_rule'])) {
            CLI::write('Findings by rule:', 'cyan');
            foreach ($report['findings_by_rule'] as $rule => $count) {
                CLI::write("  • {$rule}: {$count}", 'white');
            }
            CLI::write('');
        }

        // Tampilkan top 20 finding terbaru saja
        if (! empty($newFindings)) {
            CLI::write('Top new findings (first 20):', 'red');
            foreach (array_slice($newFindings, 0, 20) as $f) {
                CLI::write(sprintf(
                    '  [%s] %s:%d  %s  → %s',
                    $f['severity'],
                    $f['file'],
                    $f['line'],
                    $f['rule'],
                    $f['snippet']
                ), $f['severity'] === 'HIGH' ? 'red' : 'yellow');
            }
            if (count($newFindings) > 20) {
                CLI::write('  … and ' . (count($newFindings) - 20) . ' more (see JSON report)', 'yellow');
            }
            CLI::write('');
        }

        if ($strict && count($newFindings) > 0) {
            CLI::error('Strict mode: scan found ' . count($newFindings) . ' new tenant-leak risk(s).');
            CLI::write('Tip: review report, fix code, atau tambahkan ke baseline kalau false-positive:', 'yellow');
            CLI::write("  cp {$reportPath} {$baselinePath}", 'yellow');
            exit(1);
        }
    }

    /**
     * Scan a single PHP file untuk pattern berbahaya.
     */
    private function scanFile(string $path, string $base): array
    {
        $relPath = ltrim(str_replace(['\\', $base], ['/', ''], $path), '/');
        $code = (string) file_get_contents($path);
        if ($code === '') {
            return [];
        }
        $lines = explode("\n", $code);
        $found = [];

        // Rule 1: db->table('xxx') tanpa where company_id di method scope
        // Strategy: cari `->table('` lalu cek 8 baris setelahnya untuk
        // 'company_id' atau 'forTenant' atau 'scoped()' atau 'where('
        foreach ($lines as $i => $line) {
            $lineNo = $i + 1;

            // Skip comments
            $trim = ltrim($line);
            if (str_starts_with($trim, '//') || str_starts_with($trim, '*') || str_starts_with($trim, '/*')) {
                continue;
            }

            // Pattern: ->table('xxx')
            if (preg_match("/->table\(\s*['\"]([a-z_]+)['\"]\s*\)/i", $line, $m)) {
                $tableName = $m[1];
                if (in_array($tableName, $this->globalTables, true)) {
                    continue;
                }
                // Cek dalam 12 line berikutnya: ada company_id, scoped(), forTenant(), atau scope filter
                $window = implode("\n", array_slice($lines, $i, 12));
                if (! $this->hasTenantGuard($window)) {
                    $found[] = [
                        'rule'     => 'TABLE_NO_COMPANY_FILTER',
                        'severity' => 'HIGH',
                        'file'     => $relPath,
                        'line'     => $lineNo,
                        'table'    => $tableName,
                        'snippet'  => trim($line),
                    ];
                }
            }

            // Rule 2: $db->query("SELECT ... FROM xxx") raw tanpa company_id
            if (preg_match("/->query\(\s*['\"](SELECT[^'\"]+)['\"]/i", $line, $m)) {
                $sql = strtolower($m[1]);
                if (! str_contains($sql, 'company_id')
                    && ! preg_match('/from\s+(' . implode('|', $this->globalTables) . ')/', $sql)) {
                    $found[] = [
                        'rule'     => 'RAW_QUERY_NO_COMPANY_FILTER',
                        'severity' => 'HIGH',
                        'file'     => $relPath,
                        'line'     => $lineNo,
                        'snippet'  => trim($line),
                    ];
                }
            }

            // Rule 3: Model->find($id) tanpa pakai findScoped
            // Hanya warning di Controller (untuk Service biasanya internal call)
            if (str_contains($relPath, 'app/Controllers/')) {
                if (preg_match('/->find\(\s*\$[a-z_]+\s*\)/i', $line)
                    && ! preg_match('/findScoped|findChild|findInBatch/', $line)) {
                    // Skip kalau di line yang sama ada $tenantContext / scope check
                    $window = implode("\n", array_slice($lines, max(0, $i - 3), 6));
                    if (! preg_match('/findScoped|forTenant|company_id|scoped\(/', $window)) {
                        $found[] = [
                            'rule'     => 'MODEL_FIND_NO_SCOPE',
                            'severity' => 'MEDIUM',
                            'file'     => $relPath,
                            'line'     => $lineNo,
                            'snippet'  => trim($line),
                        ];
                    }
                }
            }

            // Rule 4: setTable() — bypass model table; suspicious
            if (preg_match('/->setTable\(/', $line)) {
                $found[] = [
                    'rule'     => 'MODEL_SET_TABLE_DYNAMIC',
                    'severity' => 'LOW',
                    'file'     => $relPath,
                    'line'     => $lineNo,
                    'snippet'  => trim($line),
                ];
            }
        }

        return $found;
    }

    /**
     * Cek apakah window code 12-line punya guard tenant (company_id /
     * scoped()/ forTenant() / hardcoded ID).
     */
    private function hasTenantGuard(string $window): bool
    {
        return (bool) preg_match(
            "/(company_id|->scoped\(|->forTenant\(|requireCompanyId|TenantContext|company_column|getCompanyId|tenant\(\)|\\\$companyId|\\\$tenantId|isSuperAdmin)/i",
            $window
        );
    }

    /**
     * Filter findings yang ada di baseline.
     */
    private function filterBaseline(array $findings, array $baseline): array
    {
        if (empty($baseline['all'])) {
            return $findings;
        }
        $baselineKeys = array_map([$this, 'fingerprint'], $baseline['all']);
        return array_values(array_filter($findings, function ($f) use ($baselineKeys) {
            return ! in_array($this->fingerprint($f), $baselineKeys, true);
        }));
    }

    private function fingerprint(array $f): string
    {
        return ($f['rule'] ?? '') . '|' . ($f['file'] ?? '') . '|' . ($f['line'] ?? 0) . '|' . md5($f['snippet'] ?? '');
    }

    private function groupByRule(array $findings): array
    {
        $out = [];
        foreach ($findings as $f) {
            $r = $f['rule'] ?? 'UNKNOWN';
            $out[$r] = ($out[$r] ?? 0) + 1;
        }
        arsort($out);
        return $out;
    }
}