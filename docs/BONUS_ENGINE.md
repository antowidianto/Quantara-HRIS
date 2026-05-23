# Bonus Engine

Sprint 16+. Modul untuk handle bonus selain THR & insentif: end-of-year bonus,
KPI / performance bonus, milestone bonus, sign-on bonus, retention bonus, atau
discretionary bonus dengan formula configurable.

> Ini berbeda dengan THR (yang sudah handled di `payroll_periods` type=THR
> via `PayrollController::generateThr`) — THR memiliki schedule fix dan
> formula yang baku (proporsional masa kerja). Bonus Engine fleksibel: HR
> define skema sendiri (formula + criteria), bisa ad-hoc atau scheduled,
> eligibility per departemen / cabang / grade / posisi.

---

## Konsep

```
BonusScheme  (definisi reusable, mis. "Tahun-Baru-2027", "KPI-Q1-Sales")
    ↓ 1:N
BonusRun     (instance pelaksanaan, status DRAFT → CALCULATED → APPROVED → PAID)
    ↓ 1:N
BonusRecipient (line item per karyawan, snapshot + override)
```

### Status workflow

```
   ┌─── cancel ──────────────────────────┐
   │                                      ↓
DRAFT ─→ CALCULATED ─→ APPROVED ─→ PAID  CANCELLED
   ↑          │                     ↑
   └──recalc──┘                     │
              └── cancel (HR) ──────┘
```

- `DRAFT` — baru dibuat, scheme bisa diganti, run bisa dihapus
- `CALCULATED` — sudah hitung amount per recipient; HR boleh edit recipient (override)
- `APPROVED` — locked; recipient tidak boleh diubah
- `PAID` — final; tidak bisa di-cancel kecuali super_admin
- `CANCELLED` — terminal

---

## Skema (BonusScheme)

### Field utama

| Field | Tipe | Keterangan |
|---|---|---|
| `code` | string(50) UNIQUE per company | Mis. `ANN-2027`, `KPI-Q1-SALES` |
| `name` | string(200) | Nama display |
| `kind` | enum | `ANNUAL` / `KPI` / `MILESTONE` / `SIGNON` / `RETENTION` / `DISCRETIONARY` |
| `formula_type` | enum | `FIXED_AMOUNT` / `PCT_OF_BASIC` / `PCT_OF_GROSS` / `TENURE_BASED` / `KPI_SCORE` / `FORMULA_EXPR` |
| `formula_value` | decimal | Nilai numerik tergantung formula_type |
| `formula_expr` | text | Untuk type `FORMULA_EXPR` — ekspresi simple |
| `cap_amount` | decimal nullable | Max per karyawan; null = tidak di-cap |
| `eligibility_json` | JSON | Filter karyawan (lihat di bawah) |
| `tax_treatment` | enum | `GROSS` / `NET` / `NON_TAXABLE` |

### Formula types

#### `FIXED_AMOUNT`
Setiap recipient dapat nominal sama. Cocok untuk milestone, sign-on, retention.

```json
{ "formula_type": "FIXED_AMOUNT", "formula_value": 5000000 }
```

#### `PCT_OF_BASIC`
Persen dari `basic_salary`. Cocok untuk annual bonus klasik.

```json
{ "formula_type": "PCT_OF_BASIC", "formula_value": 50 }
```
→ 50% × basic salary per recipient.

#### `PCT_OF_GROSS`
Persen dari (basic + fixed_allowance). Untuk perusahaan yang bonus-nya
dihitung dari take-home tetap (bukan basic saja).

```json
{ "formula_type": "PCT_OF_GROSS", "formula_value": 25 }
```

#### `TENURE_BASED`
Linear berdasarkan tahun masa kerja: `tenure_years × formula_value`. Cocok
untuk service award atau retention.

```json
{ "formula_type": "TENURE_BASED", "formula_value": 100000 }
```
→ Rp 100.000 per tahun masa kerja. Karyawan 5 tahun dapat Rp 500.000.

#### `KPI_SCORE`
Multiplier KPI di atas basic. Butuh field `kpi_score` di employee snapshot
(0..2, di-clamp aman).

```json
{ "formula_type": "KPI_SCORE", "formula_value": 50 }
```
→ basic × 50% × kpi_score. KPI 1.5 berarti 150% performance multiplier.

#### `FORMULA_EXPR`
Ekspresi kustom dengan variable predefined. Pure math evaluator —
**tidak** support function call atau ekspresi PHP berbahaya.

```json
{
  "formula_type": "FORMULA_EXPR",
  "formula_expr": "basic * 0.5 + tenure_months * 100000"
}
```

Variable yang tersedia:

| Variable | Sumber |
|---|---|
| `basic` | `employees.basic_salary` |
| `gross` | `basic_salary + fixed_allowance` |
| `tenure_months` | hitung dari `join_date` sampai `reference_date` |
| `tenure_years` | `tenure_months / 12` |
| `kpi_score` | `employees.kpi_score` (opsional, default 0) |
| `value` | `formula_value` di scheme (untuk override flexible) |

Operator yang didukung: `+ - * /` dan grouping `( )`. Decimal & integer literal.
Selain itu reject (return 0).

### Eligibility filter

JSON di `eligibility_json` menentukan siapa yang ikut run:

```json
{
  "department_ids":     [3, 5, 7],     // whitelist (kosong = semua)
  "branch_ids":         [1, 2],
  "grade_ids":          [4, 5],
  "position_ids":       [],
  "employment_status":  ["PKWT", "PKWTT"],
  "min_tenure_months":  6,
  "exclude_resigned":   true
}
```

- Field whitelist (department/branch/grade/position): array kosong / absent =
  no filter, allow semua.
- `min_tenure_months`: gate, karyawan dengan tenure < N bulan di-skip.
- `exclude_resigned`: skip karyawan yang `resign_date <= reference_date`.
- `employment_status`: whitelist string. Cek case-insensitive.

---

## Cara pakai end-to-end

### 1. Buat skema (sekali)

```bash
curl -X POST https://api.hris.example.com/api/v1/bonus-schemes \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "code": "ANN-2027",
    "name": "Annual Bonus 2027",
    "kind": "ANNUAL",
    "formula_type": "PCT_OF_BASIC",
    "formula_value": 100,
    "cap_amount": 50000000,
    "eligibility": {
      "employment_status": ["PKWTT"],
      "min_tenure_months": 12,
      "exclude_resigned": true
    },
    "tax_treatment": "GROSS"
  }'
```

### 2. Preview compute sebelum commit

Simulasikan formula tanpa save ke DB:

```bash
curl -X POST https://api.hris.example.com/api/v1/bonus-schemes/preview \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "scheme": {"formula_type": "PCT_OF_BASIC", "formula_value": 100},
    "employee": {"basic_salary": 15000000, "join_date": "2024-01-01"},
    "reference_date": "2027-12-31"
  }'
# → {"data": {"amount": 15000000, "capped": false, "breakdown": {...}}}
```

### 3. Buat run (DRAFT)

```bash
curl -X POST https://api.hris.example.com/api/v1/bonus-runs \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "bonus_scheme_id": 1,
    "name": "Annual Bonus 2027 — Wave 1",
    "reference_date": "2027-12-31",
    "pay_date": "2028-01-15"
  }'
# → {"data": {"id": 12, "status": "DRAFT"}}
```

### 4. Calculate

Resolve eligibility + hitung amount + INSERT recipients:

```bash
curl -X POST https://api.hris.example.com/api/v1/bonus-runs/12/calculate \
  -H "Authorization: Bearer $TOKEN"
# → {"data": {"recipient_count": 145, "total_amount": 1750000000, "skipped": 8}}
```

`skipped` = karyawan yang tidak eligible (di-log per-employee di breakdown).

### 5. Review & override

```bash
# List recipients
curl https://api.hris.example.com/api/v1/bonus-runs/12/recipients?per_page=100 \
  -H "Authorization: Bearer $TOKEN"

# Override amount tertentu
curl -X PUT https://api.hris.example.com/api/v1/bonus-recipients/892 \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"amount": 5000000, "reason": "Adjustment based on Q4 performance"}'

# Exclude karyawan dari run
curl -X PUT https://api.hris.example.com/api/v1/bonus-recipients/901 \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"is_excluded": true, "exclude_reason": "Resign before pay date"}'
```

Override: amount tetap di-validate terhadap `cap_amount` skema.

### 6. Approve (lock)

```bash
curl -X POST https://api.hris.example.com/api/v1/bonus-runs/12/approve \
  -H "Authorization: Bearer $APPROVER_TOKEN"
# Setelah ini recipient tidak boleh diubah lagi
```

Butuh permission `bonus.approve` (terpisah dari `bonus.manage` agar
4-eyes principle).

### 7. Mark paid

Setelah Finance kirim transfer ke karyawan:

```bash
curl -X POST https://api.hris.example.com/api/v1/bonus-runs/12/mark-paid \
  -H "Authorization: Bearer $APPROVER_TOKEN"
```

State final. Hanya super_admin yang bisa cancel kalau ada kesalahan.

---

## Webhook events

Sprint 16+ dispatch 3 event ke webhook outbound subscribers:

| Event | Saat |
|---|---|
| `bonus.run_calculated` | Setelah `POST /bonus-runs/{id}/calculate` sukses (include `recipient_count`, `total_amount`, `skipped`) |
| `bonus.run_approved` | Setelah approve (status APPROVED) |
| `bonus.run_paid` | Setelah mark-paid (status PAID) |

Subscribe via `POST /webhooks` dengan `event_types: ["bonus.run_calculated", ...]`.

---

## Authorization

| Permission | Boleh |
|---|---|
| `bonus.view` | List & show scheme/run/recipient |
| `bonus.manage` | Create/edit scheme, create/calculate/edit run, override recipient |
| `bonus.approve` | Approve & mark-paid run |

Super admin auto-grant semua. Tenant admin standar biasa-nya combine
`bonus.view` + `bonus.manage`; HR senior yang `bonus.approve`.

---

## Audit trail

Semua state transition log di `audit_logs` module=`PAYROLL`:

- `bonus_scheme.create` / `.update` / `.delete`
- `bonus_run.create` / `.update` / `.delete`
- `bonus.calculate` (severity NOTICE)
- `bonus.approve` (severity CRITICAL)
- `bonus.mark_paid` (severity CRITICAL)
- `bonus.cancel` (severity WARNING, with reason)
- `bonus_recipient.update` (saat HR override)

Semua include `user_id` + timestamp + before/after snapshot.

---

## Limit & guard

- `cap_amount` dienforce di calculate maupun saat HR override manual.
- Negative amount otomatis di-clamp ke 0 (defensive: bonus tidak pernah negatif).
- `FORMULA_EXPR` parser pure math — tolak fungsi PHP, eval, ekspresi non-numerik.
  Kalau parse fail → return 0.
- `kpi_score` di-clamp ke `[0, 2]` supaya outlier KPI tidak menghasilkan
  amount tidak masuk akal.
- Recipient `amount = 0` otomatis di-skip (tidak masuk count). Pakai
  `is_excluded=1` untuk eksplisit exclude dengan alasan tercatat.

---

## Integrasi ke payroll period (opsional)

Field `bonus_runs.payroll_period_id` boleh di-set saat create kalau bonus
mau dimasukkan ke payslip bulan tertentu (mis. bonus tahun-baru disisipkan
ke payslip Januari). Saat ini integrasi auto-merge ke `PayrollService`
masih manual — HR harus add komponen `BONUS` ke payslip terkait pakai
PayrollComponent.

Defer: integrasi otomatis bonus → payslip line item (Sprint 17+ kalau
ada permintaan dari customer).