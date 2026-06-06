# Webhooks — Integration Guide

HRIS punya 2 arah integrasi event real-time:

- **Outbound** (Sprint 11+): HRIS kirim event ke sistem eksternal saat sesuatu terjadi di HRIS. Lihat [bagian outbound](#outbound) di bawah.
- **Inbound** (Sprint 14+): Sistem eksternal kirim event ke HRIS untuk trigger logic internal (mis. recruitment marketplace post lamaran, accounting tool sync vendor PO). Lihat [bagian inbound](#inbound) di bawah.

Keduanya pakai pattern yang sama: HMAC-SHA256 signature, replay protection 5 menit, idempotency via UUID per delivery, dan auto-retry/auto-disable untuk endpoint yang konsisten gagal.

---

## Outbound

HRIS bisa kirim event real-time ke sistem eksternal (accounting, slack, internal apps) lewat webhook outbound. Sprint 11+.

## Overview

```
HRIS event happen
    ↓
WebhookDispatcher::dispatch(event_type, company_id, payload)
    ↓
INSERT row di webhook_deliveries (per webhook yang subscribe)
    ↓
Cron `php spark webhooks:process` setiap menit
    ↓
HTTP POST ke endpoint customer dengan HMAC signature
    ↓
Customer verifikasi signature → process event
```

## Setup di Admin

### Daftarkan webhook baru

```bash
curl -X POST https://api.hris.example.com/api/v1/webhooks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Accurate Sync",
    "url": "https://your-app.com/webhooks/hris",
    "event_types": [
      "employee.created",
      "employee.resigned",
      "payroll.period_finalized"
    ]
  }'
```

Response — **secret muncul SEKALI**, simpan dengan aman:

```json
{
  "status": "success",
  "message": "Webhook dibuat. Simpan secret — tidak akan ditampilkan lagi.",
  "data": {
    "id": 1,
    "name": "Accurate Sync",
    "url": "https://your-app.com/webhooks/hris",
    "event_types": ["employee.created", "employee.resigned", "payroll.period_finalized"],
    "is_active": 1,
    "secret": "a3f7c8e2b9d4..."
  }
}
```

### Lihat daftar event yang tersedia

```bash
curl https://api.hris.example.com/api/v1/webhooks/event-types \
  -H "Authorization: Bearer $TOKEN"
```

Lihat list lengkap di `App\Constants\WebhookEvent::ALL`.

### Rotate secret (kalau kompromi atau periodic rotation)

```bash
curl -X POST https://api.hris.example.com/api/v1/webhooks/1/rotate-secret \
  -H "Authorization: Bearer $TOKEN"
```

Update secret baru di consumer side. Webhook lama (dengan secret lama) tidak akan diterima lagi.

## Anatomy of HTTP Delivery

Saat event terjadi, customer akan menerima HTTP POST:

```http
POST /webhooks/hris HTTP/1.1
Host: your-app.com
Content-Type: application/json; charset=utf-8
User-Agent: HRIS-Webhook/1.0
X-HRIS-Signature: sha256=8f9b3a2c4d...
X-HRIS-Timestamp: 1700000000
X-HRIS-Event: leave.approved
X-HRIS-Delivery: 550e8400-e29b-41d4-a716-446655440000

{
  "event": "leave.approved",
  "company_id": 5,
  "leave_id": 42,
  "employee": {"id": 100, "nik": "EMP001", "name": "Budi"},
  "leave_type": "ANNUAL",
  "start_date": "2026-06-15",
  "end_date": "2026-06-19",
  "approved_by": {"id": 7, "name": "Manager"},
  "approved_at": "2026-05-22T10:30:00+07:00"
}
```

### Headers

| Header | Description |
|---|---|
| `X-HRIS-Signature` | `sha256=<hex>` HMAC signature, format Stripe/GitHub-style |
| `X-HRIS-Timestamp` | Unix timestamp saat signing (untuk replay protection) |
| `X-HRIS-Event` | Event type, mis. `leave.approved` |
| `X-HRIS-Delivery` | UUID unik per delivery (untuk idempotency consumer) |

## Verifying Signatures (Consumer Implementation)

### Node.js / Express

```javascript
const crypto = require('crypto');

function verifyHrisSignature(req, secret) {
  const sig       = req.header('X-HRIS-Signature');     // 'sha256=...'
  const ts        = parseInt(req.header('X-HRIS-Timestamp'), 10);
  const rawBody   = req.rawBody;  // express.raw() middleware

  // 1) Replay protection — reject kalau > 5 menit lama
  if (Math.abs(Date.now() / 1000 - ts) > 300) {
    return false;
  }

  // 2) Compute expected signature
  const expected = 'sha256=' + crypto
    .createHmac('sha256', secret)
    .update(`${ts}.${rawBody}`)
    .digest('hex');

  // 3) Timing-safe comparison
  return crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected));
}

app.post('/webhooks/hris', express.raw({ type: 'application/json' }), (req, res) => {
  if (!verifyHrisSignature(req, process.env.HRIS_WEBHOOK_SECRET)) {
    return res.status(401).send('Invalid signature');
  }

  const event = JSON.parse(req.body.toString());
  const deliveryId = req.header('X-HRIS-Delivery');

  // Idempotency: cek deliveryId di DB consumer; skip kalau sudah pernah diproses
  // ... process event ...

  res.status(200).send('ok');
});
```

### Python / Flask

```python
import hmac, hashlib, time
from flask import request, abort

def verify_hris_signature(secret: str) -> bool:
    sig_header = request.headers.get('X-HRIS-Signature', '')
    ts = int(request.headers.get('X-HRIS-Timestamp', 0))
    raw_body = request.get_data()

    if abs(time.time() - ts) > 300:
        return False

    expected = 'sha256=' + hmac.new(
        secret.encode(),
        f'{ts}.'.encode() + raw_body,
        hashlib.sha256
    ).hexdigest()

    return hmac.compare_digest(expected, sig_header)

@app.route('/webhooks/hris', methods=['POST'])
def hris_webhook():
    if not verify_hris_signature(HRIS_WEBHOOK_SECRET):
        abort(401)
    # process event
    return 'ok', 200
```

### PHP / Laravel

```php
public function handle(Request $request)
{
    $secret = config('services.hris.webhook_secret');
    $sig    = $request->header('X-HRIS-Signature');
    $ts     = (int) $request->header('X-HRIS-Timestamp');
    $body   = $request->getContent();

    if (abs(time() - $ts) > 300) {
        abort(401, 'Timestamp out of window');
    }

    $expected = 'sha256=' . hash_hmac('sha256', "{$ts}.{$body}", $secret);
    if (! hash_equals($expected, $sig)) {
        abort(401, 'Invalid signature');
    }

    // process event...
    return response('ok', 200);
}
```

## Retry Strategy

| Attempt | Delay setelah gagal |
|---|---|
| 1 | langsung |
| 2 | ~1 menit |
| 3 | ~5 menit |
| 4 | ~30 menit |
| 5 | ~2 jam |
| 6+ | tidak retry, status FAILED final |

Jitter ±25% diterapkan untuk anti thundering-herd. Total maksimum window retry ~9 jam.

**Auto-disable**: kalau webhook punya `consecutive_failures >= 10` → otomatis di-set `is_active=0`. Admin harus aktifkan kembali manual lewat PUT `/webhooks/{id}` setelah fix endpoint.

## Idempotency Consumer

Setiap delivery punya `X-HRIS-Delivery` UUID. **Consumer wajib** track UUID yang sudah diproses untuk handle:

- Network glitch → HRIS retry → consumer terima 2× event yang sama
- Consumer ambil down saat process tapi sudah commit DB → retry tidak boleh duplicate effect

Pattern simple:

```sql
CREATE TABLE webhook_processed (
    delivery_id VARCHAR(36) PRIMARY KEY,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sebelum process:
INSERT IGNORE INTO webhook_processed (delivery_id) VALUES (?);
-- kalau row baru → process. Kalau duplicate (existed) → skip.
```

## HTTP Response Expectation

| Status | Behavior |
|---|---|
| `200`-`299` | Sukses, mark DELIVERED, no retry |
| `400`-`499` (kecuali `408`, `429`) | **Tidak di-retry** — error semantic, butuh fix consumer |
| `408`, `429`, `500`-`599` | Retry sesuai backoff schedule |
| Network timeout (>10s) | Retry sesuai backoff schedule |

> Kalau consumer endpoint slow (>10s), HRIS akan abort + retry. Best practice: **terima request → enqueue di consumer side → return 200 cepat → process async**.

## Available Events

Lihat `App\Constants\WebhookEvent::ALL` untuk list lengkap. Highlights:

| Event | Trigger |
|---|---|
| `employee.created` | Karyawan baru di-create di HR module |
| `employee.resigned` | Resignation approved + offboarding triggered |
| `payroll.period_finalized` | Payroll period status FINALIZED |
| `payroll.paid` | Mark-as-paid action di Payroll |
| `payslip.published` | Payslip individual ready ke karyawan |
| `leave.approved` | Cuti final approved oleh approver terakhir |
| `overtime.approved` | Lembur approved |
| `attendance.late_absent` | Karyawan late ≥ X menit ATAU absent (run via cron) |
| `attendance.anomaly_flagged` | Anti-cheat detect anomaly_score ≥ threshold |
| `recruitment.candidate_hired` | Candidate move ke status HIRED |
| `disciplinary.sp_issued` | Surat Peringatan terbit |
| `severance.finalized` | Pesangon PHK dihitung & finalized |

Detail payload schema akan dipublish di `docs/openapi.yaml` per Sprint berikutnya.

## Operations

### Cron job

Tambah ke crontab untuk process queue tiap menit:

```cron
* * * * * cd /var/www/hris/backend && php spark webhooks:process --batch=50 >> /var/log/hris/webhooks.log 2>&1
```

(Spark command `webhooks:process` perlu dibuat — defer Sprint 12+, untuk sekarang panggil `service('webhook')->processQueue()` manual atau dari worker.)

### Monitoring

Cek webhook delivery history per webhook:

```bash
curl https://api.hris.example.com/api/v1/webhooks/1/deliveries?status=FAILED \
  -H "Authorization: Bearer $TOKEN"
```

### Troubleshooting

**Delivery stuck di QUEUED**: cek apakah cron jalan + `attempts < 5`. Lihat `webhook_deliveries.last_error` untuk detail.

**Signature verification fail di consumer**: paling sering karena
- Body di-parse dulu (consumer pakai `express.json()` instead of `express.raw()`) → body diberbeda, signature mismatch
- Secret yang berbeda (rotation tanpa update consumer)
- Clock drift > 5 menit → timestamp out of window

**Auto-disabled webhook**: cek `webhooks.consecutive_failures`. Setelah fix endpoint, PUT `/webhooks/{id}` dengan `is_active: true` (counter auto-reset).

## Security

- Secret di-store **encrypted at rest** lewat `Services::encryption()` (AES-256-GCM)
- Secret muncul **sekali saja** saat create / rotate-secret — kalau hilang, harus rotate
- HMAC-SHA256 dengan timestamp prefix → mitigasi replay attack (window 5 menit)
- Timing-safe comparison wajib di consumer (`hash_equals` di PHP, `crypto.timingSafeEqual` di Node, `hmac.compare_digest` di Python)
- Auto-disable setelah 10 consecutive failures → mencegah zombie endpoint dari spam infinite

## Limits

- Max URL length: 500 char
- Max event types per webhook: tidak dibatasi (subscribe semua kalau perlu)
- Max payload size: 16 MB (LONGTEXT)
- Response body audit: 1 KB pertama disimpan untuk debug
- HTTP timeout: 10 detik per attempt

---

## Inbound

Sprint 14+. HRIS terima event dari sistem eksternal (recruitment marketplace, accounting tool, partner HRIS, dll) lewat HTTP POST yang sudah ditandatangani HMAC-SHA256.

### Flow

```
External system → POST /api/v1/inbound/{slug}
                  Headers: X-HRIS-Signature, X-HRIS-Timestamp, X-HRIS-Source-Event
                  Body:    {"event":"candidate.applied", ...}
                       ↓
HRIS verifikasi signature + replay window
                       ↓
INSERT row di inbound_webhook_events (audit + idempotency)
                       ↓
InboundWebhookHandler::dispatch(event_type, payload, source)
                       ↓
Handler terdaftar (mis. RecruitmentService::importCandidate) eksekusi
                       ↓
Response 200 OK dengan {processed, source_event_id}
```

### Setup admin: daftarkan source

```bash
curl -X POST https://api.hris.example.com/api/v1/inbound-webhooks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Talenta Sync",
    "source": "talenta",
    "event_types": ["candidate.applied", "interview.scheduled"],
    "allowed_ips": ["203.0.113.10", "10.0.0.0/8"]
  }'
```

`event_types` opsional — kalau kosong artinya source boleh kirim event apa saja. `allowed_ips` opsional — kalau ada, request dari IP di luar list akan ditolak 403 (CIDR-aware untuk IPv4).

Response — secret + URL muncul **sekali saja**. Bagikan ke vendor:

```json
{
  "data": {
    "id": 7,
    "slug": "talenta-sync-a3f2c1",
    "name": "Talenta Sync",
    "source": "talenta",
    "url": "https://api.hris.example.com/api/v1/inbound/talenta-sync-a3f2c1",
    "secret": "9f8e7d6c5b4a..."
  }
}
```

### Anatomy request inbound

Sender (sistem eksternal) harus kirim request dengan header:

```
POST /api/v1/inbound/talenta-sync-a3f2c1 HTTP/1.1
Host: api.hris.example.com
Content-Type: application/json
X-HRIS-Signature: sha256=<hex>
X-HRIS-Timestamp: 1769172000
X-HRIS-Source-Event: 8e7c3f12-aa-bb-cc-dd  ← UUID unik per event
Content-Length: ...

{"event":"candidate.applied","candidate":{"name":"Budi","email":"budi@x.com"},"event_id":"8e7c3f12-..."}
```

Field `event` (atau `event_type`) di body wajib. Field `event_id` di body opsional kalau header `X-HRIS-Source-Event` sudah dikirim.

### Signature

Identik dengan outbound — sender hitung HMAC-SHA256 dari string `{timestamp}.{rawBody}` pakai shared secret:

**Node.js sender example:**
```js
const crypto = require('crypto')
const body   = JSON.stringify({ event: 'candidate.applied', candidate: {...}, event_id: uuid() })
const ts     = Math.floor(Date.now() / 1000)
const sig    = 'sha256=' + crypto.createHmac('sha256', SECRET).update(`${ts}.${body}`).digest('hex')

await fetch(URL, {
  method: 'POST',
  headers: {
    'Content-Type':       'application/json',
    'X-HRIS-Signature':   sig,
    'X-HRIS-Timestamp':   ts.toString(),
    'X-HRIS-Source-Event': uuid(),
  },
  body,
})
```

### Response codes

| Code | Meaning | Sender action |
|---|---|---|
| **200** | Event diproses sukses oleh handler | Done |
| **202** | Event diterima tapi belum ada handler terdaftar (audit-only) | Done |
| **200** + `duplicate:true` | Idempotent — sudah pernah diproses | Done, jangan retry |
| **400** | Body bukan JSON valid / `event` field missing | Fix payload |
| **401** | Signature mismatch / timestamp expired | Cek secret + clock |
| **403** | IP tidak dalam allowlist | Hubungi admin HRIS |
| **404** | Slug tidak terdaftar atau source dinonaktifkan | Hubungi admin HRIS |
| **422** | Event type tidak dalam filter source | Cek subscription |
| **500** | Handler internal error | Retry boleh; admin akan investigate |

### Idempotency

Source HARUS kirim `X-HRIS-Source-Event` (UUID atau identifier unik per event) supaya retry sender (timeout, network error) tidak duplicate process di sisi HRIS.

UNIQUE constraint di `inbound_webhook_events(inbound_id, source_event_id)` mencegah double insert. Saat duplicate ter-detect, HRIS balas 200 OK dengan `{"duplicate":true,"source_event_id":"..."}`.

### Daftarkan handler internal

Backend HRIS pakai pattern registry. Daftarkan handler di `App\Config\Events.php` atau bootstrap controller saat boot:

```php
service('inbound')->register('candidate.applied', function (array $payload, array $source) {
    $candidate = service('recruitment')->createCandidateFromInbound([
        'job_posting_id' => $payload['job_posting_id'] ?? null,
        'full_name'      => $payload['candidate']['name'] ?? '',
        'email'          => $payload['candidate']['email'] ?? null,
        'source'         => strtoupper($source['source'] ?? 'OTHER'),
    ]);
    return ['candidate_id' => $candidate['id']];
});

// Multiple handler boleh untuk event yang sama (best-effort, semua dieksekusi)
service('inbound')->register('candidate.applied', function (array $payload, array $source) {
    log_message('info', "Inbound candidate from {$source['slug']}: {$payload['candidate']['email']}");
});
```

Handler yang throw akan di-log + flag failed, tapi handler lain di event yang sama tetap eksekusi (best-effort dispatch).

### Reading audit log

Admin HR bisa lihat riwayat event diterima per source via endpoint:

```bash
curl 'https://api.hris.example.com/api/v1/inbound-webhooks/7/events?status=PROCESSED' \
  -H "Authorization: Bearer $TOKEN"
```

Filter `?status=` boleh: `RECEIVED`, `PROCESSED`, `FAILED`, `REJECTED`. Kolom `payload` & `headers` simpan raw request (untuk replay & debug).

### Rotate secret

Sama dengan outbound — kalau secret bocor:

```bash
curl -X POST https://api.hris.example.com/api/v1/inbound-webhooks/7/rotate-secret \
  -H "Authorization: Bearer $TOKEN"
```

Bagikan secret baru ke vendor. Window replay 5 menit cukup untuk vendor pivot kunci tanpa downtime.

### Limits & rate

- Throttle: 60 request/menit per (IP × slug). Override via env `ratelimit.inboundWebhookAttempts` & `ratelimit.inboundWebhookWindow`.
- Replay window: 5 menit (sama dengan outbound `WebhookSigner::REPLAY_TOLERANCE_SECONDS`).
- Max payload size: 16 MB (LONGTEXT).
- Max headers logged: 7 spesifik (X-HRIS-*, User-Agent, Content-Type) — header lain di-strip dari audit log untuk privacy.
