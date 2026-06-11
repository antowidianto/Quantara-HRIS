<?php

namespace App\Libraries\CustomerSuccess;

use Config\Database;

/**
 * S3.4 — Customer Success — Health Score Calculator.
 *
 * Compute health score per company berdasarkan 5 driver:
 *   1. Login frequency (30 hari) — bobot 25%
 *   2. Feature adoption rate     — bobot 20%
 *   3. Payslip activity (30d)    — bobot 25%
 *   4. Support ticket count (30d, inverse) — bobot 15%
 *   5. Subscription status / tenure — bobot 15%
 *
 * Output: 0-100 score + tier (GREEN/YELLOW/RED) + churn risk + recommendations.
 *
 * Pure helpers `computeScorePure()` testable tanpa DB.
 *
 * Dipanggil oleh `App\Commands\CsComputeHealthScores` weekly cron.
 */
class CustomerHealthService
{
    /**
     * Pure helper: hitung score 0-100 dari driver values normalized.
     *
     * Setiap driver value 0..1 (sudah normalized).
     */
    public static function computeScorePure(array $drivers): array
    {
        $weights = [
            'login_frequency'    => 0.25,
            'feature_adoption'   => 0.20,
            'payslip_activity'   => 0.25,
            'support_tickets'    => 0.15, // inverse: makin sedikit ticket = makin sehat
            'subscription_health'=> 0.15,
        ];

        $score = 0.0;
        foreach ($weights as $key => $w) {
            $val = (float) ($drivers[$key] ?? 0);
            $val = max(0.0, min(1.0, $val));
            $score += $val * $w * 100.0;
        }
        $score = round($score, 2);

        // Tier classification
        if ($score >= 75) {
            $tier = 'GREEN';
            $churn = 'LOW';
        } elseif ($score >= 50) {
            $tier = 'YELLOW';
            $churn = 'MEDIUM';
        } else {
            $tier = 'RED';
            $churn = 'HIGH';
        }

        // Recommendations berdasarkan driver terendah
        $lowest = '';
        $lowestVal = 1.1;
        foreach ($drivers as $k => $v) {
            $v = (float) $v;
            if ($v < $lowestVal) {
                $lowestVal = $v;
                $lowest = $k;
            }
        }
        $recMap = [
            'login_frequency'      => 'User aktivitas rendah - kirim re-engagement email + product tour',
            'feature_adoption'     => 'Adopsi fitur rendah - jadwalkan training session atau webinar',
            'payslip_activity'     => 'Belum jalan payroll - hubungi untuk onboarding tutorial',
            'support_tickets'      => 'Banyak tiket support - eskalasi ke senior CSM untuk root cause',
            'subscription_health'  => 'Subscription status PAST_DUE/SUSPENDED - tindak lanjut pembayaran',
        ];

        return [
            'score'             => $score,
            'tier'              => $tier,
            'churn_risk'        => $churn,
            'breakdown'         => $drivers,
            'recommendations'   => $recMap[$lowest] ?? null,
            'lowest_driver'     => $lowest,
        ];
    }

    /**
     * Compute health score untuk satu company berdasarkan data DB.
     */
    public function compute(int $companyId): array
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));

        // Driver 1: login frequency (% user aktif login dalam 30 hari)
        $loginFrequency = 0.0;
        if ($db->tableExists('users') && $db->tableExists('users')) {
            $totalUsers = (int) $db->table('users')
                ->where('company_id', $companyId)
                ->where('is_active', 1)
                ->countAllResults();
            if ($totalUsers > 0) {
                $activeUsers = (int) $db->table('users')
                    ->where('company_id', $companyId)
                    ->where('is_active', 1)
                    ->where('last_login_at >=', $thirtyDaysAgo)
                    ->countAllResults();
                $loginFrequency = min(1.0, $activeUsers / max(1, $totalUsers));
            }
        }

        // Driver 2: feature adoption (% feature aktif yang dipakai)
        $featureAdoption = 0.0;
        if ($db->tableExists('feature_flags')) {
            $totalFeatures = (int) $db->table('feature_flags')->countAllResults();
            if ($totalFeatures > 0 && class_exists('\\App\\Libraries\\Billing\\FeatureGateService')) {
                try {
                    $svc = new \App\Libraries\Billing\FeatureGateService();
                    $features = $svc->listForCompany($companyId);
                    $enabled = count(array_filter($features, fn($f) => ! empty($f['enabled'])));
                    $featureAdoption = min(1.0, $enabled / max(1, $totalFeatures));
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        // Driver 3: payslip activity (jumlah payslip 30 hari, normalized vs employee count)
        $payslipActivity = 0.0;
        if ($db->tableExists('payslips') && $db->tableExists('employees')) {
            $employeeCount = max(1, (int) $db->table('employees')
                ->where('company_id', $companyId)
                ->where('deleted_at IS NULL')
                ->where('is_active', 1)
                ->countAllResults());
            $payslipCount = (int) $db->table('payslips')
                ->where('company_id', $companyId)
                ->where('created_at >=', $thirtyDaysAgo)
                ->countAllResults();
            $payslipActivity = min(1.0, $payslipCount / $employeeCount);
        }

        // Driver 4: support tickets (inverse: makin sedikit = makin sehat)
        $ticketHealth = 1.0;
        if ($db->tableExists('helpdesk_tickets')) {
            $openTickets = (int) $db->table('helpdesk_tickets')
                ->where('company_id', $companyId)
                ->whereIn('status', ['OPEN', 'IN_PROGRESS'])
                ->where('created_at >=', $thirtyDaysAgo)
                ->countAllResults();
            // Threshold: > 10 ticket = unhealthy (0.0), 0 ticket = healthy (1.0)
            $ticketHealth = max(0.0, 1.0 - ($openTickets / 10.0));
        }

        // Driver 5: subscription health (ACTIVE/TRIAL = 1.0, PAST_DUE = 0.5, SUSPENDED = 0.0)
        $subHealth = 0.5; // default kalau belum ada subscription
        if ($db->tableExists('subscriptions')) {
            $sub = $db->table('subscriptions')
                ->select('status, current_period_end')
                ->where('company_id', $companyId)
                ->limit(1)
                ->get()->getRowArray();
            if ($sub) {
                switch (strtoupper((string) $sub['status'])) {
                    case 'ACTIVE':
                    case 'TRIAL':
                        $subHealth = 1.0;
                        break;
                    case 'PAST_DUE':
                        $subHealth = 0.5;
                        break;
                    case 'SUSPENDED':
                    case 'CANCELLED':
                        $subHealth = 0.0;
                        break;
                }
            }
        }

        $drivers = [
            'login_frequency'      => $loginFrequency,
            'feature_adoption'     => $featureAdoption,
            'payslip_activity'     => $payslipActivity,
            'support_tickets'      => $ticketHealth,
            'subscription_health'  => $subHealth,
        ];

        $result = self::computeScorePure($drivers);

        // Persist
        if ($db->tableExists('customer_health_scores')) {
            $db->table('customer_health_scores')->insert([
                'company_id'      => $companyId,
                'score'           => $result['score'],
                'tier'            => $result['tier'],
                'breakdown_json'  => json_encode($result['breakdown']),
                'churn_risk'      => $result['churn_risk'],
                'recommendations' => $result['recommendations'],
                'calculated_at'   => $now,
                'created_at'      => $now,
            ]);
        }

        $result['company_id']    = $companyId;
        $result['calculated_at'] = $now;
        return $result;
    }

    /**
     * Compute untuk semua company aktif (dipanggil cron weekly).
     *
     * @return array Summary stats (green/yellow/red count, processed)
     */
    public function computeAll(): array
    {
        $db = Database::connect();
        if (! $db->tableExists('companies')) {
            return ['processed' => 0];
        }

        $companies = $db->table('companies')
            ->select('id')
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();

        $stats = ['processed' => 0, 'GREEN' => 0, 'YELLOW' => 0, 'RED' => 0, 'failed' => 0];

        foreach ($companies as $c) {
            try {
                $result = $this->compute((int) $c['id']);
                $stats['processed']++;
                $stats[$result['tier']]++;
            } catch (\Throwable $e) {
                log_message('warning', '[CustomerHealth] compute failed for company#' . $c['id'] . ': ' . $e->getMessage());
                $stats['failed']++;
            }
        }
        return $stats;
    }

    /**
     * Get latest score untuk satu company (untuk dashboard).
     */
    public function getLatest(int $companyId): ?array
    {
        $db = Database::connect();
        if (! $db->tableExists('customer_health_scores')) {
            return null;
        }
        $row = $db->table('customer_health_scores')
            ->where('company_id', $companyId)
            ->orderBy('calculated_at', 'DESC')
            ->limit(1)
            ->get()->getRowArray();
        if (! $row) return null;

        $row['breakdown'] = json_decode($row['breakdown_json'] ?? '{}', true);
        return $row;
    }
}