# Product

**Quantara HRIS** — Human Resource Information System untuk perusahaan Indonesia.

Fokus utama:

- **Compliance Indonesia**: payroll PMK 168/2023 (PPh 21 TER), UU 13/2003 + UU Cipta Kerja + PP 35/2021, BPJS (Kesehatan + JHT/JP/JKK/JKM/JKP), THR (PMK 6/2016), pesangon, UU 27/2022 (UU PDP), referensi UMP/UMK.
- **Multi business unit (multi-tenant)**: banyak perusahaan dalam satu instalasi, scoping otomatis via `BaseModel::scoped()` + `PrivacyScope`, konfigurasi payroll/cuti/kehadiran per company.
- **Security berlapis**: JWT (access + refresh), 2FA TOTP + 8 backup codes, Argon2id + password history, password policy R1–R6, magic-byte upload validation, rate limit per endpoint, CORS/CSP/HSTS, encryption at rest untuk field sensitif (NIK, NPWP, rekening), audit log immutable dengan hash chain, idempotency-key untuk mutation kritis, webhook outbox HMAC.
- **Tiga aplikasi terintegrasi**: Backend REST API, Frontend SPA (PWA-ready dengan offline queue), Mobile app (React Native, Tier 1–3 modules termasuk attendance offline outbox + biometric + FCM push).

Modul utama: Auth & RBAC, Master Data, Employee, Attendance (geofence + selfie + anti-cheat + cross-day shift), Shift & Roster, Leave, Overtime, Payroll (slip + BPJS + PPh21 TER + THR + bank export), Reimbursement & Loans, Bonus Engine (formula DSL), Reports, Audit Log, HR Helpdesk dengan SLA, Recruitment & Career Portal, Training, Performance (OKR/KPI/Review), MPP + JIC, Asset Management, Disciplinary, Resignation/Severance, Employee Transfer, Business Trip, Notifications.

Audience: Internal HR + employee self-service. Lisensi proprietary, internal use.

Bahasa default UI: Bahasa Indonesia (i18n ID/EN tersedia di frontend & mobile).
