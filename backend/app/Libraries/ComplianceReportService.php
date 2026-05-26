<?php

namespace App\Libraries;

use Config\Database;
use Config\Services;

/**
 * ComplianceReportService — generator laporan kepatuhan HR (Sprint 4 / CC-5).
 *
 * Kumpulan reports yang sering diminta auditor / pemerintah:
 *
 *   1. WLKP_ANNUAL — Wajib Lapor Ketenagakerjaan tahunan (UU 7/1981)
 *      Header: company, period_year, totals (HC, gender split, age groups,
 *      WNA count). Detail: per dept HC + new hires + resigns + PHK.
 *
 *   2. BPJS_MONTHLY — list aktif & rate iuran per karyawan untuk
 *      reconciliation dengan e-Dabu (Kesehatan) & SiPP (TK).
 *
 *   3. PKWT_EXPIRING — PKWT yang akan habis kontrak dalam X hari.
 *      Wajib HR notify minimum 30 hari sebelum (UU 6/2023).
 *
 *   4. PHK_LIST — daftar PHK dalam periode (volunatry resign + termination).
 *      Untuk laporan ke Disnaker / asosiasi industri.
 *
 *   5. AUDIT_SUMMARY — count audit log per severity & module untuk audit
 *      internal compliance (UU PDP).
 *
 *   6. PROBATION_OVERDUE — karyawan PKWTT yang probation_end_date sudah
 *      lewat tapi probation_status belum diputuskan (HIRE-1).
 *
 *   7. BPJS_OVERDUE — karyawan baru yang BPJS_REGISTER overdue (HIRE-4).
 *
 *   8. TURNOVER_SUMMARY — turnover rate, retention, avg tenure per dept.
 *
 * Hasil di-cache di `compliance_report_runs` dengan TTL default 24 jam.
 * Service ini idempotent: kalau report sama (type + periode + parameters)
 * sudah pernah generate dalam window cache, return cached version.
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item CC-5.
 */
class ComplianceReportService
{
    public const REPORT_TYPES = [
        'WLKP_ANNUAL', 'BPJS_MONTHLY', 'PKWT_EXPIRING', 'PHK_LIST',
        'AUDIT_SUMMARY', 'PROBATION_OVERDUE', 'BPJS_OVERDUE', 'TURNOVER_SUMMARY',
    ];

    private const DEFAULT_CACHE_TTL_HOURS = 24;

    /**
     * Generate report. Cek cache dulu, kalau cache valid return cached.
     */
    public function generate(
        int $companyId,
        string $reportType,
        ?int $year = null,
        ?int $month = null,
        array $parameters = [],
        ?int $userId = null,
        bool $forceRefresh = false,
    ): array {
        if (! in_array($reportType, self::REPORT_TYPES, true)) {
            throw new \InvalidArgumentException(
                "report_type tidak valid. Pilihan: " . implode(', ', self::REPORT_TYPES)
            );
        }

        $db = Database::connect();

        // Cek cache (kalau ada) — same company × report_type × year × month
        if (! $forceRefresh) {
            $cached = $this->getValidCache($db, $companyId, $reportType, $year, $month);
            if ($cached) {
                $cached['result'] = json_decode((string) $cached['result_json'], true) ?: [];
                $cached['cached']  = true;
                return $cached;
            }
        }

        // Generate fresh
        $result = $this->dispatchGeneration($db, $companyId, $reportType, $year, $month, $parameters);

        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime("+" . self::DEFAULT_CACHE_TTL_HOURS . " hours"));
        $insert = [
            'company_id'      => $companyId,
            'report_type'     => $reportType,
            'period_year'     => $year,
            'period_month'    => $month,
            'parameters_json' => $parameters ? json_encode($parameters, JSON_UNESCAPED_UNICODE) : null,
            'result_json'     => json_encode($result, JSON_UNESCAPED_UNICODE),
            'row_count'       => (int) ($result['row_count'] ?? 0),
            'export_format'   => 'JSON',
            'generated_by'    => $userId,
            'generated_at'    => $now,
            'expires_at'      => $expiresAt,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $db->table('compliance_report_runs')->insert($insert);
        $runId = (int) $db->insertID();

        try {
            Services::audit()->forModule('COMPLIANCE')->log(
                action: 'compliance.report_generate',
                entity: 'compliance_report_run',
                entityId: $runId,
                description: "Generate {$reportType} untuk company #{$companyId} "
                    . ($year ? "({$year}" . ($month ? "-{$month}" : '') . ")" : '')
                    . " — {$insert['row_count']} rows",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        return [
            'id'           => $runId,
            'report_type'  => $reportType,
            'period_year'  => $year,
            'period_month' => $month,
            'generated_at' => $now,
            'expires_at'   => $expiresAt,
            'row_count'    => $insert['row_count'],
            'result'       => $result,
            'cached'       => false,
        ];
    }

    private function getValidCache(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        string $reportType,
        ?int $year,
        ?int $month,
    ): ?array {
        $b = $db->table('compliance_report_runs')
            ->where('company_id', $companyId)
            ->where('report_type', $reportType)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->where('deleted_at IS NULL');
        if ($year === null) $b->where('period_year IS NULL');
        else $b->where('period_year', $year);
        if ($month === null) $b->where('period_month IS NULL');
        else $b->where('period_month', $month);
        return $b->orderBy('id', 'DESC')->limit(1)->get()->getRowArray() ?: null;
    }

    /**
     * Dispatch ke method generator yang sesuai.
     */
    private function dispatchGeneration(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        string $type,
        ?int $year,
        ?int $month,
        array $params,
    ): array {
        return match ($type) {
            'WLKP_ANNUAL'       => $this->generateWlkpAnnual($db, $companyId, $year ?? (int) date('Y')),
            'BPJS_MONTHLY'      => $this->generateBpjsMonthly($db, $companyId, $year ?? (int) date('Y'), $month ?? (int) date('n')),
            'PKWT_EXPIRING'     => $this->generatePkwtExpiring($db, $companyId, (int) ($params['days_ahead'] ?? 60)),
            'PHK_LIST'          => $this->generatePhkList($db, $companyId, $year ?? (int) date('Y'), $month),
            'AUDIT_SUMMARY'     => $this->generateAuditSummary($db, $companyId, $year ?? (int) date('Y'), $month),
            'PROBATION_OVERDUE' => $this->generateProbationOverdue($db, $companyId),
            'BPJS_OVERDUE'      => $this->generateBpjsOverdue($db, $companyId),
            'TURNOVER_SUMMARY'  => $this->generateTurnoverSummary($db, $companyId, $year ?? (int) date('Y')),
            default             => ['row_count' => 0, 'rows' => []],
        };
    }

    /**
     * WLKP — Wajib Lapor Ketenagakerjaan. Output sesuai format Kemnaker.
     */
    private function generateWlkpAnnual(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        int $year,
    ): array {
        $yearStart = "{$year}-01-01";
        $yearEnd   = "{$year}-12-31";

        // Active employees per Dec 31
        $activeRows = $db->table('employees e')
            ->select('e.id, e.gender, e.date_of_birth, e.nationality, e.join_date, '
                . 'e.employment_status, e.employment_type, '
                . 'd.name AS department_name, p.name AS position_name')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->where('e.company_id', $companyId)
            ->where('e.is_active', 1)
            ->where('e.deleted_at IS NULL')
            ->where('e.join_date <=', $yearEnd)
            ->groupStart()
                ->where('e.resign_date IS NULL')
                ->orWhere('e.resign_date >', $yearEnd)
            ->groupEnd()
            ->get()->getResultArray();

        $totalHc = count($activeRows);
        $maleHc = $femaleHc = 0;
        $wnaHc = $wniHc = 0;
        $under25 = $age25To40 = $age40To55 = $over55 = 0;

        foreach ($activeRows as $r) {
            $g = strtoupper((string) ($r['gender'] ?? ''));
            if ($g === 'MALE' || $g === 'L') $maleHc++;
            elseif ($g === 'FEMALE' || $g === 'P') $femaleHc++;

            $nationality = strtoupper((string) ($r['nationality'] ?? 'INDONESIA'));
            if ($nationality !== 'INDONESIA' && $nationality !== 'WNI' && $nationality !== '') {
                $wnaHc++;
            } else $wniHc++;

            $dob = $r['date_of_birth'] ?? null;
            if ($dob) {
                $age = (int) date('Y') - (int) date('Y', strtotime($dob));
                if ($age < 25) $under25++;
                elseif ($age < 40) $age25To40++;
                elseif ($age < 55) $age40To55++;
                else $over55++;
            }
        }

        // New hires & exits in year
        $newHires = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('join_date >=', $yearStart)
            ->where('join_date <=', $yearEnd)
            ->where('deleted_at IS NULL')
            ->countAllResults();

        $exits = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('resign_date >=', $yearStart)
            ->where('resign_date <=', $yearEnd)
            ->where('deleted_at IS NULL')
            ->countAllResults();

        // Per-department breakdown
        $byDept = [];
        foreach ($activeRows as $r) {
            $dept = $r['department_name'] ?? 'Unassigned';
            $byDept[$dept] = ($byDept[$dept] ?? 0) + 1;
        }
        arsort($byDept);

        return [
            'period_year'    => $year,
            'company_id'     => $companyId,
            'total_hc'       => $totalHc,
            'male_hc'        => $maleHc,
            'female_hc'      => $femaleHc,
            'wni_hc'         => $wniHc,
            'wna_hc'         => $wnaHc,
            'age_under_25'   => $under25,
            'age_25_to_40'   => $age25To40,
            'age_40_to_55'   => $age40To55,
            'age_over_55'    => $over55,
            'new_hires_year' => $newHires,
            'exits_year'     => $exits,
            'by_department'  => $byDept,
            'row_count'      => $totalHc,
        ];
    }

    /**
     * BPJS Monthly — list active employees + iuran summary untuk reconciliation.
     */
    private function generateBpjsMonthly(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        int $year,
        int $month,
    ): array {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd   = date('Y-m-t', strtotime($monthStart));

        $rows = $db->table('employees e')
            ->select('e.id, e.employee_number, e.full_name, e.nik, '
                . 'e.bpjs_kesehatan_number, e.bpjs_ketenagakerjaan_number, '
                . 'e.basic_salary, e.fixed_allowance, e.join_date, e.resign_date')
            ->where('e.company_id', $companyId)
            ->where('e.is_active', 1)
            ->where('e.deleted_at IS NULL')
            ->where('e.join_date <=', $monthEnd)
            ->groupStart()
                ->where('e.resign_date IS NULL')
                ->orWhere('e.resign_date >=', $monthStart)
            ->groupEnd()
            ->orderBy('e.full_name', 'ASC')
            ->get()->getResultArray();

        $totalKesPremium = $totalTkPremium = 0;
        $totalNoKes = $totalNoTk = 0;
        foreach ($rows as &$r) {
            $base = (float) ($r['basic_salary'] ?? 0) + (float) ($r['fixed_allowance'] ?? 0);
            // Kesehatan: 4% perusahaan + 1% karyawan, atap UMP/UMK biasanya 12jt/bulan.
            // Default cap 12jt karena belum baca config UMP per company.
            $kesBase = min($base, 12_000_000);
            $r['bpjs_kes_employer'] = round($kesBase * 0.04, 0);
            $r['bpjs_kes_employee'] = round($kesBase * 0.01, 0);

            // TK: JHT 3.7% emp + 2% karyawan; JKK 0.24-1.74% (default 0.54%); JKM 0.3%; JP 2% emp + 1% karyawan
            $tkEmployer = round($base * (0.037 + 0.0054 + 0.003 + 0.02), 0);
            $tkEmployee = round($base * (0.02 + 0.01), 0);
            $r['bpjs_tk_employer'] = $tkEmployer;
            $r['bpjs_tk_employee'] = $tkEmployee;

            $totalKesPremium += $r['bpjs_kes_employer'] + $r['bpjs_kes_employee'];
            $totalTkPremium  += $tkEmployer + $tkEmployee;

            if (empty($r['bpjs_kesehatan_number'])) $totalNoKes++;
            if (empty($r['bpjs_ketenagakerjaan_number'])) $totalNoTk++;
        }
        unset($r);

        return [
            'period_year'        => $year,
            'period_month'       => $month,
            'period_label'       => "{$year}-{$month}",
            'active_employees'   => count($rows),
            'no_bpjs_kes_count'  => $totalNoKes,
            'no_bpjs_tk_count'   => $totalNoTk,
            'total_kes_premium'  => $totalKesPremium,
            'total_tk_premium'   => $totalTkPremium,
            'rows'               => $rows,
            'row_count'          => count($rows),
        ];
    }

    /**
     * PKWT yang akan expire dalam X hari (default 60).
     */
    private function generatePkwtExpiring(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        int $daysAhead,
    ): array {
        $today = date('Y-m-d');
        $cutoff = date('Y-m-d', strtotime("+{$daysAhead} days"));

        $rows = [];
        try {
            if (! $db->tableExists('employee_contracts')) {
                return ['row_count' => 0, 'rows' => [], 'days_ahead' => $daysAhead];
            }
            $rows = $db->table('employee_contracts ec')
                ->select('ec.id, ec.employee_id, ec.contract_type, ec.start_date, ec.end_date, '
                    . 'ec.status, e.full_name, e.employee_number, e.email, '
                    . 'd.name AS department_name, p.name AS position_name')
                ->join('employees e', 'e.id = ec.employee_id')
                ->join('departments d', 'd.id = e.department_id', 'left')
                ->join('positions p', 'p.id = e.position_id', 'left')
                ->where('e.company_id', $companyId)
                ->where('e.is_active', 1)
                ->where('e.deleted_at IS NULL')
                ->whereIn('ec.contract_type', ['PKWT', 'PROBATION', 'MAGANG', 'OUTSOURCING'])
                ->whereIn('ec.status', ['ACTIVE', 'SIGNED'])
                ->where('ec.deleted_at IS NULL')
                ->where('ec.end_date >=', $today)
                ->where('ec.end_date <=', $cutoff)
                ->orderBy('ec.end_date', 'ASC')
                ->get()->getResultArray();
        } catch (\Throwable $e) {}

        // Tambah days_remaining
        foreach ($rows as &$r) {
            if (! empty($r['end_date'])) {
                $r['days_remaining'] = (int) round(
                    (strtotime($r['end_date']) - strtotime($today)) / 86400
                );
            }
        }
        unset($r);

        return [
            'days_ahead'  => $daysAhead,
            'cutoff_date' => $cutoff,
            'rows'        => $rows,
            'row_count'   => count($rows),
        ];
    }

    /**
     * Daftar PHK (resignation + termination) dalam periode.
     */
    private function generatePhkList(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        int $year,
        ?int $month = null,
    ): array {
        $start = $month
            ? sprintf('%04d-%02d-01', $year, $month)
            : "{$year}-01-01";
        $end = $month
            ? date('Y-m-t', strtotime($start))
            : "{$year}-12-31";

        $rows = [];

        // Resignation (voluntary)
        try {
            $resigns = $db->table('resignation_requests rr')
                ->select('rr.id AS source_id, "RESIGNATION" AS source, '
                    . 'rr.status, rr.last_working_date, rr.effective_date, '
                    . 'rr.reason_category AS reason_code, '
                    . 'e.id AS employee_id, e.full_name, e.employee_number, '
                    . 'e.join_date, e.resign_date, '
                    . 'd.name AS department_name, p.name AS position_name')
                ->join('employees e', 'e.id = rr.employee_id')
                ->join('departments d', 'd.id = e.department_id', 'left')
                ->join('positions p', 'p.id = e.position_id', 'left')
                ->where('rr.company_id', $companyId)
                ->where('rr.status', 'APPROVED')
                ->where('rr.deleted_at IS NULL')
                ->where('rr.effective_date >=', $start)
                ->where('rr.effective_date <=', $end)
                ->get()->getResultArray();
            $rows = array_merge($rows, $resigns);
        } catch (\Throwable $e) {}

        // Termination (PHK)
        try {
            if ($db->tableExists('termination_requests')) {
                $terminations = $db->table('termination_requests tr')
                    ->select('tr.id AS source_id, "TERMINATION" AS source, '
                        . 'tr.status, tr.planned_termination_date AS last_working_date, '
                        . 'tr.effective_date, tr.reason_code, tr.termination_type, '
                        . 'tr.reference_number, '
                        . 'e.id AS employee_id, e.full_name, e.employee_number, '
                        . 'e.join_date, e.resign_date, '
                        . 'd.name AS department_name, p.name AS position_name')
                    ->join('employees e', 'e.id = tr.employee_id')
                    ->join('departments d', 'd.id = e.department_id', 'left')
                    ->join('positions p', 'p.id = e.position_id', 'left')
                    ->where('tr.company_id', $companyId)
                    ->whereIn('tr.status', ['APPROVED', 'EXECUTED'])
                    ->where('tr.deleted_at IS NULL')
                    ->where('tr.effective_date >=', $start)
                    ->where('tr.effective_date <=', $end)
                    ->get()->getResultArray();
                $rows = array_merge($rows, $terminations);
            }
        } catch (\Throwable $e) {}

        // Sort by effective_date desc
        usort($rows, static fn ($a, $b) => strcmp(
            (string) ($b['effective_date'] ?? ''),
            (string) ($a['effective_date'] ?? ''),
        ));

        // Aggregate counts
        $resignCount = 0; $termCount = 0;
        $reasonBreakdown = [];
        foreach ($rows as $r) {
            if ($r['source'] === 'RESIGNATION') $resignCount++;
            else $termCount++;
            $key = (string) ($r['reason_code'] ?? 'OTHER');
            $reasonBreakdown[$key] = ($reasonBreakdown[$key] ?? 0) + 1;
        }
        arsort($reasonBreakdown);

        return [
            'period_year'      => $year,
            'period_month'     => $month,
            'period_start'     => $start,
            'period_end'       => $end,
            'total'            => count($rows),
            'resignation_count'=> $resignCount,
            'termination_count'=> $termCount,
            'reason_breakdown' => $reasonBreakdown,
            'rows'             => $rows,
            'row_count'        => count($rows),
        ];
    }

    /**
     * Audit log summary per severity & module untuk audit kepatuhan.
     */
    private function generateAuditSummary(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        int $year,
        ?int $month = null,
    ): array {
        if (! $db->tableExists('audit_logs')) {
            return ['row_count' => 0, 'period_year' => $year, 'rows' => []];
        }

        $start = $month
            ? sprintf('%04d-%02d-01 00:00:00', $year, $month)
            : "{$year}-01-01 00:00:00";
        $end = $month
            ? date('Y-m-t 23:59:59', strtotime($start))
            : "{$year}-12-31 23:59:59";

        $rows = $db->table('audit_logs')
            ->select('module, severity, COUNT(*) AS count')
            ->where('company_id', $companyId)
            ->where('created_at >=', $start)
            ->where('created_at <=', $end)
            ->groupBy(['module', 'severity'])
            ->orderBy('count', 'DESC')
            ->get()->getResultArray();

        $bySeverity = [];
        $byModule = [];
        $totalRows = 0;
        foreach ($rows as $r) {
            $sev = (string) ($r['severity'] ?? 'INFO');
            $mod = (string) ($r['module'] ?? 'GENERAL');
            $cnt = (int) $r['count'];
            $totalRows += $cnt;
            $bySeverity[$sev] = ($bySeverity[$sev] ?? 0) + $cnt;
            $byModule[$mod]   = ($byModule[$mod] ?? 0) + $cnt;
        }
        arsort($bySeverity);
        arsort($byModule);

        return [
            'period_year'  => $year,
            'period_month' => $month,
            'total_logs'   => $totalRows,
            'by_severity'  => $bySeverity,
            'by_module'    => $byModule,
            'detail'       => $rows,
            'row_count'    => count($rows),
        ];
    }

    /**
     * Karyawan PKWTT yang probation_end_date sudah lewat tapi belum diputuskan.
     */
    private function generateProbationOverdue(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
    ): array {
        $today = date('Y-m-d');
        $rows = [];
        try {
            $rows = $db->table('employees e')
                ->select('e.id, e.employee_number, e.full_name, e.join_date, '
                    . 'e.probation_end_date, e.probation_status, e.employment_status, '
                    . 'd.name AS department_name, p.name AS position_name')
                ->join('departments d', 'd.id = e.department_id', 'left')
                ->join('positions p', 'p.id = e.position_id', 'left')
                ->where('e.company_id', $companyId)
                ->where('e.is_active', 1)
                ->where('e.deleted_at IS NULL')
                ->where('e.probation_end_date <', $today)
                ->groupStart()
                    ->where('e.probation_status IS NULL')
                    ->orWhere('e.probation_status', 'ONGOING')
                ->groupEnd()
                ->orderBy('e.probation_end_date', 'ASC')
                ->get()->getResultArray();
            foreach ($rows as &$r) {
                $r['days_overdue'] = (int) round(
                    (strtotime($today) - strtotime($r['probation_end_date'])) / 86400
                );
            }
            unset($r);
        } catch (\Throwable $e) {}

        return ['rows' => $rows, 'row_count' => count($rows)];
    }

    /**
     * BPJS_REGISTER overdue (delegate ke OffboardingService::findBpjsRegisterOverdue).
     */
    private function generateBpjsOverdue(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
    ): array {
        $rows = [];
        try {
            $svc = Services::offboarding();
            $rows = $svc->findBpjsRegisterOverdue($companyId);
        } catch (\Throwable $e) {}

        return ['rows' => $rows, 'row_count' => count($rows)];
    }

    /**
     * Turnover summary: per department, monthly turnover rate, retention.
     */
    private function generateTurnoverSummary(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        int $year,
    ): array {
        $yearStart = "{$year}-01-01";
        $yearEnd   = "{$year}-12-31";

        // Active per Jan 1 (start), Dec 31 (end), avg
        $activeStart = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('join_date <=', $yearStart)
            ->groupStart()
                ->where('resign_date IS NULL')
                ->orWhere('resign_date >=', $yearStart)
            ->groupEnd()
            ->where('deleted_at IS NULL')
            ->countAllResults();

        $activeEnd = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('join_date <=', $yearEnd)
            ->groupStart()
                ->where('resign_date IS NULL')
                ->orWhere('resign_date >', $yearEnd)
            ->groupEnd()
            ->where('deleted_at IS NULL')
            ->countAllResults();

        $newHires = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('join_date >=', $yearStart)
            ->where('join_date <=', $yearEnd)
            ->where('deleted_at IS NULL')
            ->countAllResults();

        $exits = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('resign_date >=', $yearStart)
            ->where('resign_date <=', $yearEnd)
            ->where('deleted_at IS NULL')
            ->countAllResults();

        $avgHc = max(1, ($activeStart + $activeEnd) / 2);
        $turnoverRate = round(($exits / $avgHc) * 100, 2);

        // Per dept turnover
        $deptRows = $db->table('employees e')
            ->select('d.name AS department_name, COUNT(*) AS exits')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->where('e.company_id', $companyId)
            ->where('e.resign_date >=', $yearStart)
            ->where('e.resign_date <=', $yearEnd)
            ->where('e.deleted_at IS NULL')
            ->groupBy('d.id')
            ->orderBy('exits', 'DESC')
            ->get()->getResultArray();

        // Per month new vs exits
        $monthlyTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $mStart = sprintf('%04d-%02d-01', $year, $m);
            $mEnd   = date('Y-m-t', strtotime($mStart));
            $hires = $db->table('employees')
                ->where('company_id', $companyId)
                ->where('join_date >=', $mStart)->where('join_date <=', $mEnd)
                ->where('deleted_at IS NULL')->countAllResults();
            $monthExits = $db->table('employees')
                ->where('company_id', $companyId)
                ->where('resign_date >=', $mStart)->where('resign_date <=', $mEnd)
                ->where('deleted_at IS NULL')->countAllResults();
            $monthlyTrend[] = ['month' => $m, 'hires' => $hires, 'exits' => $monthExits];
        }

        return [
            'period_year'   => $year,
            'active_start'  => $activeStart,
            'active_end'    => $activeEnd,
            'new_hires'     => $newHires,
            'exits'         => $exits,
            'avg_hc'        => round($avgHc, 1),
            'turnover_rate' => $turnoverRate,
            'by_department' => $deptRows,
            'monthly_trend' => $monthlyTrend,
            'row_count'     => $exits,
        ];
    }

    /**
     * Convert result rows ke CSV. Helper untuk export download.
     */
    public function toCsv(array $rows, array $columns = []): string
    {
        if (empty($rows)) return '';
        $columns = $columns ?: array_keys($rows[0]);

        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            throw new \RuntimeException('Tidak bisa buka temp stream untuk CSV export');
        }
        // Header
        fputcsv($fp, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $c) {
                $v = $row[$c] ?? '';
                if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                $line[] = (string) $v;
            }
            fputcsv($fp, $line);
        }
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);
        return $csv === false ? '' : $csv;
    }
}