<script setup>
/*
 * Manpower Planning (MPP) — list view dengan tracking lifecycle.
 *
 * Alur:
 *   DRAFT → SUBMITTED → APPROVED → HIRING → FILLED → CLOSED
 *
 * Halaman ini digunakan oleh:
 *   - Line manager (N+1)         → ajukan & lihat MPP miliknya
 *   - HRD Manager / Director     → approve/reject MPP via tombol
 *   - Talent Acquisition (TA)    → tombol "Buat Lowongan" untuk MPP APPROVED/HIRING
 *
 * Permission:
 *   mpp.view    — semua HR/manager
 *   mpp.submit  — line manager
 *   mpp.approve — HRD/Direksi
 *   mpp.fulfill — TA
 *
 * Lihat docs/MPP.md untuk detail proses bisnis.
 */
import { ref, computed, onMounted, reactive } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import {
  ClipboardDocumentListIcon, PlusIcon, EyeIcon, PencilIcon, TrashIcon,
  PaperAirplaneIcon, CheckCircleIcon, XCircleIcon, BriefcaseIcon,
  ArrowPathIcon, ClockIcon, UserGroupIcon, BuildingOffice2Icon,
} from '@heroicons/vue/24/outline'
import SearchableSelect from '@/components/SearchableSelect.vue'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'

const auth = useAuthStore()
const toast = useToast()
const router = useRouter()

// ─── State ──────────────────────────────────────────────────────────
const items   = ref([])
const total   = ref(0)
const loading = ref(false)
const stats   = ref({})
const filters = reactive({ q: '', status: '', request_type: '', mine: 0, overdue_only: 0, page: 1, per_page: 20 })

// Migration #066 — overdue helper. Backend kirim `vacancy_started_at`
// di setiap row MPP. Hitung "X hari overdue" / "X hari lagi" di client
// supaya tidak perlu join ulang ke resignation_requests.
function overdueInfo(row) {
  if (! row?.vacancy_started_at) return null
  // Skip status terminal
  if (['FILLED', 'CLOSED', 'REJECTED', 'CANCELLED'].includes(row.status)) return null
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const vac = new Date(row.vacancy_started_at)
  vac.setHours(0, 0, 0, 0)
  const diffMs = today.getTime() - vac.getTime()
  const days = Math.round(diffMs / 86400000)
  if (days > 0) {
    return { kind: 'overdue', days, label: `Overdue ${days} hari`, cls: 'bg-red-100 text-red-700 border border-red-200' }
  }
  if (days === 0) {
    return { kind: 'today', days: 0, label: 'Vacancy hari ini', cls: 'bg-amber-100 text-amber-800 border border-amber-200' }
  }
  if (days >= -30) {
    return { kind: 'upcoming', days: Math.abs(days), label: `H-${Math.abs(days)} vacancy`, cls: 'bg-amber-50 text-amber-700 border border-amber-200' }
  }
  return null
}

const showForm = ref(false)
const editing  = ref(null)
const saving   = ref(false)

const showDetail = ref(false)
const detail     = ref(null)
const detailLoading = ref(false)

const showFulfill = ref(false)
const fulfillForm = reactive({
  title: '', headcount: 1, employment_type: 'PKWTT',
  description: '', requirements: '', salary_min: null, salary_max: null,
  salary_visible: 0, branch_id: null, opened_at: '', closed_at: '',
  notes: '',
})

// Dependencies (untuk dropdown)
const departments = ref([])
const positions   = ref([])
const branches    = ref([])
const employees   = ref([])
const jobGrades   = ref([])
const assetCategories = [
  { value: 'LAPTOP', label: 'Laptop' },
  { value: 'DESKTOP', label: 'Desktop / PC' },
  { value: 'PHONE', label: 'Telepon / HP' },
  { value: 'VEHICLE', label: 'Kendaraan' },
  { value: 'FURNITURE', label: 'Furniture' },
  { value: 'TOOL', label: 'Tools / Peralatan' },
  { value: 'ID_CARD', label: 'ID Card' },
  { value: 'ACCESS_CARD', label: 'Access Card' },
  { value: 'OTHER', label: 'Lainnya' },
]

// ─── Static maps ────────────────────────────────────────────────────
const STATUS_BADGE = {
  DRAFT:      { label: 'Draft',     cls: 'bg-gray-100 text-gray-600',         dot: 'bg-gray-400' },
  SUBMITTED:  { label: 'Diajukan',  cls: 'bg-amber-100 text-amber-700',       dot: 'bg-amber-500' },
  APPROVED:   { label: 'Disetujui', cls: 'bg-emerald-100 text-emerald-700',   dot: 'bg-emerald-500' },
  REJECTED:   { label: 'Ditolak',   cls: 'bg-red-100 text-red-700',           dot: 'bg-red-500' },
  HIRING:     { label: 'Recruiting',cls: 'bg-blue-100 text-blue-700',         dot: 'bg-blue-500' },
  FILLED:     { label: 'Terpenuhi', cls: 'bg-indigo-100 text-indigo-700',     dot: 'bg-indigo-500' },
  CLOSED:     { label: 'Selesai',   cls: 'bg-gray-200 text-gray-700',         dot: 'bg-gray-500' },
  CANCELLED:  { label: 'Dibatalkan',cls: 'bg-gray-100 text-gray-500',         dot: 'bg-gray-400' },
}
const TYPE_BADGE = {
  BUDGET:   { label: 'Budget',   cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  UNBUDGET: { label: 'Unbudget', cls: 'bg-rose-50 text-rose-700 border-rose-200' },
}
const REASON_LABEL = {
  NEW_POSITION: 'Posisi Baru',
  REPLACEMENT:  'Pengganti',
  EXPANSION:    'Ekspansi',
  SEASONAL:     'Musiman',
  OTHER:        'Lainnya',
}
const EMPLOYMENT_TYPES = ['PKWT','PKWTT','MAGANG','OUTSOURCING','PARTTIME','CONTRACT']
const STAGES_ORDER = ['DRAFT','SUBMITTED','APPROVED','HIRING','FILLED','CLOSED']

// ─── Permission flags ───────────────────────────────────────────────
const canSubmit  = computed(() => auth.can('mpp.submit'))
const canApprove = computed(() => auth.can('mpp.approve'))
const canFulfill = computed(() => auth.can('mpp.fulfill') || auth.can('recruitment.manage'))

function canApproveRow(row) {
  return row?.can_current_user_approve === true
    || row?.can_current_user_approve === 1
    || row?.can_current_user_approve === '1'
}

// ─── Helpers ────────────────────────────────────────────────────────
function progressIndex(status) {
  // Hitung urutan tahap saat ini di STAGES_ORDER. REJECTED/CANCELLED:
  // tampilkan sebagai akhir flow (-1 = stop early).
  if (status === 'REJECTED' || status === 'CANCELLED') return -1
  return STAGES_ORDER.indexOf(status)
}

// ─── Fetch ──────────────────────────────────────────────────────────
async function fetchData() {
  loading.value = true
  try {
    const [listRes, statsRes] = await Promise.all([
      api.get('/manpower-requests', { params: filters }),
      api.get('/manpower-requests/stats').catch(() => ({ data: { data: {} } })),
    ])
    items.value = listRes.data.data
    total.value = listRes.data.meta?.total || 0
    stats.value = statsRes.data.data || {}
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat MPP')
  } finally {
    loading.value = false
  }
}

async function loadDeps() {
  try {
    // Karyawan TIDAK lagi di-load semua sekaligus — picker
    // EmployeeSearchSelect melakukan server-side search saat dibutuhkan,
    // sehingga aman walau ada ratusan/ribuan karyawan.
    const [d, p, b, jg] = await Promise.all([
      api.get('/departments', { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
      api.get('/positions',   { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
      api.get('/branches',    { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } })),
      api.get('/job-grades',  { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
    ])
    departments.value = d.data.data || []
    positions.value   = p.data.data || []
    branches.value    = b.data.data || []
    jobGrades.value   = jg.data.data || []
  } catch { /* deps optional */ }
}

onMounted(() => { fetchData(); loadDeps() })

// ─── Form (create/edit DRAFT) ───────────────────────────────────────
function openCreate() {
  editing.value = {
    proposed_title: '',
    request_type: 'BUDGET',
    reason: 'REPLACEMENT',
    headcount: 1,
    employment_type: 'PKWTT',
    department_id: null,
    position_id: null,
    branch_id: null,
    replacement_for_employee_id: null,
    salary_min: null, salary_max: null,
    target_start_date: '',
    justification: '',
    job_description: '',
    requirements: '',
    notes: '',
    submit_now: false,
    // Sprint 5 — onboarding planning
    job_grade_id: null,
    proposed_supervisor_employee_id: null,
    proposed_manager_employee_id: null,
    planned_assets: [],
  }
  showForm.value = true
}

async function openEdit(row) {
  editing.value = JSON.parse(JSON.stringify(row))
  editing.value.submit_now = false
  // Lazy-load planned assets
  editing.value.planned_assets = []
  try {
    const res = await api.get(`/manpower-requests/${row.id}/planned-assets`)
    editing.value.planned_assets = (res.data.data || []).map(p => ({
      category: p.category,
      name: p.name,
      brand_model_pref: p.brand_model_pref || '',
      asset_id: p.asset_id || null,
      is_required: Number(p.is_required) === 1 ? 1 : 0,
      notes: p.notes || '',
    }))
  } catch { /* keep empty */ }
  showForm.value = true
}

function addPlannedAsset() {
  editing.value.planned_assets.push({
    category: 'LAPTOP',
    name: '',
    brand_model_pref: '',
    asset_id: null,
    is_required: 1,
    notes: '',
  })
}

function removePlannedAsset(idx) {
  editing.value.planned_assets.splice(idx, 1)
}

async function save() {
  if (! editing.value.proposed_title?.trim()) {
    toast.warning('Judul posisi wajib diisi'); return
  }
  if (editing.value.request_type === 'UNBUDGET' && ! (editing.value.justification || '').trim()) {
    toast.warning('Justifikasi wajib diisi untuk pengajuan UNBUDGET'); return
  }
  if (editing.value.submit_now) {
    const preview = await loadApprovalPreview(editing.value.request_type)
    if (! preview) return
    const ok = await confirmDialog({
      title: 'Buat dan ajukan MPP?',
      message: approvalPreviewMessage(preview),
      okText: 'Buat & Ajukan',
    })
    if (! ok) return
  }
  saving.value = true
  try {
    if (editing.value.id) {
      await api.put(`/manpower-requests/${editing.value.id}`, editing.value)
      toast.success('MPP diperbarui')
    } else {
      const res = await api.post('/manpower-requests', editing.value)
      const ref = res.data.data?.reference_number
      const submitted = res.data.data?.submitted
      toast.success(`MPP dibuat: ${ref}${submitted ? ' (langsung diajukan)' : ' (status DRAFT)'}`)
    }
    showForm.value = false
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  } finally {
    saving.value = false
  }
}

// ─── Lifecycle actions ──────────────────────────────────────────────
async function submitMpp(row) {
  const preview = await loadApprovalPreview(row.request_type)
  if (! preview) return
  const ok = await confirmDialog({
    title: 'Ajukan MPP?',
    message: `${row.reference_number} akan diajukan dan tidak bisa diedit.\n\n${approvalPreviewMessage(preview)}`,
    okText: 'Ajukan',
  })
  if (! ok) return
  try {
    await api.post(`/manpower-requests/${row.id}/submit`)
    toast.success('MPP diajukan, menunggu approval')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function loadApprovalPreview(requestType) {
  try {
    const res = await api.get('/manpower-requests/approval-preview', {
      params: { request_type: requestType },
    })
    const preview = res.data.data
    if (! preview?.ready) {
      const missing = (preview?.steps || []).filter(s => !s.assigned).map(s => s.role_key).join(', ')
      toast.error(`MPP belum bisa diajukan. Penjabat belum ditentukan: ${missing}`, { timeout: 8000 })
      return null
    }
    return preview
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memeriksa jalur approval')
    return null
  }
}

function approvalPreviewMessage(preview) {
  const lines = (preview.steps || []).map((step) =>
    `Step ${step.step}: ${step.role_key} - ${step.employee_name || 'Belum ditentukan'}`,
  )
  return `Approval akan dikirim kepada:\n${lines.join('\n')}`
}

async function approveMpp(row) {
  const ok = await confirmDialog({
    title: `Approve MPP ${row.reference_number}?`,
    message: `Posisi: ${row.proposed_title} (${row.headcount} orang, ${row.request_type}). Setelah approve, Talent Acquisition bisa membuat lowongan.`,
    okText: 'Approve',
  })
  if (! ok) return
  try {
    const res = await api.post(`/manpower-requests/${row.id}/approve`, { comment: '' })
    const r = res.data.data
    toast.success(r.final_approved
      ? 'MPP fully approved, siap untuk dibuatkan lowongan'
      : `Step ${r.step} approved, sisa ${r.remaining_steps} langkah lagi`)
    fetchData()
    if (showDetail.value && detail.value?.id === row.id) loadDetail(row.id)
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function rejectMpp(row) {
  const reason = prompt(`Alasan reject MPP ${row.reference_number}? (minimal 5 karakter)`)
  if (! reason || reason.trim().length < 5) {
    if (reason !== null) toast.warning('Alasan minimal 5 karakter')
    return
  }
  try {
    await api.post(`/manpower-requests/${row.id}/reject`, { reason: reason.trim() })
    toast.success('MPP ditolak')
    fetchData()
    if (showDetail.value && detail.value?.id === row.id) loadDetail(row.id)
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function cancelMpp(row) {
  const ok = await confirmDialog({
    title: 'Batalkan MPP?',
    message: `${row.reference_number} - ${row.proposed_title}. Setelah dibatalkan tidak bisa diaktifkan kembali.`,
    okText: 'Batalkan',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.post(`/manpower-requests/${row.id}/cancel`, {})
    toast.success('MPP dibatalkan')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function removeMpp(row) {
  const ok = await confirmDialog({
    title: 'Hapus MPP draft?',
    message: `${row.reference_number} - ${row.proposed_title}`,
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/manpower-requests/${row.id}`)
    toast.success('MPP dihapus')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function closeMpp(row) {
  const ok = await confirmDialog({
    title: 'Close MPP?',
    message: `${row.reference_number} - ${row.proposed_title}. Tutup tracking MPP ini (tidak akan dilanjutkan rekrut).`,
    okText: 'Close',
  })
  if (! ok) return
  try {
    await api.post(`/manpower-requests/${row.id}/close`, {})
    toast.success('MPP closed')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

// ─── Detail modal ───────────────────────────────────────────────────
async function viewDetail(row) {
  showDetail.value = true
  detail.value = null
  await loadDetail(row.id)
}

async function loadDetail(id) {
  detailLoading.value = true
  try {
    const res = await api.get(`/manpower-requests/${id}`)
    detail.value = res.data.data
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
  finally { detailLoading.value = false }
}

// ─── Fulfill (TA buat lowongan) ─────────────────────────────────────
function openFulfill(row) {
  Object.assign(fulfillForm, {
    title: row.proposed_title,
    headcount: Math.max(1, (row.remaining_headcount ?? row.headcount) || 1),
    employment_type: row.employment_type || 'PKWTT',
    description: row.job_description || '',
    requirements: row.requirements || '',
    salary_min: row.salary_min ?? null,
    salary_max: row.salary_max ?? null,
    salary_visible: 0,
    branch_id: row.branch_id ?? null,
    opened_at: '', closed_at: '',
    notes: `Dibuat dari MPP ${row.reference_number}`,
  })
  // Simpan id MPP target di showFulfill (pakai object container)
  showFulfill.value = row.id
}

async function submitFulfill() {
  const id = showFulfill.value
  if (! id) return
  try {
    const res = await api.post(`/manpower-requests/${id}/create-posting`, fulfillForm)
    toast.success(`Lowongan dibuat: ${res.data.data?.reference_number}`)
    showFulfill.value = false
    fetchData()
    if (showDetail.value && detail.value?.id === id) loadDetail(id)
    // Tawarkan langsung redirect ke pipeline board
    const newPostingId = res.data.data?.job_posting_id
    if (newPostingId) {
      setTimeout(async () => {
        const ok = await confirmDialog({
          title: 'Buka pipeline kandidat?',
          message: 'Lowongan sudah dibuat sebagai DRAFT. Kamu bisa edit, publish ke Career Portal, dan mulai proses kandidat.',
          okText: 'Buka',
        })
        if (ok) router.push(`/recruitment/jobs/${newPostingId}`)
      }, 300)
    }
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

// ─── Filter helpers ─────────────────────────────────────────────────
function applyFilter() { filters.page = 1; fetchData() }
function toggleMine() { filters.mine = filters.mine ? 0 : 1; applyFilter() }

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / filters.per_page)))
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ClipboardDocumentListIcon class="w-7 h-7 text-primary-600" />
          Manpower Planning (MPP)
        </h1>
        <p class="text-sm text-gray-500">
          Pengajuan kebutuhan karyawan oleh atasan langsung. Approval HRD → Talent Acquisition buatkan lowongan → tracking sampai onboarding.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <button class="btn-secondary" @click="fetchData" :disabled="loading">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" /> Refresh
        </button>
        <button v-if="canSubmit" class="btn-primary" @click="openCreate">
          <PlusIcon class="w-4 h-4" /> Pengajuan Baru
        </button>
      </div>
    </div>

    <!-- Stats cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 mb-4">
      <div class="card p-3">
        <div class="text-xs text-gray-500 mb-1">Diajukan</div>
        <div class="text-xl font-bold text-amber-600">{{ stats.SUBMITTED?.count || 0 }}</div>
        <div class="text-[10px] text-gray-400">{{ stats.SUBMITTED?.headcount || 0 }} HC menunggu</div>
      </div>
      <div class="card p-3">
        <div class="text-xs text-gray-500 mb-1">Disetujui</div>
        <div class="text-xl font-bold text-emerald-600">{{ stats.APPROVED?.count || 0 }}</div>
        <div class="text-[10px] text-gray-400">Siap rekrut</div>
      </div>
      <div class="card p-3">
        <div class="text-xs text-gray-500 mb-1">Hiring</div>
        <div class="text-xl font-bold text-blue-600">{{ stats.HIRING?.count || 0 }}</div>
        <div class="text-[10px] text-gray-400">{{ stats.HIRING?.hired || 0 }} / {{ stats.HIRING?.headcount || 0 }} hired</div>
      </div>
      <div class="card p-3">
        <div class="text-xs text-gray-500 mb-1">Terpenuhi</div>
        <div class="text-xl font-bold text-indigo-600">{{ stats.FILLED?.count || 0 }}</div>
        <div class="text-[10px] text-gray-400">Headcount lengkap</div>
      </div>
      <div class="card p-3">
        <div class="text-xs text-gray-500 mb-1">Ditolak</div>
        <div class="text-xl font-bold text-red-600">{{ stats.REJECTED?.count || 0 }}</div>
      </div>
      <div class="card p-3">
        <div class="text-xs text-gray-500 mb-1">Selesai</div>
        <div class="text-xl font-bold text-gray-600">{{ stats.CLOSED?.count || 0 }}</div>
      </div>
    </div>

    <!-- Migration #066 — Overdue summary banner -->
    <div v-if="(stats.overdue?.count || 0) > 0 || (stats.about_to_be_overdue?.count || 0) > 0"
      class="rounded-lg border p-3 mb-4 flex items-center gap-3 flex-wrap"
      :class="(stats.overdue?.count || 0) > 0
        ? 'bg-red-50 border-red-200 text-red-800'
        : 'bg-amber-50 border-amber-200 text-amber-800'">
      <ClockIcon class="w-5 h-5 flex-shrink-0" />
      <div class="flex-1 text-sm">
        <template v-if="(stats.overdue?.count || 0) > 0">
          <strong>{{ stats.overdue.count }} MPP overdue</strong> —
          {{ stats.overdue.total_headcount }} posisi belum terisi padahal sudah lewat target tanggal kosong.
          Terlama {{ stats.overdue.oldest_days }} hari.
        </template>
        <template v-else>
          <strong>{{ stats.about_to_be_overdue.count }} MPP akan overdue</strong> dalam ≤ 30 hari
          ({{ stats.about_to_be_overdue.total_headcount }} posisi). Mohon segera proses fulfillment.
        </template>
      </div>
      <button v-if="!filters.overdue_only"
        class="text-xs px-3 py-1 rounded bg-white border hover:bg-gray-50"
        @click="filters.overdue_only = 1; applyFilter()">
        Tampilkan saja yg overdue
      </button>
      <button v-else
        class="text-xs px-3 py-1 rounded bg-white border hover:bg-gray-50"
        @click="filters.overdue_only = 0; applyFilter()">
        Reset filter
      </button>
    </div>

    <!-- Filters -->
    <div class="card card-body mb-4">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <div>
          <label class="label">Cari</label>
          <input v-model="filters.q" class="input" placeholder="Title, ref, justifikasi..." @keydown.enter="applyFilter" />
        </div>
        <div>
          <label class="label">Status</label>
          <select v-model="filters.status" class="input">
            <option value="">Semua</option>
            <option v-for="(b, k) in STATUS_BADGE" :key="k" :value="k">{{ b.label }}</option>
          </select>
        </div>
        <div>
          <label class="label">Tipe</label>
          <select v-model="filters.request_type" class="input">
            <option value="">Semua</option>
            <option value="BUDGET">Budget (sesuai MPN)</option>
            <option value="UNBUDGET">Unbudget</option>
          </select>
        </div>
        <div class="flex items-end">
          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input type="checkbox" :checked="!!filters.mine" @change="toggleMine" />
            Hanya pengajuan saya
          </label>
        </div>
        <div class="flex items-end">
          <button class="btn-secondary w-full" @click="applyFilter">Filter</button>
        </div>
      </div>
    </div>

    <!-- List -->
    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Ref</th>
              <th>Posisi</th>
              <th>Tipe</th>
              <th class="text-center">Qty</th>
              <th>Pengaju</th>
              <th>Tahap</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="8" class="text-center py-8 text-gray-400">Memuat...</td></tr>
            <tr v-else-if="items.length === 0">
              <td colspan="8" class="text-center py-12 text-gray-400">Belum ada pengajuan MPP.</td>
            </tr>
            <tr v-for="r in items" :key="r.id"
              v-show="!filters.overdue_only || overdueInfo(r)?.kind === 'overdue'"
              class="cursor-pointer hover:bg-gray-50" @click="viewDetail(r)">
              <td class="font-mono text-xs">{{ r.reference_number }}</td>
              <td>
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="font-medium">{{ r.proposed_title }}</span>
                  <!-- Migration #066 — overdue / upcoming badge -->
                  <span v-if="overdueInfo(r)"
                    class="text-[10px] px-1.5 py-0.5 rounded font-medium"
                    :class="overdueInfo(r).cls"
                    :title="r.vacancy_started_at ? `Vacancy: ${r.vacancy_started_at}` : ''">
                    {{ overdueInfo(r).label }}
                  </span>
                </div>
                <div class="text-xs text-gray-500">
                  {{ r.department_name || '-' }} · {{ REASON_LABEL[r.reason] || r.reason }}
                </div>
              </td>
              <td>
                <span class="px-2 py-0.5 rounded text-xs border" :class="TYPE_BADGE[r.request_type]?.cls">
                  {{ TYPE_BADGE[r.request_type]?.label }}
                </span>
              </td>
              <td class="text-center font-mono">
                <span class="text-base font-bold">{{ r.headcount }}</span>
                <span v-if="r.hired_count > 0" class="text-xs text-gray-500 block">
                  {{ r.hired_count }} hired
                </span>
              </td>
              <td>
                <div class="text-xs">{{ r.requested_by_name || '-' }}</div>
                <div class="text-[10px] text-gray-400">{{ fmtDate(r.created_at) }}</div>
              </td>
              <td>
                <!-- Mini progress dots -->
                <div class="flex items-center gap-1">
                  <template v-if="['REJECTED','CANCELLED'].includes(r.status)">
                    <span class="text-xs text-red-600">{{ STATUS_BADGE[r.status]?.label }}</span>
                  </template>
                  <template v-else>
                    <span v-for="(s, i) in STAGES_ORDER" :key="s"
                      class="w-2 h-2 rounded-full"
                      :class="i <= progressIndex(r.status) ? STATUS_BADGE[s]?.dot : 'bg-gray-200'"
                      :title="STATUS_BADGE[s]?.label"></span>
                  </template>
                </div>
              </td>
              <td>
                <span class="px-2 py-0.5 rounded text-xs" :class="STATUS_BADGE[r.status]?.cls">
                  {{ STATUS_BADGE[r.status]?.label || r.status }}
                </span>
              </td>
              <td class="text-right" @click.stop>
                <div class="flex items-center justify-end gap-1">
                  <button class="text-blue-600 hover:text-blue-800 p-1" title="Detail" @click="viewDetail(r)">
                    <EyeIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canSubmit && r.status === 'DRAFT'" class="text-gray-600 hover:text-primary-600 p-1"
                    title="Edit draft" @click="openEdit(r)">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canSubmit && r.status === 'DRAFT'" class="text-amber-600 hover:text-amber-800 p-1"
                    title="Ajukan untuk approval" @click="submitMpp(r)">
                    <PaperAirplaneIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canApproveRow(r) && r.status === 'SUBMITTED'" class="text-emerald-600 hover:text-emerald-800 p-1"
                    title="Approve" @click="approveMpp(r)">
                    <CheckCircleIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canApproveRow(r) && r.status === 'SUBMITTED'" class="text-red-600 hover:text-red-800 p-1"
                    title="Reject" @click="rejectMpp(r)">
                    <XCircleIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canFulfill && ['APPROVED','HIRING'].includes(r.status) && (r.remaining_headcount ?? r.headcount) > 0"
                    class="text-indigo-600 hover:text-indigo-800 p-1"
                    title="Buat Lowongan dari MPP" @click="openFulfill(r)">
                    <BriefcaseIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canSubmit && r.status === 'DRAFT'" class="text-red-600 hover:text-red-800 p-1"
                    title="Hapus" @click="removeMpp(r)">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="totalPages > 1" class="px-4 py-3 flex items-center justify-between border-t text-sm">
        <span class="text-gray-500">{{ total }} pengajuan · halaman {{ filters.page }} / {{ totalPages }}</span>
        <div class="flex gap-1">
          <button :disabled="filters.page <= 1" class="btn-secondary px-3 py-1 text-sm" @click="filters.page--; fetchData()">Prev</button>
          <button :disabled="filters.page >= totalPages" class="btn-secondary px-3 py-1 text-sm" @click="filters.page++; fetchData()">Next</button>
        </div>
      </div>
    </div>

    <!-- Form Modal (Create / Edit DRAFT) -->
    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">
            {{ editing.id ? 'Edit Pengajuan MPP' : 'Pengajuan Manpower Planning Baru' }}
          </h2>
          <button class="text-gray-500 hover:text-gray-800" @click="showForm = false">&times;</button>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-3">
          <div class="md:col-span-2">
            <label class="label">Judul Posisi yang Diusulkan *</label>
            <input v-model="editing.proposed_title" class="input" placeholder="mis. Senior Backend Developer" maxlength="200" />
          </div>

          <div>
            <label class="label">Tipe Pengajuan *</label>
            <select v-model="editing.request_type" class="input">
              <option value="BUDGET">Budget — sudah masuk MPN/RKAP tahunan</option>
              <option value="UNBUDGET">Unbudget — di luar rencana (butuh approval Direksi)</option>
            </select>
          </div>
          <div>
            <label class="label">Alasan</label>
            <select v-model="editing.reason" class="input">
              <option v-for="(lbl, k) in REASON_LABEL" :key="k" :value="k">{{ lbl }}</option>
            </select>
          </div>

          <div v-if="editing.reason === 'REPLACEMENT'" class="md:col-span-2">
            <label class="label">Pengganti Karyawan (opsional)</label>
            <EmployeeSearchSelect
              v-model="editing.replacement_for_employee_id"
              placeholder="- Tidak terkait karyawan tertentu -"
              :only-active="false"
            />
          </div>

          <div>
            <label class="label">Department</label>
            <SearchableSelect
              v-model="editing.department_id"
              :items="departments"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="- Pilih -"
            />
          </div>
          <div>
            <label class="label">Posisi (opsional, kalau sudah ada)</label>
            <SearchableSelect
              v-model="editing.position_id"
              :items="positions"
              value-key="id"
              label-key="name"
              sub-label-key="department_name"
              :search-keys="['name','code','tier','department_name']"
              placeholder="- Posisi baru / belum dipetakan -"
            />
          </div>
          <div>
            <label class="label">Cabang / Lokasi</label>
            <SearchableSelect
              v-model="editing.branch_id"
              :items="branches"
              value-key="id"
              label-key="name"
              :search-keys="['name','code','city']"
              placeholder="- Semua / belum ditentukan -"
            />
          </div>
          <div>
            <label class="label">Jumlah Kebutuhan (Headcount) *</label>
            <input type="number" v-model.number="editing.headcount" class="input" min="1" />
          </div>

          <div>
            <label class="label">Tipe Kepegawaian</label>
            <select v-model="editing.employment_type" class="input">
              <option v-for="t in EMPLOYMENT_TYPES" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div>
            <label class="label">Target Mulai Bekerja</label>
            <input type="date" v-model="editing.target_start_date" class="input" />
          </div>

          <div>
            <label class="label">Salary Min (Rp)</label>
            <input type="number" v-model.number="editing.salary_min" class="input" />
          </div>
          <div>
            <label class="label">Salary Max (Rp)</label>
            <input type="number" v-model.number="editing.salary_max" class="input" />
          </div>

          <div class="md:col-span-2">
            <label class="label">
              Justifikasi
              <span v-if="editing.request_type === 'UNBUDGET'" class="text-red-600">* (wajib untuk UNBUDGET)</span>
            </label>
            <textarea v-model="editing.justification" rows="3" class="input"
              placeholder="Mengapa kebutuhan ini penting? (workload meningkat, ekspansi, project baru, dst.)"></textarea>
          </div>
          <div class="md:col-span-2">
            <label class="label">Deskripsi Pekerjaan (akan jadi job posting nanti)</label>
            <textarea v-model="editing.job_description" rows="3" class="input"></textarea>
          </div>
          <div class="md:col-span-2">
            <label class="label">Persyaratan Kandidat</label>
            <textarea v-model="editing.requirements" rows="3" class="input"></textarea>
          </div>
          <!-- ─── Onboarding Planning (Sprint 5) ──────────────────── -->
          <div class="md:col-span-2 mt-2 border-t pt-3">
            <h3 class="text-sm font-semibold text-gray-700 flex items-center gap-1.5 mb-1">
              <UserGroupIcon class="w-4 h-4 text-primary-600" />
              Rencana Onboarding (opsional, mempercepat hari pertama)
            </h3>
            <p class="text-xs text-gray-500 mb-3">
              Isi data ini saat MPP supaya HR & IT punya gambaran utuh sebelum kandidat di-hire:
              job grade, atasan langsung, manager kepegawaian, dan asset kerja yang dibutuhkan.
              Saat candidate jadi karyawan, semua field di bawah otomatis ter-apply ke profil
              dan asset langsung di-pre-allocate — onboarding tinggal ceklis.
            </p>
          </div>

          <div>
            <label class="label">Job Grade Target</label>
            <SearchableSelect
              v-model="editing.job_grade_id"
              :items="jobGrades"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="- Belum ditentukan -"
            />
            <p class="text-[11px] text-gray-500 mt-0.5">
              Mempengaruhi range gaji & allowance default karyawan baru.
            </p>
          </div>
          <div></div>

          <div>
            <label class="label">Atasan Langsung (Supervisor)</label>
            <EmployeeSearchSelect
              v-model="editing.proposed_supervisor_employee_id"
              placeholder="- Pilih dari direktori -"
              :only-active="true"
            />
            <p class="text-[11px] text-gray-500 mt-0.5">
              Jadi reporting line untuk approval cuti, lembur, attendance.
            </p>
          </div>
          <div>
            <label class="label">Manager Kepegawaian</label>
            <EmployeeSearchSelect
              v-model="editing.proposed_manager_employee_id"
              placeholder="- Pilih dari direktori -"
              :only-active="true"
            />
            <p class="text-[11px] text-gray-500 mt-0.5">
              HR-side approver. Boleh sama dengan supervisor atau orang yang berbeda.
            </p>
          </div>

          <div class="md:col-span-2">
            <label class="label flex items-center justify-between">
              <span>
                Asset yang Dibutuhkan
                <span class="text-xs text-gray-400 font-normal">({{ editing.planned_assets?.length || 0 }} item)</span>
              </span>
              <button type="button" class="text-xs text-primary-600 hover:underline"
                @click="addPlannedAsset">
                + Tambah Asset
              </button>
            </label>
            <div v-if="!editing.planned_assets || editing.planned_assets.length === 0"
              class="text-xs text-gray-400 italic border border-dashed border-gray-200 rounded p-3 text-center">
              Belum ada asset yang di-plan. Mis. laptop, ID card, kendaraan operasional.
            </div>
            <div v-for="(pa, idx) in editing.planned_assets" :key="idx"
              class="border rounded p-2 mb-2 bg-gray-50">
              <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-start">
                <div class="md:col-span-3">
                  <label class="text-[10px] text-gray-500">Kategori *</label>
                  <select v-model="pa.category" class="input text-sm">
                    <option v-for="c in assetCategories" :key="c.value" :value="c.value">
                      {{ c.label }}
                    </option>
                  </select>
                </div>
                <div class="md:col-span-5">
                  <label class="text-[10px] text-gray-500">Deskripsi Singkat *</label>
                  <input v-model="pa.name" class="input text-sm"
                    placeholder="mis. Laptop developer 16GB RAM" maxlength="200" />
                </div>
                <div class="md:col-span-3">
                  <label class="text-[10px] text-gray-500">Brand/Model (opsional)</label>
                  <input v-model="pa.brand_model_pref" class="input text-sm"
                    placeholder="mis. ThinkPad T14" maxlength="200" />
                </div>
                <div class="md:col-span-1 flex items-end justify-end h-full">
                  <button type="button" class="text-red-600 hover:text-red-800 p-1"
                    title="Hapus" @click="removePlannedAsset(idx)">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
                <div class="md:col-span-12 flex items-center gap-3">
                  <label class="flex items-center gap-1 text-xs">
                    <input type="checkbox" v-model="pa.is_required" :true-value="1" :false-value="0" />
                    Wajib (tanpa ini kerja terhambat)
                  </label>
                  <input v-model="pa.notes" class="input text-xs flex-1"
                    placeholder="Catatan tambahan untuk IT (opsional)" />
                </div>
              </div>
            </div>
          </div>

          <div class="md:col-span-2">
            <label class="label">Catatan Internal</label>
            <textarea v-model="editing.notes" rows="2" class="input"></textarea>
          </div>

          <div v-if="! editing.id" class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm bg-amber-50 border border-amber-200 rounded p-2">
              <input type="checkbox" v-model="editing.submit_now" />
              Langsung ajukan (tanpa simpan sebagai draft dulu)
            </label>
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showForm = false" :disabled="saving">Batal</button>
          <button class="btn-primary" @click="save" :disabled="saving">
            {{ saving ? 'Menyimpan...' : (editing.submit_now ? 'Simpan & Ajukan' : 'Simpan Draft') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Detail Modal -->
    <div v-if="showDetail" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold flex items-center gap-2">
            <ClipboardDocumentListIcon class="w-5 h-5 text-primary-600" />
            Detail MPP {{ detail?.reference_number }}
          </h2>
          <button class="text-gray-500 hover:text-gray-800" @click="showDetail = false">&times;</button>
        </div>
        <div v-if="detailLoading" class="p-8 text-center text-gray-400">Memuat detail...</div>
        <div v-else-if="detail" class="p-6 space-y-4">
          <!-- Status banner + lifecycle bar -->
          <div class="rounded-lg border p-4" :class="STATUS_BADGE[detail.status]?.cls + ' bg-opacity-30'">
            <div class="flex items-center justify-between mb-3">
              <div>
                <div class="text-xs uppercase tracking-wide opacity-70">Status</div>
                <div class="text-2xl font-bold">{{ STATUS_BADGE[detail.status]?.label }}</div>
              </div>
              <span class="px-3 py-1 rounded text-sm border" :class="TYPE_BADGE[detail.request_type]?.cls">
                {{ TYPE_BADGE[detail.request_type]?.label }}
              </span>
            </div>
            <!-- Lifecycle stepper -->
            <div v-if="! ['REJECTED','CANCELLED'].includes(detail.status)" class="flex items-center gap-2 text-xs">
              <template v-for="(s, i) in STAGES_ORDER" :key="s">
                <div class="flex items-center gap-1.5">
                  <div class="w-3 h-3 rounded-full"
                    :class="i <= progressIndex(detail.status) ? STATUS_BADGE[s]?.dot : 'bg-gray-300'"></div>
                  <span :class="i <= progressIndex(detail.status) ? 'font-medium' : 'text-gray-400'">
                    {{ STATUS_BADGE[s]?.label }}
                  </span>
                </div>
                <div v-if="i < STAGES_ORDER.length - 1" class="flex-1 h-px"
                  :class="i < progressIndex(detail.status) ? 'bg-primary-300' : 'bg-gray-200'"></div>
              </template>
            </div>
            <div v-else class="text-sm">
              <span v-if="detail.rejection_reason" class="font-medium">Alasan: </span>
              {{ detail.rejection_reason || '-' }}
            </div>
          </div>

          <!-- Summary grid -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="card p-3">
              <div class="text-xs text-gray-500">Posisi</div>
              <div class="font-semibold">{{ detail.proposed_title }}</div>
              <div class="text-xs text-gray-500 mt-1">{{ REASON_LABEL[detail.reason] || detail.reason }}</div>
            </div>
            <div class="card p-3">
              <div class="text-xs text-gray-500">Headcount</div>
              <div class="text-2xl font-bold">{{ detail.hired_count || 0 }} / {{ detail.headcount }}</div>
              <div class="text-xs text-gray-500 mt-1">
                {{ detail.allocated_headcount }} dialokasikan ke lowongan ·
                {{ detail.remaining_headcount }} sisa
              </div>
            </div>
            <div class="card p-3">
              <div class="text-xs text-gray-500">Diajukan oleh</div>
              <div class="font-medium">{{ detail.requested_by_name || '-' }}</div>
              <div class="text-xs text-gray-500 mt-1">
                {{ detail.department_name || '-' }} · {{ detail.branch_name || 'Semua' }}
              </div>
            </div>
          </div>

          <!-- Justification & deskripsi -->
          <div v-if="detail.justification" class="card p-4">
            <div class="text-xs font-semibold text-gray-600 mb-1">Justifikasi</div>
            <div class="text-sm whitespace-pre-line">{{ detail.justification }}</div>
          </div>
          <div v-if="detail.job_description" class="card p-4">
            <div class="text-xs font-semibold text-gray-600 mb-1">Deskripsi Pekerjaan</div>
            <div class="text-sm whitespace-pre-line">{{ detail.job_description }}</div>
          </div>
          <div v-if="detail.requirements" class="card p-4">
            <div class="text-xs font-semibold text-gray-600 mb-1">Persyaratan</div>
            <div class="text-sm whitespace-pre-line">{{ detail.requirements }}</div>
          </div>

          <!-- Approvals timeline — title dari Master Jabatan, nama penjabat resolved -->
          <div v-if="detail.approvals?.length" class="card p-4">
            <div class="text-sm font-semibold text-gray-700 mb-2 flex items-center gap-1.5">
              <CheckCircleIcon class="w-4 h-4" /> Riwayat Approval
            </div>
            <ol class="space-y-2 text-sm">
              <li v-for="a in detail.approvals" :key="a.id" class="flex items-start gap-3">
                <div class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0"
                  :class="{
                    'bg-emerald-100 text-emerald-700': a.decision === 'APPROVED',
                    'bg-red-100 text-red-700': a.decision === 'REJECTED',
                    'bg-gray-100 text-gray-500': a.decision === 'SKIPPED',
                    'bg-amber-100 text-amber-700': a.decision === 'PENDING',
                  }">
                  {{ a.step }}
                </div>
                <div class="flex-1">
                  <div class="font-medium">
                    {{ a.expected_approver_title || a.approver_role || 'Approver' }}
                    <span v-if="a.expected_approver_name && a.decision === 'PENDING'"
                      class="text-xs font-normal text-amber-700">
                      → {{ a.expected_approver_name }}
                    </span>
                    <span v-else-if="a.decision === 'PENDING' && !a.expected_approver_name"
                      class="text-xs font-normal text-gray-400 italic">
                      (penjabat belum di-assign di Master Jabatan)
                    </span>
                    <span class="text-xs font-normal text-gray-500">
                      · {{ a.decision === 'PENDING' ? 'Menunggu keputusan'
                          : (a.decision === 'APPROVED' ? `Disetujui oleh ${a.approver_name || '-'}`
                          : (a.decision === 'REJECTED' ? `Ditolak oleh ${a.approver_name || '-'}`
                          : 'Skipped')) }}
                    </span>
                  </div>
                  <div v-if="a.approver_role" class="text-[10px] text-gray-400 font-mono">{{ a.approver_role }}</div>
                  <div v-if="a.comment" class="text-xs text-gray-600 mt-0.5 italic">"{{ a.comment }}"</div>
                  <div v-if="a.decided_at" class="text-[11px] text-gray-400 mt-0.5">{{ fmtDate(a.decided_at) }}</div>
                </div>
              </li>
            </ol>
            <div class="mt-3 text-[11px] text-gray-500 border-t pt-2">
              💡 Penjabat di-resolve dari Master Jabatan Kunci (role_key → karyawan).
              Jika kosong/vacant, hubungi HR untuk assign penjabat baru.
            </div>
          </div>

          <!-- Linked job postings + tracking -->
          <div v-if="detail.postings?.length" class="card p-4">
            <div class="text-sm font-semibold text-gray-700 mb-2 flex items-center gap-1.5">
              <BriefcaseIcon class="w-4 h-4" /> Lowongan Terkait ({{ detail.postings.length }})
            </div>
            <table class="w-full text-sm">
              <thead class="text-xs text-gray-500 border-b">
                <tr><th class="text-left py-1">Ref</th><th class="text-left">Judul</th>
                  <th class="text-center">HC</th><th class="text-center">Kandidat</th>
                  <th class="text-center">Hired</th><th>Status</th></tr>
              </thead>
              <tbody>
                <tr v-for="p in detail.postings" :key="p.id"
                  class="cursor-pointer border-b hover:bg-gray-50"
                  @click="router.push(`/recruitment/jobs/${p.id}`)">
                  <td class="font-mono text-xs py-1.5">{{ p.reference_number }}</td>
                  <td>{{ p.title }}</td>
                  <td class="text-center font-mono">{{ p.headcount }}</td>
                  <td class="text-center">
                    <span class="px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded text-xs">{{ p.candidate_count }}</span>
                  </td>
                  <td class="text-center font-mono">{{ p.hired_count }}</td>
                  <td><span class="text-xs">{{ p.status }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Notes -->
          <div v-if="detail.notes" class="card p-4 text-sm whitespace-pre-line">
            <div class="text-xs font-semibold text-gray-600 mb-1">Catatan</div>
            {{ detail.notes }}
          </div>
        </div>

        <!-- Footer actions -->
        <div v-if="detail" class="px-6 py-3 border-t flex items-center justify-between bg-gray-50">
          <div class="text-xs text-gray-500">
            Dibuat: {{ fmtDate(detail.created_at) }}
            <span v-if="detail.submitted_at"> · Diajukan: {{ fmtDate(detail.submitted_at) }}</span>
            <span v-if="detail.approved_at"> · Disetujui: {{ fmtDate(detail.approved_at) }}</span>
          </div>
          <div class="flex items-center gap-2">
            <button v-if="canSubmit && detail.status === 'DRAFT'" class="btn-secondary" @click="submitMpp(detail)">
              <PaperAirplaneIcon class="w-4 h-4" /> Ajukan
            </button>
            <button v-if="canApproveRow(detail) && detail.status === 'SUBMITTED'" class="btn-secondary text-red-600" @click="rejectMpp(detail)">
              <XCircleIcon class="w-4 h-4" /> Reject
            </button>
            <button v-if="canApproveRow(detail) && detail.status === 'SUBMITTED'" class="btn-primary" @click="approveMpp(detail)">
              <CheckCircleIcon class="w-4 h-4" /> Approve
            </button>
            <button v-if="canFulfill && ['APPROVED','HIRING'].includes(detail.status) && detail.remaining_headcount > 0"
              class="btn-primary" @click="openFulfill(detail)">
              <BriefcaseIcon class="w-4 h-4" /> Buat Lowongan ({{ detail.remaining_headcount }} sisa)
            </button>
            <button v-if="(canApprove || canFulfill) && ['HIRING','FILLED'].includes(detail.status)"
              class="btn-secondary" @click="closeMpp(detail)">
              Close MPP
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Fulfill modal: TA buat job_postings dari MPP -->
    <div v-if="showFulfill" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold flex items-center gap-2">
            <BriefcaseIcon class="w-5 h-5 text-primary-600" />
            Buat Lowongan dari MPP
          </h2>
          <button class="text-gray-500 hover:text-gray-800" @click="showFulfill = false">&times;</button>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-3">
          <div class="md:col-span-2 bg-blue-50 border border-blue-200 rounded p-3 text-xs text-blue-800">
            Lowongan akan dibuat sebagai <strong>DRAFT</strong>. Setelah disimpan, kamu bisa edit detail
            di halaman Recruitment, lalu publish ke Career Portal supaya pelamar bisa apply.
          </div>
          <div class="md:col-span-2">
            <label class="label">Judul Lowongan *</label>
            <input v-model="fulfillForm.title" class="input" maxlength="200" />
          </div>
          <div>
            <label class="label">Headcount Lowongan Ini *</label>
            <input type="number" v-model.number="fulfillForm.headcount" class="input" min="1" />
            <p class="text-xs text-gray-500 mt-1">
              Bisa di-split: kalau MPP butuh 5 orang, boleh buat 2 lowongan (3 + 2).
            </p>
          </div>
          <div>
            <label class="label">Tipe Pekerjaan</label>
            <select v-model="fulfillForm.employment_type" class="input">
              <option v-for="t in EMPLOYMENT_TYPES" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div>
            <label class="label">Cabang / Lokasi</label>
            <SearchableSelect
              v-model="fulfillForm.branch_id"
              :items="branches"
              value-key="id"
              label-key="name"
              :search-keys="['name','code','city']"
              placeholder="- Semua cabang -"
            />
          </div>
          <div>
            <label class="label">Tgl Buka</label>
            <input type="date" v-model="fulfillForm.opened_at" class="input" />
          </div>
          <div>
            <label class="label">Tgl Tutup (Deadline)</label>
            <input type="date" v-model="fulfillForm.closed_at" class="input" />
          </div>
          <div>
            <label class="label">Salary Min (Rp)</label>
            <input type="number" v-model.number="fulfillForm.salary_min" class="input" />
          </div>
          <div>
            <label class="label">Salary Max (Rp)</label>
            <input type="number" v-model.number="fulfillForm.salary_max" class="input" />
          </div>
          <div class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm">
              <input type="checkbox" v-model="fulfillForm.salary_visible" :true-value="1" :false-value="0" />
              Tampilkan range gaji ke pelamar di Career Portal
            </label>
          </div>
          <div class="md:col-span-2">
            <label class="label">Deskripsi Pekerjaan</label>
            <textarea v-model="fulfillForm.description" rows="4" class="input"></textarea>
          </div>
          <div class="md:col-span-2">
            <label class="label">Persyaratan</label>
            <textarea v-model="fulfillForm.requirements" rows="3" class="input"></textarea>
          </div>
          <div class="md:col-span-2">
            <label class="label">Catatan</label>
            <textarea v-model="fulfillForm.notes" rows="2" class="input"></textarea>
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showFulfill = false">Batal</button>
          <button class="btn-primary" @click="submitFulfill">
            <BriefcaseIcon class="w-4 h-4" /> Buat Lowongan
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
