# Performance Management — OKR / KPI / Review

Modul **Performance Management** untuk goal-setting (KPI/OKR), tracking
progress berkala (check-in), dan review akhir cycle dengan scoring +
rating + rekomendasi (kenaikan gaji, promosi, PIP, dll).

Status: ✅ Implementasi end-to-end terverifikasi (smoke test 42/42).

## Latar Belakang

Tanpa sistem performance terstruktur, perusahaan mengandalkan:
- Excel manual untuk track KPI per karyawan
- Review tahunan ad-hoc tanpa scoring konsisten
- Keputusan promosi/raise tanpa data objektif

Modul ini menyediakan kerangka:
1. **Cycle terdaftar** (Q1, Annual, dll) dengan deadline goal-setting & review
2. **Goal alignment** — child goal bisa link ke parent (manager/dept)
3. **Weight system** — tiap goal punya bobot, total per karyawan = 100%
4. **Auto-progress** dari current_value vs target_value
5. **Check-in berkala** dengan status traffic-light (GREEN/YELLOW/RED)
6. **Scoring weighted** saat review — overall_score dihitung otomatis
7. **Rating rubric** — score 90+ OUTSTANDING, 80+ EXCEEDS, 65+ MEETS, dst
8. **Recommendations** untuk follow-up: PROMOTION/RAISE/KEEP/PIP/TERMINATION

## Apa yang Berubah

### Database

Migration `2024-01-01-000024_CreatePerformanceTables.php` membuat **4 tabel**:

**`performance_cycles`** — period:
- `code` unik per company (mis. `Q1-2026`, `ANNUAL-2026`)
- `cycle_type` (YEARLY/QUARTERLY/MONTHLY/CUSTOM)
- Schedule + goal_setting_deadline + review_period
- Status 7-state: DRAFT → OPEN → GOAL_SETTING → IN_PROGRESS → REVIEW → CLOSED, branch CANCELLED

**`goals`** — KPI/OKR per karyawan:
- `goal_type` (KPI/OKR_OBJECTIVE/OKR_KEY_RESULT/DEVELOPMENT)
- `category` BSC (FINANCIAL/CUSTOMER/INTERNAL/LEARNING)
- `parent_id` untuk alignment dengan goal manager
- `measurement_type` (PERCENT/NUMBER/CURRENCY/BINARY/MILESTONE)
- `target_value`, `current_value`, `unit` ("%", "IDR", "pcs")
- `weight` 0-100, total per (cycle, employee) harus 100
- `progress_percent` auto-calc dari current/target × 100
- Status 7-state: DRAFT → APPROVED → IN_PROGRESS → ACHIEVED / PARTIAL / MISSED, branch CANCELLED
- `final_score` saat review (0-100)

**`goal_check_ins`** — progress updates:
- `check_in_date`, `progress_value`, `progress_percent`
- `status_color` (GREEN/YELLOW/RED) — self-assessment status
- `comment` — progress, blocker, kebutuhan support
- Submit check-in → auto update goal current_value + recompute progress

**`performance_reviews`** — review akhir cycle:
- `review_type` (SELF/MANAGER/PEER/SUBORDINATE) — support 360°
- `overall_score` (0-100) auto-computed dari weighted goal scores
- `rating` auto-derived dari score (OUTSTANDING/EXCEEDS/MEETS/NEEDS_IMPROVEMENT/UNSATISFACTORY)
- `goal_scores` JSON `{goal_id: score}`
- `competency_scores` JSON (optional, blend 70% goal + 30% competency)
- `strengths`, `improvements`, `development_plan`, `reviewer_comment`, `employee_comment`
- `recommendation` (PROMOTION/RAISE/KEEP/PIP/TERMINATION)
- `recommended_increase_percent` untuk integrasi ke payroll
- Status 5-state: DRAFT → SUBMITTED → ACKNOWLEDGED → FINALIZED, branch CANCELLED

**7 permission baru**:
- `performance.view`, `performance.manage`
- `goal.view`, `goal.manage`, `goal.approve`
- `review.conduct`, `review.view_team`

Auto-grant: role 1, 2 dapat semua; role 3 manager dapat goal.approve + review.conduct + view_team; role 4-5 dapat view only.

### Library `PerformanceService` (`Services::performance()`)

7 method utama:
- `validateWeights($cycleId, $employeeId)` — total bobot = 100? Return ok/total/missing
- `recomputeGoalProgress($goalId)` — refresh progress_percent + auto-update status (IN_PROGRESS/ACHIEVED)
- `submitCheckIn($goalId, $employeeId, $data)` — update current_value + create check_in row
- `computeOverallScore($cycleId, $employeeId, $goalScores)` — weighted average dengan breakdown
- `scoreToRating($score)` — apply RATING_RUBRIC (90+ OUTSTANDING, dst)
- `submitReview($reviewId, $data)` — orchestrate submit + auto-score + auto-rating + propagate goal final_score
- `acknowledgeReview($reviewId, $employeeId, $data)` — karyawan acknowledge dengan comment optional
- `finalizeReview($reviewId)` — HR lock review (status FINALIZED)
- `cycleStats($cycleId)` — counter goals/reviews + rating distribution

### Backend Endpoints

**Controller `PerformanceController`** — 22 endpoint:
- Cycles: CRUD + change-status (DRAFT→OPEN→GOAL_SETTING→IN_PROGRESS→REVIEW→CLOSED)
- Goals: CRUD + approve + check-in + validate-weights
- Reviews: CRUD + submit + acknowledge + finalize
- My: aggregate goals + reviews milik karyawan

### Frontend

**`Performance/PerformanceList.vue`** — daftar cycle dengan status + tombol manage.

**`Performance/CycleDetail.vue`** — halaman per-cycle:
- 4 stat cards (Total Goals/Karyawan/Avg Progress/Reviews)
- 2 tab: Goals + Reviews
- Tabel goals dengan progress bar berwarna (emerald/blue/amber/red sesuai %)
- Tombol per-row: Approve (DRAFT→APPROVED), Check-in (untuk owner)
- Tabel reviews dengan score + rating badge
- 3 modal: Goal create, Check-in, Review create

**`Performance/MyPerformance.vue`** — view karyawan:
- List goals milik sendiri dengan progress bar + tombol Check-in
- List reviews milik sendiri dengan link ke detail
- Modal Check-in dengan traffic light status (🟢 On Track / 🟡 At Risk / 🔴 Off Track)

**`Performance/ReviewDetail.vue`** — review form:
- Header: skor + rating
- Tabel goals dengan input Skor 0-100 per goal (editable saat DRAFT)
- Live preview overall score (weighted average)
- Form: strengths, improvements, development_plan, reviewer_comment
- Recommendation dropdown + recommended_increase_percent
- Tombol kontekstual: Save Draft, Submit, Acknowledge, Finalize
- Tampilan beda untuk reviewer vs employee vs HR (sesuai permission + status)

## Workflow Skenario

### Skenario 1 — Quarterly cycle dengan goal setting

1. **HR buat cycle** "Q1 2026" — start_date Jan 1, end_date Mar 31, deadline goal-setting Jan 15.
2. **HR ubah status** ke GOAL_SETTING.
3. **Karyawan A** create 3 goals (sales 40%, defect rate 40%, training 20%) → total 100%.
4. **Manager** approve semua 3 goals.
5. **HR ubah status** ke IN_PROGRESS.
6. **Karyawan A** check-in mingguan dengan progress_value + status_color.
7. End of Q1: **HR ubah status** ke REVIEW.
8. **Manager** create review row, input skor per goal (0-100).
9. **Manager** submit → sistem hitung overall_score (weighted) + auto-derive rating.
10. **Karyawan A** acknowledge dengan tanggapan.
11. **HR finalize** → review LOCKED, jadi referensi untuk merit increase.

### Skenario 2 — OKR dengan alignment hierarchy

1. **CEO** punya Objective "Tingkatkan revenue 30%".
2. **VP Sales** create child goal "Target deal 50 client baru" dengan parent_id = CEO objective.
3. **Sales Manager** create child goal "Setiap rep dapat 5 deal" dengan parent_id = VP goal.
4. **Sales Rep** create individual goals dengan parent_id = manager goal.
5. Saat review, alignment terlihat di hierarchy: CEO score → cascade dari sub-team performance.

### Skenario 3 — 360° review

1. Karyawan A direview oleh:
   - SELF (sendiri)
   - MANAGER (atasan langsung)
   - PEER × 2 (rekan kerja)
   - SUBORDINATE × 1 (bawahan)
2. Setiap reviewer create row terpisah dengan `review_type` berbeda.
3. HR aggregate semua untuk melihat 360° picture.
4. Final rating biasanya dari MANAGER review tapi bisa weighted average dari semua tipe.

### Skenario 4 — Score fail → PIP

1. Karyawan B akhir cycle dapat overall_score 45 (UNSATISFACTORY).
2. Manager set recommendation = PIP, recommended_increase_percent = 0.
3. HR finalize. Otomatis trigger workflow follow-up:
   - HR set up Performance Improvement Plan (manual untuk sekarang)
   - Karyawan dimonitor lebih ketat di cycle berikutnya
   - Kalau cycle berikut tetap UNSATISFACTORY → bisa lanjut ke disciplinary / severance

### Skenario 5 — Promotion candidate

1. Karyawan C 3 cycle berturut-turut dapat OUTSTANDING.
2. Manager pada cycle terbaru set recommendation = PROMOTION + recommended_increase_percent = 15.
3. HR pakai data ini sebagai input untuk:
   - Salary review (apply 15% increase)
   - Job grade change
   - Update kontrak baru via modul Contract Templates

## Verifikasi

Smoke test `backend/scripts/test-performance-flow.php` lulus 42/42:
- Schema (4 tabel + 7 permission) ✓
- Validate weights total = 100 ✓
- Approve goals ✓
- Submit check-in (target 90, current 72 → 80%) ✓
- recomputeGoalProgress (100/100 → 100% + status ACHIEVED) ✓
- computeOverallScore (3 goals 80/100/60 with weights 40/40/20 → score 84) ✓
- scoreToRating: 95→OUTSTANDING, 85→EXCEEDS, 70→MEETS, 55→NEEDS_IMPROVEMENT, 30→UNSATISFACTORY ✓
- submitReview → status SUBMITTED + score 84 + rating EXCEEDS ✓
- Goal final_score di-propagate ✓
- acknowledgeReview → status ACKNOWLEDGED ✓
- finalizeReview → status FINALIZED ✓
- cycleStats: goals.total/unique_employees/avg_progress + reviews.rating_distribution ✓
- Class structure ✓

## Migrasi Database

```bash
cd backend
php spark migrate
```

Tidak ada seeder; data cycle/goal dibuat HR via UI.

## Integrasi dengan Modul Lain

- **Disciplinary**: rating UNSATISFACTORY 2 cycle berturut-turut bisa jadi alasan SP.
- **Severance**: rating data sebagai bukti pendukung untuk PHK efisiensi.
- **Payroll**: `recommended_increase_percent` jadi input merit increase saat salary review tahunan.
- **Recruitment**: rating top performer (OUTSTANDING) bisa flag untuk talent pool internal.
- **Training**: development_plan mention training → otomatis link ke modul Training.
- **Audit Log**: setiap state transition tersimpan di modul `PERFORMANCE`.

## Roadmap Selanjutnya

- **Calibration meeting** — UI untuk HR adjust ratings agar tidak terlalu lenient/strict per manager.
- **OKR scoring** — adjustment khusus (mis. 0.7 score = on-track untuk OKR ambitious).
- **Performance dashboard** — heatmap karyawan × goals, trend rating per dept.
- **Goal templates** — preset KPI per posisi untuk speed up goal-setting.
- **Continuous feedback** — kanal feedback informal di luar formal review.
- **9-box grid** — performance × potential matrix untuk succession planning.
- **Auto-trigger merit increase** — saat review FINALIZED dengan recommendation=RAISE, generate proposal payroll.