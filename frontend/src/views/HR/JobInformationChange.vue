<script setup>
/**
 * Job Information Change (JIC) — Promosi / Demosi / Mutasi.
 *
 * Halaman ini menampilkan list JIC dengan filter, form create/edit,
 * dan dialog detail dengan tombol aksi sesuai tahap workflow.
 *
 * Konteks Indonesia (lihat docs/JIC.md):
 *   - DEMOSI non-disipliner butuh consent karyawan (Pasal 31 UU 13/2003).
 *   - Upah baru wajib >= UMP/UMK (cek otomatis backend).
 *   - SK auto-generate saat APPROVED.
 */
import { ref, computed, onMounted, reactive, watch } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtDate } from '@/composables/useResource'
import {
  ArrowsUpDownIcon, ArrowTrendingUpIcon, ArrowTrendingDownIcon,
  ArrowPathIcon, PencilIcon, TrashIcon, EyeIcon, PlusIcon,
  PaperAirplaneIcon, CheckCircleIcon, XCircleIcon, BoltIcon,
  ExclamationTriangleIcon, ShieldExclamationIcon, ChevronLeftIcon,
  ChevronRightIcon, UsersIcon,
} from '@heroicons/vue/24/outline'
import ReportingCard from './ReportingCard.vue'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import SearchableSelect from '@/components/SearchableSelect.vue'

/**
 * Helper kecil untuk highlight perubahan di kolom "Baru".
 * Mengembalikan true kalau dua nilai berbeda secara semantik (loose !=).
 * Kita pakai longgar supaya `1` dan `'1'` (string vs int dari payload JSON)
 * tidak salah-deteksi sebagai berubah.
 */
function changed(a, b) {
  if (a === null || a === undefined || a === '') return b !== null && b !== undefined && b !== ''
  if (b === null || b === undefined || b === '') return false
  // eslint-disable-next-line eqeqeq
  return a != b
}

const auth = useAuthStore()
const toast = useToast()

// ─── State ─────────────────────────────────────────────────────
const rows = ref([])
const total = ref(0)
const loading = ref(false)
const employees = ref([])
const positions = ref([])
const departments = ref([])
const branches = ref([])
const grades = ref([])
const options = ref({ change_types: [], reason_categories: [] })

const filters = reactive({
  q: '',
  status: '',
  change_type: '',
  employee_id: '',
  effective_from: '',
  effective_to: '',
  as: '',
  page: 1,
  per_page: 20,
})

const showForm = ref(false)
const editing = ref(null)
const showDetail = ref(null)
const detailLoading = ref(false)

const showRejectFor = ref(null)
const rejectNote = ref('')
const showCancelFor = ref(null)
const cancelNote = ref('')
const showConsentFor = ref(null)
const consentDecision = ref('AGREED')
const consentNote = ref('')

// ─── Permissions ───────────────────────────────────────────────
const canCreate  = computed(() => auth.can('jic.create')  || auth.can('jic.manage'))
const canApprove = computed(() => auth.can('jic.approve') || auth.can('jic.manage'))
const canManage  = computed(() => auth.can('jic.manage'))
const canImpl    = computed(() => auth.can('jic.implement') || auth.can('jic.manage'))
const canViewAll = computed(() => auth.can('jic.view') || canApprove.value || canManage.value)

// ─── Lookup metadata ──────────────────────────────────────────
const STATUS_META = {
  DRAFT:                    { label: 'Draft',                    badge: 'bg-gray-100 text-gray-700' },
  PENDING_SUPERVISOR:       { label: 'Menunggu Atasan',          badge: 'bg-yellow-100 text-yellow-800' },
  PENDING_DEPT_HEAD:        { label: 'Menunggu Kadep',           badge: 'bg-yellow-100 text-yellow-800' },
  PENDING_HR:               { label: 'Menunggu HR',              badge: 'bg-orange-100 text-orange-800' },
  PENDING_BOD:              { label: 'Menunggu Direksi',         badge: 'bg-purple-100 text-purple-800' },
  PENDING_EMPLOYEE_CONSENT: { label: 'Menunggu Persetujuan Karyawan', badge: 'bg-blue-100 text-blue-800' },
  APPROVED:                 { label: 'Disetujui',                badge: 'bg-emerald-100 text-emerald-800' },
  IMPLEMENTED:              { label: 'Diberlakukan',             badge: 'bg-emerald-200 text-emerald-900 font-semibold' },
  REJECTED:                 { label: 'Ditolak',                  badge: 'bg-red-100 text-red-800' },
  CANCELLED:                { label: 'Dibatalkan',               badge: 'bg-gray-100 text-gray-500' },
}
function statusMeta(s) { return STATUS_META[s] || { label: s, badge: 'bg-gray-100' } }

const CHANGE_TYPE_ICON = {
  PROMOTION:         ArrowTrendingUpIcon,
  DEMOTION:          ArrowTrendingDownIcon,
  LATERAL:           ArrowsUpDownIcon,
  REORG:             ArrowsUpDownIcon,
  SALARY_ADJUSTMENT: ArrowTrendingUpIcon,
  STATUS_CHANGE:     ArrowPathIcon,
}
const CHANGE_TYPE_BADGE = {
  PROMOTION:         'bg-emerald-100 text-emerald-800',
  DEMOTION:          'bg-red-100 text-red-800',
  LATERAL:           'bg-blue-100 text-blue-800',
  REORG:             'bg-purple-100 text-purple-800',
  SALARY_ADJUSTMENT: 'bg-cyan-100 text-cyan-800',
  STATUS_CHANGE:     'bg-indigo-100 text-indigo-800',
}
function changeTypeLabel(c) {
  return options.value.change_types.find(t => t.code === c)?.label || c
}
function reasonLabel(c) {
  return options.value.reason_categories.find(r => r.code === c)?.label || c
}

const fmtRupiah = (n) => {
  if (n === null || n === undefined || n === '') return '-'
  return 'Rp ' + Number(n).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
}

const totalPages = computed(() => Math.ceil(total.value / filters.per_page) || 1)

// ─── Data fetching ─────────────────────────────────────────────
async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/jic', { params: { ...filters } })
    rows.value = res.data.data
    total.value = res.data.meta?.total || 0
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat data JIC')
  } finally {
    loading.value = false
  }
}

async function fetchOptions() {
  try {
    const res = await api.get('/jic/options')
    options.value = res.data.data || { change_types: [], reason_categories: [] }
  } catch (e) { /* silent */ }
}

async function fetchMaster() {
  try {
    // Karyawan TIDAK lagi di-load semua sekaligus — picker
    // EmployeeSearchSelect melakukan server-side search saat dibutuhkan.
    // Ini menghindari payload besar saat ada ratusan/ribuan karyawan.
    const res = await Promise.all([
      api.get('/positions',     { params: { per_page: 200 } }),
      api.get('/departments',   { params: { per_page: 200 } }),
      api.get('/branches',      { params: { per_page: 200 } }),
      api.get('/job-grades',    { params: { per_page: 200 } }),
    ])
    positions.value   = res[0].data.data || []
    departments.value = res[1].data.data || []
    branches.value    = res[2].data.data || []
    grades.value      = res[3].data.data || []
  } catch (e) { /* silent */ }
}

onMounted(() => { fetchOptions(); fetchMaster(); fetchData() })

watch(() => [filters.status, filters.change_type, filters.as, filters.employee_id,
  filters.effective_from, filters.effective_to, filters.page, filters.per_page],
  () => fetchData())

function applyFilter() {
  filters.page = 1
  fetchData()
}
function resetFilter() {
  Object.assign(filters, {
    q: '', status: '', change_type: '', employee_id: '',
    effective_from: '', effective_to: '', as: '', page: 1,
  })
  fetchData()
}

// ─── Form ──────────────────────────────────────────────────────
function newDraft() {
  return {
    employee_id: '',
    reason_category: 'MERIT',
    reason_text: '',
    effective_date: new Date(Date.now() + 30 * 86400 * 1000).toISOString().slice(0, 10),
    notice_date: '',
    new_position_id: '',
    new_department_id: '',
    new_branch_id: '',
    new_job_grade_id: '',
    new_employment_status: '',
    new_basic_salary: '',
    new_supervisor_id: '',
    disciplinary_record_id: '',
    performance_review_id: '',
    mpp_id: '',
    training_certificate_id: '',
    ump_override_reason: '',
  }
}

function openCreate() {
  editing.value = newDraft()
  showForm.value = true
}

function openEdit(row) {
  if (row.status !== 'DRAFT') {
    toast.warning('Hanya draft yang dapat diedit')
    return
  }
  editing.value = JSON.parse(JSON.stringify(row))
  showForm.value = true
}

/**
 * Saat user pilih posisi baru, isi otomatis department & grade kalau
 * master `positions` punya field tsb. Ini mengurangi risiko inkonsistensi
 * (mis. tampil "IT Manager · Information Technology" di list tapi
 * Departemen masih kosong di kolom BARU detail modal).
 *
 * User tetap bisa override secara manual setelahnya — auto-fill hanya
 * jalan saat field bersangkutan masih kosong supaya tidak menimpa
 * pilihan eksplisit user.
 */
function onNewPositionChange() {
  if (! editing.value?.new_position_id) return
  const pos = positions.value.find(p => Number(p.id) === Number(editing.value.new_position_id))
  if (! pos) return
  if (pos.department_id && ! editing.value.new_department_id) {
    editing.value.new_department_id = pos.department_id
  }
  if (pos.job_grade_id && ! editing.value.new_job_grade_id) {
    editing.value.new_job_grade_id = pos.job_grade_id
  }
}

async function save() {
  try {
    const payload = { ...editing.value }
    // Bersihkan field empty string biar backend pakai null
    Object.keys(payload).forEach(k => { if (payload[k] === '') payload[k] = null })

    let res
    if (editing.value.id) {
      res = await api.put(`/jic/${editing.value.id}`, payload)
      toast.success('Draft JIC diperbarui')
    } else {
      res = await api.post('/jic', payload)
      const cls = res.data.data?.classification
      const ump = res.data.data?.ump_check
      let msg = `Draft JIC dibuat sebagai ${changeTypeLabel(res.data.data?.change_type || 'PROMOTION')}`
      if (cls) msg += ` (Δ upah ${cls.salary_change_pct}%)`
      if (ump?.status === 'FAIL') msg += ' — perhatian: upah baru di bawah UMP rujukan'
      toast.success(msg, { timeout: 8000 })
    }
    showForm.value = false
    await fetchData()
  } catch (e) {
    const errs = e.response?.data?.errors
    if (errs && typeof errs === 'object') {
      const msg = Array.isArray(errs) ? errs.join(', ') : Object.values(errs).join(', ')
      toast.error(msg)
    } else {
      toast.error(e.response?.data?.message || 'Gagal menyimpan')
    }
  }
}

async function removeDraft(row) {
  const ok = await confirmDialog({
    title: 'Hapus draft JIC?',
    message: `Draft #${row.id} akan dihapus permanen.`,
    confirmText: 'Hapus',
    confirmVariant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/jic/${row.id}`)
    toast.success('Draft dihapus')
    await fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

// ─── Detail + actions ──────────────────────────────────────────
async function openDetail(id) {
  detailLoading.value = true
  showDetail.value = { id, loading: true }
  try {
    const res = await api.get(`/jic/${id}`)
    showDetail.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat detail')
    showDetail.value = null
  } finally {
    detailLoading.value = false
  }
}

async function submitDraft(row) {
  const ok = await confirmDialog({
    title: 'Submit ke approval?',
    message: 'Setelah submit, draft tidak bisa diedit lagi tanpa cancel dulu. Lanjutkan?',
    confirmText: 'Submit',
  })
  if (! ok) return
  try {
    const res = await api.post(`/jic/${row.id}/submit`)
    toast.success(`Submitted sebagai ${res.data.data?.jic_number}`)
    await fetchData()
    if (showDetail.value?.id === row.id) await openDetail(row.id)
  } catch (e) {
    const errs = e.response?.data?.errors
    if (Array.isArray(errs)) {
      toast.error('Validasi gagal: ' + errs.join('; '), { timeout: 10000 })
    } else {
      toast.error(e.response?.data?.message || 'Gagal submit')
    }
  }
}

async function approve(row) {
  const note = window.prompt('Catatan persetujuan (opsional):', '')
  if (note === null) return
  try {
    const res = await api.post(`/jic/${row.id}/approve`, { note: note || undefined })
    toast.success(`Disetujui — status sekarang ${statusMeta(res.data.data?.status).label}`)
    await fetchData()
    if (showDetail.value?.id === row.id) await openDetail(row.id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal approve')
  }
}

function openReject(row) { showRejectFor.value = row; rejectNote.value = '' }
async function submitReject() {
  if (rejectNote.value.trim().length < 10) {
    toast.warning('Catatan minimal 10 karakter')
    return
  }
  try {
    await api.post(`/jic/${showRejectFor.value.id}/reject`, { note: rejectNote.value })
    toast.success('JIC ditolak')
    showRejectFor.value = null
    await fetchData()
    if (showDetail.value) await openDetail(showDetail.value.id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal reject')
  }
}

function openCancel(row) { showCancelFor.value = row; cancelNote.value = '' }
async function submitCancel() {
  try {
    await api.post(`/jic/${showCancelFor.value.id}/cancel`, { note: cancelNote.value || undefined })
    toast.success('JIC dibatalkan')
    showCancelFor.value = null
    await fetchData()
    if (showDetail.value) await openDetail(showDetail.value.id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal cancel')
  }
}

function openConsent(row) {
  showConsentFor.value = row
  consentDecision.value = 'AGREED'
  consentNote.value = ''
}
async function submitConsent() {
  try {
    const res = await api.post(`/jic/${showConsentFor.value.id}/consent`, {
      decision: consentDecision.value,
      note: consentNote.value || undefined,
    })
    toast.success(`Persetujuan tercatat — status: ${statusMeta(res.data.data?.status).label}`)
    showConsentFor.value = null
    await fetchData()
    if (showDetail.value) await openDetail(showDetail.value.id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal mencatat persetujuan')
  }
}

async function implement(row) {
  const ok = await confirmDialog({
    title: 'Apply JIC sekarang?',
    message: `Perubahan posisi/upah akan langsung diberlakukan ke record karyawan, dan amandemen kontrak akan dibuat. Lanjutkan?`,
    confirmText: 'Apply',
    confirmVariant: 'primary',
  })
  if (! ok) return
  try {
    const res = await api.post(`/jic/${row.id}/implement`)
    toast.success(res.data.message || 'JIC diberlakukan')
    await fetchData()
    if (showDetail.value?.id === row.id) await openDetail(row.id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal apply')
  }
}

// Cek apakah karyawan saat ini boleh consent (employee.user_id == auth.user.id)
function canConsentFor(row) {
  if (! row || row.status !== 'PENDING_EMPLOYEE_CONSENT') return false
  // Backend yang otoritatif; di sini hanya petunjuk UI.
  // Tampilkan tombol kalau owner (kemungkinan) atau HR.
  return true
}
</script>

<template>
  <div class="p-6 space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-semibold flex items-center gap-2">
          <ArrowsUpDownIcon class="w-6 h-6 text-indigo-600" />
          Job Information Change (JIC)
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Promosi, demosi, mutasi, dan perubahan kompensasi karyawan sesuai
          ketentuan UU 13/2003 jo. UU 6/2023.
        </p>
      </div>
      <button v-if="canCreate" @click="openCreate" class="btn-primary inline-flex items-center gap-2">
        <PlusIcon class="w-5 h-5" /> Buat Draft JIC
      </button>
    </div>

    <!-- Filter bar -->
    <div class="card p-4 grid grid-cols-1 md:grid-cols-4 lg:grid-cols-7 gap-3 items-end">
      <div>
        <label class="label">Status</label>
        <select v-model="filters.status" class="input">
          <option value="">Semua</option>
          <option v-for="(meta, code) in STATUS_META" :key="code" :value="code">{{ meta.label }}</option>
        </select>
      </div>
      <div>
        <label class="label">Tipe</label>
        <select v-model="filters.change_type" class="input">
          <option value="">Semua</option>
          <option v-for="t in options.change_types" :key="t.code" :value="t.code">{{ t.label }}</option>
        </select>
      </div>
      <div v-if="canViewAll">
        <label class="label">Karyawan</label>
        <EmployeeSearchSelect
          v-model="filters.employee_id"
          placeholder="Semua karyawan"
          :only-active="false"
        />
      </div>
      <div>
        <label class="label">Efektif dari</label>
        <AppDatePicker  v-model="filters.effective_from" class="input" />
      </div>
      <div>
        <label class="label">s/d</label>
        <AppDatePicker  v-model="filters.effective_to" class="input" />
      </div>
      <div v-if="canViewAll">
        <label class="label">Tampilkan</label>
        <select v-model="filters.as" class="input">
          <option value="">Semua</option>
          <option value="mine">Punya saya</option>
          <option value="inbox">Menunggu aksi saya</option>
        </select>
      </div>
      <div class="flex gap-2">
        <button @click="applyFilter" class="btn-secondary flex-1">Filter</button>
        <button @click="resetFilter" class="btn-secondary flex-1">Reset</button>
      </div>
    </div>

    <!-- Table -->
    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 dark:bg-gray-800 text-xs uppercase">
            <tr>
              <th class="px-3 py-2 text-left">No. JIC</th>
              <th class="px-3 py-2 text-left">Karyawan</th>
              <th class="px-3 py-2 text-left">Tipe</th>
              <th class="px-3 py-2 text-left">Posisi Lama → Baru</th>
              <th class="px-3 py-2 text-right">Δ Upah</th>
              <th class="px-3 py-2 text-left">Efektif</th>
              <th class="px-3 py-2 text-left">Status</th>
              <th class="px-3 py-2 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="8" class="px-3 py-6 text-center text-gray-500">Memuat...</td>
            </tr>
            <tr v-else-if="rows.length === 0">
              <td colspan="8" class="px-3 py-6 text-center text-gray-500">Belum ada JIC.</td>
            </tr>
            <tr v-for="r in rows" :key="r.id" class="border-t hover:bg-gray-50 dark:hover:bg-gray-800/50">
              <td class="px-3 py-2 font-mono text-xs">{{ r.jic_number || `#${r.id}` }}</td>
              <td class="px-3 py-2">
                <div class="font-medium">{{ r.employee_name || '-' }}</div>
                <div class="text-xs text-gray-500">{{ r.employee_number || '-' }}</div>
              </td>
              <td class="px-3 py-2">
                <span :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium', CHANGE_TYPE_BADGE[r.change_type]]">
                  <component :is="CHANGE_TYPE_ICON[r.change_type] || ArrowsUpDownIcon" class="w-3.5 h-3.5" />
                  {{ changeTypeLabel(r.change_type) }}
                </span>
                <div v-if="r.employee_consent_required == 1" class="text-[11px] text-blue-600 mt-1 flex items-center gap-1">
                  <ShieldExclamationIcon class="w-3.5 h-3.5" /> Butuh consent karyawan
                </div>
              </td>
              <td class="px-3 py-2 text-xs">
                <div>{{ r.old_position_name || '-' }}<span v-if="r.old_dept_name_join" class="text-gray-400"> · {{ r.old_dept_name_join }}</span></div>
                <div class="text-emerald-600">→ {{ r.new_position_name || r.old_position_name || '-' }}<span v-if="r.new_dept_name" class="text-gray-400"> · {{ r.new_dept_name }}</span></div>
              </td>
              <td class="px-3 py-2 text-right font-mono text-xs">
                <span v-if="r.salary_change_pct !== null && r.salary_change_pct !== undefined"
                      :class="Number(r.salary_change_pct) > 0 ? 'text-emerald-600' : Number(r.salary_change_pct) < 0 ? 'text-red-600' : 'text-gray-500'">
                  {{ Number(r.salary_change_pct) > 0 ? '+' : '' }}{{ Number(r.salary_change_pct).toFixed(2) }}%
                </span>
                <span v-else class="text-gray-400">-</span>
                <div v-if="r.ump_check_status === 'FAIL'" class="text-[11px] text-red-600 flex items-center gap-1 justify-end mt-0.5">
                  <ExclamationTriangleIcon class="w-3.5 h-3.5" /> < UMP
                </div>
                <div v-else-if="r.ump_check_status === 'OVERRIDE'" class="text-[11px] text-amber-600 mt-0.5">UMP override</div>
              </td>
              <td class="px-3 py-2 text-xs">{{ fmtDate(r.effective_date) }}</td>
              <td class="px-3 py-2">
                <span :class="['px-2 py-0.5 rounded text-xs font-medium', statusMeta(r.status).badge]">
                  {{ statusMeta(r.status).label }}
                </span>
              </td>
              <td class="px-3 py-2 text-right">
                <div class="inline-flex gap-1">
                  <button @click="openDetail(r.id)" class="btn-icon" title="Detail">
                    <EyeIcon class="w-4 h-4" />
                  </button>
                  <button v-if="r.status === 'DRAFT'" @click="openEdit(r)" class="btn-icon" title="Edit">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button v-if="r.status === 'DRAFT'" @click="submitDraft(r)" class="btn-icon text-indigo-600" title="Submit">
                    <PaperAirplaneIcon class="w-4 h-4" />
                  </button>
                  <button v-if="r.status === 'DRAFT'" @click="removeDraft(r)" class="btn-icon text-red-600" title="Hapus">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="flex items-center justify-between px-4 py-3 border-t text-sm">
        <span class="text-gray-500">{{ total }} record · halaman {{ filters.page }}/{{ totalPages }}</span>
        <div class="flex gap-2">
          <button @click="filters.page = Math.max(1, filters.page - 1)" :disabled="filters.page <= 1"
                  class="btn-icon disabled:opacity-50">
            <ChevronLeftIcon class="w-4 h-4" />
          </button>
          <button @click="filters.page = Math.min(totalPages, filters.page + 1)" :disabled="filters.page >= totalPages"
                  class="btn-icon disabled:opacity-50">
            <ChevronRightIcon class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>

    <!-- ─── Form Modal ─────────────────────────────────────── -->
    <div v-if="showForm" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
      <div class="bg-white dark:bg-gray-900 rounded-lg shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
        <div class="p-5 border-b">
          <h2 class="text-lg font-semibold">{{ editing.id ? 'Edit Draft JIC' : 'Buat Draft JIC' }}</h2>
          <p class="text-xs text-gray-500 mt-1">
            Sistem akan otomatis menentukan tipe (Promosi/Demosi/Lateral) berdasar perbandingan tier & gaji.
          </p>
        </div>
        <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <label class="label">Karyawan <span class="text-red-500">*</span></label>
            <EmployeeSearchSelect
              v-model="editing.employee_id"
              placeholder="Cari & pilih karyawan…"
              :disabled="!!editing.id"
              :only-active="true"
            />
            <p v-if="editing.id" class="text-[11px] text-gray-500 mt-1">
              Karyawan tidak dapat diganti pada draft yang sudah disimpan.
            </p>
          </div>

          <div>
            <label class="label">Alasan <span class="text-red-500">*</span></label>
            <select v-model="editing.reason_category" class="input">
              <option v-for="r in options.reason_categories" :key="r.code" :value="r.code">{{ r.label }}</option>
            </select>
          </div>
          <div>
            <label class="label">Tanggal Efektif <span class="text-red-500">*</span></label>
            <AppDatePicker  v-model="editing.effective_date" class="input" />
          </div>

          <div class="md:col-span-2">
            <label class="label">Catatan / Justifikasi</label>
            <textarea v-model="editing.reason_text" rows="2" class="input" placeholder="Mis. promosi setelah review tahunan A+, dst."></textarea>
          </div>

          <div class="md:col-span-2 border-t pt-3">
            <p class="text-xs uppercase font-semibold text-gray-500 mb-2">Posisi / Departemen Baru (opsional, isi yang berubah)</p>
          </div>

          <div>
            <label class="label">Posisi Baru</label>
            <SearchableSelect
              v-model="editing.new_position_id"
              :items="positions"
              value-key="id"
              label-key="name"
              sub-label-key="department_name"
              :search-keys="['name','tier','code','department_name']"
              placeholder="— Tidak berubah —"
              @change="onNewPositionChange"
            >
              <template #item="{ item }">
                <span class="block text-sm truncate">
                  {{ item.name }}<span v-if="item.tier" class="text-xs text-gray-500"> ({{ item.tier }})</span>
                </span>
                <span v-if="item.department_name" class="block text-[11px] text-gray-500 truncate">
                  {{ item.department_name }}
                </span>
              </template>
            </SearchableSelect>
            <p class="text-[11px] text-gray-500 mt-1">
              Memilih posisi akan otomatis isi Departemen & Job Grade kalau master punya nilainya.
            </p>
          </div>
          <div>
            <label class="label">Departemen Baru</label>
            <SearchableSelect
              v-model="editing.new_department_id"
              :items="departments"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="— Tidak berubah —"
            />
          </div>
          <div>
            <label class="label">Cabang Baru</label>
            <SearchableSelect
              v-model="editing.new_branch_id"
              :items="branches"
              value-key="id"
              label-key="name"
              :search-keys="['name','code','city']"
              placeholder="— Tidak berubah —"
            />
          </div>
          <div>
            <label class="label">Job Grade Baru</label>
            <SearchableSelect
              v-model="editing.new_job_grade_id"
              :items="grades"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="— Tidak berubah —"
            />
          </div>
          <div>
            <label class="label">Status Kepegawaian Baru</label>
            <select v-model="editing.new_employment_status" class="input">
              <option value="">— Tidak berubah —</option>
              <option value="PROBATION">PROBATION</option>
              <option value="PKWT">PKWT</option>
              <option value="PKWTT">PKWTT</option>
              <option value="HARIAN">Harian Lepas</option>
              <option value="MAGANG">Magang</option>
              <option value="OUTSOURCING">Outsourcing</option>
            </select>
          </div>
          <div>
            <label class="label">Upah Pokok Baru (Rp)</label>
            <input type="number" v-model="editing.new_basic_salary" class="input" min="0" step="1000" />
          </div>

          <div class="md:col-span-2 border-t pt-3">
            <p class="text-xs uppercase font-semibold text-gray-500 mb-2">Dokumen Pendukung</p>
            <p class="text-xs text-gray-500 mb-2">
              Wajib diisi sesuai alasan: <strong>DISCIPLINARY</strong> butuh ID Surat Peringatan,
              <strong>PERFORMANCE/MERIT</strong> butuh ID Performance Review,
              <strong>COMPETENCY</strong> butuh ID Sertifikasi.
            </p>
          </div>
          <div v-if="editing.reason_category === 'DISCIPLINARY'">
            <label class="label">SP / Disciplinary ID</label>
            <input type="number" v-model="editing.disciplinary_record_id" class="input" />
          </div>
          <div v-if="['PERFORMANCE','MERIT'].includes(editing.reason_category)">
            <label class="label">Performance Review ID</label>
            <input type="number" v-model="editing.performance_review_id" class="input" />
          </div>
          <div v-if="editing.reason_category === 'COMPETENCY'">
            <label class="label">Training Certificate ID</label>
            <input type="number" v-model="editing.training_certificate_id" class="input" />
          </div>
          <div v-if="editing.reason_category === 'RESTRUCTURE'">
            <label class="label">MPP ID</label>
            <input type="number" v-model="editing.mpp_id" class="input" />
          </div>

          <div class="md:col-span-2 border-t pt-3">
            <label class="label">UMP Override Reason</label>
            <textarea v-model="editing.ump_override_reason" rows="2" class="input"
                      placeholder="Wajib diisi kalau upah baru di bawah UMP karena ketentuan PKB/PP yang sah."></textarea>
            <p class="text-xs text-gray-500 mt-1">
              Catatan: untuk DEMOSI yang menurunkan upah di bawah UMP, perusahaan tetap wajib
              mengikuti UU pengupahan. Override hanya boleh berdasar perjanjian kolektif yang sah.
            </p>
          </div>
        </div>
        <div class="p-5 border-t flex justify-end gap-2">
          <button @click="showForm = false" class="btn-secondary">Batal</button>
          <button @click="save" class="btn-primary">Simpan Draft</button>
        </div>
      </div>
    </div>

    <!-- ─── Detail Modal ───────────────────────────────────── -->
    <div v-if="showDetail" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
      <div class="bg-white dark:bg-gray-900 rounded-lg shadow-xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="p-5 border-b flex items-start justify-between gap-4">
          <div>
            <div class="flex items-center gap-2">
              <span :class="['px-2 py-0.5 rounded text-xs font-medium', statusMeta(showDetail.status || '').badge]">
                {{ statusMeta(showDetail.status || '').label }}
              </span>
              <span :class="['px-2 py-0.5 rounded text-xs font-medium', CHANGE_TYPE_BADGE[showDetail.change_type] || 'bg-gray-100']">
                {{ changeTypeLabel(showDetail.change_type) }}
              </span>
            </div>
            <h2 class="text-lg font-semibold mt-1">{{ showDetail.jic_number || `JIC #${showDetail.id}` }}</h2>
            <p class="text-xs text-gray-500">SK: {{ showDetail.sk_number || '-' }}</p>
          </div>
          <button @click="showDetail = null" class="btn-icon"><XCircleIcon class="w-5 h-5" /></button>
        </div>
        <div v-if="detailLoading" class="p-10 text-center text-gray-500">Memuat...</div>
        <div v-else class="p-5 space-y-5">

          <!-- Profil Karyawan -->
          <div v-if="showDetail.employee" class="card p-4 bg-indigo-50/40 dark:bg-indigo-900/10 border-indigo-200">
            <div class="text-xs uppercase font-semibold text-indigo-700 mb-3 flex items-center gap-2">
              <UsersIcon class="w-4 h-4" /> Profil Karyawan
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-2 text-sm">
              <div><dt class="text-xs text-gray-500">Nama Lengkap</dt><dd class="font-semibold">{{ showDetail.employee.full_name }}</dd></div>
              <div><dt class="text-xs text-gray-500">NIK Karyawan</dt><dd class="font-mono">{{ showDetail.employee.employee_number }}</dd></div>
              <div><dt class="text-xs text-gray-500">Email</dt><dd>{{ showDetail.employee.email || '-' }}</dd></div>
              <div><dt class="text-xs text-gray-500">Telepon</dt><dd>{{ showDetail.employee.phone || '-' }}</dd></div>
              <div><dt class="text-xs text-gray-500">Status</dt>
                <dd>
                  <span :class="['px-1.5 py-0.5 rounded text-xs', showDetail.employee.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-600']">
                    {{ showDetail.employee.is_active ? 'Aktif' : 'Tidak Aktif' }}
                  </span>
                  <span class="ml-2 text-xs text-gray-500">{{ showDetail.employee.employment_status }}</span>
                </dd>
              </div>
              <div><dt class="text-xs text-gray-500">Tanggal Bergabung</dt>
                <dd>{{ fmtDate(showDetail.employee.join_date) }}
                  <span v-if="showDetail.employee.tenure" class="text-xs text-gray-500 ml-1">({{ showDetail.employee.tenure.human }})</span>
                </dd>
              </div>
              <div v-if="showDetail.employee.probation_end_date">
                <dt class="text-xs text-gray-500">Akhir Probation</dt>
                <dd>{{ fmtDate(showDetail.employee.probation_end_date) }}</dd>
              </div>
              <div v-if="showDetail.employee.contract_end_date">
                <dt class="text-xs text-gray-500">Akhir Kontrak</dt>
                <dd>{{ fmtDate(showDetail.employee.contract_end_date) }}</dd>
              </div>
              <div v-if="showDetail.employee.education_level">
                <dt class="text-xs text-gray-500">Pendidikan</dt>
                <dd>{{ showDetail.employee.education_level }}</dd>
              </div>
              <div v-if="showDetail.employee.marital_status">
                <dt class="text-xs text-gray-500">Status PTKP</dt>
                <dd>{{ showDetail.employee.marital_status }}</dd>
              </div>
              <div><dt class="text-xs text-gray-500">Metode Pajak</dt>
                <dd>{{ showDetail.employee.tax_method }} · PTKP {{ showDetail.employee.ptkp_status }}</dd>
              </div>
              <div><dt class="text-xs text-gray-500">Cabang Saat Ini</dt><dd>{{ showDetail.employee.current?.branch || '-' }}</dd></div>
            </div>
          </div>

          <!-- Side-by-side comparison: posisi & kompensasi -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="card p-4 bg-gray-50 dark:bg-gray-800">
              <div class="text-xs uppercase font-semibold text-gray-500 mb-2">Sebelum (Lama)</div>
              <dl class="space-y-1.5 text-sm">
                <div class="flex justify-between gap-2"><dt class="text-gray-500">Posisi</dt><dd class="text-right font-medium">{{ showDetail.old?.position?.name || '-' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-gray-500">Departemen</dt><dd class="text-right">{{ showDetail.old?.department?.name || '-' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-gray-500">Cabang</dt><dd class="text-right">{{ showDetail.old?.branch?.name || '-' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-gray-500">Job Grade</dt><dd class="text-right">{{ showDetail.old?.job_grade?.name || '-' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-gray-500">Lokasi Kerja</dt><dd class="text-right">{{ showDetail.old?.work_location?.name || '-' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-gray-500">Status Kepegawaian</dt><dd class="text-right">{{ showDetail.old?.employment_status || '-' }}</dd></div>
                <div class="flex justify-between gap-2 border-t pt-1.5"><dt class="text-gray-500">Upah Pokok</dt><dd class="text-right font-mono font-semibold">{{ fmtRupiah(showDetail.old?.basic_salary) }}</dd></div>
              </dl>
            </div>
            <div class="card p-4 bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200">
              <div class="text-xs uppercase font-semibold text-emerald-700 mb-2">Sesudah (Baru)</div>
              <dl class="space-y-1.5 text-sm">
                <div class="flex justify-between gap-2">
                  <dt class="text-gray-500">Posisi</dt>
                  <dd class="text-right font-medium" :class="changed(showDetail.old?.position?.id, showDetail.new?.position?.id) ? 'text-emerald-700' : ''">
                    {{ showDetail.new?.position?.name || showDetail.old?.position?.name || '-' }}
                    <span v-if="showDetail.new?.position?.tier" class="text-xs text-gray-500 ml-1">({{ showDetail.new.position.tier }})</span>
                  </dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-gray-500">Departemen</dt>
                  <dd class="text-right" :class="changed(showDetail.old?.department?.id, showDetail.new?.department?.id) ? 'text-emerald-700 font-medium' : ''">
                    {{ showDetail.new?.department?.name || showDetail.old?.department?.name || '-' }}
                  </dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-gray-500">Cabang</dt>
                  <dd class="text-right" :class="changed(showDetail.old?.branch?.id, showDetail.new?.branch?.id) ? 'text-emerald-700 font-medium' : ''">
                    {{ showDetail.new?.branch?.name || showDetail.old?.branch?.name || '-' }}
                  </dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-gray-500">Job Grade</dt>
                  <dd class="text-right" :class="changed(showDetail.old?.job_grade?.id, showDetail.new?.job_grade?.id) ? 'text-emerald-700 font-medium' : ''">
                    {{ showDetail.new?.job_grade?.name || showDetail.old?.job_grade?.name || '-' }}
                  </dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-gray-500">Lokasi Kerja</dt>
                  <dd class="text-right">{{ showDetail.new?.work_location?.name || showDetail.old?.work_location?.name || '-' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-gray-500">Status Kepegawaian</dt>
                  <dd class="text-right" :class="changed(showDetail.old?.employment_status, showDetail.new?.employment_status) ? 'text-emerald-700 font-medium' : ''">
                    {{ showDetail.new?.employment_status || showDetail.old?.employment_status || '-' }}
                  </dd>
                </div>
                <div class="flex justify-between gap-2 border-t pt-1.5">
                  <dt class="text-gray-500">Upah Pokok</dt>
                  <dd class="text-right font-mono font-semibold"
                      :class="Number(showDetail.salary_change_pct) > 0 ? 'text-emerald-700' : Number(showDetail.salary_change_pct) < 0 ? 'text-red-600' : ''">
                    {{ fmtRupiah(showDetail.new?.basic_salary || showDetail.old?.basic_salary) }}
                    <span v-if="showDetail.salary_change_pct" class="text-xs ml-1">
                      ({{ Number(showDetail.salary_change_pct) > 0 ? '+' : '' }}{{ Number(showDetail.salary_change_pct).toFixed(2) }}%)
                    </span>
                  </dd>
                </div>
              </dl>
            </div>
          </div>

          <!-- Reporting line: atasan langsung & kepala departemen, lama vs baru -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <ReportingCard label="Reporting Line — Lama" :supervisor="showDetail.old?.supervisor"
                           :manager="showDetail.old?.manager" :dept-head="showDetail.old?.department_head"
                           variant="neutral" />
            <ReportingCard label="Reporting Line — Baru" :supervisor="showDetail.new?.supervisor"
                           :manager="showDetail.new?.manager" :dept-head="showDetail.new?.department_head"
                           variant="emerald" />
          </div>

          <!-- Compliance -->
          <div class="card p-4">
            <div class="text-xs uppercase font-semibold text-gray-500 mb-2">Compliance</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
              <div>
                <span class="text-gray-500">Tanggal Efektif:</span>
                <span class="ml-1">{{ fmtDate(showDetail.effective_date) }}</span>
              </div>
              <div>
                <span class="text-gray-500">Δ Upah:</span>
                <span class="ml-1 font-mono"
                      :class="Number(showDetail.salary_change_pct) > 0 ? 'text-emerald-600' : Number(showDetail.salary_change_pct) < 0 ? 'text-red-600' : ''">
                  {{ Number(showDetail.salary_change_pct || 0).toFixed(2) }}%
                </span>
              </div>
              <div>
                <span class="text-gray-500">UMP Check:</span>
                <span class="ml-1" :class="showDetail.ump_check_status === 'FAIL' ? 'text-red-600' : showDetail.ump_check_status === 'OVERRIDE' ? 'text-amber-600' : ''">
                  {{ showDetail.ump_check_status }}
                  <template v-if="showDetail.ump_reference"> (rujukan {{ fmtRupiah(showDetail.ump_reference) }})</template>
                </span>
              </div>
              <div>
                <span class="text-gray-500">Persetujuan Karyawan:</span>
                <span class="ml-1">{{ showDetail.employee_consent_status }}</span>
              </div>
              <div class="sm:col-span-2" v-if="showDetail.ump_override_reason">
                <span class="text-gray-500">UMP Override Reason:</span>
                <p class="text-amber-700 mt-1 text-sm whitespace-pre-line">{{ showDetail.ump_override_reason }}</p>
              </div>
              <div class="sm:col-span-2" v-if="showDetail.reason_text">
                <span class="text-gray-500">Justifikasi:</span>
                <p class="mt-1 text-sm whitespace-pre-line">{{ showDetail.reason_text }}</p>
              </div>
            </div>
          </div>

          <!-- Approval trail -->
          <div class="card p-4">
            <div class="text-xs uppercase font-semibold text-gray-500 mb-2">Trail Persetujuan</div>
            <div class="space-y-2 text-sm">
              <div class="flex items-start gap-2">
                <CheckCircleIcon v-if="showDetail.supervisor_decision === 'APPROVED'" class="w-5 h-5 text-emerald-600 flex-shrink-0" />
                <XCircleIcon v-else-if="showDetail.supervisor_decision === 'REJECTED'" class="w-5 h-5 text-red-600 flex-shrink-0" />
                <span v-else class="w-5 h-5 rounded-full border-2 border-gray-300 flex-shrink-0 inline-block"></span>
                <div>
                  <div class="font-medium">Atasan Langsung</div>
                  <div class="text-xs text-gray-500">{{ showDetail.supervisor_action_at ? fmtDate(showDetail.supervisor_action_at) : '—' }}</div>
                  <div v-if="showDetail.supervisor_note" class="text-xs italic">"{{ showDetail.supervisor_note }}"</div>
                </div>
              </div>
              <div class="flex items-start gap-2">
                <CheckCircleIcon v-if="showDetail.dept_head_decision === 'APPROVED'" class="w-5 h-5 text-emerald-600 flex-shrink-0" />
                <XCircleIcon v-else-if="showDetail.dept_head_decision === 'REJECTED'" class="w-5 h-5 text-red-600 flex-shrink-0" />
                <span v-else class="w-5 h-5 rounded-full border-2 border-gray-300 flex-shrink-0 inline-block"></span>
                <div>
                  <div class="font-medium">Kepala Departemen</div>
                  <div class="text-xs text-gray-500">{{ showDetail.dept_head_action_at ? fmtDate(showDetail.dept_head_action_at) : '—' }}</div>
                  <div v-if="showDetail.dept_head_note" class="text-xs italic">"{{ showDetail.dept_head_note }}"</div>
                </div>
              </div>
              <div class="flex items-start gap-2">
                <CheckCircleIcon v-if="showDetail.hr_decision === 'APPROVED'" class="w-5 h-5 text-emerald-600 flex-shrink-0" />
                <XCircleIcon v-else-if="showDetail.hr_decision === 'REJECTED'" class="w-5 h-5 text-red-600 flex-shrink-0" />
                <span v-else class="w-5 h-5 rounded-full border-2 border-gray-300 flex-shrink-0 inline-block"></span>
                <div>
                  <div class="font-medium">HR</div>
                  <div class="text-xs text-gray-500">{{ showDetail.hr_action_at ? fmtDate(showDetail.hr_action_at) : '—' }}</div>
                  <div v-if="showDetail.hr_note" class="text-xs italic">"{{ showDetail.hr_note }}"</div>
                </div>
              </div>
              <div v-if="['PENDING_BOD','APPROVED','IMPLEMENTED'].includes(showDetail.status) || showDetail.bod_decision" class="flex items-start gap-2">
                <CheckCircleIcon v-if="showDetail.bod_decision === 'APPROVED'" class="w-5 h-5 text-emerald-600 flex-shrink-0" />
                <XCircleIcon v-else-if="showDetail.bod_decision === 'REJECTED'" class="w-5 h-5 text-red-600 flex-shrink-0" />
                <span v-else class="w-5 h-5 rounded-full border-2 border-gray-300 flex-shrink-0 inline-block"></span>
                <div>
                  <div class="font-medium">Direksi (BOD)</div>
                  <div class="text-xs text-gray-500">{{ showDetail.bod_action_at ? fmtDate(showDetail.bod_action_at) : '—' }}</div>
                  <div v-if="showDetail.bod_note" class="text-xs italic">"{{ showDetail.bod_note }}"</div>
                </div>
              </div>
              <div v-if="showDetail.employee_consent_required == 1" class="flex items-start gap-2">
                <CheckCircleIcon v-if="showDetail.employee_consent_status === 'AGREED'" class="w-5 h-5 text-emerald-600 flex-shrink-0" />
                <XCircleIcon v-else-if="showDetail.employee_consent_status === 'REJECTED'" class="w-5 h-5 text-red-600 flex-shrink-0" />
                <span v-else class="w-5 h-5 rounded-full border-2 border-blue-300 flex-shrink-0 inline-block"></span>
                <div>
                  <div class="font-medium">Persetujuan Karyawan
                    <span class="text-xs font-normal text-gray-500 ml-1">(Pasal 31 UU 13/2003)</span>
                  </div>
                  <div class="text-xs text-gray-500">{{ showDetail.employee_consent_at ? fmtDate(showDetail.employee_consent_at) : '—' }}</div>
                  <div v-if="showDetail.employee_consent_note" class="text-xs italic">"{{ showDetail.employee_consent_note }}"</div>
                </div>
              </div>
              <div v-if="showDetail.implemented_at" class="flex items-start gap-2 pt-2 border-t">
                <BoltIcon class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                <div>
                  <div class="font-medium">Diberlakukan</div>
                  <div class="text-xs text-gray-500">{{ fmtDate(showDetail.implemented_at) }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Action footer -->
        <div class="p-5 border-t flex flex-wrap gap-2 justify-end">
          <button v-if="showDetail.status === 'DRAFT'" @click="submitDraft(showDetail)" class="btn-primary inline-flex items-center gap-1">
            <PaperAirplaneIcon class="w-4 h-4" /> Submit
          </button>
          <button v-if="['PENDING_SUPERVISOR','PENDING_DEPT_HEAD','PENDING_HR','PENDING_BOD'].includes(showDetail.status)"
                  @click="approve(showDetail)" class="btn-primary inline-flex items-center gap-1">
            <CheckCircleIcon class="w-4 h-4" /> Setujui
          </button>
          <button v-if="['PENDING_SUPERVISOR','PENDING_DEPT_HEAD','PENDING_HR','PENDING_BOD'].includes(showDetail.status)"
                  @click="openReject(showDetail)" class="btn-danger inline-flex items-center gap-1">
            <XCircleIcon class="w-4 h-4" /> Tolak
          </button>
          <button v-if="canConsentFor(showDetail)" @click="openConsent(showDetail)" class="btn-primary inline-flex items-center gap-1">
            <ShieldExclamationIcon class="w-4 h-4" /> Berikan Consent
          </button>
          <button v-if="showDetail.status === 'APPROVED' && canImpl"
                  @click="implement(showDetail)" class="btn-primary inline-flex items-center gap-1">
            <BoltIcon class="w-4 h-4" /> Apply Sekarang
          </button>
          <button v-if="!['IMPLEMENTED','REJECTED','CANCELLED'].includes(showDetail.status)"
                  @click="openCancel(showDetail)" class="btn-secondary inline-flex items-center gap-1">
            <XCircleIcon class="w-4 h-4" /> Batalkan
          </button>
          <button @click="showDetail = null" class="btn-secondary">Tutup</button>
        </div>
      </div>
    </div>

    <!-- ─── Reject Modal ───────────────────────────────────── -->
    <div v-if="showRejectFor" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
      <div class="bg-white dark:bg-gray-900 rounded-lg shadow-xl w-full max-w-md">
        <div class="p-5 border-b">
          <h3 class="font-semibold">Tolak JIC #{{ showRejectFor.id }}</h3>
        </div>
        <div class="p-5">
          <label class="label">Alasan penolakan <span class="text-red-500">*</span></label>
          <textarea v-model="rejectNote" rows="4" class="input" placeholder="Minimal 10 karakter."></textarea>
        </div>
        <div class="p-5 border-t flex justify-end gap-2">
          <button @click="showRejectFor = null" class="btn-secondary">Batal</button>
          <button @click="submitReject" class="btn-danger">Tolak</button>
        </div>
      </div>
    </div>

    <!-- ─── Cancel Modal ───────────────────────────────────── -->
    <div v-if="showCancelFor" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
      <div class="bg-white dark:bg-gray-900 rounded-lg shadow-xl w-full max-w-md">
        <div class="p-5 border-b">
          <h3 class="font-semibold">Batalkan JIC #{{ showCancelFor.id }}</h3>
        </div>
        <div class="p-5">
          <label class="label">Catatan (opsional)</label>
          <textarea v-model="cancelNote" rows="3" class="input"></textarea>
          <p v-if="showCancelFor.status === 'APPROVED'" class="text-xs text-amber-600 mt-2">
            Pembatalan setelah APPROVED akan revert efek; pastikan koordinasi dengan payroll.
          </p>
        </div>
        <div class="p-5 border-t flex justify-end gap-2">
          <button @click="showCancelFor = null" class="btn-secondary">Tidak</button>
          <button @click="submitCancel" class="btn-danger">Ya, Batalkan</button>
        </div>
      </div>
    </div>

    <!-- ─── Consent Modal (untuk karyawan) ─────────────────── -->
    <div v-if="showConsentFor" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
      <div class="bg-white dark:bg-gray-900 rounded-lg shadow-xl w-full max-w-md">
        <div class="p-5 border-b">
          <h3 class="font-semibold">Persetujuan Karyawan</h3>
          <p class="text-xs text-gray-500 mt-1">
            Sesuai Pasal 31 UU 13/2003, demosi yang menurunkan posisi/upah memerlukan
            persetujuan tertulis Anda. Anda berhak menolak.
          </p>
        </div>
        <div class="p-5 space-y-3">
          <div class="flex gap-2">
            <label class="flex-1 cursor-pointer">
              <input type="radio" v-model="consentDecision" value="AGREED" class="sr-only peer" />
              <div class="border-2 rounded p-3 text-center peer-checked:border-emerald-500 peer-checked:bg-emerald-50">
                <CheckCircleIcon class="w-5 h-5 mx-auto text-emerald-600" />
                <div class="text-sm mt-1">Saya Setuju</div>
              </div>
            </label>
            <label class="flex-1 cursor-pointer">
              <input type="radio" v-model="consentDecision" value="REJECTED" class="sr-only peer" />
              <div class="border-2 rounded p-3 text-center peer-checked:border-red-500 peer-checked:bg-red-50">
                <XCircleIcon class="w-5 h-5 mx-auto text-red-600" />
                <div class="text-sm mt-1">Saya Tolak</div>
              </div>
            </label>
          </div>
          <div>
            <label class="label">Catatan {{ consentDecision === 'REJECTED' ? '(disarankan diisi)' : '(opsional)' }}</label>
            <textarea v-model="consentNote" rows="3" class="input"></textarea>
          </div>
        </div>
        <div class="p-5 border-t flex justify-end gap-2">
          <button @click="showConsentFor = null" class="btn-secondary">Batal</button>
          <button @click="submitConsent" class="btn-primary">Kirim Persetujuan</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.btn-primary { @apply bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded font-medium text-sm transition; }
.btn-secondary { @apply bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 px-4 py-2 rounded font-medium text-sm transition; }
.btn-danger { @apply bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded font-medium text-sm transition; }
.btn-icon { @apply p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition; }
.input { @apply w-full px-3 py-2 border rounded text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:bg-gray-800 dark:border-gray-700; }
.label { @apply block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1; }
.card { @apply bg-white dark:bg-gray-900 border rounded-lg dark:border-gray-700 shadow-sm; }
</style>