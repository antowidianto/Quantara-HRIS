# Horizon 2 — Core Deepening (Sprint 11-13)

Dokumen ini meringkas implementasi Horizon 2 dari roadmap
`QUANTARA_HRIS_ROADMAP_PENGEMBANGAN.md`. Fokus: deepen modul existing
(recruitment, performance, payroll, reimbursement, finance) dengan
kapabilitas baru yang umumnya kompetitor sudah punya.

## Ringkasan Modul

| ID | Fitur | Status | Backend Service | Endpoint utama |
|----|-------|--------|-----------------|----------------|
| H2.1 | PWA Enhancement | n/a | frontend only | n/a |
| H2.2 | Goals Cascading | ✅ | `PerformanceCascadingService` | `GET /performance/goals/tree` |
| H2.3 | 360° Peer Feedback | ✅ | `PerformanceCascadingService` | `POST /performance/cycles/:id/peer-nominations` |
| H2.4 | Candidate Composite Scoring | ✅ | `CandidateScoringService` | `POST /candidates/:id/recalculate-score` |
| H2.5 | Employee Referral Program | ✅ | `ReferralProgramService` | `POST /referrals/links`, `GET /referrals/r/:slug` |
| H2.6 | Turnover Analytics | ✅ | `TurnoverAnalyticsService` | `GET /turnover/snapshot`, `GET /turnover/trend` |
| H2.7 | Payroll Simulasi (THP what-if) | ✅ | `PayrollSimulationService` | `POST /payroll/simulate` |
| H2.8 | Reimbursement OCR | ✅ | `OcrService` | `POST /reimbursements/:id/ocr-extract` |
| H2.9 | Cost Center Drill-Down | ✅ | `CostCenterService` (existing) | `GET /cost-centers/:id/budget-vs-actual` |

## Schema Changes

Migration `2024-01-01-000061_Horizon2CoreDeepening` menyatukan semua DDL:

- `candidates`: `composite_score`, `manual_score_override`, `score_breakdown`, `referral_link_id`, `referral_bonus_paid_at`
- `job_posting_scoring_criteria`: master kriteria + bobot per posting
- `peer_review_nominations`: nominate peer reviewer per cycle × reviewee
- `performance_cycles`: `peer_review_enabled`, `self_weight`, `supervisor_weight`, `peer_weight`, `peer_min_reviewers`
- `performance_reviews.review_type` (defensive add — biasanya sudah ada)
- `referral_links`: slug + click/apply count + expires_at
- `referral_bonus_rules`: rule per position × grade dengan catch-all
- `turnover_metrics_snapshot`: cache compute per period (MONTH/YEAR)
- `reimbursements`: `ocr_extracted_at`, `ocr_confidence`, `ocr_raw_data`

Permission baru (auto-granted ke role super_admin + hr_admin via migration):

- `hr_analytics.view`, `hr_analytics.manage`
- `payroll.simulate`
- `candidate.score.view`

## H2.4 Candidate Composite Scoring

Engine weighted-average dari 6 kriteria, masing-masing dinormalize ke 0-100:

- `ONLINE_TEST_SCORE` — best percentage dari `online_test_assignments`
- `SALARY_FIT` — 100 dalam range, menurun linear di atas range
- `EXPERIENCE_YEARS` — `years/target × 100`
- `AVAILABILITY_DAYS` — 100 kalau `available_from <= today`, menurun
- `EDUCATION_LEVEL` — map dari `last_education_level` (S3=100, S2=85, dst)
- `INTERVIEW_RATING` — avg rating × 20 (1-5 → 20-100)

Output `composite + breakdown` di-persist ke `candidates.composite_score`.
HR bisa override manual via `candidates.manual_score_override`. UI
recruiter sort kandidat by score untuk shortlist.

## H2.2 Goals Cascading

Tree builder dari `goals.parent_id` (kolom existing dari migration 000024).

- `treeFromRows(rows, ?root)` — pure tree builder, testable
- `rollupProgress(node)` — weighted avg child progress
- `recomputeFromChild(goalId)` — walk dari leaf ke root, update parent
- `canSetParent(child, proposedParent)` — cycle detection (parent ↔ child loop)

UI manager bisa lihat tree per cycle, drag goal jadi child goal lain,
dan progress parent auto-update saat child di-update.

## H2.3 360° Peer Feedback

- `nominate(cycle, reviewee, reviewer, source)` — supervisor/HR/SELF
- `aggregatePeerRating(cycle, reviewee)` — weighted avg, anonim kalau
  jumlah reviewer ≥ `peer_min_reviewers` (default 3); di bawah itu
  return null untuk privacy
- `computeFinalRating(ratings, weights)` — pure final score dari self +
  supervisor + peer dengan bobot di `performance_cycles.*_weight`

## H2.5 Employee Referral Program

Workflow:

1. Karyawan klik "Share" di job posting → `POST /referrals/links` →
   slug 12-char hex unik
2. Pelamar buka URL `/r/{slug}` → `GET /referrals/r/{slug}` (public) →
   redirect ke career page + tag candidate ke link saat apply
3. Saat probasi PASS, `ProbationService` panggil
   `evaluateBonusForHiredEmployee()` → kalau ada rule match → buat bonus
   run + `markBonusPaid()`

Rule scoring (paling spesifik menang):

- position match = 2
- grade match = 1
- catch-all = 0

## H2.6 Turnover Analytics

Source data: `employees.resign_date` + cross-reference ke
`resignation_requests` (status APPROVED) untuk classify VOLUNTARY,
`termination_requests` (status EXECUTED) untuk INVOLUNTARY.

Metric per period (MONTH `YYYY-MM` atau YEAR `YYYY`):

- `turnover_rate = separations / avg_headcount × 100`
- `voluntary_rate`, `involuntary_rate`
- `retention_rate = 100 − turnover_rate`
- `early_attrition_rate` — % separations dengan tenure < 12 bulan
- `regrettable_rate` — % separations dengan last performance rating ≥ 4

Cron `php spark turnover:snapshot` (TODO: command file) jalan tiap awal
bulan untuk persist trend ke `turnover_metrics_snapshot`. Dashboard
read dari snapshot table, bukan recompute setiap kali.

## H2.7 Payroll Simulasi

What-if calculator pakai service real (`BpjsCalculator`,
`Pph21TerCalculator`). Bukan implementasi terpisah supaya angka
konsisten dengan payroll real.

Input:

```json
{
  "company_id": 1,
  "basic_salary": 10000000,
  "allowances": {"transport": 500000, "meal": 300000},
  "ptkp_status": "TK0",
  "pph21_method": "TER",
  "employment_status": "PKWTT"
}
```

Output: `gross_total`, `taxable_gross`, `bpjs.{kes/jht/jp/jkk/jkm/jkp}`,
`pph21.{monthly,rate,category}`, `deductions_total`, `net_takehome`,
`company_cost` (gross + bpjs employer).

Endpoint tambahan:

- `POST /payroll/simulate/compare` — current vs proposed (kenaikan gaji)
- `POST /payroll/simulate/reverse` — bisection dari target THP →
  estimasi basic_salary

Use case offer letter: HR ketik target THP 15jt → reverse → dapat
basic_salary ≈ 18jt → tampilkan ke kandidat sebagai estimasi take-home.

## H2.8 Reimbursement OCR

Driver pluggable via env `OCR_DRIVER`:

- `mock` (default DEV) — return dummy untuk testing UI
- `tesseract` — shell out ke `tesseract` CLI lokal
- `gcloud_vision` — Google Cloud Vision API
- `aws_textract` — stub (perlu install AWS SDK)

Pure parser `OcrService::parseText()` extract `vendor`, `total`, `date`,
`currency` dari plaintext OCR output. Heuristic:

- VENDOR: line 1-3 yang panjangnya 3-60 char, bukan tanggal/angka
- TOTAL: line dengan keyword `total|jumlah|grand total|amount|subtotal`,
  ambil angka terbesar
- DATE: regex DD/MM/YYYY, DD-MM-YYYY, YYYY-MM-DD
- CURRENCY: `Rp` → IDR, `$` → USD, `€` → EUR

`normalizeNumber()` handle 4 format separator:

- `1.234.567,89` (ID) → 1234567.89
- `1,234,567.89` (US) → 1234567.89
- `80.000` (ID thousand only) → 80000
- `1,5` (ID decimal) → 1.5

Confidence 0-1 dihitung dari berapa field yang sukses di-extract.
Caller (UI) tampilkan ke user untuk review sebelum submit.

## H2.9 Cost Center Drill-Down

Existing `CostCenterService` sudah punya `actualsBreakdown()` +
`committedSpending()` + `budgetVsActual()` per kategori. Tidak ada
endpoint baru untuk H2.9 — UI sudah bisa drill dari list kategori
(SALARY/BONUS/TRAINING/dst) ke detail transaksi via `actualsBreakdown.breakdown[].table`.

## Testing

Pure-function tests di `tests/Unit/Horizon2ServicesTest.php` (27 tests, 44 assertions):

- Composite scoring + per-criterion scorers
- Tree builder + progress rollup + final rating
- Referral rule picker
- Turnover rate + classifier + period range
- OCR parser + number normalizer

Run: `cd backend && vendor/bin/phpunit --filter Horizon2Services`.