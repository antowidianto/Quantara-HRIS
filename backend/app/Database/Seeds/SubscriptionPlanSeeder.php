<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * S1.1 — Seeder katalog plan + feature flag matrix.
 *
 * Plan tiers:
 *  - FREE_TRIAL : 14 hari trial penuh fitur core, max 25 employees
 *  - STARTER    : SMB <50 emp, payroll & attendance basic
 *  - PRO        : Mid-market 50-300 emp, semua fitur HR + analytics
 *  - ENTERPRISE : 300+ emp, white-label, API key access, custom contract
 *
 * Aman di-run berkali-kali (idempotent via INSERT IGNORE / upsert).
 */
class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        // ─── PLANS ───────────────────────────────────────────────────
        $plans = [
            [
                'code'                => 'FREE_TRIAL',
                'name'                => 'Free Trial',
                'description'         => 'Coba semua fitur Pro selama 14 hari, tanpa kartu kredit.',
                'price_monthly'       => 0,
                'price_yearly'        => 0,
                'currency'            => 'IDR',
                'max_employees'       => 25,
                'max_companies'       => 1,
                'max_storage_gb'      => 1.0,
                'max_api_calls_month' => 1000,
                'trial_days'          => 14,
                'is_public'           => 1,
                'is_active'           => 1,
                'sort_order'          => 0,
            ],
            [
                'code'                => 'STARTER',
                'name'                => 'Starter',
                'description'         => 'Untuk perusahaan kecil di bawah 50 karyawan. Payroll + Attendance + Cuti.',
                'price_monthly'       => 1_500_000,
                'price_yearly'        => 15_000_000, // 10×monthly = 2 bulan gratis
                'currency'            => 'IDR',
                'max_employees'       => 50,
                'max_companies'       => 1,
                'max_storage_gb'      => 10.0,
                'max_api_calls_month' => 5000,
                'trial_days'          => 0,
                'is_public'           => 1,
                'is_active'           => 1,
                'sort_order'          => 10,
            ],
            [
                'code'                => 'PRO',
                'name'                => 'Pro',
                'description'         => 'Untuk perusahaan menengah 50-300 karyawan. Semua fitur HR + Performance + Analytics.',
                'price_monthly'       => 4_500_000,
                'price_yearly'        => 45_000_000,
                'currency'            => 'IDR',
                'max_employees'       => 300,
                'max_companies'       => 3,
                'max_storage_gb'      => 50.0,
                'max_api_calls_month' => 20000,
                'trial_days'          => 0,
                'is_public'           => 1,
                'is_active'           => 1,
                'sort_order'          => 20,
            ],
            [
                'code'                => 'ENTERPRISE',
                'name'                => 'Enterprise',
                'description'         => 'Custom quote untuk 300+ karyawan. White-label, API key access, dedicated support, custom integration.',
                'price_monthly'       => 0, // custom_price di subscriptions
                'price_yearly'        => 0,
                'currency'            => 'IDR',
                'max_employees'       => null, // unlimited
                'max_companies'       => 99,
                'max_storage_gb'      => null,
                'max_api_calls_month' => null,
                'trial_days'          => 0,
                'is_public'           => 0, // tidak ditampilkan di pricing page publik
                'is_active'           => 1,
                'sort_order'          => 30,
            ],
        ];

        $db = $this->db;

        foreach ($plans as $plan) {
            $existing = $db->table('subscription_plans')->where('code', $plan['code'])->get()->getRowArray();
            if ($existing) {
                $db->table('subscription_plans')->where('id', $existing['id'])->update($plan + ['updated_at' => $now]);
            } else {
                $db->table('subscription_plans')->insert($plan + ['created_at' => $now, 'updated_at' => $now]);
            }
        }

        // ─── FEATURE FLAGS REGISTRY ──────────────────────────────────
        // Format key: module.feature
        // Cakupan: 12 modul existing + ekstensi SaaS
        $features = [
            // Payroll
            ['key' => 'payroll.basic',           'category' => 'PAYROLL',     'description' => 'Hitung gaji + slip gaji standar', 'default_enabled' => 1, 'is_paid_only' => 0],
            ['key' => 'payroll.simulate',        'category' => 'PAYROLL',     'description' => 'Simulasi payroll & what-if PPh21', 'default_enabled' => 1, 'is_paid_only' => 0],
            ['key' => 'payroll.bank_export',     'category' => 'PAYROLL',     'description' => 'Export disbursement multi-bank', 'default_enabled' => 1, 'is_paid_only' => 0],
            ['key' => 'payroll.thr',             'category' => 'PAYROLL',     'description' => 'THR generator + THR planning dashboard', 'default_enabled' => 1, 'is_paid_only' => 0],
            ['key' => 'payroll.bonus_engine',    'category' => 'PAYROLL',     'description' => 'Bonus & insentif scheme + auto-merge', 'default_enabled' => 1, 'is_paid_only' => 1],
            ['key' => 'payroll.gross_up',        'category' => 'PAYROLL',     'description' => 'PPh21 gross-up calculator iterative', 'default_enabled' => 1, 'is_paid_only' => 1],
            ['key' => 'payroll.natura',          'category' => 'PAYROLL',     'description' => 'Natura/Kenikmatan PMK 66/2023', 'default_enabled' => 1, 'is_paid_only' => 0],

            // Attendance & Operasional
            ['key' => 'attendance.geofence',     'category' => 'ATTENDANCE',  'description' => 'GPS geofence per branch saat clock-in', 'default_enabled' => 1, 'is_paid_only' => 0],
            ['key' => 'attendance.wfh_mode',     'category' => 'ATTENDANCE',  'description' => 'WFH approval flow', 'default_enabled' => 1, 'is_paid_only' => 0],
            ['key' => 'attendance.overtime_pre', 'category' => 'ATTENDANCE',  'description' => 'Overtime pre-approval flow', 'default_enabled' => 1, 'is_paid_only' => 0],
            ['key' => 'reimbursement.ocr',       'category' => 'ATTENDANCE',  'description' => 'OCR struk reimbursement', 'default_enabled' => 0, 'is_paid_only' => 1],

            // Recruitment
            ['key' => 'recruitment.basic',       'category' => 'RECRUITMENT', 'description' => 'MPP + pipeline kandidat', 'default_enabled' => 1, 'is_paid_only' => 0],
            ['key' => 'recruitment.online_test', 'category' => 'RECRUITMENT', 'description' => 'Online assessment + scoring', 'default_enabled' => 1, 'is_paid_only' => 1],
            ['key' => 'recruitment.scoring',     'category' => 'RECRUITMENT', 'description' => 'Composite candidate scoring engine', 'default_enabled' => 1, 'is_paid_only' => 1],
            ['key' => 'recruitment.referral',    'category' => 'RECRUITMENT', 'description' => 'Employee referral program', 'default_enabled' => 1, 'is_paid_only' => 1],

            // Performance & Talent
            ['key' => 'performance.review',      'category' => 'TALENT',      'description' => 'Performance review + goals', 'default_enabled' => 1, 'is_paid_only' => 0],
            ['key' => 'performance.cascading',   'category' => 'TALENT',      'description' => 'Goals cascading + tree view', 'default_enabled' => 1, 'is_paid_only' => 1],
            ['key' => 'performance.peer_360',    'category' => 'TALENT',      'description' => '360° peer feedback', 'default_enabled' => 1, 'is_paid_only' => 1],
            ['key' => 'talent.competency',       'category' => 'TALENT',      'description' => 'Competency library + gap analysis', 'default_enabled' => 1, 'is_paid_only' => 1],
            ['key' => 'talent.succession',       'category' => 'TALENT',      'description' => 'Succession planning + 9-box', 'default_enabled' => 1, 'is_paid_only' => 1],
            ['key' => 'talent.marketplace',      'category' => 'TALENT',      'description' => 'Internal job marketplace', 'default_enabled' => 1, 'is_paid_only' => 1],

            // Intelligence (AI / Analytics)
            ['key' => 'intel.turnover_analytics','category' => 'INTELLIGENCE','description' => 'Turnover analytics + flight risk', 'default_enabled' => 0, 'is_paid_only' => 1],
            ['key' => 'intel.ai_copilot',        'category' => 'INTELLIGENCE','description' => 'AI HR Assistant chat', 'default_enabled' => 0, 'is_paid_only' => 1],
            ['key' => 'intel.workforce_scenarios','category' => 'INTELLIGENCE','description' => 'Workforce planning + Monte Carlo', 'default_enabled' => 0, 'is_paid_only' => 1],
            ['key' => 'intel.compensation',      'category' => 'INTELLIGENCE','description' => 'Compa-ratio + pay equity', 'default_enabled' => 0, 'is_paid_only' => 1],
            ['key' => 'intel.pulse_survey',      'category' => 'INTELLIGENCE','description' => 'Anonymous pulse survey + burnout score', 'default_enabled' => 0, 'is_paid_only' => 1],

            // Compliance
            ['key' => 'compliance.audit_log',    'category' => 'COMPLIANCE',  'description' => 'Audit log lengkap + chain hash', 'default_enabled' => 1, 'is_paid_only' => 0],
            ['key' => 'compliance.uu_pdp',       'category' => 'COMPLIANCE',  'description' => 'UU PDP consent + data export + erasure', 'default_enabled' => 1, 'is_paid_only' => 0],
            ['key' => 'compliance.wlkp_export',  'category' => 'COMPLIANCE',  'description' => 'Laporan WLKP / Sisnaker', 'default_enabled' => 1, 'is_paid_only' => 0],

            // Integration
            ['key' => 'integration.webhooks',    'category' => 'INTEGRATION', 'description' => 'Outbound webhooks dengan retry', 'default_enabled' => 1, 'is_paid_only' => 1],
            ['key' => 'integration.api_access',  'category' => 'INTEGRATION', 'description' => 'Scoped API key access', 'default_enabled' => 0, 'is_paid_only' => 1],
            ['key' => 'integration.sso',         'category' => 'INTEGRATION', 'description' => 'Reserved placeholder: Single Sign-On (SAML/OIDC) belum diimplementasikan', 'default_enabled' => 0, 'is_paid_only' => 1],
            ['key' => 'integration.erp',         'category' => 'INTEGRATION', 'description' => 'Accurate/Zahir/Odoo journal posting', 'default_enabled' => 0, 'is_paid_only' => 1],

            // Notification
            ['key' => 'notification.email',      'category' => 'NOTIFICATION','description' => 'Email notification (transactional)', 'default_enabled' => 1, 'is_paid_only' => 0],
            ['key' => 'notification.whatsapp',   'category' => 'NOTIFICATION','description' => 'WhatsApp notification (BYO API key)', 'default_enabled' => 0, 'is_paid_only' => 1],

            // Branding & Enterprise
            ['key' => 'branding.white_label',    'category' => 'ENTERPRISE',  'description' => 'Logo, color, custom domain per tenant', 'default_enabled' => 0, 'is_paid_only' => 1],
            ['key' => 'enterprise.multi_company','category' => 'ENTERPRISE',  'description' => 'Multi-PT / holding di satu tenant', 'default_enabled' => 0, 'is_paid_only' => 1],
            ['key' => 'enterprise.dedicated_csm','category' => 'ENTERPRISE',  'description' => 'Dedicated Customer Success Manager', 'default_enabled' => 0, 'is_paid_only' => 1],
        ];

        foreach ($features as $feat) {
            $existing = $db->table('feature_flags')->where('key', $feat['key'])->get()->getRowArray();
            if ($existing) {
                $db->table('feature_flags')->where('id', $existing['id'])->update($feat + ['updated_at' => $now]);
            } else {
                $db->table('feature_flags')->insert($feat + ['created_at' => $now, 'updated_at' => $now]);
            }
        }

        // ─── PLAN × FEATURE MATRIX ───────────────────────────────────
        // Susun matrix: feature_key => [plan_code => is_included]
        // Plan FREE_TRIAL = sama dengan PRO (full access selama trial)
        // STARTER = subset: payroll + attendance + recruitment basic + email notif
        // PRO = STARTER + analytics + intel basic + integration webhooks
        // ENTERPRISE = semua fitur yang tersedia + white-label + ERP + dedicated CSM.
        // SSO tetap 0 sampai provider SAML/OIDC dan acceptance test tersedia.

        $matrix = [
            // module       => [STARTER, PRO, ENTERPRISE]  (FREE_TRIAL = sama dengan PRO)
            'payroll.basic'             => [1, 1, 1],
            'payroll.simulate'          => [1, 1, 1],
            'payroll.bank_export'       => [1, 1, 1],
            'payroll.thr'               => [1, 1, 1],
            'payroll.bonus_engine'      => [0, 1, 1],
            'payroll.gross_up'          => [0, 1, 1],
            'payroll.natura'            => [1, 1, 1],
            'attendance.geofence'       => [1, 1, 1],
            'attendance.wfh_mode'       => [1, 1, 1],
            'attendance.overtime_pre'   => [0, 1, 1],
            'reimbursement.ocr'         => [0, 1, 1],
            'recruitment.basic'         => [1, 1, 1],
            'recruitment.online_test'   => [0, 1, 1],
            'recruitment.scoring'       => [0, 1, 1],
            'recruitment.referral'      => [0, 1, 1],
            'performance.review'        => [1, 1, 1],
            'performance.cascading'     => [0, 1, 1],
            'performance.peer_360'      => [0, 1, 1],
            'talent.competency'         => [0, 1, 1],
            'talent.succession'         => [0, 1, 1],
            'talent.marketplace'        => [0, 1, 1],
            'intel.turnover_analytics'  => [0, 1, 1],
            'intel.ai_copilot'          => [0, 0, 1],
            'intel.workforce_scenarios' => [0, 0, 1],
            'intel.compensation'        => [0, 0, 1],
            'intel.pulse_survey'        => [0, 1, 1],
            'compliance.audit_log'      => [1, 1, 1],
            'compliance.uu_pdp'         => [1, 1, 1],
            'compliance.wlkp_export'    => [1, 1, 1],
            'integration.webhooks'      => [0, 1, 1],
            'integration.api_access'    => [0, 1, 1],
            'integration.sso'           => [0, 0, 0],
            'integration.erp'           => [0, 0, 1],
            'notification.email'        => [1, 1, 1],
            'notification.whatsapp'     => [0, 1, 1],
            'branding.white_label'      => [0, 0, 1],
            'enterprise.multi_company'  => [0, 0, 1],
            'enterprise.dedicated_csm'  => [0, 0, 1],
        ];

        $planIds = $db->table('subscription_plans')->select('id, code')->get()->getResultArray();
        $planIdByCode = [];
        foreach ($planIds as $p) $planIdByCode[$p['code']] = (int) $p['id'];

        $planCodesOrdered = ['STARTER', 'PRO', 'ENTERPRISE'];

        foreach ($matrix as $featureKey => $included) {
            // FREE_TRIAL = sama dengan PRO
            $rows = [
                'FREE_TRIAL' => $included[1],
                'STARTER'    => $included[0],
                'PRO'        => $included[1],
                'ENTERPRISE' => $included[2],
            ];

            foreach ($rows as $planCode => $isIncluded) {
                if (! isset($planIdByCode[$planCode])) continue;
                $planId = $planIdByCode[$planCode];

                $existing = $db->table('plan_features')
                    ->where('plan_id', $planId)
                    ->where('feature_key', $featureKey)
                    ->get()->getRowArray();

                $data = [
                    'plan_id'     => $planId,
                    'feature_key' => $featureKey,
                    'is_included' => $isIncluded ? 1 : 0,
                    'quota_value' => null,
                ];
                if ($existing) {
                    $db->table('plan_features')->where('id', $existing['id'])->update($data + ['updated_at' => $now]);
                } else {
                    $db->table('plan_features')->insert($data + ['created_at' => $now, 'updated_at' => $now]);
                }
            }
        }

        echo "[SubscriptionPlanSeeder] Seeded " . count($plans) . " plans, "
            . count($features) . " feature flags, "
            . (count($matrix) * 4) . " plan-feature matrix entries.\n";
    }
}
