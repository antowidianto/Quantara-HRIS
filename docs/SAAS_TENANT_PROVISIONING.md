# Quantara HRIS — Tenant Provisioning Reference

End-to-end flow self-service signup → tenant provisioned + auto-login.
Dokumen ini menjelaskan semua step, edge cases, rollback matrix, dan
debug guidance untuk operator.

> Code reference:
> - DB: `backend/app/Database/Migrations/2026-08-10-000001_S1_3_SignupAttempts.php`
> - BE: `backend/app/Libraries/Tenant/TenantProvisioningService.php`
> - BE: `backend/app/Controllers/Api/V1/Auth/AuthSignupController.php`
> - FE: `frontend/src/views/Signup.vue` + `VerifyEmail.vue`

---

## High-Level Flow

```
┌──────────┐     ┌──────────────┐     ┌────────────┐     ┌──────────┐
│ Marketing│────▶│  /signup     │────▶│ verify-    │────▶│Onboarding│
│  page    │     │  (form)      │     │ email link │     │ wizard   │
└──────────┘     └──────────────┘     └────────────┘     └──────────┘
                       │                     │                  │
                       ▼                     ▼                  ▼
                 signup_attempts        provisioning         8-step
                 (status PENDING)       transaction          checklist
                                        (companies +
                                         users +
                                         subscriptions)
```

---

## Step-by-Step

### 1. User submit form `/signup`

Frontend: `Signup.vue` form dengan validation real-time.

```js
POST /api/v1/auth/signup
Content-Type: application/json

{
  "email": "admin@perusahaan.co.id",
  "company_name": "PT Contoh Indonesia",
  "admin_name": "Budi Hartono",
  "phone": "0812-3456-7890",
  "password": "rahasia12345",
  "password_confirm": "rahasia12345",
  "industry": "manufacturing",
  "employees_count_estimate": 50,
  "signup_source": "google_ads",
  "referrer_code": "PARTNER01",
  "utm_campaign": "q3-launch",
  "utm_medium": "cpc",
  "utm_source": "google",
  "agreed_terms": true
}
```

Backend (`AuthSignupController::signup`):

1. **Validate input** (email format, password ≥ 8 char, password_confirm matches, agreed_terms required)
2. **Anti-abuse cek**:
   - Email tidak boleh sudah terdaftar di `users` (return 409 EMAIL_EXISTS)
   - Tidak boleh ada signup PENDING/VERIFIED dengan email sama belum expired (re-send email instead)
   - Rate limit: max 3 signup attempt / IP / jam (return 429 RATE_LIMITED)
   - Rate limit kedua: filter `throttle:signup` 3/3600s/path
3. **Insert** `signup_attempts` row dengan:
   - `status = PENDING`
   - `verification_token = bin2hex(random_bytes(32))` → 64-char hex
   - `password_hash = password_hash(pw, BCRYPT, cost=12)`
   - `expires_at = NOW + 24 hours`
   - Capture UTM + referrer + IP + user_agent untuk attribution
4. **Audit log** `signup.requested` severity NOTICE
5. **Send verification email** (best-effort, tidak fail signup kalau SMTP down)
6. **Response 201** dengan `{ signup_id, expires_at }`

### 2. User klik link email

Email berisi link `https://app.quantara.io/verify-email?token=<64-char-hex>`.

Frontend: `VerifyEmail.vue` mount → state `verifying`:

```js
GET /api/v1/auth/verify-signup?token=<token>
```

Backend (`AuthSignupController::verify`):

1. **Validate token format** (must 64 char)
2. **Lookup signup** by verification_token
3. **Idempotency check**:
   - Status = PROVISIONED → return `{ already_provisioned: true }` (no error)
   - Status = FAILED → return 422 dengan error_message
   - Status = EXPIRED OR expires_at < NOW → return 410 TOKEN_EXPIRED
4. **Mark VERIFIED** (intermediate state untuk audit trail)
5. **Call `TenantProvisioningService.provision($signupId)`**:
   - BEGIN transaction
   - Generate company code unik
   - INSERT `companies`
   - INSERT `users` (role super_company_admin)
   - Call `SubscriptionService.createTrial()` → INSERT subscriptions + subscription_events
   - Sync `companies.trial_ends_at` dari subscription
   - UPDATE signup_attempts.status = PROVISIONED + simpan IDs
   - COMMIT
   - Audit log severity NOTICE
6. **Generate JWT** untuk auto-login (TTL 8 jam, role_id = 1)
7. **Response 200** dengan `{ token, redirect_to: '/onboarding', company_id, user_id }`

Frontend menerima response → `auth.setToken(token)` → redirect ke `/onboarding`.

### 3. User di onboarding wizard `/onboarding`

8-step checklist (lihat `frontend/src/views/Onboarding/OnboardingWizard.vue`):

1. Upload logo perusahaan
2. Set timezone & currency
3. Undang karyawan pertama
4. Buat periode payroll
5. Generate payslip pertama
6. Approve cuti pertama
7. Eksplorasi integrasi
8. Baca dokumentasi

Setiap step mark complete via `POST /onboarding/{step_key}/complete`.
Saat completion_percent = 100%, `companies.onboarding_completed_at` di-set
untuk SaaS metrics signup funnel.

---

## Provisioning Transaction Detail

Pure inside `TenantProvisioningService::provision()`. Single DB transaction
untuk atomicity:

```php
$db->transStart();
try {
    $code = $this->generateCompanyCode($signup['company_name']);
    // 1. Insert company
    $companyId = (int) $db->insertId();

    // 2. Insert admin user
    //    role_id = 1 (super_company_admin per-tenant)
    $userId = (int) $db->insertId();

    // 3. Create TRIAL subscription
    $subSvc = new SubscriptionService();
    $sub = $subSvc->createTrial($companyId, 'FREE_TRIAL', $userId);

    // 4. Sync trial_ends_at to companies (fast-filter)
    $db->table('companies')->where('id', $companyId)->update([
        'trial_ends_at' => $sub['trial_ends_at'],
    ]);

    // 5. Mark signup_attempts.PROVISIONED
    $db->table('signup_attempts')->update([
        'status' => 'PROVISIONED',
        'provisioned_at' => NOW,
        'provisioned_company_id' => $companyId,
        'provisioned_user_id' => $userId,
    ]);

    $db->transComplete();
} catch (Throwable $e) {
    $db->transRollback();
    // Mark FAILED supaya bisa retry / investigate
    $db->table('signup_attempts')->update([
        'status' => 'FAILED',
        'error_message' => $e->getMessage(),
    ]);
    throw $e;
}
```

---

## Company Code Generation

Algoritma `TenantProvisioningService::generateCompanyCode()`:

```
Input: "PT Quantara Tech"
1. Strip prefix umum: "PT", "CV", "Yayasan", "Koperasi"
   → "Quantara Tech"
2. Take first word, alfanumerik only, UPPERCASE
   → "QUANTARA"
3. Truncate ke max 8 char
   → "QUANTARA"
4. Cek conflict di companies.code
   → kalau exists, append suffix: "QUANTARA1", "QUANTARA2", ...
5. Fallback random kalau >999 conflict (sangat jarang)
   → "QUANTARA" + 4 hex random
```

Edge cases:
- "PT 99 Plus" → "99PLUS"
- "CV (Empty)" → "TENANT" + suffix
- "李四公司" (non-ASCII) → strip non-ASCII → kalau kosong → "TENANT"

---

## Anti-Abuse & Security

### Rate Limiting

3 layer protection:

1. **Application-level dedup**: cek email di `users` + signup_attempts PENDING
2. **IP throttling** di service: max 3 attempt / IP / jam (di
   `AuthSignupController` sebelum insert)
3. **Filter throttle:signup** di `ThrottleFilter` (3/3600s per IP+path)

### Email Verification

- Token: `bin2hex(random_bytes(32))` = 64 char (256 bits entropy)
- TTL: 24 jam, expired auto-mark di backend
- One-time use: setelah verified, status berubah ke PROVISIONED — link tidak bisa dipakai ulang

### Password Storage

`password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])` — sesuai standar
modern. Hash disimpan di `signup_attempts.password_hash` selama PENDING/
VERIFIED, lalu di-copy ke `users.password_hash` saat provision.

`signup_attempts.password_hash` TIDAK dihapus setelah provision (untuk
audit trail), tapi field ini di-mask di endpoint status.

### Audit

```
audit_logs (severity NOTICE / HIGH):
  signup.requested            (NOTICE) — saat POST /signup sukses
  signup.email_resent         (NOTICE) — re-send karena PENDING ada
  signup.verified             (NOTICE) — saat token verified
  tenant.provisioned          (NOTICE) — provisioning sukses
  tenant.provisioning_failed  (HIGH)   — provisioning gagal, butuh investigate
```

---

## Edge Cases & Rollback Matrix

| Skenario | Apa yang terjadi | Recovery |
| --- | --- | --- |
| User submit form, email service down | signup_attempts row PENDING tersimpan, response 201 sukses, user tidak terima email | User signup ulang dengan email sama → backend re-send email (idempotent) |
| User klik link expired | Backend mark EXPIRED, response 410 | UI tampilkan "Signup Ulang" button → form `/signup` |
| User klik link 2x bersamaan | Row level lock di transaction → satu sukses jadi PROVISIONED, satu return `already_provisioned: true` | Idempotent by design |
| Race signup beda IP, email sama | First insert win (unique index sebenarnya tidak ada di email — pakai dedup explicit di controller) | Cek ulang di POST handler; jika race terjadi, tetap insert dua row, tapi salah satu akan gagal di provision (companies.email duplicate kalau ada constraint) |
| Provisioning gagal di INSERT users (mis. disk full) | Transaction rollback → companies dihapus, signup_attempts.status = FAILED | Super admin bisa POST /admin/signup/{id}/retry (TODO) atau hapus row + minta user signup ulang |
| Provisioning sukses tapi audit log gagal | Provision committed, audit insert dilewat (try/catch silent) | Tidak fatal, user sudah provisioned. Audit gap di-log ke `writable/logs/` |
| User cancel mid-onboarding | Tidak ada efek — onboarding_completed_at tetap null | Saat login berikutnya, redirect ke `/onboarding` lagi |
| Email user sudah terdaftar di tenant lain | Backend reject 409 EMAIL_EXISTS | UI tampilkan "Login dengan email ini" link |

---

## Default Master Data Bagi Tenant Baru

Saat tenant baru di-provision, master data berikut **dishared dari global
seed** (tidak di-copy per-company):

- `subscription_plans` (4 tier global)
- `feature_flags` (38 flags global)
- `permissions` (RBAC permissions)
- `roles` (sistem roles 1-5)
- `online_test_categories` (built-in 14 kategori)

Master data berikut **belum di-seed otomatis** (TODO untuk MVP):
- `leave_types` — tenant harus setup manual atau jalankan seeder via spark
- `payroll_components` — sama
- `bpjs_rates` — global, ada migration default
- `ptkp_rates` — global PMK 168
- `holidays_indonesia` — global, ada migration default

Action item: tambah `php spark provision:seed-tenant <company_id>` command
untuk auto-seed master data untuk tenant baru. Future enhancement.

---

## Cleanup Cron

Untuk hygienis, `signup_attempts` row stale harus dibersihkan:

```cron
# Setiap hari 03:00 — purge signup_attempts EXPIRED > 30 hari
0 3 * * * cd $HRIS_DIR && php spark idempotency:purge
# (Future: bikin command khusus signup_attempts purge)
```

Untuk row PROVISIONED, jangan hapus — keep untuk audit trail signup
attribution (UTM analytics, referrer tracking).

---

## Frontend UX

### `Signup.vue` features

- Real-time validation (email format, password match, password strength)
- Password strength meter (Lemah/Sedang/Kuat)
- UTM auto-capture dari query string (utm_source/medium/campaign/referrer/source)
- Industry + employees_count_estimate untuk segmentation
- Terms checkbox required
- Trust indicators di footer (PMK 168, UU PDP, BPJS, JKN compliant)
- Submitted state: hide form, tampil "Cek email Anda" + email yang di-target

### `VerifyEmail.vue` 4-state machine

```
verifying → loading spinner ("Memverifikasi…")
   ↓
   ├── success     → simpan JWT, redirect /onboarding setelah 1.5s
   ├── already     → tombol "Login Sekarang"
   └── error       → tombol "Signup Ulang" (kalau TOKEN_EXPIRED)
                     atau "Login" (untuk kasus lain)
```

---

## Local Development & Testing

### Test happy path

```bash
# 1. Submit signup
curl -X POST http://localhost:8080/api/v1/auth/signup \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@dev.local",
    "company_name": "PT Test Dev",
    "password": "Test12345!",
    "password_confirm": "Test12345!",
    "agreed_terms": true
  }'

# Response: { signup_id: 1, expires_at: "..." }
```

### Bypass email verification

Karena email service di local mungkin belum setup, ambil token langsung dari DB:

```sql
SELECT id, verification_token FROM signup_attempts WHERE status = 'PENDING' ORDER BY id DESC LIMIT 1;
```

Lalu hit verify endpoint:

```bash
curl "http://localhost:8080/api/v1/auth/verify-signup?token=<paste_token>"
# Response: { token: "<jwt>", redirect_to: "/onboarding", company_id: 2, ... }
```

### Reset signup attempt untuk re-test

```sql
DELETE FROM signup_attempts WHERE email = 'test@dev.local';
DELETE FROM users WHERE email = 'test@dev.local';
DELETE FROM companies WHERE email = 'test@dev.local';
DELETE FROM subscriptions WHERE company_id IN (SELECT id FROM companies WHERE email = 'test@dev.local');
```

---

## Production Checklist

Sebelum membuka signup ke publik:

- [ ] SMTP / SES configured + verified domain (lihat `docs/EMAIL_DELIVERABILITY.md`)
- [ ] CAPTCHA / Cloudflare Turnstile di form signup (defer, sekarang hanya rate limit IP)
- [ ] Privacy policy + ToS pages live di `/terms` dan `/privacy`
- [ ] Welcome email template dengan branding final
- [ ] Onboarding wizard tested end-to-end (8 steps deep-link work)
- [ ] Audit log review per minggu untuk signup.requested vs tenant.provisioned ratio
- [ ] Monitor signup_attempts.status FAILED → trigger Slack alert kalau > 5 / hari

---

*Versi 1.0 · 2026-08-10 · Update bila ada step baru di provisioning workflow.*