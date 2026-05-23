# 0002. Authentication: JWT (bukan session-based)

**Status**: Accepted
**Tanggal**: 2024-01

## Konteks

HRIS punya kebutuhan auth:
- **Frontend SPA** (Vue 3 + Vite) — bukan server-rendered, butuh API token-based
- **Mobile** (PWA, future native via Capacitor) — tidak praktis pakai cookie session
- **Multi-device** per user — karyawan login dari laptop kantor + HP pribadi
- **Multi-tenant scoping** — setiap request harus tahu `company_id` dari user
- **Stateless backend** — bisa scale horizontal tanpa shared session storage

Server bisa jadi multi-instance di belakang load balancer; session-based butuh sticky session atau Redis session store.

## Keputusan

Pakai **JWT (JSON Web Token)** dengan pattern access token + refresh token rotation:
- **Access token**: JWT signed dengan HS256, lifetime 60 menit, dikirim di `Authorization: Bearer <token>` header
- **Refresh token**: random opaque string (bukan JWT), lifetime 30 hari, disimpan di tabel `refresh_tokens` dengan flag `is_revoked`
- Refresh rotation: setiap call `/auth/refresh` issue refresh token baru, revoke yang lama (mitigasi token theft)
- Library: `firebase/php-jwt` (well-maintained, no eval, no extension dep)

Claim di JWT:
```json
{
  "sub": <user_id>,
  "company_id": <int>,
  "employee_id": <int|null>,
  "roles": [<role_slugs>],
  "permissions": [<permission_keys>],
  "iat": <unix>,
  "exp": <unix>,
  "iss": "hris-api"
}
```

`company_id` di-baca oleh `JwtFilter` → set ke `Services::tenant()` → dipakai semua controller untuk multi-tenant scoping.

## Alternatif yang Dipertimbangkan

### Session-based (CodeIgniter native session + cookie)
- ✅ Simpler — tidak ada token expiration handling di client
- ✅ Built-in CSRF protection
- ❌ Butuh sticky session di load balancer ATAU Redis session storage
- ❌ Tidak praktis untuk mobile native (cookie scope berbeda dari WebView)
- ❌ Cross-domain (frontend di subdomain berbeda dari backend) butuh `SameSite=None` + HTTPS strict

### OAuth 2.0 / OpenID Connect
- ✅ Standard untuk integrasi 3rd-party
- ✅ Single source of truth via Identity Provider (Auth0, Keycloak, Google Workspace)
- ❌ Overkill untuk single-app scenario (HRIS internal, bukan platform multi-app)
- ❌ Operational complexity — perlu IdP terpisah, secret rotation, JWKS endpoint
- ❌ Biaya lisensi Auth0/Okta tidak masuk untuk customer SMB Indonesia

OAuth/OIDC nanti bisa ditambah sebagai _alternative login flow_ (Google Workspace SSO untuk korporasi enterprise), tapi default flow tetap JWT.

### Stateless JWT only (no refresh token)
- ✅ Simple — no DB lookup
- ❌ Bahaya — kalau token expired, user harus login ulang penuh (UX buruk)
- ❌ Tidak bisa logout (token masih valid sampai expired)

### JWT + refresh token (PILIHAN)
- ✅ Stateless untuk request normal (no DB lookup di JwtFilter, hanya signature verify)
- ✅ Refresh rotation = sliding window, UX bagus
- ✅ Logout via revoke refresh_token
- ✅ Account lockout: tinggal revoke semua refresh_tokens user → semua device logout
- ❌ Refresh token storage perlu DB (tradeoff yang acceptable — hanya hit DB saat refresh, bukan setiap request)

## Konsekuensi

### Positif
- Backend stateless → bisa scale horizontal mudah
- Mobile-friendly — token disimpan di secure storage (Keychain iOS, EncryptedSharedPreferences Android)
- Multi-device alami — setiap device punya pasangan token sendiri
- Granular logout — bisa logout 1 device tertentu via revoke refresh_token spesifik (UI "Active Sessions" — defer Sprint 10+)
- Permissions baked-in di token claim → tidak perlu DB hit untuk permission check (cocok dengan PrivacyScope)

### Negatif
- Token revocation BUKAN immediate untuk access token — kalau access token bocor, masih valid sampai expired (max 60 menit)
- Token size lebih besar dari session ID (~500-800 byte vs 32 byte) → header overhead per request
- Permissions di token = stale kalau di-revoke mid-session (max 60 menit drift)
- Secret management (`jwt.secret`, `jwt.refreshSecret`) jadi kritis — bocor = catastrophic

### Mitigasi
- Access token lifetime pendek (60 menit) — kompromi antara overhead refresh dan exposure window
- Refresh token rotation — kalau attacker steal refresh token, korban yang refresh duluan akan invalidate attacker
- Audit log setiap login + refresh + logout (lihat `docs/AUDIT.md`)
- Rate limit `/auth/login` (5/menit) + `/auth/refresh` (default api bucket) untuk brute-force
- 2FA TOTP optional + backup codes untuk role admin (Sprint 3)
- Secret tersimpan di `.env` di luar git, di-load via CI4 `env()`
- Sprint 3+: rotate kalau leak detected via `RotateEncryption` command (sama mekanism untuk encryption keys)

### Open question (deferred Sprint 10+)
- Refresh token belum track `device_fingerprint` + `last_used_at` → semua refresh token user di-revoke saat logout dari satu device
- UI "Active Sessions" di Profile → user bisa lihat list device + tombol "Sign out other devices"
- Tracking ini butuh tambahan kolom di `refresh_tokens` table + UI work