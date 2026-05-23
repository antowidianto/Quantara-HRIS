/**
 * bonusTemplates.js — Katalog template skema bonus umum di Indonesia.
 *
 * Dipakai di Bonus Scheme form untuk pre-fill skema baru dengan setting
 * yang sudah sesuai praktik industri. HR tinggal pilih, sesuaikan
 * angka, dan simpan.
 *
 * Tax treatment mengikuti praktik PPh21 Indonesia:
 *   - NORMAL    → bonus jadi penghasilan bruto, dipotong PPh21 dari bonus
 *   - GROSS_UP  → employer absorb pajak, karyawan terima nominal penuh
 *   - NET       → angka di kontrak adalah nominal net (sama efek dgn GROSS_UP
 *                 tapi narasi beda; biasanya untuk sign-on / retention)
 *   - NON_TAXABLE → fasilitas non-pajak (BPJS overage, allowance kategori
 *                 dipisah). Jarang dipakai untuk bonus murni.
 *
 * Referensi: PMK 168/2023, UU HPP, praktik HR Indonesia umum.
 *
 * Setiap template berisi:
 *   - id, label, icon (emoji untuk visual cepat)
 *   - description: penjelasan singkat untuk HR
 *   - usage_note: kapan biasanya bonus ini dibayarkan
 *   - example: contoh kasus sederhana
 *   - apply(form): callback yang merge ke form ref tanpa nimpa code/name
 *     yang sudah HR ketik
 */

/* eslint-disable no-param-reassign */

const today = () => new Date().toISOString().slice(0, 10)
const currentYear = () => new Date().getFullYear()

export const BONUS_TEMPLATES = [
  // ─── 1. THR (Tunjangan Hari Raya) ──────────────────────────────────
  {
    id: 'THR',
    label: 'THR — Tunjangan Hari Raya',
    icon: '🕌',
    category: 'WAJIB',
    description:
      'Wajib menurut Permenaker 6/2016. Karyawan ≥ 12 bulan dapat 1× gaji; ' +
      '< 12 bulan pro-rata (masa_kerja / 12 × gaji).',
    usage_note: 'Dibayar paling lambat H-7 hari raya keagamaan karyawan.',
    example: 'Gaji 10jt, masa kerja 18 bulan → THR = 10jt × (12/12) = Rp 10.000.000',
    apply: (form) => {
      form.code = form.code || `THR-${currentYear()}`
      form.name = form.name || `THR ${currentYear()}`
      form.kind = 'ANNUAL'
      form.formula_type = 'PCT_OF_GROSS'
      form.formula_value = 100
      form.formula_expr = ''
      form.cap_amount = ''
      form.tax_treatment = 'NORMAL'
      form.description =
        'THR sesuai Permenaker 6/2016: 1× gaji untuk masa kerja ≥ 12 bulan. ' +
        'Untuk pro-rata < 12 bulan, gunakan template "THR Pro-rata".'
      form.eligibility = {
        employment_status: ['PKWTT', 'PKWT'],
        min_tenure_months: 1,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  {
    id: 'THR_PRORATA',
    label: 'THR Pro-rata (masa kerja < 12 bulan)',
    icon: '🕌',
    category: 'WAJIB',
    description:
      'THR pro-rata untuk karyawan dengan masa kerja 1–11 bulan. ' +
      'Formula: gaji × (tenure_months / 12).',
    usage_note: 'Pakai run terpisah dari THR full untuk karyawan tenure < 12 bulan.',
    example: 'Gaji 10jt, masa kerja 6 bulan → THR = 10jt × (6/12) = Rp 5.000.000',
    apply: (form) => {
      form.code = form.code || `THR-PR-${currentYear()}`
      form.name = form.name || `THR Pro-rata ${currentYear()}`
      form.kind = 'ANNUAL'
      form.formula_type = 'FORMULA_EXPR'
      form.formula_value = 0
      form.formula_expr = 'gross * (tenure_months / 12)'
      form.cap_amount = ''
      form.tax_treatment = 'NORMAL'
      form.description =
        'THR pro-rata Permenaker 6/2016 untuk karyawan masa kerja 1–11 bulan. ' +
        'Formula: gross × (tenure_months / 12).'
      form.eligibility = {
        employment_status: ['PKWTT', 'PKWT'],
        min_tenure_months: 1,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  // ─── 2. Bonus Tahunan / Annual Bonus ──────────────────────────────
  {
    id: 'ANNUAL_BONUS',
    label: 'Bonus Tahunan (1× Gaji)',
    icon: '🎁',
    category: 'TAHUNAN',
    description:
      'Bonus tahunan setara 1× gaji bulanan, biasanya akhir tahun atau Q1 tahun ' +
      'berikutnya setelah audit performance.',
    usage_note: 'Dibayar Desember atau Januari–Maret sesuai kebijakan perusahaan.',
    example: 'Gaji 12jt → bonus = Rp 12.000.000',
    apply: (form) => {
      form.code = form.code || `ANN-${currentYear()}`
      form.name = form.name || `Annual Bonus ${currentYear()}`
      form.kind = 'ANNUAL'
      form.formula_type = 'PCT_OF_BASIC'
      form.formula_value = 100
      form.formula_expr = ''
      form.cap_amount = ''
      form.tax_treatment = 'NORMAL'
      form.description =
        'Bonus tahunan 1× basic salary untuk karyawan PKWTT dengan masa kerja ≥ 12 bulan.'
      form.eligibility = {
        employment_status: ['PKWTT'],
        min_tenure_months: 12,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  {
    id: 'BONUS_13',
    label: 'Bonus Bulan ke-13',
    icon: '📅',
    category: 'TAHUNAN',
    description:
      'Gaji ke-13 — bonus akhir tahun setara 1 bulan gaji penuh. Umum di ' +
      'BUMN dan perusahaan dengan budaya konservatif.',
    usage_note: 'Biasanya cair menjelang tahun ajaran baru (Juli) atau Desember.',
    example: 'Gross 8jt → bonus = Rp 8.000.000',
    apply: (form) => {
      form.code = form.code || `B13-${currentYear()}`
      form.name = form.name || `Gaji ke-13 ${currentYear()}`
      form.kind = 'ANNUAL'
      form.formula_type = 'PCT_OF_GROSS'
      form.formula_value = 100
      form.formula_expr = ''
      form.cap_amount = ''
      form.tax_treatment = 'NORMAL'
      form.description = 'Gaji bulan ke-13 = 1× gross salary. PKWTT minimal 12 bulan masa kerja.'
      form.eligibility = {
        employment_status: ['PKWTT'],
        min_tenure_months: 12,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  // ─── 3. KPI / Performance Bonus ───────────────────────────────────
  {
    id: 'KPI_QUARTERLY',
    label: 'KPI Bonus Kuartalan',
    icon: '📊',
    category: 'KINERJA',
    description:
      'Bonus berbasis pencapaian KPI per kuartal. Formula: basic × persen × kpi_score (0..2). ' +
      'KPI 1.0 = on-target, > 1.0 over-achieve, < 1.0 below-target.',
    usage_note: 'Dibayar setiap akhir kuartal (Apr, Jul, Okt, Jan tahun berikut).',
    example: 'Basic 10jt, KPI score 1.2 → bonus = 10jt × 25% × 1.2 = Rp 3.000.000',
    apply: (form) => {
      form.code = form.code || `KPI-Q${Math.ceil((new Date().getMonth() + 1) / 3)}-${currentYear()}`
      form.name = form.name || `KPI Bonus Q${Math.ceil((new Date().getMonth() + 1) / 3)} ${currentYear()}`
      form.kind = 'KPI'
      form.formula_type = 'KPI_SCORE'
      form.formula_value = 25
      form.formula_expr = ''
      form.cap_amount = ''
      form.tax_treatment = 'NORMAL'
      form.description =
        'Bonus kuartalan berbasis KPI: 25% basic × kpi_score. ' +
        'KPI score karyawan diambil dari modul Performance/OKR.'
      form.eligibility = {
        employment_status: ['PKWTT', 'PKWT'],
        min_tenure_months: 3,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  {
    id: 'KPI_ANNUAL',
    label: 'KPI Bonus Tahunan',
    icon: '🎯',
    category: 'KINERJA',
    description:
      'Bonus tahunan berbasis KPI individual. Formula: basic × 100% × kpi_score. ' +
      'KPI 1.0 = 1× gaji, di atas itu over-perform.',
    usage_note: 'Dibayar setelah year-end review, biasanya Februari–Maret.',
    example: 'Basic 15jt, KPI score 1.1 → bonus = 15jt × 100% × 1.1 = Rp 16.500.000',
    apply: (form) => {
      form.code = form.code || `KPI-ANN-${currentYear()}`
      form.name = form.name || `KPI Annual Bonus ${currentYear()}`
      form.kind = 'KPI'
      form.formula_type = 'KPI_SCORE'
      form.formula_value = 100
      form.formula_expr = ''
      form.cap_amount = ''
      form.tax_treatment = 'NORMAL'
      form.description = 'Bonus tahunan KPI-based: 1× basic × kpi_score. PKWTT, tenure ≥ 12 bulan.'
      form.eligibility = {
        employment_status: ['PKWTT'],
        min_tenure_months: 12,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  // ─── 4. Sales Incentive / Komisi ──────────────────────────────────
  {
    id: 'SALES_INCENTIVE',
    label: 'Sales Incentive (% dari Basic)',
    icon: '💰',
    category: 'KINERJA',
    description:
      'Insentif sales bulanan flat % dari basic salary. Untuk komisi berbasis ' +
      'omzet aktual, gunakan modul Commission terpisah atau template "Sales Komisi".',
    usage_note: 'Dibayar bulanan bareng payroll atau terpisah di tengah bulan.',
    example: 'Basic 5jt, achievement target → bonus = 5jt × 50% = Rp 2.500.000',
    apply: (form) => {
      form.code = form.code || `SLS-INC-${currentYear()}`
      form.name = form.name || `Sales Incentive ${currentYear()}`
      form.kind = 'KPI'
      form.formula_type = 'PCT_OF_BASIC'
      form.formula_value = 50
      form.formula_expr = ''
      form.cap_amount = 10000000
      form.tax_treatment = 'NORMAL'
      form.description =
        'Sales incentive 50% basic salary saat hit target. Cap 10jt per bulan ' +
        'untuk fairness. Filter di department/position sales.'
      form.eligibility = {
        employment_status: ['PKWTT', 'PKWT'],
        min_tenure_months: 3,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  // ─── 5. Sign-on Bonus ─────────────────────────────────────────────
  {
    id: 'SIGN_ON',
    label: 'Sign-on Bonus (Joining Bonus)',
    icon: '✍️',
    category: 'REKRUTMEN',
    description:
      'Bonus sekali bayar saat join, biasanya untuk hire senior atau hard-to-fill role. ' +
      'Sering ada clawback clause: kalau resign < 12 bulan, harus dikembalikan.',
    usage_note: 'Dibayar di payroll bulan pertama atau kedua setelah join.',
    example: 'Senior engineer → sign-on Rp 25.000.000 (nominal tetap di kontrak).',
    apply: (form) => {
      form.code = form.code || `SIGN-ON-${currentYear()}`
      form.name = form.name || `Sign-on Bonus ${currentYear()}`
      form.kind = 'SIGNON'
      form.formula_type = 'FIXED_AMOUNT'
      form.formula_value = 10000000
      form.formula_expr = ''
      form.cap_amount = ''
      form.tax_treatment = 'NORMAL'
      form.description =
        'Sign-on bonus untuk new hire. Sesuaikan formula_value per kandidat ' +
        'via override recipient. Tambahkan klausul clawback di offer letter.'
      form.eligibility = {
        employment_status: ['PKWTT'],
        min_tenure_months: 0,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  // ─── 6. Retention Bonus ───────────────────────────────────────────
  {
    id: 'RETENTION',
    label: 'Retention Bonus (Loyalty)',
    icon: '🤝',
    category: 'RETENSI',
    description:
      'Bonus untuk karyawan kunci agar tidak pindah, biasanya dibayar setelah ' +
      'milestone tenure tertentu (mis. 2 tahun, 5 tahun).',
    usage_note: 'Dibayar saat anniversary masa kerja yang dijanjikan di kontrak.',
    example: 'Employee 5 tahun → retention bonus = Rp 30.000.000 (nominal flat).',
    apply: (form) => {
      form.code = form.code || `RET-${currentYear()}`
      form.name = form.name || `Retention Bonus ${currentYear()}`
      form.kind = 'RETENTION'
      form.formula_type = 'FIXED_AMOUNT'
      form.formula_value = 15000000
      form.formula_expr = ''
      form.cap_amount = ''
      form.tax_treatment = 'GROSS_UP'
      form.description =
        'Retention bonus key-talent. Tax treatment GROSS_UP supaya karyawan terima ' +
        'nominal penuh sesuai janji di kontrak (employer absorb PPh21).'
      form.eligibility = {
        employment_status: ['PKWTT'],
        min_tenure_months: 24,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  // ─── 7. Long Service Award / Tenure Bonus ────────────────────────
  {
    id: 'LONG_SERVICE',
    label: 'Long Service Award (per Tahun Masa Kerja)',
    icon: '🏅',
    category: 'RETENSI',
    description:
      'Bonus berbasis akumulasi masa kerja: setiap tahun dapat rate tertentu. ' +
      'Cocok untuk perusahaan yang menghargai loyalitas jangka panjang.',
    usage_note: 'Dibayar saat anniversary atau di akhir tahun.',
    example: 'Tenure 5 tahun, rate 500rb/tahun → bonus = 5 × 500rb = Rp 2.500.000',
    apply: (form) => {
      form.code = form.code || `LSA-${currentYear()}`
      form.name = form.name || `Long Service Award ${currentYear()}`
      form.kind = 'MILESTONE'
      form.formula_type = 'TENURE_BASED'
      form.formula_value = 500000
      form.formula_expr = ''
      form.cap_amount = ''
      form.tax_treatment = 'NORMAL'
      form.description =
        'Penghargaan masa kerja: Rp 500.000 per tahun masa kerja, tanpa cap. ' +
        'Hanya untuk PKWTT dengan tenure ≥ 1 tahun.'
      form.eligibility = {
        employment_status: ['PKWTT'],
        min_tenure_months: 12,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  // ─── 8. Project / Milestone Bonus ─────────────────────────────────
  {
    id: 'PROJECT',
    label: 'Project Completion Bonus',
    icon: '🚀',
    category: 'KINERJA',
    description:
      'Bonus selesai project untuk tim spesifik. Nominal flat di-set per project, ' +
      'biasanya HR override per recipient sesuai kontribusi.',
    usage_note: 'Dibayar setelah project go-live / handover diterima client.',
    example: 'Project IT migration selesai → tim dapat Rp 5.000.000 / orang.',
    apply: (form) => {
      form.code = form.code || `PROJ-${currentYear()}`
      form.name = form.name || `Project Bonus ${currentYear()}`
      form.kind = 'MILESTONE'
      form.formula_type = 'FIXED_AMOUNT'
      form.formula_value = 5000000
      form.formula_expr = ''
      form.cap_amount = ''
      form.tax_treatment = 'NORMAL'
      form.description =
        'Project completion bonus. Filter eligibility ke department/position yang ' +
        'terlibat. Override per-recipient untuk reflect kontribusi individual.'
      form.eligibility = {
        employment_status: ['PKWTT', 'PKWT'],
        min_tenure_months: 0,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  // ─── 9. Referral Bonus ────────────────────────────────────────────
  {
    id: 'REFERRAL',
    label: 'Employee Referral Bonus',
    icon: '👥',
    category: 'REKRUTMEN',
    description:
      'Bonus untuk karyawan yang merefer kandidat baru yang berhasil di-hire. ' +
      'Biasanya cair setelah referee lulus probation (3 bulan).',
    usage_note: 'Pakai run terpisah per batch referral, override per recipient.',
    example: 'Hire backend senior dari referral → bonus referrer Rp 3.000.000.',
    apply: (form) => {
      form.code = form.code || `REF-${currentYear()}`
      form.name = form.name || `Referral Bonus ${currentYear()}`
      form.kind = 'DISCRETIONARY'
      form.formula_type = 'FIXED_AMOUNT'
      form.formula_value = 3000000
      form.formula_expr = ''
      form.cap_amount = ''
      form.tax_treatment = 'NORMAL'
      form.description =
        'Referral bonus per kandidat berhasil di-hire dan lulus probation. ' +
        'HR pilih recipient manual via override (tidak semua karyawan di-eligible).'
      form.eligibility = {
        employment_status: ['PKWTT', 'PKWT'],
        min_tenure_months: 0,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  // ─── 10. Profit Sharing ──────────────────────────────────────────
  {
    id: 'PROFIT_SHARING',
    label: 'Profit Sharing / Jaspro',
    icon: '🏦',
    category: 'TAHUNAN',
    description:
      'Pembagian keuntungan perusahaan (sering disebut Jasa Produksi / Jaspro). ' +
      'Persen dari basic dengan multiplier sesuai pencapaian profit perusahaan.',
    usage_note: 'Dibayar setelah closing audit tahunan (Februari–April).',
    example:
      'Profit perusahaan over-target → 200% basic. Basic 10jt → bonus = Rp 20.000.000.',
    apply: (form) => {
      form.code = form.code || `JASPRO-${currentYear()}`
      form.name = form.name || `Jasa Produksi ${currentYear()}`
      form.kind = 'ANNUAL'
      form.formula_type = 'PCT_OF_BASIC'
      form.formula_value = 100
      form.formula_expr = ''
      form.cap_amount = ''
      form.tax_treatment = 'NORMAL'
      form.description =
        'Jasa produksi / profit sharing. Sesuaikan formula_value (persen) sesuai ' +
        'profit perusahaan tahun bersangkutan setelah board approve.'
      form.eligibility = {
        employment_status: ['PKWTT'],
        min_tenure_months: 12,
        exclude_resigned: false,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  // ─── 11. Spot / Discretionary Bonus ──────────────────────────────
  {
    id: 'SPOT',
    label: 'Spot Bonus / Apresiasi',
    icon: '⭐',
    category: 'AD_HOC',
    description:
      'Bonus apresiasi spontan untuk individu/tim atas kontribusi khusus. ' +
      'Nominal flat kecil, fleksibel, tidak terjadwal.',
    usage_note: 'Dibayar sewaktu-waktu setelah approval manager + HR.',
    example: 'Bantu deliver client deadline → spot bonus Rp 1.000.000.',
    apply: (form) => {
      form.code = form.code || `SPOT-${today().replace(/-/g, '')}`
      form.name = form.name || `Spot Bonus ${today()}`
      form.kind = 'DISCRETIONARY'
      form.formula_type = 'FIXED_AMOUNT'
      form.formula_value = 1000000
      form.formula_expr = ''
      form.cap_amount = ''
      form.tax_treatment = 'NORMAL'
      form.description =
        'Spot bonus / apresiasi spontan. Recipient di-pilih manual via override; ' +
        'eligibility filter di-set seluas mungkin.'
      form.eligibility = {
        employment_status: ['PKWTT', 'PKWT', 'PROBATION'],
        min_tenure_months: 0,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },

  // ─── 12. Insentif Kehadiran ──────────────────────────────────────
  {
    id: 'ATTENDANCE',
    label: 'Insentif Kehadiran (Attendance)',
    icon: '✅',
    category: 'AD_HOC',
    description:
      'Bonus bulanan untuk karyawan dengan kehadiran sempurna (no late, no absence). ' +
      'Biasanya nominal kecil tapi konsisten setiap bulan.',
    usage_note: 'Dibayar bulanan bareng payroll. Filter manual: hanya yang full hadir.',
    example: 'Operator pabrik full hadir bulan ini → bonus Rp 300.000.',
    apply: (form) => {
      form.code = form.code || `ATT-${currentYear()}`
      form.name = form.name || `Insentif Kehadiran ${currentYear()}`
      form.kind = 'DISCRETIONARY'
      form.formula_type = 'FIXED_AMOUNT'
      form.formula_value = 300000
      form.formula_expr = ''
      form.cap_amount = ''
      form.tax_treatment = 'NORMAL'
      form.description =
        'Insentif kehadiran sempurna. Buat run baru tiap bulan, filter recipient ' +
        'lewat report Attendance (full hadir, no late, no leave non-paid).'
      form.eligibility = {
        employment_status: ['PKWTT', 'PKWT', 'HARIAN'],
        min_tenure_months: 1,
        exclude_resigned: true,
        department_ids: [],
        branch_ids: [],
        grade_ids: [],
        position_ids: [],
      }
    },
  },
]

/**
 * Group templates by category untuk UI picker.
 */
export const BONUS_TEMPLATE_CATEGORIES = [
  { id: 'WAJIB',     label: 'Wajib (Regulasi)',  hint: 'THR, sesuai Permenaker' },
  { id: 'TAHUNAN',   label: 'Tahunan',            hint: 'Annual, B13, Jaspro' },
  { id: 'KINERJA',   label: 'Berbasis Kinerja',   hint: 'KPI, sales, project' },
  { id: 'RETENSI',   label: 'Retensi',            hint: 'Long service, retention' },
  { id: 'REKRUTMEN', label: 'Rekrutmen',          hint: 'Sign-on, referral' },
  { id: 'AD_HOC',    label: 'Ad-hoc / Lainnya',   hint: 'Spot, kehadiran' },
]

export function templatesByCategory() {
  return BONUS_TEMPLATE_CATEGORIES.map((cat) => ({
    ...cat,
    templates: BONUS_TEMPLATES.filter((t) => t.category === cat.id),
  })).filter((cat) => cat.templates.length > 0)
}