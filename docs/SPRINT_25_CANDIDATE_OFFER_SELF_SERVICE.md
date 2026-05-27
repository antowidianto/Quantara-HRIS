# Sprint 25 — Candidate Offer Self-Service (Option C)

## Konteks

Sebelum sprint ini, alur respond offer kerja di Quantara HRIS adalah:
**HR proxy** — kandidat menerima offer via email/WA, lalu menjawab via channel non-sistem (chat/email), dan HR yang masuk ke `/api/v1/offers/{id}/respond` untuk catat keputusan kandidat.

Pola ini punya 3 masalah:
1. **Audit trail kabur** — log catat HR sebagai actor, padahal keputusan datang dari kandidat. Kalau ada dispute "saya nggak pernah accept", tidak ada bukti IP/UA/timestamp dari kandidat.
2. **Inkonsisten dengan online test** — test sudah self-service via Career Portal, tapi offer (yang lebih penting) tidak.
3. **HR overhead** — HR harus standby untuk catat keputusan, bottleneck saat banyak offer aktif.

Sprint 25 menyelesaikan ini dengan menambahkan kandidat self-service di Career Portal — mirror pola yang sudah ada untuk online test.

## Scope

### Backend

- `OfferService::recordResponseByCandidate()` — method baru di service yang menerima `candidate_account_id` + IP + User-Agent. Audit log pakai `source='CANDIDATE_PORTAL'` + `metadata.actor='candidate'`.
- 3 endpoint baru di `CandidateAuthController` (filter `candidatejwt`):
  - `GET  /api/v1/careers/account/offers` — list semua offer milik kandidat (semua status). Return `pending_count` untuk badge widget.
  - `GET  /api/v1/careers/account/offers/{id}` — detail offer dengan decoded amounts, allowances, benefits. Block `DRAFT` (404) supaya internal HR offer tidak leak.
  - `POST /api/v1/careers/account/offers/{id}/respond` — accept/reject. Body: `{ decision, note?, rejection_reason? }`.
- Ownership check di setiap endpoint via `candidates.candidate_account_id` join — kandidat hanya bisa lihat & respond offer lamarannya sendiri.

### Frontend

- `CandidateOffers.vue` (route `/careers/{slug}/offers`) — list semua offer dengan kartu detail (gaji pokok, status kerja, tanggal mulai, deadline). Status `SENT` di-highlight + badge banner pending count.
- `CandidateOfferDetail.vue` (route `/careers/{slug}/offers/{id}`) — review breakdown kompensasi (gaji + tunjangan + joining bonus + total bulanan), ketentuan kerja (status, probation, tanggal mulai), benefits. Tombol Accept/Reject buka konfirmasi dialog dengan ringkasan + textarea note (Accept) atau alasan (Reject, min 5 karakter).
- `CandidateDashboard.vue` — banner "📩 Anda punya N penawaran kerja" di paling atas kalau ada offer status `SENT` aktif.

### Audit & Forensic Trail

Setiap aksi accept/reject self-service di-log via `AuditLogger` dengan:

```
action       = offer.accepted | offer.rejected
entity       = candidate_offer
source       = CANDIDATE_PORTAL  (vs WEB untuk HR proxy)
severity     = NOTICE | WARNING (REJECTED)
metadata     = {
  actor: 'candidate',
  candidate_account_id: 42,
  candidate_name: 'Budi Santoso',
  candidate_email: 'budi@example.com',
  ip_address: '203.0.113.1',
  user_agent: 'Mozilla/5.0 ...',  (truncated 255 char)
  rejection_reason: '...' | null,
}
```

Kalau ada dispute legal, HR bisa filter audit log:

```sql
SELECT * FROM audit_logs
WHERE entity = 'candidate_offer'
  AND entity_id = 123
  AND source = 'CANDIDATE_PORTAL'
ORDER BY created_at DESC;
```

→ dapat full trail siapa-kapan-dari-IP-mana respond.

## Validation Rules

| Rule | Source | Behavior |
|---|---|---|
| Decision must be `ACCEPTED` or `REJECTED` | Controller + Service | 422 INVALID_INPUT |
| Rejection reason wajib (min 5 char) jika REJECTED | Controller | 422 REJECTION_REASON_REQUIRED / TOO_SHORT |
| Note max 2000 char | Controller (mb_substr) | Auto-truncate |
| Rejection reason max 1000 char | Controller (mb_substr) | Auto-truncate |
| Hanya offer status `SENT` yang bisa direspon | Service | 422 OFFER_BLOCKED |
| `DRAFT` tidak visible ke kandidat | Controller (showOffer) | 404 OFFER_NOT_VISIBLE |
| Offer harus milik account yang login (via `candidate_account_id`) | Controller (ownedOfferOrFail) | 404 |
| Deadline lewat → auto-expire + reject | Service (cek `response_deadline`) | 422 |

## Side Effects

Saat `ACCEPTED`:
1. Status offer → `ACCEPTED`, `responded_at` di-set
2. Candidate stage auto-progress ke `HIRED` (via `RecruitmentService::moveStage`)
3. Notifikasi ke HR users (yang punya `recruitment.offer.manage` atau `recruitment.manage`): "Kandidat menerima offer X via Career Portal — siap di-hire"
4. Audit log `offer.accepted` dengan source `CANDIDATE_PORTAL`

Saat `REJECTED`:
1. Status offer → `REJECTED`, `rejection_reason` tercatat
2. Candidate stage **tidak berubah** (HR yang putuskan re-engage atau reject final)
3. Notifikasi ke HR users: "Kandidat menolak offer X via Career Portal" + alasan
4. Audit log `offer.rejected` dengan source `CANDIDATE_PORTAL`, severity `WARNING`

## Backward Compatibility

Endpoint HR proxy `/api/v1/offers/{id}/respond` (filter `jwt`, permission `recruitment.offer.respond`) **tetap ada** — untuk kasus:
- Kandidat tidak punya akun Career Portal (apply via channel lama)
- HR perlu input keputusan atas nama kandidat (mis. via WA/email)
- Test/migration dari data lama

Service-level: `OfferService::recordResponse()` (HR proxy) dan `recordResponseByCandidate()` (self-service) berbagi DRY logic — sama-sama validasi status, deadline, auto-progress, notify HR. Yang beda hanya audit metadata dan source.

## Test Coverage

`backend/tests/Unit/CandidateOfferSelfServiceTest.php` — 17 tests, 54 assertions. Cover:

- Decision validation (ACCEPTED/REJECTED, case-insensitive, reject invalid input)
- Rejection reason rules (required for REJECTED, min 5 char, optional for ACCEPTED)
- Note & rejection reason max length truncation
- Status gating (hanya SENT yang bisa direspon)
- DRAFT not visible to candidate
- Deadline expiry detection
- Audit metadata shape (actor=candidate, account_id, IP, UA truncated)
- Severity per decision (REJECTED=WARNING, ACCEPTED=NOTICE)

Run:
```bash
cd backend
vendor/bin/phpunit --filter CandidateOfferSelfServiceTest
```

## Effort Aktual

| Fase | Estimasi | Aktual |
|---|---|---|
| Backend service + controller + routes | ~2j | ~1.5j |
| Frontend list + detail + dialog + dashboard widget | ~3j | ~2j |
| Test + dokumentasi | ~1j | ~1j |
| **Total** | **~6j** | **~4.5j** |

PDF letter generator (opsional di scope) di-defer ke Sprint berikutnya — fitur cukup tanpanya karena offer detail sudah tampil lengkap di portal.

## Files Touched

### Backend
- `backend/app/Libraries/OfferService.php` — tambah `recordResponseByCandidate()`
- `backend/app/Controllers/Api/V1/CandidateAuthController.php` — tambah `listOffers()`, `showOffer()`, `respondToOffer()`, `ownedOfferOrFail()`
- `backend/app/Config/Routes.php` — register 3 route baru di group `careers/account` (filter candidatejwt)
- `backend/tests/Unit/CandidateOfferSelfServiceTest.php` — new test file

### Frontend
- `frontend/src/views/Career/CandidateOffers.vue` — new (list)
- `frontend/src/views/Career/CandidateOfferDetail.vue` — new (detail + respond dialog)
- `frontend/src/views/Career/CandidateDashboard.vue` — tambah pending offers badge banner
- `frontend/src/router/index.js` — register 2 route baru (`candidate-offers`, `candidate-offer-detail`)