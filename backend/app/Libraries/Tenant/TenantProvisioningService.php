<?php

namespace App\Libraries\Tenant;

use App\Libraries\Billing\SubscriptionService;
use Config\Database;
use Config\Services;
use RuntimeException;

/**
 * S1.3 — Tenant Provisioning Service (Roadmap SaaS).
 *
 * Service ini mengubah `signup_attempts` (status VERIFIED) menjadi tenant
 * yang fully-provisioned: company + super admin user + subscription TRIAL.
 *
 * Workflow:
 *   1. Validasi signup row exists & belum di-provision
 *   2. BEGIN transaction
 *   3. Generate company code unik dari company_name
 *   4. Insert companies (timezone Asia/Jakarta default, currency IDR)
 *   5. Insert users (email = signup.email, role_id = 1 super_company_admin,
 *      company_id = baru, password_hash dari signup row)
 *   6. Buat subscription TRIAL via SubscriptionService.createTrial()
 *   7. Mark signup_attempts.status = PROVISIONED + simpan company_id, user_id
 *   8. COMMIT
 *
 * Bila ada step yang gagal, transaction di-rollback supaya tidak ada
 * orphan data (company tanpa user, atau user tanpa subscription).
 *
 * Audit log severity NOTICE untuk provisioning sukses, ERROR kalau gagal.
 */
class TenantProvisioningService
{
    /**
     * Provision tenant dari signup_attempts row.
     *
     * @return array{company_id:int, user_id:int, subscription_id:int}
     * @throws RuntimeException Bila signup tidak ditemukan / sudah di-provision / gagal.
     */
    public function provision(int $signupId): array
    {
        $db = Database::connect();

        $signup = $db->table('signup_attempts')->where('id', $signupId)->get()->getRowArray();
        if (! $signup) {
            throw new RuntimeException("Signup attempt #{$signupId} not found");
        }
        if ($signup['status'] === 'PROVISIONED') {
            return [
                'company_id'      => (int) $signup['provisioned_company_id'],
                'user_id'         => (int) $signup['provisioned_user_id'],
                'subscription_id' => 0, // already done; caller bisa re-fetch
            ];
        }
        if ($signup['status'] !== 'VERIFIED') {
            throw new RuntimeException("Signup #{$signupId} status '{$signup['status']}', butuh VERIFIED dulu");
        }

        $now = date('Y-m-d H:i:s');
        $db->transStart();

        try {
            // ─── 1. Generate company code unik ──────────────
            $code = $this->generateCompanyCode((string) $signup['company_name']);

            // ─── 2. Insert companies ────────────────────────
            $companyData = [
                'code'              => $code,
                'name'              => $signup['company_name'],
                'phone'             => $signup['phone'] ?? null,
                'email'             => $signup['email'],
                'timezone'          => 'Asia/Jakarta',
                'currency'          => 'IDR',
                'fiscal_year_start' => 1,
                'is_active'         => 1,
                'signup_source'     => $signup['signup_source'] ?? 'organic',
                'referrer_code'     => $signup['referrer_code'] ?? null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
            $db->table('companies')->insert($companyData);
            $companyId = (int) $db->insertID();
            if ($companyId <= 0) {
                throw new RuntimeException('Failed to insert company');
            }

            // ─── 3. Insert user super_company_admin ─────────
            // role_id 1 = super_admin di seed migration core. Di multi-tenant
            // konteks, super_company_admin per tenant juga pakai role_id 1
            // (per-company karena company_id di-set). Untuk Quantara internal
            // super_admin, company_id NULL.
            $userData = [
                'company_id'           => $companyId,
                'role_id'              => 1, // super admin per-tenant
                'employee_id'          => null,
                'email'                => strtolower($signup['email']),
                'password_hash'        => $signup['password_hash'],
                'name'                 => $signup['admin_name'] ?? $signup['company_name'],
                'is_active'            => 1,
                'password_changed_at'  => $now,
                'must_change_password' => 0,
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
            $db->table('users')->insert($userData);
            $userId = (int) $db->insertID();
            if ($userId <= 0) {
                throw new RuntimeException('Failed to insert admin user');
            }

            // ─── 4. Buat subscription TRIAL ─────────────────
            $subSvc = new SubscriptionService();
            $sub = $subSvc->createTrial($companyId, 'FREE_TRIAL', $userId);
            $subscriptionId = (int) ($sub['id'] ?? 0);

            // Sync trial_ends_at ke companies untuk fast filter
            if (! empty($sub['trial_ends_at'])) {
                $db->table('companies')->where('id', $companyId)->update([
                    'trial_ends_at' => $sub['trial_ends_at'],
                    'updated_at'    => $now,
                ]);
            }

            // ─── 5. Update signup row ───────────────────────
            $db->table('signup_attempts')->where('id', $signupId)->update([
                'status'                 => 'PROVISIONED',
                'provisioned_at'         => $now,
                'provisioned_company_id' => $companyId,
                'provisioned_user_id'    => $userId,
                'updated_at'             => $now,
            ]);

            $db->transComplete();

            if ($db->transStatus() === false) {
                throw new RuntimeException('Transaction failed');
            }

            // Audit (post-commit, di luar transaction supaya gagal logging
            // tidak rollback data tenant)
            $this->auditSuccess($companyId, $userId, $signupId);

            return [
                'company_id'      => $companyId,
                'user_id'         => $userId,
                'subscription_id' => $subscriptionId,
            ];
        } catch (\Throwable $e) {
            $db->transRollback();

            // Mark signup gagal supaya bisa retry / investigate
            $db->table('signup_attempts')->where('id', $signupId)->update([
                'status'        => 'FAILED',
                'error_message' => substr($e->getMessage(), 0, 1000),
                'updated_at'    => $now,
            ]);

            $this->auditFailure($signupId, $e);

            throw new RuntimeException('Provisioning failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate company code unik: 3-8 huruf uppercase + suffix angka kalau perlu.
     * Contoh: "PT Quantara Tech" → "QUANTARA" atau "QUANTARA1" kalau sudah ada.
     */
    private function generateCompanyCode(string $companyName): string
    {
        $db = Database::connect();

        // Strip prefix umum (PT, CV, Yayasan, dll)
        $clean = preg_replace('/^(PT|CV|Yayasan|Koperasi)\s+/i', '', trim($companyName));
        // Take first word, uppercase, alphanumeric only
        $firstWord = strtok($clean, ' ');
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $firstWord));
        if (strlen($base) < 3) {
            $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $clean));
        }
        if (strlen($base) < 3) {
            $base = 'TENANT';
        }
        $base = substr($base, 0, 8);

        // Cek conflict; tambah suffix angka kalau sudah ada
        $code = $base;
        $i = 1;
        while ($db->table('companies')->where('code', $code)->countAllResults() > 0) {
            $code = $base . $i;
            if (strlen($code) > 32) {
                // Truncate base supaya total ≤ 32
                $code = substr($base, 0, 32 - strlen((string) $i)) . $i;
            }
            $i++;
            if ($i > 999) {
                // Fallback random
                $code = $base . substr(bin2hex(random_bytes(2)), 0, 4);
                break;
            }
        }
        return $code;
    }

    private function auditSuccess(int $companyId, int $userId, int $signupId): void
    {
        try {
            Services::audit()
                ->forModule('TENANT_PROVISIONING')
                ->log(
                    action: 'tenant.provisioned',
                    entity: 'company',
                    entityId: $companyId,
                    description: "Tenant baru berhasil di-provision: company #{$companyId}, admin user #{$userId}, dari signup #{$signupId}",
                    severity: 'NOTICE',
                    metadata: [
                        'company_id' => $companyId,
                        'user_id'    => $userId,
                        'signup_id'  => $signupId,
                    ],
                );
        } catch (\Throwable $e) {
            log_message('warning', '[TenantProvisioning] audit success failed: ' . $e->getMessage());
        }
    }

    private function auditFailure(int $signupId, \Throwable $e): void
    {
        try {
            Services::audit()
                ->forModule('TENANT_PROVISIONING')
                ->logFailure(
                    action: 'tenant.provisioning_failed',
                    entity: 'signup_attempt',
                    entityId: $signupId,
                    code: 'PROVISIONING_FAILED',
                    description: 'Provisioning gagal: ' . $e->getMessage(),
                );
        } catch (\Throwable $e2) {
            log_message('error', '[TenantProvisioning] audit failure failed: ' . $e2->getMessage());
        }
    }
}