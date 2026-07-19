<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import {
  ArrowLeftIcon, PlusIcon, ArrowRightIcon, XCircleIcon, CheckBadgeIcon,
  StarIcon, TrashIcon, ClipboardDocumentIcon, CheckIcon, KeyIcon,
  DocumentIcon, EyeIcon, ArrowDownTrayIcon, PhotoIcon, DocumentTextIcon,
  EnvelopeIcon, BanknotesIcon, ClockIcon, NoSymbolIcon,
} from '@heroicons/vue/24/outline'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const jobId = computed(() => Number(route.params.id))
const job = ref(null)
const candidates = ref([])
const loading = ref(false)

// Show modals
const showAddCand = ref(false)
const candForm = reactive({
  full_name: '', email: '', phone: '', current_position: '', current_company: '',
  years_experience: 0, expected_salary: 0, available_from: '', source: 'WEBSITE', notes: '',
})

const showCandDetail = ref(null)
const detailData = ref(null)
const moveStageInput = reactive({
  stage: '', feedback: '', scheduled_at: '', rating: null,
})
const showRejectModal = ref(null)
const rejectReason = ref('')
const showHireModal = ref(null)
// Sprint 23+ — hire form sekarang mencakup data payroll-essential
// (PTKP, NPWP, BPJS, rekening bank, tax method) supaya saat HR submit,
// employee record langsung siap untuk perhitungan payroll. Field
// sensitif (npwp, bank_account) di-encrypt at-rest oleh backend.
const hireForm = reactive({
  // Employment
  basic_salary: 0,
  join_date: '',
  employment_status: 'PKWTT',
  probation_end_date: '',
  contract_end_date: '',
  // Tax / PTKP
  ptkp_status: 'TK0',
  tax_method: 'GROSS',
  pph21_method: 'TER',
  npwp: '',
  // BPJS
  bpjs_kesehatan_no: '',
  bpjs_kesehatan_class: 1,
  bpjs_tk_no: '',
  // Rekening bank
  bank_name: '',
  bank_account: '',
  bank_account_holder: '',
})

// Indikator kelengkapan data payroll. Dipakai untuk badge di footer modal
// supaya HR sadar field mana yang masih kosong.
const payrollReadyCount = computed(() => {
  let n = 0
  if (hireForm.basic_salary > 0) n++
  if (hireForm.ptkp_status) n++
  if (hireForm.npwp.trim() !== '') n++
  if (hireForm.bpjs_kesehatan_no.trim() !== '') n++
  if (hireForm.bpjs_tk_no.trim() !== '') n++
  if (hireForm.bank_name.trim() !== '' && hireForm.bank_account.trim() !== '') n++
  return n
})
const PAYROLL_READY_TOTAL = 6

const PTKP_OPTIONS = [
  { value: 'TK0', label: 'TK/0 — Belum kawin, tanpa tanggungan' },
  { value: 'TK1', label: 'TK/1 — Belum kawin, 1 tanggungan' },
  { value: 'TK2', label: 'TK/2 — Belum kawin, 2 tanggungan' },
  { value: 'TK3', label: 'TK/3 — Belum kawin, 3 tanggungan' },
  { value: 'K0',  label: 'K/0 — Kawin, tanpa tanggungan' },
  { value: 'K1',  label: 'K/1 — Kawin, 1 tanggungan' },
  { value: 'K2',  label: 'K/2 — Kawin, 2 tanggungan' },
  { value: 'K3',  label: 'K/3 — Kawin, 3 tanggungan' },
  { value: 'HB0', label: 'HB/0 — Penghasilan istri digabung, tanpa tanggungan' },
  { value: 'HB1', label: 'HB/1' },
  { value: 'HB2', label: 'HB/2' },
  { value: 'HB3', label: 'HB/3' },
]
const EMPLOYMENT_STATUS_OPTIONS = ['PKWTT','PKWT','PROBATION','HARIAN','MAGANG','OUTSOURCING']
const BANK_OPTIONS = ['BCA','MANDIRI','BNI','BRI','CIMB','PERMATA','DANAMON','BTN','BSI','OCBC','MAYBANK','PANIN','MEGA','BTPN','LAINNYA']

// Credential modal — tampil sekali setelah hire berhasil bila auto-create
// user akun untuk karyawan baru. HR wajib catat / kirim ke karyawan; setelah
// modal ditutup credential hilang dari client (tidak disimpan).
// Offer management (Sprint 2 / REC-1) — modal sebelum HR convert ke HIRED.
// Workflow: HR buka modal → buat DRAFT → kirim → kandidat respon di portal
// → HR proceed ke Hire kalau ACCEPTED.
const showOfferModal = ref(null) // candidate row saat aktif
const offers = ref([])           // list offer untuk kandidat ini
const loadingOffers = ref(false)
const offerForm = reactive({
  basic_salary: 0,
  joining_bonus: 0,
  employment_status: 'PKWTT',
  probation_months: 3,
  proposed_join_date: '',
  response_deadline: '',
  currency: 'IDR',
  internal_notes: '',
  allowances: [], // [{label, amount, type, is_taxable}]
})
const showOfferConfirm = ref(false) // konfirmasi send

const credentialResult = ref(null)
const copiedField = ref(null)
async function copyToClipboard(text, field) {
  try {
    await navigator.clipboard.writeText(text)
    copiedField.value = field
    setTimeout(() => { if (copiedField.value === field) copiedField.value = null }, 1500)
  } catch (e) {
    toast.error('Gagal copy ke clipboard')
  }
}
function copyAllCredentials() {
  if (! credentialResult.value) return
  const r = credentialResult.value
  const text = `Akun HRIS — ${r.employee_name}\nEmail: ${r.user_email}\nPassword sementara: ${r.temp_password}\nLink login: ${window.location.origin}/login\n(Wajib ganti password saat login pertama)`
  copyToClipboard(text, 'all')
}

// Migration #065 — STAGES dinamis dari pipeline template kalau job pakai
// custom pipeline. Default fallback: 7-stage classic (backward compat).
// Color & emoji ditentukan dari stage_type supaya visual konsisten lintas
// nama-nama custom (mis. "PSIKOTEST" dapat warna sama dengan "ONLINE_TEST").
const STAGE_TYPE_DECOR = {
  SCREENING:        { emoji: '📋', color: 'bg-blue-50' },
  ONLINE_TEST:      { emoji: '📝', color: 'bg-cyan-50' },
  INTERVIEW:        { emoji: '🗣️', color: 'bg-indigo-50' },
  ASSESSMENT:       { emoji: '🎯', color: 'bg-pink-50' },
  OFFER:            { emoji: '💰', color: 'bg-amber-50' },
  BACKGROUND_CHECK: { emoji: '🔍', color: 'bg-cyan-50' },
  MEDICAL_CHECK:    { emoji: '🏥', color: 'bg-teal-50' },
  CUSTOM:           { emoji: '🔹', color: 'bg-gray-50' },
  HIRED:            { emoji: '✅', color: 'bg-emerald-50' },
  REJECTED:         { emoji: '❌', color: 'bg-red-50' },
  WITHDRAWN:        { emoji: '🚪', color: 'bg-stone-50' },
}

// Default fallback stages — dipakai ketika job posting tidak punya
// pipeline_template_id dan tidak ada default template di company.
const DEFAULT_STAGES = [
  { code: 'NEW',            name: 'Baru Masuk',     stage_type: 'SCREENING',   is_terminal: 0, order_index: 0 },
  { code: 'SCREENING',      name: 'CV Screening',   stage_type: 'SCREENING',   is_terminal: 0, order_index: 1 },
  { code: 'ONLINE_TEST',    name: 'Online Test',    stage_type: 'ONLINE_TEST', is_terminal: 0, order_index: 2 },
  { code: 'INTERVIEW_HR',   name: 'Interview HR',   stage_type: 'INTERVIEW',   is_terminal: 0, order_index: 3 },
  { code: 'INTERVIEW_USER', name: 'Interview User', stage_type: 'INTERVIEW',   is_terminal: 0, order_index: 4 },
  { code: 'OFFER',          name: 'Offer Letter',   stage_type: 'OFFER',       is_terminal: 0, order_index: 5 },
  { code: 'HIRED',          name: 'Hired',          stage_type: 'HIRED',       is_terminal: 1, order_index: 6 },
]

// Pipeline stages (raw) — di-load dari API atau fallback DEFAULT_STAGES
const pipelineStages = ref(DEFAULT_STAGES)

// STAGES = computed, sembunyikan REJECTED/WITHDRAWN dari kolom papan utama
const STAGES = computed(() => {
  return pipelineStages.value
    .filter(s => ! ['REJECTED', 'WITHDRAWN'].includes(s.stage_type))
    .map(s => {
      const decor = STAGE_TYPE_DECOR[s.stage_type] || STAGE_TYPE_DECOR.CUSTOM
      return {
        code: s.code,
        label: `${decor.emoji} ${s.name}`,
        color: decor.color,
        stage_type: s.stage_type,
        is_terminal: s.is_terminal,
        required_test_category_id: s.required_test_category_id || null,
      }
    })
})

// Terminal codes — gabungan stage_type=HIRED/REJECTED/WITHDRAWN dari template
const TERMINAL = computed(() => {
  const codes = pipelineStages.value
    .filter(s => Number(s.is_terminal) === 1)
    .map(s => s.code)
  return Array.from(new Set([...codes, 'HIRED', 'REJECTED', 'WITHDRAWN']))
})

async function loadPipelineStages() {
  try {
    if (! job.value) return
    const tplId = job.value.pipeline_template_id
    if (tplId) {
      const res = await api.get(`/recruitment-pipelines/${tplId}`)
      const stages = res.data?.data?.stages || []
      if (stages.length) { pipelineStages.value = stages; return }
    }
    // Fallback ke default template per company
    const picker = await api.get('/recruitment-pipelines/picker')
    const def = (picker.data?.data || []).find(t => Number(t.is_default) === 1)
    if (def) {
      const detail = await api.get(`/recruitment-pipelines/${def.id}`)
      const stages = detail.data?.data?.stages || []
      if (stages.length) { pipelineStages.value = stages; return }
    }
    pipelineStages.value = DEFAULT_STAGES
  } catch (_) {
    pipelineStages.value = DEFAULT_STAGES
  }
}
const SOURCES = ['WEBSITE','LINKEDIN','JOBSTREET','REFERRAL','WALK_IN','OTHER']

const canManage = computed(() => auth.can('recruitment.manage'))
const canHire = computed(() => auth.can('recruitment.hire'))

const candidatesByStage = computed(() => {
  const map = {}
  STAGES.value.forEach(s => map[s.code] = [])
  // Distribusikan ke kolom sesuai current_stage. REJECTED & WITHDRAWN
  // otomatis terpisah karena bukan key valid di map (lihat counter
  // rejectedCount untuk badge "X ditolak"). HIRED tetap muncul di
  // kolomnya supaya HR bisa lihat siapa saja yang sudah jadi karyawan.
  candidates.value.forEach(c => {
    if (map[c.current_stage]) map[c.current_stage].push(c)
  })
  return map
})

const rejectedCount = computed(() => candidates.value.filter(c => c.current_stage === 'REJECTED').length)

async function fetchAll() {
  loading.value = true
  try {
    const [j, c] = await Promise.all([
      api.get(`/job-postings/${jobId.value}`),
      api.get('/candidates', { params: { job_posting_id: jobId.value, per_page: 200 } }),
    ])
    job.value = j.data.data
    candidates.value = c.data.data || []
    // Migration #065 — load custom stages dari pipeline_template (atau default)
    await loadPipelineStages()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
  finally { loading.value = false }
}

onMounted(fetchAll)

function openAddCandidate() {
  Object.assign(candForm, {
    full_name: '', email: '', phone: '', current_position: '', current_company: '',
    years_experience: 0, expected_salary: 0, available_from: '', source: 'WEBSITE', notes: '',
  })
  showAddCand.value = true
}

async function addCandidate() {
  if (! candForm.full_name) { toast.error('Nama wajib'); return }
  try {
    await api.post('/candidates', { ...candForm, job_posting_id: jobId.value })
    toast.success('Kandidat ditambahkan')
    showAddCand.value = false
    fetchAll()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function openCandidateDetail(c) {
  showCandDetail.value = c
  try {
    const res = await api.get(`/candidates/${c.id}`)
    detailData.value = res.data.data
  } catch (e) { toast.error('Gagal'); showCandDetail.value = null }
}

// Sprint 23+ — gate "online test must be completed & graded by HR"
// sebelum boleh maju ke INTERVIEW_HR/USER/OFFER/HIRED. Backend juga
// enforce di moveStage(); frontend cek dulu untuk UX yang bagus
// (warning + tombol disabled, bukan klik-error-baru-tahu).
// Migration #065 — gate "online test must be completed" sekarang dynamic:
// cek apakah targetStage berada SETELAH stage ber-stage_type ONLINE_TEST
// di pipeline template. Default fallback ke daftar legacy.
const POST_TEST_STAGES_FALLBACK = ['INTERVIEW_HR', 'INTERVIEW_USER', 'OFFER', 'HIRED']

function isPostOnlineTestStage(targetStage) {
  const list = pipelineStages.value || []
  if (! list.length) return POST_TEST_STAGES_FALLBACK.includes(targetStage)
  const target = list.find(s => s.code === targetStage)
  if (! target) return false
  // Cari prior ONLINE_TEST (order_index lebih kecil dari target)
  return list.some(s => s.stage_type === 'ONLINE_TEST'
    && Number(s.order_index || 0) < Number(target.order_index || 0))
}

function onlineTestBlocker(candidate, targetStage) {
  if (! isPostOnlineTestStage(targetStage)) return null
  const status = candidate?.online_test_status
  if (! status || ! status.has_assignments || status.all_done) return null
  return status.blocker_reason || 'Online test belum selesai dikerjakan / belum di-review HR.'
}

async function moveStage(c, newStage) {
  if (newStage === 'HIRED') return openHireModal(c)
  if (newStage === 'REJECTED') return openRejectModal(c)

  // Sprint 23+ — gate online test
  const blocker = onlineTestBlocker(c, newStage)
  if (blocker) {
    toast.error(blocker, { timeout: 7000 })
    return
  }

  try {
    await api.post(`/candidates/${c.id}/move-stage`, { stage: newStage })
    toast.success(`Pindah ke ${newStage}`)

    // Memindahkan kandidat ke stage OFFER belum menghasilkan candidate_offer:
    // salary, status kerja, join date, dan deadline tetap harus diisi TA.
    // Langsung buka form offer agar alurnya tidak berhenti di kartu OFFER yang
    // terlihat seolah sudah terkirim ke portal kandidat.
    if (newStage === 'OFFER') {
      await openOfferModal({ ...c, current_stage: newStage })
    }

    fetchAll()
    if (showCandDetail.value && showCandDetail.value.id === c.id) {
      const res = await api.get(`/candidates/${c.id}`)
      detailData.value = res.data.data
      showCandDetail.value = res.data.data
    }
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

function openRejectModal(c) {
  showRejectModal.value = c
  rejectReason.value = ''
}

async function doReject() {
  if (rejectReason.value.length < 5) { toast.error('Alasan min 5 karakter'); return }
  try {
    await api.post(`/candidates/${showRejectModal.value.id}/reject`, { reason: rejectReason.value })
    toast.success('Kandidat ditolak')
    showRejectModal.value = null
    fetchAll()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function openHireModal(c) {
  let candidateOffers = []
  try {
    const res = await api.get(`/candidates/${c.id}/offers`)
    candidateOffers = res.data.data || []
    const acceptedOffer = candidateOffers.find((offer) => offer.status === 'ACCEPTED') || null
    if (!acceptedOffer) {
      if (candidateOffers.length === 0) {
        toast.warning(
          'Belum ada offer untuk kandidat ini. Buat dan ajukan offer terlebih dahulu agar tampil di portal kandidat.',
          { timeout: 8000 },
        )
      } else if (candidateOffers.some((offer) => offer.status === 'SENT')) {
        toast.warning('Offer sudah dikirim dan masih menunggu respon kandidat.', { timeout: 8000 })
      } else if (candidateOffers.some((offer) => offer.status === 'PENDING_APPROVAL')) {
        toast.warning('Offer masih menunggu approval internal sebelum dikirim ke kandidat.', { timeout: 8000 })
      } else if (candidateOffers.some((offer) => ['DRAFT', 'APPROVAL_REJECTED'].includes(offer.status))) {
        toast.warning('Offer belum berhasil dikirim. Periksa draft atau hasil approval offer.', { timeout: 8000 })
      } else {
        toast.warning('Belum ada offer yang diterima kandidat. Periksa riwayat offer sebelum Hire.', { timeout: 8000 })
      }

      await openOfferModal(c)
      return
    }

    showHireModal.value = c
    // Reset semua field; pre-fill dari data candidate kalau tersedia.
    // PTKP di-derive dari marital_status candidate (BELUM_KAWIN→TK0,
    // KAWIN→K0). Dependent count belum di-track di candidates table
    // jadi default ke 0.
    const maritalToPtkp = {
      BELUM_KAWIN: 'TK0',
      KAWIN: 'K0',
      CERAI_HIDUP: 'TK0',
      CERAI_MATI: 'TK0',
    }
    Object.assign(hireForm, {
      basic_salary: acceptedOffer.basic_salary || c.expected_salary || 0,
      join_date: acceptedOffer.proposed_join_date || c.available_from || new Date().toISOString().slice(0, 10),
      employment_status: acceptedOffer.employment_status || 'PKWTT',
      probation_end_date: '',
      contract_end_date: '',
      ptkp_status: maritalToPtkp[c.marital_status] || 'TK0',
      tax_method: 'GROSS',
      pph21_method: 'TER',
      npwp: c.npwp || '',
      bpjs_kesehatan_no: c.bpjs_kesehatan_no || '',
      bpjs_kesehatan_class: 1,
      bpjs_tk_no: c.bpjs_tk_no || '',
      bank_name: '',
      bank_account: '',
      bank_account_holder: c.full_name || '',
    })
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memeriksa status offer kandidat')
    return
  }
}

async function doHire() {
  if (! canHire.value) { toast.error('Butuh permission recruitment.hire'); return }

  // Validasi minimum: gaji pokok + tanggal bergabung wajib supaya
  // payroll calculator bisa hitung apa pun. Field payroll lain bersifat
  // soft-required (HR boleh kosongkan & lengkapi nanti via profil).
  if (! hireForm.basic_salary || hireForm.basic_salary <= 0) {
    toast.error('Gaji pokok wajib diisi (> 0)')
    return
  }
  if (! hireForm.join_date) {
    toast.error('Tanggal bergabung wajib diisi')
    return
  }

  // Soft-warn kalau data payroll esensial belum lengkap. HR bisa tetap
  // lanjut, tapi periode payroll pertama mungkin punya data kosong
  // (NPWP/BPJS/rekening) yang harus dilengkapi sebelum bayar.
  if (payrollReadyCount.value < PAYROLL_READY_TOTAL) {
    const missing = []
    if (! hireForm.npwp.trim()) missing.push('NPWP')
    if (! hireForm.bpjs_kesehatan_no.trim()) missing.push('BPJS Kesehatan')
    if (! hireForm.bpjs_tk_no.trim()) missing.push('BPJS Ketenagakerjaan')
    if (! hireForm.bank_name.trim() || ! hireForm.bank_account.trim()) missing.push('Rekening bank')
    if (missing.length > 0) {
      const ok = await confirmDialog({
        title: 'Data payroll belum lengkap',
        message: `Field berikut masih kosong: ${missing.join(', ')}.\n\nKaryawan tetap bisa di-hire, tapi data tersebut harus dilengkapi sebelum periode payroll pertama supaya export bank, e-SPT PPh21, dan setor BPJS tidak ada kolom kosong.`,
        okText: 'Lanjut Hire',
        variant: 'warning',
      })
      if (! ok) return
    }
  }

  try {
    // Backend memvalidasi offer ACCEPTED dan mengajukan payload terenkripsi.
    // Employee baru dibentuk oleh adapter setelah final approval.
    // Build payload — kirim semua field yang HR lengkapi. Backend akan
    // skip field yang kosong-string supaya tidak overwrite carry-over
    // dari candidate dengan empty value.
    const payload = { ...hireForm }
    // Empty-string strip (kecuali yang relevan dengan backend `array_key_exists` flow)
    for (const k of Object.keys(payload)) {
      if (payload[k] === '' || payload[k] === null) delete payload[k]
    }
    await api.post(`/candidates/${showHireModal.value.id}/hire`, payload)
    showHireModal.value = null
    toast.success('Konversi kandidat diajukan untuk approval. Employee dan onboarding dibuat setelah keputusan final.')
    fetchAll()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function deleteCandidate(c) {
  const ok = await confirmDialog({
    title: 'Hapus kandidat?', message: c.full_name,
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/candidates/${c.id}`)
    fetchAll()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

// ─── Offer management (Sprint 2 / REC-1) ─────────────────────────────

const OFFER_STATUS_BADGE = {
  DRAFT:     { label: 'Draft',     color: 'bg-gray-100 text-gray-700' },
  PENDING_APPROVAL: { label: 'Menunggu Approval', color: 'bg-amber-100 text-amber-700' },
  APPROVAL_REJECTED: { label: 'Approval Ditolak', color: 'bg-rose-100 text-rose-700' },
  SENT:      { label: 'Terkirim',  color: 'bg-blue-100 text-blue-700' },
  ACCEPTED:  { label: 'Diterima',  color: 'bg-emerald-100 text-emerald-700' },
  REJECTED:  { label: 'Ditolak',   color: 'bg-rose-100 text-rose-700' },
  EXPIRED:   { label: 'Kadaluarsa',color: 'bg-amber-100 text-amber-700' },
  WITHDRAWN: { label: 'Ditarik',   color: 'bg-gray-100 text-gray-500' },
}

const offerActiveCount = computed(
  () => offers.value.filter(o => ['DRAFT', 'PENDING_APPROVAL', 'APPROVAL_REJECTED', 'SENT'].includes(o.status)).length,
)

// Total monthly compensation untuk preview (basic + sum allowances)
const offerTotalCompensation = computed(() => {
  const base = Number(offerForm.basic_salary) || 0
  const allow = (offerForm.allowances || []).reduce(
    (acc, a) => acc + (Number(a.amount) || 0), 0,
  )
  return base + allow
})

async function openOfferModal(c) {
  if (! canManage.value) {
    toast.error('Butuh permission recruitment.manage')
    return
  }
  showOfferModal.value = c
  // Reset form & default deadline +7 hari kerja
  Object.assign(offerForm, {
    basic_salary: c.expected_salary || 0,
    joining_bonus: 0,
    employment_status: 'PKWTT',
    probation_months: 3,
    proposed_join_date: c.available_from || '',
    response_deadline: defaultDeadline(),
    currency: 'IDR',
    internal_notes: '',
    allowances: [],
  })
  await loadOffersForCandidate(c.id)
}

function defaultDeadline() {
  const d = new Date()
  d.setDate(d.getDate() + 7)
  return d.toISOString().slice(0, 10)
}

async function loadOffersForCandidate(candId) {
  loadingOffers.value = true
  try {
    const res = await api.get(`/candidates/${candId}/offers`)
    offers.value = res.data.data || []
  } catch (e) {
    offers.value = []
  } finally {
    loadingOffers.value = false
  }
}

function addOfferAllowance() {
  offerForm.allowances.push({
    label: '', amount: 0, type: 'fixed', is_taxable: true,
  })
}
function removeOfferAllowance(idx) {
  offerForm.allowances.splice(idx, 1)
}

async function saveOfferDraft() {
  if (! showOfferModal.value) return

  if (! offerForm.basic_salary || offerForm.basic_salary <= 0) {
    toast.error('Gaji pokok wajib diisi (> 0)')
    return
  }
  if (offerForm.employment_status === 'PKWT' && offerForm.probation_months > 0) {
    toast.error('PKWT tidak boleh ada probation (PP 35/2021 Pasal 12). Set probation = 0.')
    return
  }
  if (offerForm.probation_months < 0 || offerForm.probation_months > 3) {
    toast.error('Probation antara 0-3 bulan (UU 13/2003 Pasal 60)')
    return
  }

  // Filter allowances yang valid
  const cleanAllowances = (offerForm.allowances || []).filter(
    a => a.label && a.amount > 0,
  )

  try {
    const payload = {
      candidate_id: showOfferModal.value.id,
      basic_salary: Number(offerForm.basic_salary),
      joining_bonus: Number(offerForm.joining_bonus) || 0,
      employment_status: offerForm.employment_status,
      probation_months: Number(offerForm.probation_months) || 0,
      proposed_join_date: offerForm.proposed_join_date || null,
      response_deadline: offerForm.response_deadline || null,
      currency: offerForm.currency || 'IDR',
      internal_notes: offerForm.internal_notes || null,
      allowances: cleanAllowances,
    }
    const res = await api.post(
      `/candidates/${showOfferModal.value.id}/offers`,
      payload,
    )
    toast.success(`Offer ${res.data.data.offer_number} dibuat (DRAFT)`)
    await loadOffersForCandidate(showOfferModal.value.id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal buat offer')
  }
}

async function sendOffer(offerId) {
  const ok = await confirmDialog({
    title: 'Ajukan approval offer?',
    message: 'Offer akan diperiksa Hiring Manager dan HR. Finance serta Direksi ikut memeriksa bila salary atau joining bonus merupakan policy exception. Kandidat baru menerima offer setelah approval final.',
    okText: 'Ajukan',
    variant: 'primary',
  })
  if (! ok) return
  try {
    await api.post(`/offers/${offerId}/send`)
    toast.success('Offer diajukan ke workflow approval')
    await loadOffersForCandidate(showOfferModal.value.id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal kirim offer')
  }
}

async function withdrawOffer(offerId) {
  const reason = window.prompt('Alasan withdraw offer (min 5 karakter):', '')
  if (! reason || reason.trim().length < 5) {
    toast.error('Alasan withdraw wajib diisi minimum 5 karakter')
    return
  }
  try {
    await api.post(`/offers/${offerId}/withdraw`, { reason: reason.trim() })
    toast.success('Offer di-withdraw')
    await loadOffersForCandidate(showOfferModal.value.id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal withdraw')
  }
}

function offerLetterUrl(offerId, format = 'pdf') {
  // Note: backend belum expose endpoint render letter standalone untuk
  // offer (cuma di generated_contracts). Sementara, user akses preview
  // lewat detail page atau langsung copy dari log. Kalau diperlukan,
  // tambah GET /offers/{id}/letter di OfferController kemudian.
  return null
}

function nextStage(currentStage) {
  const list = STAGES.value
  const idx = list.findIndex(s => s.code === currentStage)
  if (idx === -1 || idx === list.length - 1) return null
  return list[idx + 1].code
}

// Gabungan dokumen: prioritas `documents` dari candidate_documents,
// fallback ke `legacy_documents` (cv_path/photo_path/dst di candidates).
// Backend sudah include signed URL untuk semua-nya.
const allDocuments = computed(() => {
  if (! detailData.value) return []
  const docs = []
  for (const d of detailData.value.documents || []) {
    docs.push({
      type: d.document_type || 'OTHER',
      label: docTypeLabel(d.document_type),
      file_name: d.file_name || d.file_path?.split('/').pop() || 'document',
      file_path: d.file_path,
      view_url: d.view_url,
      mime_hint: guessMimeFromPath(d.file_path || ''),
    })
  }
  // Hanya tambahkan legacy kalau tipe-nya belum ada di documents (avoid duplicate)
  const seenTypes = new Set(docs.map(d => d.type))
  for (const d of detailData.value.legacy_documents || []) {
    if (seenTypes.has(d.document_type)) continue
    docs.push({
      type: d.document_type,
      label: d.label || docTypeLabel(d.document_type),
      file_name: d.file_name || 'document',
      file_path: d.file_path,
      view_url: d.view_url,
      mime_hint: guessMimeFromPath(d.file_path || ''),
    })
  }
  return docs
})

function docTypeLabel(type) {
  const map = {
    CV: 'CV / Resume',
    KTP: 'KTP',
    IJAZAH: 'Ijazah',
    TRANSKRIP: 'Transkrip Nilai',
    SERTIFIKAT: 'Sertifikat',
    PORTFOLIO: 'Portfolio',
    OTHER: 'Dokumen Lain',
  }
  return map[type] || type
}
function guessMimeFromPath(path) {
  const ext = (path.split('.').pop() || '').toLowerCase()
  if (['jpg','jpeg','png','webp','gif'].includes(ext)) return 'image'
  if (ext === 'pdf') return 'pdf'
  if (['doc','docx'].includes(ext)) return 'doc'
  return 'other'
}
function downloadDoc(doc) {
  if (! doc.view_url) {
    toast.error('URL tidak tersedia. Coba refresh halaman.')
    return
  }
  // Force-download via temporary <a download>. Browser akan tetap bisa
  // pakai signed URL yang sama untuk PDF/image preview.
  const a = document.createElement('a')
  a.href = doc.view_url
  a.download = doc.file_name || 'document'
  a.target = '_blank'
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
}
</script>

<template>
  <div>
    <button class="text-sm text-gray-600 hover:text-primary-600 flex items-center gap-1 mb-3" @click="router.push('/recruitment/jobs')">
      <ArrowLeftIcon class="w-4 h-4" /> Kembali ke list posting
    </button>

    <div v-if="job" class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold">{{ job.title }}</h1>
        <p class="text-sm text-gray-500">
          {{ job.reference_number }} · {{ job.employment_type }} · Headcount {{ job.headcount }} ·
          <span class="font-mono">{{ candidates.length }} kandidat</span>
          <span v-if="rejectedCount > 0" class="text-red-600">({{ rejectedCount }} ditolak)</span>
        </p>
      </div>
    </div>

    <!-- Kanban board -->
    <div v-if="loading" class="text-center py-12 text-gray-400">Memuat…</div>
    <div v-else class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-7 gap-3">
      <div v-for="stage in STAGES" :key="stage.code"
        :class="['rounded-lg p-3 min-h-64', stage.color]">
        <div class="font-semibold text-sm mb-3 flex items-center justify-between">
          <span>{{ stage.label }}</span>
          <span class="px-2 py-0.5 bg-white rounded text-xs">{{ candidatesByStage[stage.code]?.length || 0 }}</span>
        </div>
        <div class="space-y-2">
          <div v-for="c in candidatesByStage[stage.code]" :key="c.id"
            class="bg-white rounded-md shadow-sm p-3 cursor-pointer hover:shadow-md transition"
            @click="openCandidateDetail(c)">
            <div class="font-medium text-sm">{{ c.full_name }}</div>
            <div class="text-xs text-gray-500">{{ c.current_position || '-' }}</div>
            <div v-if="c.expected_salary" class="text-xs text-gray-500 mt-1 font-mono">
              {{ fmtCurrency(c.expected_salary) }}
            </div>
            <div v-if="c.rating" class="flex items-center gap-0.5 mt-1">
              <StarIcon v-for="n in c.rating" :key="n" class="w-3 h-3 text-amber-400 fill-amber-400" />
            </div>
            <div v-if="canManage" class="flex gap-1 mt-2 pt-2 border-t border-gray-100" @click.stop>
              <button v-if="c.current_stage === 'OFFER'"
                class="text-xs px-2 py-1 rounded flex items-center gap-1 text-amber-700 bg-amber-50 hover:bg-amber-100"
                title="Buat, ajukan, dan pantau offer kandidat"
                @click="openOfferModal(c)">
                <BanknotesIcon class="w-3 h-3" /> Kelola Offer
              </button>
              <button v-if="nextStage(c.current_stage)"
                :class="[
                  'text-xs px-2 py-1 rounded flex items-center gap-1',
                  onlineTestBlocker(c, nextStage(c.current_stage))
                    ? 'text-gray-400 bg-gray-50 cursor-not-allowed'
                    : 'text-blue-600 hover:bg-blue-50',
                ]"
                :title="onlineTestBlocker(c, nextStage(c.current_stage))
                  || `Pindah ke ${nextStage(c.current_stage)}`"
                :disabled="!! onlineTestBlocker(c, nextStage(c.current_stage))"
                @click="moveStage(c, nextStage(c.current_stage))">
                <ArrowRightIcon class="w-3 h-3" />
                {{ c.current_stage === 'OFFER' ? 'Hire' : nextStage(c.current_stage) }}
              </button>
              <button class="text-xs text-red-600 hover:bg-red-50 px-2 py-1 rounded ml-auto"
                title="Reject" @click="openRejectModal(c)">
                <XCircleIcon class="w-3 h-3" />
              </button>
            </div>
          </div>
          <div v-if="!candidatesByStage[stage.code]?.length"
            class="text-xs text-center text-gray-400 py-4">— kosong —</div>
        </div>
      </div>
    </div>

    <!-- Add candidate modal -->
    <div v-if="showAddCand" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">Tambah Kandidat</h2>
          <button class="text-gray-500" @click="showAddCand = false">&times;</button>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-3">
          <div class="md:col-span-2">
            <label class="label">Nama Lengkap *</label>
            <input v-model="candForm.full_name" class="input" />
          </div>
          <div><label class="label">Email</label><input v-model="candForm.email" class="input" /></div>
          <div><label class="label">Telepon</label><input v-model="candForm.phone" class="input" /></div>
          <div><label class="label">Posisi Saat Ini</label><input v-model="candForm.current_position" class="input" /></div>
          <div><label class="label">Perusahaan Saat Ini</label><input v-model="candForm.current_company" class="input" /></div>
          <div><label class="label">Pengalaman (tahun)</label><input type="number" v-model="candForm.years_experience" class="input" /></div>
          <div><label class="label">Expected Salary (Rp)</label><input type="number" v-model="candForm.expected_salary" class="input" /></div>
          <div><label class="label">Available From</label><AppDatePicker  v-model="candForm.available_from" class="input" /></div>
          <div><label class="label">Sumber</label>
            <select v-model="candForm.source" class="input">
              <option v-for="s in SOURCES" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>
          <div class="md:col-span-2"><label class="label">Catatan</label>
            <textarea v-model="candForm.notes" class="input" rows="2"></textarea>
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showAddCand = false">Batal</button>
          <button class="btn-primary" @click="addCandidate">Simpan</button>
        </div>
      </div>
    </div>

    <!-- Candidate detail modal -->
    <div v-if="showCandDetail && detailData" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <div>
            <h2 class="font-semibold">{{ detailData.full_name }}</h2>
            <div class="text-xs text-gray-500">
              Stage: <strong>{{ detailData.current_stage }}</strong>
              <span v-if="detailData.converted_employee_id" class="ml-2 px-2 py-0.5 bg-emerald-100 text-emerald-700 rounded text-xs">
                Sudah jadi Employee #{{ detailData.converted_employee_id }}
              </span>
              <span v-else-if="detailData.hire_approval_status === 'PENDING'" class="ml-2 px-2 py-0.5 bg-amber-100 text-amber-700 rounded text-xs">
                Konversi menunggu approval
              </span>
            </div>
          </div>
          <button class="text-gray-500" @click="showCandDetail = null">&times;</button>
        </div>

        <div class="p-6 space-y-4">
          <div class="grid grid-cols-2 gap-3 text-sm">
            <div><div class="text-xs text-gray-500">Email</div>{{ detailData.email || '-' }}</div>
            <div><div class="text-xs text-gray-500">Telepon</div>{{ detailData.phone || '-' }}</div>
            <div><div class="text-xs text-gray-500">Posisi Saat Ini</div>{{ detailData.current_position || '-' }}</div>
            <div><div class="text-xs text-gray-500">Pengalaman</div>{{ detailData.years_experience || 0 }} tahun</div>
            <div><div class="text-xs text-gray-500">Expected Salary</div>{{ fmtCurrency(detailData.expected_salary) }}</div>
            <div><div class="text-xs text-gray-500">Sumber</div>{{ detailData.source || '-' }}</div>
            <div v-if="detailData.available_from"><div class="text-xs text-gray-500">Available From</div>{{ fmtDate(detailData.available_from) }}</div>
          </div>

          <!-- Dokumen pelamar (CV, KTP, ijazah, foto, dst) -->
          <div v-if="allDocuments.length > 0">
            <h3 class="font-semibold mb-2 text-sm flex items-center gap-1.5">
              <DocumentIcon class="w-4 h-4" />
              Dokumen Pelamar ({{ allDocuments.length }})
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
              <div v-for="(doc, idx) in allDocuments" :key="idx"
                class="flex items-center gap-2 border border-gray-200 rounded-md p-2 hover:bg-gray-50">
                <div class="w-9 h-9 rounded bg-blue-50 flex items-center justify-center flex-shrink-0">
                  <PhotoIcon v-if="doc.mime_hint === 'image'" class="w-5 h-5 text-blue-600" />
                  <DocumentTextIcon v-else class="w-5 h-5 text-blue-600" />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-xs font-medium text-gray-700 truncate" :title="doc.file_name">
                    {{ doc.label }}
                  </div>
                  <div class="text-[11px] text-gray-500 truncate" :title="doc.file_name">
                    {{ doc.file_name }}
                  </div>
                </div>
                <div class="flex items-center gap-1 flex-shrink-0">
                  <a v-if="doc.view_url" :href="doc.view_url" target="_blank" rel="noopener"
                    class="p-1.5 text-gray-500 hover:text-primary-600 hover:bg-blue-50 rounded"
                    title="Buka di tab baru">
                    <EyeIcon class="w-4 h-4" />
                  </a>
                  <button v-if="doc.view_url" type="button" @click="downloadDoc(doc)"
                    class="p-1.5 text-gray-500 hover:text-primary-600 hover:bg-blue-50 rounded"
                    title="Unduh file">
                    <ArrowDownTrayIcon class="w-4 h-4" />
                  </button>
                </div>
              </div>
            </div>
            <p class="text-[11px] text-gray-400 mt-1.5">
              Link berlaku 5 menit untuk keamanan; tutup-buka modal untuk refresh.
            </p>
          </div>

          <!-- Cover letter & extra answers (kalau via Career Portal) -->
          <div v-if="detailData.cover_letter">
            <h3 class="font-semibold mb-2 text-sm">Cover Letter</h3>
            <div class="text-sm text-gray-700 bg-gray-50 rounded p-3 whitespace-pre-line border border-gray-200">
              {{ detailData.cover_letter }}
            </div>
          </div>

          <div>
            <h3 class="font-semibold mb-2 text-sm">Riwayat Stage</h3>
            <div class="space-y-1">
              <div v-for="s in detailData.stages" :key="s.id" class="flex items-center justify-between text-xs border-l-2 pl-3 py-1"
                :class="s.result === 'PASSED' ? 'border-emerald-500' : s.result === 'FAILED' ? 'border-red-500' : 'border-gray-300'">
                <div>
                  <span class="font-medium">{{ s.stage }}</span>
                  <span class="text-gray-500"> · {{ s.result }}</span>
                </div>
                <div class="text-gray-500">
                  {{ s.completed_at ? fmtDate(s.completed_at) : (s.scheduled_at ? 'Dijadwalkan: ' + fmtDate(s.scheduled_at) : 'Pending') }}
                </div>
              </div>
            </div>
          </div>

          <!-- Sprint 23+ — banner status online test (gate post-test stages) -->
          <div v-if="detailData.online_test_status?.has_assignments && ! detailData.online_test_status.all_done"
            class="bg-amber-50 border border-amber-300 rounded-md p-3">
            <div class="flex items-start gap-2">
              <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
              </svg>
              <div class="flex-1 min-w-0">
                <div class="font-semibold text-amber-900 text-sm">Online Test Belum Selesai</div>
                <p class="text-xs text-amber-800 mt-0.5">
                  {{ detailData.online_test_status.blocker_reason
                    || 'Online test belum selesai dikerjakan / belum di-review HR.' }}
                </p>
                <p class="text-[11px] text-amber-700 mt-1.5">
                  Kandidat tidak bisa dipindah ke stage <strong>INTERVIEW HR / USER / OFFER / HIRED</strong>
                  sampai semua test selesai & sudah di-grade oleh HR.
                </p>
              </div>
            </div>
          </div>

          <!-- Daftar online test assignment kandidat -->
          <div v-if="detailData.online_test_assignments?.length > 0">
            <h3 class="font-semibold mb-2 text-sm">Online Test ({{ detailData.online_test_assignments.length }})</h3>
            <div class="space-y-1.5">
              <div v-for="a in detailData.online_test_assignments" :key="a.id"
                class="flex items-center justify-between text-xs border border-gray-200 rounded px-3 py-2">
                <div class="min-w-0 flex-1">
                  <div class="font-medium truncate">{{ a.test_name || 'Online Test' }}</div>
                  <div class="text-gray-500 mt-0.5">
                    <span>{{ a.test_category || '-' }}</span>
                    <span v-if="a.due_at"> · Due: {{ fmtDate(a.due_at) }}</span>
                    <span v-if="a.attempts_used > 0"> · Attempt: {{ a.attempts_used }}/{{ a.max_attempts }}</span>
                  </div>
                </div>
                <div class="flex items-center gap-2 ml-2">
                  <span v-if="a.best_percentage !== null && a.best_percentage !== undefined"
                    class="text-xs font-mono">{{ a.best_percentage }}%</span>
                  <span :class="[
                    'text-[10px] px-2 py-0.5 rounded-full font-medium',
                    a.status === 'COMPLETED' && a.best_result === 'PASS' ? 'bg-emerald-100 text-emerald-700' : '',
                    a.status === 'COMPLETED' && a.best_result === 'FAIL' ? 'bg-rose-100 text-rose-700' : '',
                    a.status === 'COMPLETED' && ['PENDING','REVIEW'].includes(a.best_result) ? 'bg-amber-100 text-amber-700' : '',
                    a.status === 'PENDING' ? 'bg-gray-100 text-gray-600' : '',
                    a.status === 'IN_PROGRESS' ? 'bg-blue-100 text-blue-700' : '',
                    a.status === 'EXPIRED' ? 'bg-rose-100 text-rose-700' : '',
                    a.status === 'CANCELLED' ? 'bg-gray-100 text-gray-500' : '',
                  ]">
                    {{ a.status === 'COMPLETED' && ['PENDING','REVIEW'].includes(a.best_result)
                      ? 'Menunggu Review'
                      : (a.status === 'COMPLETED' ? a.best_result : a.status) }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <div v-if="canManage && ! TERMINAL.includes(detailData.current_stage)">
            <h3 class="font-semibold mb-2 text-sm">Aksi</h3>
            <div class="flex flex-wrap gap-2">
              <button v-for="s in STAGES" :key="s.code"
                v-show="s.code !== detailData.current_stage"
                :class="[
                  'text-xs px-3 py-1 rounded border',
                  onlineTestBlocker(detailData, s.code)
                    ? 'border-gray-200 bg-gray-50 text-gray-400 cursor-not-allowed'
                    : 'border-gray-300 hover:bg-gray-50',
                ]"
                :title="onlineTestBlocker(detailData, s.code)
                  || `Pindah ke ${s.label}`"
                :disabled="!! onlineTestBlocker(detailData, s.code)"
                @click="moveStage(detailData, s.code)">
                {{ s.label }}
              </button>
              <button class="text-xs px-3 py-1 rounded border border-red-300 text-red-600 hover:bg-red-50"
                @click="openRejectModal(detailData)">
                ❌ Tolak
              </button>
              <!-- Sprint 2 / REC-1: Offer letter management -->
              <button v-if="canManage && detailData.current_stage === 'OFFER'"
                class="text-xs px-3 py-1 rounded border border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100 flex items-center gap-1"
                @click="openOfferModal(detailData)">
                <BanknotesIcon class="w-3.5 h-3.5" /> Kelola Offer
              </button>
              <button v-if="canHire && detailData.current_stage === 'OFFER'"
                class="text-xs px-3 py-1 rounded bg-emerald-600 text-white hover:bg-emerald-700"
                @click="openHireModal(detailData)">
                ✅ Hire (Convert ke Employee)
              </button>
            </div>
          </div>
        </div>

        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button v-if="canManage && !detailData.converted_employee_id && detailData.hire_approval_status !== 'PENDING'"
            class="btn-secondary text-red-600" @click="async () => { await deleteCandidate(detailData); showCandDetail = null }">
            Hapus
          </button>
          <button class="btn-secondary" @click="showCandDetail = null">Tutup</button>
        </div>
      </div>
    </div>

    <!-- Reject modal -->
    <div v-if="showRejectModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">Tolak Kandidat: {{ showRejectModal.full_name }}</h2>
          <button class="text-gray-500" @click="showRejectModal = null">&times;</button>
        </div>
        <div class="p-6">
          <label class="label">Alasan penolakan *</label>
          <textarea v-model="rejectReason" class="input" rows="3"></textarea>
          <p class="text-xs text-gray-500 mt-1">Min 5 karakter. Tercatat di history stages.</p>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showRejectModal = null">Batal</button>
          <button class="btn-danger" @click="doReject">Tolak</button>
        </div>
      </div>
    </div>

    <!-- Credential one-time view modal (setelah hire) -->
    <div v-if="credentialResult" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="px-6 py-3 border-b flex items-center justify-between bg-emerald-50">
          <h2 class="font-semibold text-emerald-800 flex items-center gap-2">
            <CheckBadgeIcon class="w-5 h-5" /> Hire berhasil — Credential HRIS
          </h2>
        </div>
        <div class="p-6 space-y-4">
          <div class="bg-emerald-50 border border-emerald-200 rounded p-3 text-sm text-emerald-900">
            <div class="flex items-start gap-2">
              <CheckBadgeIcon class="w-5 h-5 flex-shrink-0 mt-0.5" />
              <div>
                <strong>Email otomatis dikirim ke <span class="font-mono">{{ credentialResult.user_email }}</span>.</strong>
                Karyawan akan menerima credential lengkap dengan link login langsung di kotak masuk email.
              </div>
            </div>
          </div>

          <div class="bg-amber-50 border border-amber-200 rounded p-3 text-sm text-amber-900">
            <div class="flex items-start gap-2">
              <KeyIcon class="w-5 h-5 flex-shrink-0 mt-0.5" />
              <div>
                <strong>Backup credential:</strong> kalau email tidak sampai (mis. masuk spam atau karyawan belum cek),
                Anda juga bisa berikan password sementara di bawah secara manual.
                Password ini <strong>hanya tampil sekali</strong>. Kalau hilang sebelum karyawan login, reset password manual via Admin → Users.
              </div>
            </div>
          </div>

          <div>
            <div class="text-xs text-gray-500 mb-1">Karyawan</div>
            <div class="font-medium">{{ credentialResult.employee_name }} (Employee #{{ credentialResult.employee_id }})</div>
          </div>

          <div>
            <div class="text-xs text-gray-500 mb-1">Email login</div>
            <div class="flex items-center gap-2 bg-gray-50 rounded border border-gray-200 px-3 py-2 font-mono text-sm">
              <span class="flex-1 break-all">{{ credentialResult.user_email }}</span>
              <button class="text-gray-500 hover:text-primary-600 flex-shrink-0"
                :title="copiedField === 'email' ? 'Tersalin!' : 'Salin email'"
                @click="copyToClipboard(credentialResult.user_email, 'email')">
                <CheckIcon v-if="copiedField === 'email'" class="w-4 h-4 text-emerald-600" />
                <ClipboardDocumentIcon v-else class="w-4 h-4" />
              </button>
            </div>
          </div>

          <div>
            <div class="text-xs text-gray-500 mb-1">Password sementara</div>
            <div class="flex items-center gap-2 bg-gray-50 rounded border border-gray-200 px-3 py-2 font-mono text-sm">
              <span class="flex-1 break-all tracking-wider select-all">{{ credentialResult.temp_password }}</span>
              <button class="text-gray-500 hover:text-primary-600 flex-shrink-0"
                :title="copiedField === 'password' ? 'Tersalin!' : 'Salin password'"
                @click="copyToClipboard(credentialResult.temp_password, 'password')">
                <CheckIcon v-if="copiedField === 'password'" class="w-4 h-4 text-emerald-600" />
                <ClipboardDocumentIcon v-else class="w-4 h-4" />
              </button>
            </div>
            <p class="text-xs text-gray-500 mt-1">Karyawan wajib ganti password saat login pertama (must_change_password=1).</p>
          </div>

          <div v-if="credentialResult.auto_completed_tasks > 0"
            class="text-sm bg-blue-50 border border-blue-200 rounded p-3 text-blue-900">
            <strong>{{ credentialResult.auto_completed_tasks }} onboarding task auto-completed</strong>
            karena datanya sudah dikumpulkan saat lamaran (dokumen, NPWP, BPJS, akun HRIS).
            Cek HR → Onboarding untuk lihat task yang masih perlu dikerjakan manual (kontrak, induksi, dst).
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-between gap-2 bg-gray-50">
          <button class="text-sm text-gray-600 hover:text-primary-600 flex items-center gap-1"
            @click="copyAllCredentials">
            <CheckIcon v-if="copiedField === 'all'" class="w-4 h-4 text-emerald-600" />
            <ClipboardDocumentIcon v-else class="w-4 h-4" />
            {{ copiedField === 'all' ? 'Tersalin!' : 'Salin semua' }}
          </button>
          <button class="btn-primary" @click="credentialResult = null">
            Saya sudah catat — Tutup
          </button>
        </div>
      </div>
    </div>

    <!-- Offer modal (Sprint 2 / REC-1) — kelola offer letter kandidat -->
    <div v-if="showOfferModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[92vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between sticky top-0 bg-white z-10">
          <div>
            <h2 class="font-semibold flex items-center gap-2">
              <BanknotesIcon class="w-5 h-5 text-amber-600" />
              Offer Letter — {{ showOfferModal.full_name }}
            </h2>
            <div class="text-xs text-gray-500">
              Buat penawaran kerja, kirim ke kandidat, dan track respon di sini
            </div>
          </div>
          <button class="text-gray-500" @click="showOfferModal = null">&times;</button>
        </div>

        <div class="p-6 space-y-5">
          <!-- Existing offers list -->
          <section v-if="loadingOffers || offers.length > 0" class="border border-gray-200 rounded-lg">
            <header class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium text-sm flex items-center justify-between">
              <span>Riwayat Offer ({{ offers.length }})</span>
              <span v-if="offerActiveCount > 0" class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded">
                {{ offerActiveCount }} aktif
              </span>
            </header>
            <div class="divide-y divide-gray-100">
              <div v-if="loadingOffers" class="px-4 py-3 text-sm text-gray-500 italic text-center">
                Memuat…
              </div>
              <div v-for="offer in offers" :key="offer.id" class="px-4 py-3">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                      <span class="font-mono text-sm font-medium">{{ offer.offer_number }}</span>
                      <span :class="[
                        'text-[10px] px-2 py-0.5 rounded-full font-medium',
                        OFFER_STATUS_BADGE[offer.status]?.color || 'bg-gray-100 text-gray-600',
                      ]">
                        {{ OFFER_STATUS_BADGE[offer.status]?.label || offer.status }}
                      </span>
                      <span class="text-xs text-gray-500">
                        {{ offer.employment_status }} · {{ offer.probation_months || 0 }} bln probation
                      </span>
                    </div>
                    <div class="text-sm text-gray-700 mt-1 font-mono">
                      {{ fmtCurrency(offer.basic_salary) }} / bulan
                      <span v-if="offer.joining_bonus > 0" class="text-xs text-emerald-600 ml-1">
                        + {{ fmtCurrency(offer.joining_bonus) }} sign-on
                      </span>
                      <span v-if="offer.total_monthly_compensation > offer.basic_salary"
                        class="text-xs text-gray-500 ml-1">
                        (total {{ fmtCurrency(offer.total_monthly_compensation) }})
                      </span>
                    </div>
                    <div class="text-xs text-gray-500 mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
                      <span v-if="offer.proposed_join_date">
                        <ClockIcon class="w-3 h-3 inline mr-0.5" />
                        Join: {{ fmtDate(offer.proposed_join_date) }}
                      </span>
                      <span v-if="offer.response_deadline">
                        Deadline respon: {{ fmtDate(offer.response_deadline) }}
                      </span>
                      <span v-if="offer.sent_at">
                        Terkirim: {{ fmtDate(offer.sent_at) }}
                      </span>
                      <span v-if="offer.responded_at">
                        Direspon: {{ fmtDate(offer.responded_at) }}
                      </span>
                    </div>
                    <div v-if="offer.response_note"
                      class="text-xs bg-gray-50 border border-gray-200 rounded px-2 py-1 mt-2 text-gray-700 italic">
                      "{{ offer.response_note }}"
                    </div>
                    <div v-if="offer.rejection_reason"
                      class="text-xs bg-rose-50 border border-rose-200 rounded px-2 py-1 mt-2 text-rose-700">
                      Alasan tolak: {{ offer.rejection_reason }}
                    </div>
                  </div>
                  <div class="flex flex-col gap-1">
                    <button v-if="['DRAFT', 'APPROVAL_REJECTED'].includes(offer.status)"
                      class="text-xs px-2.5 py-1 rounded bg-blue-600 text-white hover:bg-blue-700 flex items-center gap-1"
                      @click="sendOffer(offer.id)">
                      <EnvelopeIcon class="w-3.5 h-3.5" /> Kirim
                    </button>
                    <button v-if="['DRAFT', 'PENDING_APPROVAL', 'APPROVAL_REJECTED', 'SENT'].includes(offer.status)"
                      class="text-xs px-2.5 py-1 rounded border border-gray-300 hover:bg-gray-50 flex items-center gap-1"
                      @click="withdrawOffer(offer.id)">
                      <NoSymbolIcon class="w-3.5 h-3.5" /> Withdraw
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- Form bikin offer baru — disabled kalau ada offer aktif -->
          <section class="border border-gray-200 rounded-lg">
            <header class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium text-sm">
              Buat Offer Baru
            </header>

            <div v-if="offerActiveCount > 0"
              class="p-4 bg-amber-50 border-b border-amber-200 text-sm text-amber-800">
              <strong>Ada offer aktif untuk kandidat ini.</strong>
              Withdraw offer aktif terlebih dahulu kalau mau buat offer baru
              dengan persyaratan berbeda — supaya audit trail jelas.
            </div>

            <fieldset :disabled="offerActiveCount > 0" class="p-4 space-y-4 disabled:opacity-50">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <label class="label">Gaji Pokok (Rp) *</label>
                  <input type="number" v-model.number="offerForm.basic_salary" class="input" min="0" />
                  <p class="text-xs text-gray-500 mt-1">
                    Sebelum potongan PPh21 & BPJS. Tax method ditentukan saat hire.
                  </p>
                </div>
                <div>
                  <label class="label">Bonus Bergabung (Rp)</label>
                  <input type="number" v-model.number="offerForm.joining_bonus" class="input" min="0" />
                  <p class="text-xs text-gray-500 mt-1">
                    Sign-on bonus, dibayar sekali di payroll bulan pertama.
                  </p>
                </div>
                <div>
                  <label class="label">Status Kerja *</label>
                  <select v-model="offerForm.employment_status" class="input">
                    <option value="PKWTT">PKWTT — Tetap</option>
                    <option value="PKWT">PKWT — Kontrak waktu tertentu</option>
                    <option value="PROBATION">PROBATION — Hanya masa percobaan</option>
                    <option value="MAGANG">MAGANG</option>
                    <option value="OUTSOURCING">OUTSOURCING</option>
                  </select>
                </div>
                <div>
                  <label class="label">Probation (bulan)</label>
                  <input type="number" v-model.number="offerForm.probation_months"
                    :disabled="offerForm.employment_status === 'PKWT'"
                    class="input" min="0" max="3" />
                  <p class="text-xs text-gray-500 mt-1">
                    Max 3 bulan (UU 13/2003 Pasal 60). PKWT tidak boleh probation.
                  </p>
                </div>
                <div>
                  <label class="label">Tanggal Bergabung Diajukan</label>
                  <AppDatePicker  v-model="offerForm.proposed_join_date" class="input" />
                </div>
                <div>
                  <label class="label">Deadline Respon Kandidat *</label>
                  <AppDatePicker  v-model="offerForm.response_deadline" class="input" />
                  <p class="text-xs text-gray-500 mt-1">
                    Default H+7. Setelah lewat deadline, offer auto-EXPIRED via cron harian.
                  </p>
                </div>
              </div>

              <!-- Allowances editor -->
              <div class="border-t border-gray-200 pt-4">
                <div class="flex items-center justify-between mb-2">
                  <label class="label mb-0">Tunjangan / Allowances</label>
                  <button type="button" class="text-xs text-blue-600 hover:underline flex items-center gap-1"
                    @click="addOfferAllowance">
                    <PlusIcon class="w-3.5 h-3.5" /> Tambah
                  </button>
                </div>
                <div v-if="offerForm.allowances.length === 0"
                  class="text-xs text-gray-400 text-center italic py-3 border border-dashed border-gray-200 rounded">
                  Belum ada tunjangan tambahan. Klik "Tambah" untuk masukkan transport, makan, dll.
                </div>
                <div v-for="(a, idx) in offerForm.allowances" :key="idx"
                  class="grid grid-cols-12 gap-2 items-center mb-2">
                  <input v-model="a.label" placeholder="Nama (mis. Transport)"
                    class="input col-span-4 text-sm" />
                  <input type="number" v-model.number="a.amount" placeholder="Nominal"
                    class="input col-span-3 text-sm font-mono" min="0" />
                  <select v-model="a.type" class="input col-span-2 text-sm">
                    <option value="fixed">Fixed</option>
                    <option value="variable">Variable</option>
                  </select>
                  <label class="col-span-2 flex items-center gap-1 text-xs">
                    <input type="checkbox" v-model="a.is_taxable" /> Kena pajak
                  </label>
                  <button type="button" class="text-red-500 hover:bg-red-50 rounded p-1 col-span-1"
                    @click="removeOfferAllowance(idx)">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </div>

              <div>
                <label class="label">Catatan Internal HR
                  <span class="text-gray-400">(tidak dilihat kandidat)</span>
                </label>
                <textarea v-model="offerForm.internal_notes" class="input" rows="2"
                  placeholder="Mis. negosiasi sudah final, BOD approval ada di email tgl X"></textarea>
              </div>

              <!-- Compensation summary -->
              <div class="bg-amber-50 border border-amber-200 rounded p-3 text-sm">
                <div class="flex items-center justify-between font-semibold">
                  <span>Total Bulanan</span>
                  <span class="font-mono text-amber-900">{{ fmtCurrency(offerTotalCompensation) }}</span>
                </div>
                <div class="text-xs text-amber-700 mt-1">
                  Belum termasuk bonus bergabung, BPJS perusahaan, & tunjangan non-fixed yang variable.
                  Jadikan dasar negosiasi awal dengan kandidat.
                </div>
              </div>
            </fieldset>
          </section>

          <p class="text-xs text-gray-500 italic">
            <strong>Workflow:</strong> Buat DRAFT → Kirim → Kandidat respon di portal kandidat
            (ACCEPTED/REJECTED) → Setelah ACCEPTED, tutup modal & klik tombol "Hire" untuk konversi
            ke employee. Offer otomatis di-EXPIRED H+1 setelah deadline lewat (cron harian).
          </p>
        </div>

        <div class="px-6 py-3 border-t flex justify-end gap-2 sticky bottom-0 bg-white">
          <button class="btn-secondary" @click="showOfferModal = null">Tutup</button>
          <button class="btn-primary" :disabled="offerActiveCount > 0" @click="saveOfferDraft">
            <PlusIcon class="w-4 h-4" /> Simpan Draft
          </button>
        </div>
      </div>
    </div>

    <!-- Hire modal — sekaligus capture data payroll-essential -->
    <div v-if="showHireModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between sticky top-0 bg-white z-10">
          <div>
            <h2 class="font-semibold">Hire {{ showHireModal.full_name }}</h2>
            <div class="text-xs text-gray-500">
              Pengajuan konversi employee + setup payroll
            </div>
          </div>
          <button class="text-gray-500" @click="showHireModal = null">&times;</button>
        </div>

        <div class="p-6 space-y-5">
          <p class="text-sm text-gray-600">
            Data akan diajukan untuk approval HR checker. Setelah keputusan final, kandidat baru
            dikonversi menjadi <strong>employee</strong> dan onboarding dibuat otomatis.
            Payload sensitif (NPWP dan rekening bank) disimpan terenkripsi selama approval.
          </p>

          <!-- Section 1: Employment -->
          <section class="border border-gray-200 rounded-lg">
            <header class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium text-sm">
              Status Kepegawaian
            </header>
            <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label class="label">Gaji Pokok (Rp) *</label>
                <input type="number" v-model.number="hireForm.basic_salary" class="input" min="0" />
                <p class="text-xs text-gray-500 mt-1">Basis perhitungan PPh21, BPJS, lembur, & THR.</p>
              </div>
              <div>
                <label class="label">Tanggal Bergabung *</label>
                <AppDatePicker  v-model="hireForm.join_date" class="input" />
                <p class="text-xs text-gray-500 mt-1">Anchor untuk due date task onboarding.</p>
              </div>
              <div>
                <label class="label">Status Kerja</label>
                <select v-model="hireForm.employment_status" class="input">
                  <option v-for="s in EMPLOYMENT_STATUS_OPTIONS" :key="s" :value="s">{{ s }}</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">PKWTT = tetap; PKWT = kontrak waktu tertentu.</p>
              </div>
              <div>
                <label class="label">Kontrak Berakhir
                  <span class="text-gray-400">(opsional)</span>
                </label>
                <AppDatePicker  v-model="hireForm.contract_end_date" class="input"
                  :disabled="hireForm.employment_status === 'PKWTT'" />
                <p class="text-xs text-gray-500 mt-1">Wajib diisi untuk PKWT/PROBATION/MAGANG.</p>
              </div>
              <div class="md:col-span-2">
                <label class="label">Probation Berakhir
                  <span class="text-gray-400">(opsional)</span>
                </label>
                <AppDatePicker  v-model="hireForm.probation_end_date" class="input" />
              </div>
            </div>
          </section>

          <!-- Section 2: Pajak / PPh21 -->
          <section class="border border-amber-200 rounded-lg bg-amber-50/30">
            <header class="bg-amber-50 px-4 py-2 border-b border-amber-200 font-medium text-sm flex items-center gap-2">
              <span>Pajak Penghasilan (PPh21)</span>
              <span class="text-xs text-amber-700 font-normal">— wajib untuk perhitungan payroll</span>
            </header>
            <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
              <div class="md:col-span-2">
                <label class="label">Status PTKP *</label>
                <select v-model="hireForm.ptkp_status" class="input">
                  <option v-for="o in PTKP_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">
                  Kode PTKP menentukan penghasilan tidak kena pajak. Default TK/0 (belum kawin tanpa tanggungan).
                  HR bisa edit nanti via profil employee kalau status keluarga berubah.
                </p>
              </div>
              <div>
                <label class="label">Metode Pajak</label>
                <select v-model="hireForm.tax_method" class="input">
                  <option value="GROSS">GROSS — pajak ditanggung karyawan</option>
                  <option value="NETT">NETT — pajak ditanggung perusahaan</option>
                  <option value="GROSS_UP">GROSS UP — tunjangan pajak (gross-up)</option>
                </select>
              </div>
              <div>
                <label class="label">Metode PPh21</label>
                <select v-model="hireForm.pph21_method" class="input">
                  <option value="TER">TER — Tarif Efektif Rata-rata (default 2024+)</option>
                  <option value="GROSS_UP">GROSS UP</option>
                </select>
              </div>
              <div class="md:col-span-2">
                <label class="label">NPWP <span class="text-gray-400">(15-16 digit, opsional)</span></label>
                <input v-model="hireForm.npwp" class="input font-mono" placeholder="00.000.000.0-000.000" maxlength="32" />
                <p class="text-xs text-gray-500 mt-1">
                  Tanpa NPWP → tarif PPh21 + 20%. Boleh kosong & dilengkapi nanti.
                  Disimpan terenkripsi.
                </p>
              </div>
            </div>
          </section>

          <!-- Section 3: BPJS -->
          <section class="border border-blue-200 rounded-lg bg-blue-50/30">
            <header class="bg-blue-50 px-4 py-2 border-b border-blue-200 font-medium text-sm">
              BPJS
            </header>
            <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label class="label">No. BPJS Kesehatan</label>
                <input v-model="hireForm.bpjs_kesehatan_no" class="input font-mono" placeholder="13 digit" maxlength="20" />
              </div>
              <div>
                <label class="label">Kelas BPJS Kesehatan</label>
                <select v-model.number="hireForm.bpjs_kesehatan_class" class="input">
                  <option :value="1">Kelas 1</option>
                  <option :value="2">Kelas 2</option>
                  <option :value="3">Kelas 3</option>
                </select>
              </div>
              <div class="md:col-span-2">
                <label class="label">No. BPJS Ketenagakerjaan (JHT/JP/JKK/JKM)</label>
                <input v-model="hireForm.bpjs_tk_no" class="input font-mono" placeholder="11 digit" maxlength="20" />
                <p class="text-xs text-gray-500 mt-1">
                  Diperlukan untuk export iuran BPJS ke SiPP.
                  Boleh kosong kalau karyawan belum punya kartu (bisa daftar lewat onboarding task BPJS_REGISTER).
                </p>
              </div>
            </div>
          </section>

          <!-- Section 4: Rekening bank -->
          <section class="border border-emerald-200 rounded-lg bg-emerald-50/30">
            <header class="bg-emerald-50 px-4 py-2 border-b border-emerald-200 font-medium text-sm">
              Rekening Bank Penerimaan Gaji
            </header>
            <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label class="label">Bank</label>
                <select v-model="hireForm.bank_name" class="input">
                  <option value="">— Pilih bank —</option>
                  <option v-for="b in BANK_OPTIONS" :key="b" :value="b">{{ b }}</option>
                </select>
              </div>
              <div>
                <label class="label">No. Rekening</label>
                <input v-model="hireForm.bank_account" class="input font-mono" placeholder="e.g. 1234567890" maxlength="32" />
                <p class="text-xs text-gray-500 mt-1">Disimpan terenkripsi.</p>
              </div>
              <div class="md:col-span-2">
                <label class="label">Nama Pemilik Rekening</label>
                <input v-model="hireForm.bank_account_holder" class="input" placeholder="Sesuai buku tabungan" maxlength="200" />
                <p class="text-xs text-gray-500 mt-1">
                  Wajib sama persis dengan nama di buku tabungan untuk export bank-transfer payroll.
                </p>
              </div>
            </div>
          </section>

          <!-- Status indicator -->
          <div class="border-t border-gray-200 pt-3 flex items-center justify-between text-sm">
            <div class="flex items-center gap-2">
              <span :class="payrollReadyCount === PAYROLL_READY_TOTAL
                ? 'inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold'
                : 'inline-flex items-center justify-center w-6 h-6 rounded-full bg-amber-100 text-amber-700 text-xs font-bold'">
                {{ payrollReadyCount }}/{{ PAYROLL_READY_TOTAL }}
              </span>
              <span class="text-gray-700">
                <template v-if="payrollReadyCount === PAYROLL_READY_TOTAL">
                  Data payroll lengkap — siap di-calculate.
                </template>
                <template v-else>
                  {{ PAYROLL_READY_TOTAL - payrollReadyCount }} field payroll belum lengkap (bisa dilengkapi nanti).
                </template>
              </span>
            </div>
          </div>
        </div>

        <div class="px-6 py-3 border-t flex justify-end gap-2 sticky bottom-0 bg-white">
          <button class="btn-secondary" @click="showHireModal = null">Batal</button>
          <button class="btn-primary" @click="doHire">
            <CheckBadgeIcon class="w-4 h-4" /> Konfirmasi Hire
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
