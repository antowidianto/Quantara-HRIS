# Sprint D — Performance & Scale (Phase 1)

> **Status:** D6 selesai (Circuit Breaker library + 13 unit test). D1, D4, D5, D7 carry-over ke Sprint D+1.
> **Tanggal:** 28 Mei 2026 · Branch: `main`
> **Test count:** 825 → **838** (+13 dari CircuitBreakerTest)

---

## 1. Lingkup yang Diselesaikan

### ✅ D6 — Circuit Breaker untuk External Services

**Masalah audit (`docs/AUDIT_FINDINGS_2026-05.md` D6):**
> Tidak ada query timeout / circuit breaker untuk external service (WhatsApp, OCR, AI Copilot). Saat provider lambat, request HRIS bisa menggantung.

**Solusi:**

- `app/Libraries/CircuitBreaker.php` — implementasi pattern 3-state klasik (CLOSED → OPEN → HALF_OPEN → CLOSED) dengan persisten state via `service('cache')`.
- `app/Libraries/CircuitOpenException.php` — exception khusus saat call ditolak.
- 13 unit test di `tests/Unit/CircuitBreakerTest.php` (34 assertions). Cache di-stub dengan in-memory class anonymous supaya test tidak menyentuh Redis/file.

**API:**

```php
$cb = new CircuitBreaker('whatsapp', failureThreshold: 5, cooldownSeconds: 60);
try {
    $result = $cb->call(fn() => $whatsAppClient->send($payload));
} catch (CircuitOpenException $e) {
    // Service down dalam cooldown — fallback / queue ulang
} catch (\Throwable $e) {
    // Failure asli (timeout, 5xx) — counter sudah di-increment
}
```

**State machine:**

| State       | Behavior                                                                |
|-------------|-------------------------------------------------------------------------|
| `CLOSED`    | Normal. Setiap call diteruskan. Failure di-akumulasi.                   |
| `OPEN`      | Fail-fast. Call ditolak dengan `CircuitOpenException` tanpa eksekusi.   |
| `HALF_OPEN` | Probe. Satu call dicoba. Sukses → CLOSED, gagal → langsung re-OPEN.     |

**Kunci cache:** `hris:cb:{service}` dengan TTL 24 jam (auto-reset kalau service mati permanen).

**Ops monitoring:**

```php
$snapshot = $cb->snapshot();
// → ['service' => 'whatsapp', 'state' => 'open', 'failures' => 7,
//    'opened_at' => 1717000000, 'last_failure' => ..., 'threshold' => 5,
//    'cooldown_seconds' => 60]
```

`reset()` tersedia untuk manual override admin (mis. setelah maintenance).

---

## 2. Carry-Over ke Sprint D+1

### D1 — N+1 Query Audit

- Belum dilakukan EXPLAIN audit endpoint top-50.
- Action: setelah Sprint B+1 selesai (10 Models lengkap), tambahkan eager-load helper di `BaseModel` dan migrate query controller `EmployeeController::index/show`, `LeaveController::index`, `PayslipController::list`.

### D4 — Cache Layer untuk Lookup Stabil

- `CacheService` sudah ada (`app/Libraries/CacheService.php`) dengan `remember()` pattern dan key prefix `hris:`.
- Belum di-wire ke: UMP/UMK lookup, holidays per company-year, role permissions, BPJS rates, leave types.
- Action: di Sprint D+1, audit semua call site yang lookup data quasi-static dan bungkus dengan `$cache->remember(...)`. Tambah event listener di Master CRUD untuk `forget()` saat data berubah.

### D5 — Frontend Bundle Analysis

- Belum: tambah `rollup-plugin-visualizer` di `vite.config.js` dengan output `dist/stats.html`.
- Belum: code-splitting agresif untuk view 1.000+ baris (`EmployeeModal.vue` 1.517 baris, `PipelineBoard.vue` 1.503).
- Action: Sprint E (Frontend Refactor) — sekalian dengan A8 (god component decomposition).

### D7 — Async Job Queue

- Belum: queue worker untuk `payroll-periods/(:num)/calculate` dan `bonus-runs/(:num)/calculate`.
- Pilihan teknis perlu didiskusikan: CodeIgniter Tasks (built-in, simple) vs queue eksternal (Redis-based, Laravel Horizon style).
- Action: ADR keputusan di Sprint D+2. Implementasi Sprint D+3 (perlu integration test infra dulu).

---

## 3. Verifikasi

- **PHPStan level 0** (sesuai konfigurasi `phpstan.neon.dist`): `[OK] No errors` untuk `CircuitBreaker.php` + `CircuitOpenException.php`.
- **PHPUnit suite penuh:** `838 tests, 1884 assertions, 1 warning, 1 skipped`. Warning & skip sudah ada di baseline pre-Sprint D, tidak terkait CircuitBreaker.
- **Coverage CircuitBreaker:** 13 test mencakup state machine penuh, threshold clamping defensif, isolasi state per-service, snapshot, reset, dan rethrow exception asli.

---

## 4. Pemakaian di Production (Belum di-Wire)

CircuitBreaker library sudah siap pakai. Wiring konkret ke service eksternal di-defer karena tidak semua provider sudah ada di codebase. Lokasi yang paling kandidat:

| Service          | File                                            | Catatan                                                         |
|------------------|-------------------------------------------------|-----------------------------------------------------------------|
| WhatsApp         | `app/Libraries/WhatsAppService.php` (jika ada)  | Bungkus `sendMessage()` dengan `cb->call()`                     |
| OCR (KTP)        | `app/Libraries/OcrService.php` (jika ada)       | Bungkus `extractKtp()`                                          |
| AI Copilot       | `app/Libraries/CopilotService.php` (jika ada)   | Bungkus call ke OpenAI/Claude API                               |
| Webhook outbound | `app/Commands/ProcessWebhooks.php`              | Per-target circuit breaker supaya 1 endpoint mati tidak block lain |

Pattern yang disarankan: factory di `Services::circuitBreaker(string $service): CircuitBreaker` untuk konfigurasi terpusat (threshold/cooldown per-service di `Config/CircuitBreakers.php`).

---

## 5. Skor Per Dimensi (Update)

| Dimensi                       | Sprint C | Sprint D | Target Q4-2026 |
|-------------------------------|----------|----------|----------------|
| Performa & Skalabilitas       | 6 / 10   | 6.5 / 10 | 8 / 10         |
| Testing & QA                  | 5 / 10   | 5.2 / 10 | 7.5 / 10       |

(Sisa dimensi tidak berubah karena Sprint D Phase 1 fokus tunggal di D6.)

---

## 6. Catatan untuk Reviewer

- CircuitBreaker **deliberately tidak meng-throw** kalau cache backend down (`saveState` swallow exception + log warning). Alasan: degradasi graceful — kalau cache mati, jangan tambah failure cascade. Konsekuensinya: state tidak persist antar request kalau Redis down. Ini trade-off yang diterima.
- Test suite **tidak pakai sleep()**. Cooldown transition diuji dengan manipulasi `opened_at` di cache — deterministik dan cepat (full suite +0.064s).
- Threshold `0` atau negatif **di-clamp ke 1** (lihat `testFailureThresholdBelowOneIsClampedToOne`). Defensive pattern untuk konfigurasi env yang salah.
- Implementasi ini **tidak rolling-window**. Failure counter naik linear sampai reset oleh success. Untuk service traffic tinggi, mungkin perlu sliding-window di Sprint D+2.