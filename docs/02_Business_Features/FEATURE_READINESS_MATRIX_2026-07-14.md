# Feature Readiness Matrix — 2026-07-14

Dokumen ini adalah sumber kebenaran untuk klaim fitur di README, katalog paket, menu, API, dan kesiapan operasional. Status ditentukan dari bukti repository per 14 Juli 2026; status **Production-ready** di sini berarti alur tersedia dan lolos gate otomatis repository, bukan pengganti UAT, pentest, load test, atau restore drill di lingkungan produksi.

## Definisi status

| Status | Arti |
|---|---|
| Production-ready | Backend dan antarmuka pengguna tersedia, kontrol akses/tenant diterapkan, serta ada bukti tes yang relevan. Tetap membutuhkan deployment gate. |
| Beta | Alur dapat digunakan, tetapi integrasi eksternal, coverage, atau bukti operasional belum cukup untuk klaim GA. |
| Backend only | API/domain tersedia tanpa antarmuka pengguna yang lengkap. |
| Frontend only | Antarmuka tersedia tetapi backend belum nyata. Status ini tidak ditemukan dalam audit saat ini. |
| Partially wired | Sebagian alur ada, tetapi dependency atau sambungan penting belum lengkap. |
| Feature-flagged | Implementasi sengaja dimatikan secara default dan harus diaktifkan per paket/tenant. |
| Placeholder | Key/entitlement disediakan untuk roadmap, tetapi produk belum boleh dijual sebagai tersedia. |
| Deprecated | Tidak boleh dipakai untuk implementasi baru. Status ini tidak ditemukan dalam katalog aktif saat ini. |

## Modul yang diiklankan

| Area/modul | Status | Bukti dan batasan utama |
|---|---|---|
| Auth, RBAC, 2FA, password policy | Production-ready | Filter JWT/permission terpusat, route policy matrix 944/944, serta negative authorization tests tersedia. |
| Master data dan Core HR | Production-ready | API dan UI company/branch/department/position/employee tersedia; tenant scan tetap memiliki utang legacy yang sedang diremediasi. |
| Attendance, shift, roster, leave, overtime | Production-ready | API/UI dan business-flow tests tersedia; geofence/WFH aktif, overtime pre-approval bergantung paket. |
| Payroll, BPJS, PPh 21 TER, THR, bank export | Production-ready | Lifecycle, four-eyes control, reconciliation, variance, final payroll, dan regression tests tersedia. Aktivasi disbursement nyata tetap membutuhkan credential provider. |
| Reimbursement, loan, bonus, natura | Production-ready | API/UI tersedia; OCR reimbursement berstatus Beta dan feature-flagged. |
| Recruitment, career portal, assessment, referral | Production-ready | API/UI publik dan internal tersedia; perlu E2E browser untuk bukti journey lintas halaman. |
| Performance, training, competency, succession, marketplace | Production-ready | API/UI tersedia; fitur talent lanjutan dibatasi paket. |
| Reports, analytics, helpdesk, asset, disciplinary, lifecycle, transfer, SPPD | Production-ready | Route/controller/view tersedia; klaim produksi tetap tunduk pada UAT per tenant. |
| Audit log, UU PDP, WLKP | Production-ready | Audit hash chain, export/erasure flow, dan route policies tersedia; legal sign-off tetap tanggung jawab operator. |
| API key developer access | Beta, Feature-flagged | Scoped API key, rotate/revoke, usage log, route, dan UI tersedia. Tidak ada OAuth 2.0 authorization server. |
| ERP journal | Beta, Feature-flagged | Backend dan dashboard tersedia; konektor/provider serta credential nyata harus diuji per deployment. |
| Webhooks | Production-ready, Feature-flagged | Outbox, HMAC, replay protection, retry/dead-letter, dan metrik tersedia. |
| Email notification | Production-ready | Channel dan provider adapters tersedia; deliverability bergantung konfigurasi provider. |
| FCM mobile push | Beta | Implementasi HTTP v1 tersedia; bukti delivery membutuhkan service-account dan perangkat nyata. |
| Web Push VAPID | Beta, Partially wired | Subscribe/unsubscribe dan service-worker flow tersedia. Delivery fail-closed bila paket opsional `minishlink/web-push` belum dipasang. |
| WhatsApp | Beta, Feature-flagged | Service dan settings UI tersedia dengan model BYO credential; belum boleh dianggap aktif tanpa provider test. |
| AI HR Assistant | Beta, Feature-flagged | Backend/UI ada, dimatikan default, dan membutuhkan model/provider serta evaluasi keamanan data. |
| Turnover, workforce scenario, compensation, pulse analytics | Beta, Feature-flagged | Backend/UI tersedia dan dimatikan default; hasil prediktif perlu validasi data dan model tenant. |
| White-label dan multi-company | Beta, Feature-flagged | Implementasi dan entitlement tersedia; custom-domain/TLS serta isolasi deployment perlu acceptance test. |
| Dedicated CSM | Feature-flagged | Ini entitlement layanan operasional, bukan modul perangkat lunak. |
| SSO SAML/OIDC | Placeholder | Belum ada provider, callback, metadata, atau acceptance test. Matrix paket dipaksa `0` untuk semua plan. |

## Katalog feature flag

| Status | Feature key |
|---|---|
| Production-ready | `payroll.basic`, `payroll.simulate`, `payroll.bank_export`, `payroll.thr`, `payroll.bonus_engine`, `payroll.gross_up`, `payroll.natura`, `attendance.geofence`, `attendance.wfh_mode`, `attendance.overtime_pre`, `recruitment.basic`, `recruitment.online_test`, `recruitment.scoring`, `recruitment.referral`, `performance.review`, `performance.cascading`, `performance.peer_360`, `talent.competency`, `talent.succession`, `talent.marketplace`, `compliance.audit_log`, `compliance.uu_pdp`, `compliance.wlkp_export`, `integration.webhooks`, `notification.email` |
| Beta + Feature-flagged | `reimbursement.ocr`, `intel.turnover_analytics`, `intel.ai_copilot`, `intel.workforce_scenarios`, `intel.compensation`, `intel.pulse_survey`, `integration.api_access`, `integration.erp`, `notification.whatsapp`, `branding.white_label`, `enterprise.multi_company` |
| Operational entitlement | `enterprise.dedicated_csm` |
| Placeholder, disabled all plans | `integration.sso` |

Tidak ada feature key aktif yang diklasifikasikan **Frontend only** atau **Deprecated**. Web Push dan FCM belum memiliki feature key tersendiri; status keduanya harus tetap ditampilkan sebagai Beta sampai bukti delivery produksi tersedia.

## Gate promosi status

- Beta menjadi Production-ready hanya setelah happy path, failure/retry, tenant isolation, permission denial, dan observability terbukti.
- Integrasi eksternal membutuhkan sandbox/credential test dan runbook rotasi secret.
- Journey kritis membutuhkan browser E2E: login/2FA, attendance, leave approval, payroll finalize, candidate offer, dan tenant onboarding.
- Release produksi tetap diblokir sampai MySQL CI, sanitized snapshot migration, encrypted restore drill, pentest, dan load test selesai.
- Setiap perubahan README atau `SubscriptionPlanSeeder` harus mempertahankan `FeatureReadinessPolicyTest` hijau.

