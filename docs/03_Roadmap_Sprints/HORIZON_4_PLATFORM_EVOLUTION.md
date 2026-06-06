# Horizon 4 — Platform Evolution (Sprint 19+)

Dokumen ini meringkas implementasi Horizon 4 dari roadmap
`QUANTARA_HRIS_ROADMAP_PENGEMBANGAN.md`. Horizon 4 = future-facing
capabilities yang membawa Quantara dari "complete HRIS" menjadi
"intelligent + employee-centric platform".

## Status Cluster

| ID | Fitur | Status | Service |
|----|-------|--------|---------|
| H4.1 | AI-Powered HR Copilot | ✅ Backend done (driver pluggable) | `AiAssistantService` |
| H4.2 | Multi-Country Payroll | ⬜ Defer (refactor PayrollService besar) | — |
| H4.3 | Workforce Planning & Scenario Modeling | ✅ Backend done (Monte Carlo) | `WorkforceSimulationService` |
| H4.4 | Employee Wellbeing & Mental Health | ✅ Backend done (anonymous pulse survey) | `PulseSurveyService` |
| H4.5 | Compensation Intelligence | ✅ Backend done | `CompensationIntelligenceService` |

4 dari 5 modul backend siap. H4.2 Multi-Country Payroll di-defer
karena butuh refactor `PayrollService` jadi jurisdiction-aware
(tax rule engine per country, multi-currency, country adapter Indonesia
existing + Malaysia/Singapura/Filipina/Thailand baru).

## Schema Changes

Migration `2024-01-01-000063_Horizon4PlatformEvolution` (idempotent):

**AI Copilot (H4.1):**
- `ai_chat_sessions` — per-user session dengan driver, model, token tracking
- `ai_chat_messages` — turn-by-turn audit trail dengan flag `redacted`

**Workforce Planning (H4.3):**
- `workforce_scenarios` — saved scenarios per company dengan
  assumptions JSON (attrition rate, hiring plan, salary inflation)
- `workforce_scenario_runs` — Monte Carlo run results dengan
  p10/p50/p90 distribution untuk headcount + cost

**Wellbeing (H4.4):**
- `pulse_surveys` — master survey + frequency + min participation gate
- `pulse_survey_questions` — ENPS/LIKERT/BURNOUT/YES_NO/OPEN_TEXT
- `pulse_survey_responses` — **anonymous**, no `employee_id`, hanya
  `submission_hash` + demographic bucket
- `pulse_survey_aggregates` — cache eNPS / burnout / satisfaction
  per scope (COMPANY/DEPARTMENT/BRANCH/TENURE/GENDER)

**Compensation Intelligence (H4.5):**
- `salary_benchmarks` — manual import / future API (Glints/JobStreet/Mercer)
  dengan p10/p25/p50/p75/p90 percentile
- `compensation_analyses` — cache hasil compa-ratio + pay equity per period

**Permissions baru** (auto-grant via migration):
- `ai_assistant.use`, `ai_assistant.audit`
- `workforce_planning.view`, `workforce_planning.manage`
- `wellbeing.respond`, `wellbeing.view_aggregate`, `wellbeing.manage`
- `compensation_intelligence.view`, `compensation_intelligence.manage`

## H4.1 — AI HR Copilot

### Driver Adapter Pattern

```
env: AI_ASSISTANT_DRIVER = mock | openai | anthropic | local
```

- **mock** (default DEV) — keyword-based canned response, no API cost
- **openai** — gpt-4o-mini default, set `OPENAI_API_KEY`, `store=false`
- **anthropic** — claude-3-5-sonnet default, set `ANTHROPIC_API_KEY`
- **local** — Ollama / vLLM HTTP endpoint, set `AI_ASSISTANT_LOCAL_URL`

### Privacy-First Design

1. **PII Redactor** — `redactPII()` strip NIK 16-digit, NPWP, no rekening,
   email, phone (Indonesian format) dari user message **sebelum**
   kirim ke LLM. Flag `redacted=1` di message log.
2. **Scope-aware system prompt** — built dari role + permissions user.
   Karyawan biasa diarahkan ke self-service; HR admin disebut explicit
   punya akses payroll.
3. **Audit trail** — tiap turn (user + assistant + function call)
   di-log ke `ai_chat_messages` dengan tokens count.
4. **No training leakage** — set `store: false` (OpenAI) / privacy header
   (Anthropic). User PII tidak di-include di system prompt.

### Endpoint

```
GET    /api/v1/ai/sessions
POST   /api/v1/ai/sessions
GET    /api/v1/ai/sessions/{id}/messages
POST   /api/v1/ai/sessions/{id}/messages
POST   /api/v1/ai/sessions/{id}/archive
```

## H4.3 — Workforce Planning

### Monte Carlo Algorithm

Per iteration (default 1000):

```
hc = startHeadcount
cost = 0
for m in 0..horizon_months:
  hires = poisson(planned_hires[m] × 0.8..1.2)  // ±20% variance
  attrition = poisson(hc × monthly_attrition_rate)
  hc = max(0, hc + hires - attrition)
  cost += hc × avg_salary × (1 + inflation)^(m/12) × bpjs_load
```

Setelah N iterasi, hitung `percentile()` untuk p10/p50/p90 per metric.

### Pure Helpers

- `simulateScenarioPure()` — fully testable tanpa DB
- `poissonRandom(lambda)` — Knuth's method untuk lambda<30,
  normal approximation untuk lambda≥30
- `percentile(sortedValues, p)` — linear interpolation

### Use Cases

1. CEO: "Skenario growth aggressive 5 hire/bulan, attrition 1.5%/bulan,
   total cost akhir tahun 2026?" → `runScenario(scenarioId)` →
   p10/p50/p90 cost + headcount distribution
2. CFO: compare baseline vs aggressive vs conservative →
   `compareScenarios([baseline_id, aggressive_id, conservative_id])`
3. CHRO: confidence interval untuk planning, bukan single point estimate

### Endpoint

```
GET    /api/v1/workforce-scenarios
POST   /api/v1/workforce-scenarios
POST   /api/v1/workforce-scenarios/{id}/run
POST   /api/v1/workforce-scenarios/compare
```

## H4.4 — Employee Wellbeing

### Anonymous-by-Design

Tabel `pulse_survey_responses` **tidak punya `employee_id`**. Yang
disimpan hanya:

- `submission_hash = SHA256(employee_id : survey_id : salt)` —
  anti-double-submit, **tidak reversible** karena salt tidak diekspos
- demographic bucket: `department_id`, `branch_id`, `tenure_bucket`,
  `gender` — semua dengan privacy gate

### Privacy Gate

Saat compute aggregate per scope:

```
if response_count < min_participation_for_dept_view (default 5):
  return { suppressed: true, reason: "..." }
```

User tidak bisa de-anonymize karyawan dengan cara reverse engineer
dari demographic combo (mis. 1 perempuan di branch X) karena threshold
filter di-enforce di service layer.

### Scoring

**eNPS classic** (0-10 scale):
```
% Promoter (9-10) − % Detractor (0-6) = score (-100..100)
```

**Burnout score** (5 question MBI shortcut, 1-5 Likert):
```
Reverse-score question yang phrasing positif (mis. "saya merasa pekerjaan saya bermakna")
avg = sum(answers) / count
score = (avg − 1) / 4 × 100  → 0-100, lebih tinggi = lebih burnout
```

### Endpoint

```
GET    /api/v1/pulse-surveys
POST   /api/v1/pulse-surveys
POST   /api/v1/pulse-surveys/{id}/open
GET    /api/v1/pulse-surveys/{id}/questions
POST   /api/v1/pulse-surveys/{id}/respond
GET    /api/v1/pulse-surveys/{id}/aggregates?scope=COMPANY|DEPARTMENT|BRANCH|TENURE|GENDER
POST   /api/v1/pulse-surveys/{id}/refresh-aggregates
```

## H4.5 — Compensation Intelligence

### Compa-Ratio

```
compa_ratio = current_salary / market_p50_salary
```

Band buckets:

| Band | Range | Interpretation |
|------|-------|----------------|
| UNDERPAID | < 0.80 | Retention risk — review naik gaji |
| DEVELOPING | 0.80-0.95 | Junior / new hire — wajar |
| ON_BAND | 0.95-1.05 | Healthy — typical performer |
| EXCEEDS | 1.05-1.20 | High performer / experienced |
| OVERPAID | > 1.20 | Limit further increase, freeze |

Health indicator company-wide: avg compa 0.95-1.05 → HEALTHY,
< 0.95 → UNDER_MARKET (retention risk), > 1.05 → OVER_MARKET (cost concern).

### Pay Equity (Gender)

Per grade dengan filter sample size ≥ 3 per gender:

```
gap_amount = avg(male) − avg(female)
gap_pct = gap_amount / avg(male) × 100
```

Flag `REVIEW_NEEDED` kalau |gap_pct| > 10%. Company-wide weighted
gap → HEALTHY (≤5%), WATCH (≤10%), CONCERN (>10%).

### Salary Band Recommendation

Per grade dengan sample ≥ 3:

```
internal_min/mid/max = min/p50/max dari distribusi gaji aktual
market_min/mid/max  = dari salary_benchmarks (p25/p50/p75)
recommended = blend internal × 0.4 + market × 0.6
```

Flag `HIGH_DISPERSION` kalau coefficient of variation > 0.30
(spread terlalu lebar untuk satu grade).

### Outlier Detection (IQR)

```
Q1, Q3 = quartile dari sorted salaries
IQR = Q3 - Q1
outlier_low  = x < Q1 - 1.5×IQR
outlier_high = x > Q3 + 1.5×IQR
```

### Endpoint

```
GET    /api/v1/compensation/compa-ratio
GET    /api/v1/compensation/gender-equity
GET    /api/v1/compensation/band-recommendations
GET    /api/v1/salary-benchmarks
POST   /api/v1/salary-benchmarks
```

## Testing

Pure-function tests di `tests/Unit/Horizon4ServicesTest.php`
(30 tests, ~70 assertions) — semua pass.

- 7 test PII redactor + token estimator + system prompt builder
- 6 test percentile + Poisson sampler + Monte Carlo simulator
- 8 test submission hash + tenure bucket + eNPS + burnout score
- 9 test compa-ratio + bands + gender gap + outliers + CV

Full suite: 725 tests pass (sebelumnya 695 setelah Horizon 3, +30) /
1647 assertions / 1 pre-existing warning. Tidak ada regresi.

Run: `cd backend && vendor/bin/phpunit --filter Horizon4Services`.

## Frontend Gap

Backend siap konsumsi semua. UI yang perlu dibuat:

1. **AI Copilot Chat Widget** — floating chat di shell HRIS
   (input + history + session list + redaction indicator)
2. **Workforce Scenario Builder** — assumption form (attrition slider,
   hiring plan per month, inflation %), run button, p10/p50/p90 chart
3. **Pulse Survey Form (ESS)** — render questions per type,
   submit anonim
4. **Wellbeing Dashboard (HR)** — eNPS line chart, burnout heatmap
   per dept, suppressed-cell indicator
5. **Compa-Ratio Dashboard** — scatter plot per employee,
   band buckets bar chart, alert UNDERPAID list
6. **Pay Equity Dashboard** — per grade gender gap %, flag concerns
7. **Salary Benchmark Editor** — import CSV / manual entry per grade

## Defer Notes

**H4.2 Multi-Country Payroll** di-defer. Effort estimate: 2 sprint full-time:

- Refactor `PayrollService` jadi `PayrollService::forJurisdiction(IDN|MYS|SGP|...)`
- Country adapter interface: `TaxCalculator`, `BpjsCalculator`-equivalent,
  `MinWageCalculator`, `WorkdayCalculator`
- Migration: `companies.country_code`, `payroll_components.jurisdiction`,
  `tax_rates_country`, `social_security_rates_country`
- Multi-currency: `currency_code` di payslips, exchange rate table
- Country-specific compliance reports (mis. Form E for Singapore,
  EA Form for Malaysia)

Implementasi standalone supaya tidak break Indonesia payroll yang sudah
production-ready. Recommended: Spike PoC dulu untuk 1 country (Malaysia
karena similar tax structure ke Indonesia), validate adapter pattern,
baru rollout countries lain.