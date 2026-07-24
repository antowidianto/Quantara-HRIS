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
 *   - reviewed baseline: security/tenant-leak-baseline.json (version controlled)
 */
class SecurityScanTenantLeaks extends BaseCommand
{
    protected $group = 'Security';
    protected $name = 'security:scan-tenant-leaks';
    protected $description = 'Static scan untuk multi-tenant isolation leaks (S2.2 Roadmap SaaS)';
    protected $usage = 'security:scan-tenant-leaks [--strict] [--json] [--update-baseline]';
    protected $options = [
        '--strict' => 'Exit code 1 kalau ada finding non-baseline (untuk CI gate)',
        '--json' => 'Output hanya path JSON report (suppress stdout summary)',
        '--update-baseline' => 'Replace the version-controlled baseline with current findings',
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
        'business_job_runs',
    ];

    /**
     * Direktori yang di-scan.
     */
    private array $targetDirs = [
        'app/Controllers',
        'app/Libraries',
        'app/Models',
        'app/Repositories',
    ];

    public function run(array $params)
    {
        $strict = $this->optionEnabled('strict', $params);
        $jsonOnly = $this->optionEnabled('json', $params);
        $updateBaseline = $this->optionEnabled('update-baseline', $params);

        $base = ROOTPATH; // backend/

        $findings = [];
        $scanned = 0;

        foreach ($this->targetDirs as $rel) {
            $dir = $base . $rel;
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $scanned++;
                $findings = array_merge($findings, $this->scanFile($file->getPathname(), $base));
            }
        }

        // Apply baseline (skip findings yang sudah di-whitelist)
        $baselinePath = ROOTPATH . 'security/tenant-leak-baseline.json';
        if ($updateBaseline) {
            $baselineDir = dirname($baselinePath);
            if (!is_dir($baselineDir)) {
                mkdir($baselineDir, 0750, true);
            }
            file_put_contents($baselinePath, json_encode([
                'generated_at' => date(DATE_ATOM),
                'purpose' => 'Acknowledged legacy findings; strict CI rejects any net-new occurrence.',
                'fingerprints' => $this->fingerprintCounts($findings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }
        $baseline = [];
        if (file_exists($baselinePath)) {
            $baseline = json_decode((string) file_get_contents($baselinePath), true) ?: [];
        }
        $newFindings = $this->filterBaseline($findings, $baseline);

        // Tulis report JSON
        $reportDir = WRITEPATH . 'security';
        if (!is_dir($reportDir)) {
            @mkdir($reportDir, 0750, true);
        }
        $reportPath = $reportDir . '/tenant-leak-audit.json';
        $report = [
            'scanned_at' => date('Y-m-d H:i:s'),
            'files_scanned' => $scanned,
            'total_findings' => count($findings),
            'baseline_skipped' => count($findings) - count($newFindings),
            'new_findings' => count($newFindings),
            'findings_by_rule' => $this->groupByRule($findings),
            'new' => $newFindings,
            'all' => $findings,
        ];
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (\Config\Database::connect()->tableExists('business_job_runs')) {
            $monitor = new \App\Libraries\System\BusinessJobMonitor();
            $runId = $monitor->start('security.tenant_leak_scan', null, null, ['files_scanned' => $scanned]);
            if ($newFindings === []) {
                $monitor->succeed($runId, $scanned, ['findings' => count($findings), 'new' => 0]);
            } else {
                $monitor->fail($runId, 'TENANT_LEAK_FINDINGS', count($newFindings), ['findings' => count($findings), 'new' => count($newFindings)]);
            }
        }

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
        CLI::write(
            'New findings         : ' . count($newFindings),
            count($newFindings) > 0 ? 'red' : 'green'
        );
        CLI::write('Report file          : ' . $reportPath, 'white');
        CLI::write('Baseline file        : ' . $baselinePath, 'white');
        CLI::write('');

        if (!empty($report['findings_by_rule'])) {
            CLI::write('Findings by rule:', 'cyan');
            foreach ($report['findings_by_rule'] as $rule => $count) {
                CLI::write("  • {$rule}: {$count}", 'white');
            }
            CLI::write('');
        }

        // Tampilkan top 20 finding terbaru saja
        if (!empty($newFindings)) {
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
            CLI::write('  php spark security:scan-tenant-leaks --update-baseline', 'yellow');
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
        $context = '(file)';
        $functionStart = 0;

        // Rule 1: db->table('xxx') tanpa where company_id di method scope
        // Strategy: cari `->table('` lalu cek 8 baris setelahnya untuk
        // 'company_id' atau 'forTenant' atau 'scoped()' atau 'where('
        foreach ($lines as $i => $line) {
            $lineNo = $i + 1;
            if (preg_match('/\bfunction\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $line, $functionMatch)) {
                $context = $functionMatch[1];
                $functionStart = $i;
            }

            // Skip comments
            $trim = ltrim($line);
            if (str_starts_with($trim, '//') || str_starts_with($trim, '*') || str_starts_with($trim, '/*')) {
                continue;
            }

            // A suppression is allowed only on the immediately preceding line.
            // It is intended for tenant-less junction/global child tables whose
            // parent ownership has already been checked in the same method.
            $previousLine = $i > 0 ? $lines[$i - 1] : '';
            if (str_contains($previousLine, '@tenant-scope-reviewed')) {
                continue;
            }

            // Pattern: ->table('xxx')
            if (preg_match("/->table\(\s*['\"]([a-z_]+)['\"]\s*\)/i", $line, $m)) {
                $tableName = $m[1];
                if ($this->isGlobalTable($tableName)) {
                    continue;
                }
                // Inspect the complete chained statement (not an arbitrary 12-line
                // window) and a separately assigned builder's subsequent scope.
                $window = implode("\n", array_slice($lines, $i, 12))
                    . "\n" . $this->queryScopeWindow($lines, $i);
                $guardedInsert = false;
                if (preg_match('/->insert(?:Batch)?\(\s*(\$[a-zA-Z_][a-zA-Z0-9_]*)/i', $line, $insertMatch)) {
                    $methodPrefix = implode("\n", array_slice($lines, $functionStart, $i - $functionStart + 1));
                    $variable = preg_quote($insertMatch[1], '/');
                    $guardedInsert = (bool) preg_match(
                        '/' . $variable . '\s*=\s*\[[\s\S]*?[\'\"]company_id[\'\"]\s*=>/i',
                        $methodPrefix,
                    );
                }
                if (!$this->hasTenantGuard($window) && !$guardedInsert) {
                    $found[] = [
                        'rule' => 'TABLE_NO_COMPANY_FILTER',
                        'severity' => 'HIGH',
                        'file' => $relPath,
                        'line' => $lineNo,
                        'context' => $context,
                        'table' => $tableName,
                        'snippet' => trim($line),
                    ];
                }
            }

            // Dynamic table names previously escaped Rule 1 entirely. They
            // are common in generic CRUD/import code, so require the same
            // visible tenant guard as literal table names.
            if (preg_match("/->table\(\s*(?!['\"])([^)]+)\)/i", $line, $dynamicMatch)) {
                $window = implode("\n", array_slice($lines, $i, 12))
                    . "\n" . $this->queryScopeWindow($lines, $i);
                if (!$this->hasTenantGuard($window)) {
                    $found[] = [
                        'rule' => 'DYNAMIC_TABLE_NO_TENANT_FILTER',
                        'severity' => 'HIGH',
                        'file' => $relPath,
                        'line' => $lineNo,
                        'context' => $context,
                        'table' => trim($dynamicMatch[1]),
                        'snippet' => trim($line),
                    ];
                }
            }

            // Rule 2: $db->query("SELECT ... FROM xxx") raw tanpa company_id
            if (preg_match("/->query\(\s*['\"](SELECT[^'\"]+)['\"]/i", $line, $m)) {
                $sql = strtolower($m[1]);
                if (str_contains($sql, ' from ')
                    && !str_contains($sql, 'company_id')
                    && !preg_match('/from\s+(' . implode('|', $this->reviewedGlobalTables()) . ')/', $sql)) {
                    $found[] = [
                        'rule' => 'RAW_QUERY_NO_COMPANY_FILTER',
                        'severity' => 'HIGH',
                        'file' => $relPath,
                        'line' => $lineNo,
                        'context' => $context,
                        'snippet' => trim($line),
                    ];
                }
            }

            // Rule 3: Model->find($id) tanpa pakai findScoped
            // Hanya warning di Controller (untuk Service biasanya internal call)
            if (str_contains($relPath, 'app/Controllers/')) {
                if (preg_match('/->find\(\s*\$[a-z_]+\s*\)/i', $line)
                    && !preg_match('/findScoped|findChild|findInBatch/', $line)) {
                    // Skip kalau di line yang sama ada $tenantContext / scope check
                    $window = implode("\n", array_slice($lines, max(0, $i - 3), 6));
                    if (!preg_match('/findScoped|forTenant|company_id|scoped\(/', $window)) {
                        $found[] = [
                            'rule' => 'MODEL_FIND_NO_SCOPE',
                            'severity' => 'MEDIUM',
                            'file' => $relPath,
                            'line' => $lineNo,
                            'context' => $context,
                            'snippet' => trim($line),
                        ];
                    }
                }
            }

            // Rule 4: setTable() — bypass model table; suspicious
            if (preg_match('/->setTable\(/', $line)) {
                $found[] = [
                    'rule' => 'MODEL_SET_TABLE_DYNAMIC',
                    'severity' => 'LOW',
                    'file' => $relPath,
                    'line' => $lineNo,
                    'context' => $context,
                    'snippet' => trim($line),
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
        if (empty($baseline['fingerprints']) || !is_array($baseline['fingerprints'])) {
            return $findings;
        }
        $remaining = $baseline['fingerprints'];
        $new = [];
        foreach ($findings as $finding) {
            $fingerprint = $this->fingerprint($finding);
            if (($remaining[$fingerprint] ?? 0) > 0) {
                $remaining[$fingerprint]--;
                continue;
            }
            $new[] = $finding;
        }

        return $new;
    }

    private function fingerprint(array $f): string
    {
        $snippet = preg_replace('/\s+/', ' ', trim((string) ($f['snippet'] ?? '')));

        return hash('sha256', implode('|', [
            $f['rule'] ?? '',
            $f['file'] ?? '',
            $f['context'] ?? '(file)',
            $f['table'] ?? '',
            $snippet,
        ]));
    }

    /**
     * Return the complete fluent statement and, for `$builder = $db->table()`
     * assignments, the builder operations until it is reassigned or the next
     * method begins. This recognizes legitimate multi-line/chained scopes
     * without allowing an unrelated query later in the method to mask a leak.
     *
     * @param array<int, string> $lines
     */
    private function queryScopeWindow(array $lines, int $start): string
    {
        $parts = [];
        $limit = min(count($lines), $start + 120);
        $builder = null;
        if (preg_match(
            '/(\$[a-zA-Z_][a-zA-Z0-9_]*)\s*=.*->table\([^)]*\)\s*;\s*$/',
            $lines[$start],
            $match,
        )) {
            $builder = $match[1];
        }

        for ($i = $start; $i < $limit; $i++) {
            $line = $lines[$i];
            if ($i > $start && preg_match('/\bfunction\s+[a-zA-Z_][a-zA-Z0-9_]*\s*\(/', $line)) {
                break;
            }
            if ($builder !== null && $i > $start
                && preg_match('/' . preg_quote($builder, '/') . '\s*=/', $line)) {
                break;
            }
            $parts[] = $line;
            if ($builder === null && str_contains($line, ';')) {
                break;
            }
            if ($builder !== null && preg_match(
                '/' . preg_quote($builder, '/') . '->(?:get|insert|insertBatch|update|delete|countAllResults)\s*\(/',
                $line,
            )) {
                break;
            }
        }

        return implode("\n", $parts);
    }

    /** @return array<int, string> */
    private function reviewedGlobalTables(): array
    {
        static $tables;
        if ($tables !== null) {
            return $tables;
        }
        $classificationPath = ROOTPATH . 'security/tenant-table-classification.php';
        $classification = is_file($classificationPath) ? require $classificationPath : [];
        $tables = array_values(array_unique(array_merge(
            $this->globalTables,
            array_keys($classification['global'] ?? []),
        )));

        return $tables;
    }

    private function isGlobalTable(string $table): bool
    {
        return in_array($table, $this->reviewedGlobalTables(), true);
    }

    private function fingerprintCounts(array $findings): array
    {
        $counts = [];
        foreach ($findings as $finding) {
            $fingerprint = $this->fingerprint($finding);
            $counts[$fingerprint] = ($counts[$fingerprint] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    private function optionEnabled(string $name, array $params): bool
    {
        if (in_array('--' . $name, $params, true)) {
            return true;
        }
        $value = CLI::getOption($name);

        return $value !== null && $value !== false;
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
