<script setup>
/**
 * Employee Transfers (Mutasi Karyawan).
 *
 * Halaman ringkas untuk pemindahan karyawan antar branch/department/posisi/
 * work_location. Workflow: DRAFT → SUBMITTED → APPROVED → IMPLEMENTED.
 *
 * Beda dengan halaman JIC (Promosi/Demosi):
 *   - JIC kompleks (multi-stage approval, BOD, consent, UMP check, SK).
 *   - Mutasi simpel (1-tier HR approval, tidak menyentuh upah/grade).
 *
 * Lihat docs/EMPLOYEE_TRANSFER.md.
 */
import { ref, computed, onMounted, reactive, watch } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtDate } from '@/composables/useResource'
import {
  ArrowsRightLeftIcon, PlusIcon, EyeIcon, PaperAirplaneIcon,
  CheckCircleIcon, XCircleIcon, BoltIcon, NoSymbolIcon,
  XMarkIcon, BuildingOffice2Icon, BuildingStorefrontIcon,
  BriefcaseIcon, MapPinIcon,
} from '@heroicons/vue/24/outline'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import SearchableSelect from '@/components/SearchableSelect.vue'

const auth = useAuthStore()
const toast = useToast()

// ─── State ─────────────────────────────────────────────────────
const rows = ref([])
const total = ref(0)
const loading = ref(false)
const branches = ref([])
const departments = ref([])
const positions = ref([])
const workLocations = ref([])
const options = ref({ transfer_types: [], reasons: [], statuses: [] })

const filters = reactive({
  q: '',
  status: '',
  transfer_type: '',
  employee_id: '',
  page: 1,
  per_page: 20,
})

const showForm = ref(false)
const editing = ref(null)
const showDetail = ref(null)
const detailLoading = ref(false)

const showRejectFor = ref(null)
const rejectReason = ref('')
const showCancelFor = ref(null)
const cancelReason = ref('')

// ─── Permissions ───────────────────────────────────────────────
const canCreate    = computed(() => auth.can('employee_transfer.create'))
const canApprove   = computed(() => auth.can('employee_transfer.approve'))
const canImplement = computed(() => auth.can('employee_transfer.implement'))
const canView      = computed(() => auth.can('employee_transfer.view') || canCreate.value || canApprove.value)

// ─── Lookup metadata ──────────────────────────────────────────
const STATUS_BADGE = {
  DRAFT:       'bg-gray-100 text-gray-700',
  SUBMITTED:   'bg-amber-100 text-amber-800',
  APPROVED:    'bg-emerald-100 text-emerald-800',
  IMPLEMENTED: 'bg-emerald-200 text-emerald-900 font-semibold',
  REJECTED:    'bg-red-100 text-red-800',
  CANCELLED:   'bg-gray-100 text-gray-500',
}
function statusLabel(s) {
  return options.value.statuses.find(x => x.code === s)?.label || s
}
function typeLabel(t) {
  return options.value.transfer_types.find(x => x.code === t)?.label || t
}
function reasonLabel(r) {
  return options.value.reasons.find(x => x.code === r)?.label || r
}

const totalPages = computed(() => Math.ceil(total.value / filters.per_page) || 1)

// ─── Data fetching ─────────────────────────────────────────────
async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/employee-transfers', { params: { ...filters } })
    rows.value = res.data.data || []
    total.value = res.data.meta?.total || 0
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat data mutasi')
  } finally {
    loading.value = false
  }
}

async function fetchOptions() {
  try {
    const res = await api.get('/employee-transfers/options')
    options.value = res.data.data || { transfer_types: [], reasons: [], statuses: [] }
  } catch (_) { /* silent */ }
}

async function fetchMaster() {
  try {
    const [b, d, p, w] = await Promise.all([
      api.get('/branches',       { params: { per_page: 200 } }),
      api.get('/departments',    { params: { per_page: 200 } }),
      api.get('/positions',      { params: { per_page: 200 } }),
      api.get('/work-locations', { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
    ])
    branches.value      = b.data.data || []
    departments.value   = d.data.data || []
    positions.value     = p.data.data || []
    workLocations.value = w.data.data || []
  } catch (_) { /* silent */ }
}

onMounted(() => { fetchOptions(); fetchMaster(); fetchData() })
watch(() => [filters.status, filters.transfer_type, filters.employee_id, filters.page, filters.per_page],
  () => fetchData())

function applyFilter() { filters.page = 1; fetchData() }
function resetFilter() {
  Object.assign(filters, {
    q: '', status: '', transfer_type: '', employee_id: '', page: 1,
  })
  fetchData()
}

// ─── Form ──────────────────────────────────────────────────────
function newDraft() {
  return {
    employee_id: '',
    transfer_type: 'BRANCH',
    reason: 'OPERATIONAL',
    reason_text: '',
    new_branch_id: '',
    new_department_id: '',
    new_position_id: '',
    new_work_location_id: '',
    effective_date: new Date(Date.now() + 14 * 86400 * 1000).toISOString().slice(0, 10),
    requires_consent: 0,
    submit: false,
  }
}

function openCreate() {
  editing.value = newDraft()
  showForm.value = true
}

async function save(submitNow = false) {
  if (! editing.value.employee_id) {
    toast.warning('Pilih karyawan dulu')
    return
  }
  const payload = { ...editing.value, submit: submitNow }
  try {
    const res = await api.post('/employee-transfers', payload)
    toast.success(submitNow ? 'Mutasi disubmit untuk approval' : 'Draft mutasi tersimpan')
    showForm.value = false
    editing.value = null
    fetchData()
    // Buka detail langsung supaya user lihat hasilnya
    if (res.data.data?.id) openDetail(res.data.data.id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}

// ─── Detail / actions ──────────────────────────────────────────
async function openDetail(id) {
  detailLoading.value = true
  try {
    const res = await api.get(`/employee-transfers/${id}`)
    showDetail.value = res.data.data
  } catch (e) {
    toast.error('Gagal memuat detail')
  } finally {
    detailLoading.value = false
  }
}

async function submitDraft(row) {
  const ok = await confirmDialog({
    title: 'Submit mutasi?',
    message: `Mutasi ${row.transfer_type} untuk ${row.employee_name} akan dikirim ke HR untuk approval.`,
    okText: 'Submit',
  })
  if (! ok) return
  try {
    await api.post(`/employee-transfers/${row.id}/submit`)
    toast.success('Disubmit')
    fetchData()
    if (showDetail.value?.id === row.id) openDetail(row.id)
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function approve(row) {
  const ok = await confirmDialog({
    title: 'Approve mutasi?',
    message: `Approve mutasi ${row.transfer_type} untuk ${row.employee_name}? Setelah ini status menjadi APPROVED dan tinggal menunggu effective date untuk di-apply.`,
    okText: 'Approve', variant: 'success',
  })
  if (! ok) return
  try {
    await api.post(`/employee-transfers/${row.id}/approve`)
    toast.success('Mutasi di-approve')
    fetchData()
    if (showDetail.value?.id === row.id) openDetail(row.id)
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function rejectSubmit() {
  if (! showRejectFor.value) return
  if (rejectReason.value.trim().length < 10) {
    toast.warning('Alasan minimal 10 karakter')
    return
  }
  try {
    await api.post(`/employee-transfers/${showRejectFor.value.id}/reject`,
      { reason: rejectReason.value })
    toast.success('Mutasi di-reject')
    showRejectFor.value = null
    rejectReason.value = ''
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function cancelSubmit() {
  if (! showCancelFor.value) return
  if (cancelReason.value.trim().length < 10) {
    toast.warning('Alasan minimal 10 karakter')
    return
  }
  try {
    await api.post(`/employee-transfers/${showCancelFor.value.id}/cancel`,
      { reason: cancelReason.value })
    toast.success('Mutasi dibatalkan')
    showCancelFor.value = null
    cancelReason.value = ''
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function implement(row) {
  const ok = await confirmDialog({
    title: 'Apply mutasi sekarang?',
    message: `Mutasi ini akan langsung di-apply ke records ${row.employee_name}: branch/department/posisi diperbarui sesuai data mutasi. Allowance & manager_id akan ikut di-recompute.`,
    okText: 'Apply Sekarang', variant: 'warning',
  })
  if (! ok) return
  try {
    await api.post(`/employee-transfers/${row.id}/implement`)
    toast.success('Mutasi di-apply ke records karyawan')
    fetchData()
    if (showDetail.value?.id === row.id) openDetail(row.id)
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal apply') }
}

// ─── Helpers untuk badge change indicator ──────────────────────
function isChanged(oldVal, newVal) {
  if (newVal === null || newVal === undefined || newVal === '') return false
  // eslint-disable-next-line eqeqeq
  return oldVal != newVal
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ArrowsRightLeftIcon class="w-6 h-6 text-primary-600" />
          Mutasi Karyawan
        </h1>
        <p class="text-sm text-gray-500">
          Pemindahan karyawan antar cabang, department, posisi, atau lokasi kerja
          tanpa perubahan grade/upah. Untuk promosi/demosi gunakan halaman JIC.
        </p>
      </div>
      <button v-if="canCreate" class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> Mutasi Baru
      </button>
    </div>

    <!-- Filters -->
    <div class="card card-body mb-4">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <div class="md:col-span-2">
          <label class="label">Karyawan</label>
          <EmployeeSearchSelect
            v-model="filters.employee_id"
            placeholder="— Semua —"
            :only-active="false"
          />
        </div>
        <div>
          <label class="label">Status</label>
          <SearchableSelect
            v-model="filters.status"
            :items="options.statuses"
            value-key="code"
            label-key="label"
            placeholder="Semua status"
          />
        </div>
        <div>
          <label class="label">Tipe</label>
          <SearchableSelect
            v-model="filters.transfer_type"
            :items="options.transfer_types"
            value-key="code"
            label-key="label"
            placeholder="Semua tipe"
          />
        </div>
        <div class="flex items-end gap-2">
          <button class="btn-secondary flex-1" @click="resetFilter" title="Reset filter">↺</button>
        </div>
      </div>
    </div>

    <!-- List -->
    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>No. Mutasi</th>
              <th>Karyawan</th>
              <th>Tipe</th>
              <th>Dari → Ke</th>
              <th>Effective</th>
              <th>Status</th>
              <th class="text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="7" class="text-center py-8 text-gray-400">Memuat…</td></tr>
            <tr v-else-if="rows.length === 0">
              <td colspan="7" class="text-center py-12 text-gray-400">
                Belum ada pengajuan mutasi. Klik <strong>Mutasi Baru</strong> untuk mulai.
              </td>
            </tr>
            <tr v-for="r in rows" :key="r.id" class="align-top">
              <td>
                <div class="font-mono text-xs">{{ r.transfer_number || '(draft)' }}</div>
                <div class="text-[10px] text-gray-400">{{ fmtDate(r.created_at) }}</div>
              </td>
              <td>
                <div class="font-medium">{{ r.employee_name }}</div>
                <div class="text-xs text-gray-500">{{ r.employee_number }}</div>
              </td>
              <td>
                <div class="text-xs">{{ typeLabel(r.transfer_type) }}</div>
                <div class="text-[10px] text-gray-400">{{ reasonLabel(r.reason) }}</div>
              </td>
              <td class="text-xs leading-tight">
                <div v-if="r.transfer_type === 'BRANCH' || r.transfer_type === 'COMBINED'"
                  class="flex items-center gap-1">
                  <BuildingStorefrontIcon class="w-3.5 h-3.5 text-gray-400" />
                  <span class="text-gray-500">{{ r.old_branch_name || '-' }}</span>
                  <span class="mx-0.5">→</span>
                  <span class="font-medium">{{ r.new_branch_name || '-' }}</span>
                </div>
                <div v-if="r.transfer_type === 'DEPARTMENT' || r.transfer_type === 'COMBINED'"
                  class="flex items-center gap-1 mt-0.5">
                  <BuildingOffice2Icon class="w-3.5 h-3.5 text-gray-400" />
                  <span class="text-gray-500">{{ r.old_department_name || '-' }}</span>
                  <span class="mx-0.5">→</span>
                  <span class="font-medium">{{ r.new_department_name || '-' }}</span>
                </div>
                <div v-if="r.transfer_type === 'POSITION' || r.transfer_type === 'COMBINED'"
                  class="flex items-center gap-1 mt-0.5">
                  <BriefcaseIcon class="w-3.5 h-3.5 text-gray-400" />
                  <span class="text-gray-500">{{ r.old_position_name || '-' }}</span>
                  <span class="mx-0.5">→</span>
                  <span class="font-medium">{{ r.new_position_name || '-' }}</span>
                </div>
                <div v-if="r.transfer_type === 'WORK_LOCATION'"
                  class="flex items-center gap-1">
                  <MapPinIcon class="w-3.5 h-3.5 text-gray-400" />
                  <span class="text-gray-500">{{ r.old_work_location_name || '-' }}</span>
                  <span class="mx-0.5">→</span>
                  <span class="font-medium">{{ r.new_work_location_name || '-' }}</span>
                </div>
              </td>
              <td class="text-xs">{{ fmtDate(r.effective_date) }}</td>
              <td>
                <span class="px-2 py-0.5 rounded text-xs" :class="STATUS_BADGE[r.status]">
                  {{ statusLabel(r.status) }}
                </span>
                <div v-if="r.requires_consent && r.consent_status === 'PENDING'"
                  class="text-[10px] text-amber-600 mt-1">
                  Menunggu consent karyawan
                </div>
              </td>
              <td class="text-right">
                <div class="flex items-center justify-end gap-1">
                  <button class="text-gray-500 hover:text-primary-600 p-1"
                    title="Lihat detail" @click="openDetail(r.id)">
                    <EyeIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canCreate && r.status === 'DRAFT'"
                    class="text-blue-600 hover:text-blue-800 p-1"
                    title="Submit" @click="submitDraft(r)">
                    <PaperAirplaneIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canApprove && r.status === 'SUBMITTED'"
                    class="text-emerald-600 hover:text-emerald-800 p-1"
                    title="Approve" @click="approve(r)">
                    <CheckCircleIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canApprove && r.status === 'SUBMITTED'"
                    class="text-red-500 hover:text-red-700 p-1"
                    title="Reject"
                    @click="showRejectFor = r; rejectReason = ''">
                    <XCircleIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canImplement && r.status === 'APPROVED'"
                    class="text-amber-600 hover:text-amber-800 p-1"
                    title="Apply sekarang" @click="implement(r)">
                    <BoltIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canCreate && ['DRAFT','SUBMITTED','APPROVED'].includes(r.status)"
                    class="text-gray-400 hover:text-red-600 p-1"
                    title="Cancel"
                    @click="showCancelFor = r; cancelReason = ''">
                    <NoSymbolIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="total > filters.per_page" class="px-4 py-3 border-t flex justify-between text-sm">
        <span class="text-gray-500">
          Total {{ total }} mutasi · Halaman {{ filters.page }} / {{ totalPages }}
        </span>
        <div class="flex gap-2">
          <button :disabled="filters.page <= 1" class="btn-secondary px-3 py-1 disabled:opacity-50"
            @click="filters.page--">‹ Sebelumnya</button>
          <button :disabled="filters.page >= totalPages" class="btn-secondary px-3 py-1 disabled:opacity-50"
            @click="filters.page++">Berikutnya ›</button>
        </div>
      </div>
    </div>

    <!-- ── Form Modal ─────────────────────────────────────────── -->
    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showForm = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold flex items-center gap-2">
            <ArrowsRightLeftIcon class="w-5 h-5 text-primary-600" />
            Mutasi Karyawan Baru
          </h2>
          <button class="text-gray-500" @click="showForm = false">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-3">
          <div class="md:col-span-2">
            <label class="label">Karyawan *</label>
            <EmployeeSearchSelect
              v-model="editing.employee_id"
              placeholder="— Pilih karyawan —"
              :only-active="true"
            />
          </div>

          <div>
            <label class="label">Tipe Mutasi *</label>
            <SearchableSelect
              v-model="editing.transfer_type"
              :items="options.transfer_types"
              value-key="code"
              label-key="label"
              :clearable="false"
            />
          </div>
          <div>
            <label class="label">Alasan *</label>
            <SearchableSelect
              v-model="editing.reason"
              :items="options.reasons"
              value-key="code"
              label-key="label"
              :clearable="false"
            />
          </div>

          <!-- Tujuan: tampilkan field sesuai tipe -->
          <div v-if="['BRANCH','COMBINED'].includes(editing.transfer_type)" class="md:col-span-2">
            <label class="label">Cabang Tujuan</label>
            <SearchableSelect
              v-model="editing.new_branch_id"
              :items="branches"
              value-key="id"
              label-key="name"
              sub-label-key="city"
              :search-keys="['name','code','city']"
              placeholder="— Pilih cabang —"
            />
          </div>
          <div v-if="['DEPARTMENT','COMBINED'].includes(editing.transfer_type)" class="md:col-span-2">
            <label class="label">Department Tujuan</label>
            <SearchableSelect
              v-model="editing.new_department_id"
              :items="departments"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="— Pilih department —"
            />
          </div>
          <div v-if="['POSITION','COMBINED'].includes(editing.transfer_type)" class="md:col-span-2">
            <label class="label">Posisi Tujuan</label>
            <SearchableSelect
              v-model="editing.new_position_id"
              :items="positions"
              value-key="id"
              label-key="name"
              sub-label-key="code"
              :search-keys="['name','code']"
              placeholder="— Pilih posisi —"
            />
          </div>
          <div v-if="editing.transfer_type === 'WORK_LOCATION' && workLocations.length > 0"
            class="md:col-span-2">
            <label class="label">Lokasi Kerja Tujuan</label>
            <SearchableSelect
              v-model="editing.new_work_location_id"
              :items="workLocations"
              value-key="id"
              label-key="name"
              sub-label-key="address"
              :search-keys="['name','address']"
              placeholder="— Pilih lokasi kerja —"
            />
          </div>

          <div>
            <label class="label">Tanggal Berlaku *</label>
            <input v-model="editing.effective_date" type="date" class="input" />
          </div>
          <div class="flex items-end">
            <label class="flex items-center gap-2 text-sm cursor-pointer">
              <input type="checkbox"
                :checked="!! editing.requires_consent"
                @change="editing.requires_consent = $event.target.checked ? 1 : 0" />
              <span>Perlu surat persetujuan karyawan</span>
            </label>
          </div>

          <div class="md:col-span-2">
            <label class="label">Catatan / Alasan Detail</label>
            <textarea v-model="editing.reason_text" class="input" rows="3"
              placeholder="Latar belakang & justifikasi mutasi"></textarea>
          </div>
        </div>

        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showForm = false">Batal</button>
          <button class="btn-secondary" @click="save(false)">Simpan Draft</button>
          <button class="btn-primary" @click="save(true)">Simpan & Submit</button>
        </div>
      </div>
    </div>

    <!-- ── Detail Modal ───────────────────────────────────────── -->
    <div v-if="showDetail" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showDetail = null">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <div>
            <h2 class="font-semibold">{{ showDetail.transfer_number || 'Draft Mutasi' }}</h2>
            <p class="text-xs text-gray-500">
              {{ showDetail.employee_name }} ({{ showDetail.employee_number }})
            </p>
          </div>
          <button class="text-gray-500" @click="showDetail = null">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 space-y-4 text-sm">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <div class="text-xs text-gray-500">Status</div>
              <span class="px-2 py-0.5 rounded text-xs inline-block mt-1"
                :class="STATUS_BADGE[showDetail.status]">
                {{ statusLabel(showDetail.status) }}
              </span>
            </div>
            <div>
              <div class="text-xs text-gray-500">Tipe</div>
              <div>{{ typeLabel(showDetail.transfer_type) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">Alasan</div>
              <div>{{ reasonLabel(showDetail.reason) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">Effective Date</div>
              <div>{{ fmtDate(showDetail.effective_date) }}</div>
            </div>
          </div>

          <div v-if="showDetail.reason_text" class="bg-gray-50 rounded p-3 text-xs">
            <div class="text-gray-500 mb-1">Catatan</div>
            <div class="whitespace-pre-line">{{ showDetail.reason_text }}</div>
          </div>

          <!-- Comparison table -->
          <div class="border rounded overflow-hidden">
            <div class="bg-gray-50 px-3 py-2 text-xs font-semibold uppercase text-gray-600">
              Perubahan Posisi
            </div>
            <table class="w-full text-sm">
              <thead class="bg-gray-50 text-xs">
                <tr>
                  <th class="text-left px-3 py-1">Field</th>
                  <th class="text-left px-3 py-1 text-gray-500">Sebelum</th>
                  <th class="text-left px-3 py-1 text-emerald-700">Sesudah</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <tr v-if="showDetail.new_branch_id">
                  <td class="px-3 py-1.5">Cabang</td>
                  <td class="px-3 py-1.5 text-gray-500">{{ showDetail.old_branch_name || '-' }}</td>
                  <td class="px-3 py-1.5"
                    :class="isChanged(showDetail.old_branch_id, showDetail.new_branch_id) ? 'font-semibold text-emerald-700' : ''">
                    {{ showDetail.new_branch_name || '-' }}
                  </td>
                </tr>
                <tr v-if="showDetail.new_department_id">
                  <td class="px-3 py-1.5">Department</td>
                  <td class="px-3 py-1.5 text-gray-500">{{ showDetail.old_department_name || '-' }}</td>
                  <td class="px-3 py-1.5"
                    :class="isChanged(showDetail.old_department_id, showDetail.new_department_id) ? 'font-semibold text-emerald-700' : ''">
                    {{ showDetail.new_department_name || '-' }}
                  </td>
                </tr>
                <tr v-if="showDetail.new_position_id">
                  <td class="px-3 py-1.5">Posisi</td>
                  <td class="px-3 py-1.5 text-gray-500">{{ showDetail.old_position_name || '-' }}</td>
                  <td class="px-3 py-1.5"
                    :class="isChanged(showDetail.old_position_id, showDetail.new_position_id) ? 'font-semibold text-emerald-700' : ''">
                    {{ showDetail.new_position_name || '-' }}
                  </td>
                </tr>
                <tr v-if="showDetail.new_work_location_id">
                  <td class="px-3 py-1.5">Lokasi Kerja</td>
                  <td class="px-3 py-1.5 text-gray-500">{{ showDetail.old_work_location_name || '-' }}</td>
                  <td class="px-3 py-1.5"
                    :class="isChanged(showDetail.old_work_location_id, showDetail.new_work_location_id) ? 'font-semibold text-emerald-700' : ''">
                    {{ showDetail.new_work_location_name || '-' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Workflow history -->
          <div class="text-xs space-y-1">
            <div v-if="showDetail.submitted_at" class="text-gray-600">
              Disubmit: {{ fmtDate(showDetail.submitted_at) }}
              <span v-if="showDetail.creator_name"> oleh {{ showDetail.creator_name }}</span>
            </div>
            <div v-if="showDetail.approved_at" class="text-emerald-700">
              Approved: {{ fmtDate(showDetail.approved_at) }}
              <span v-if="showDetail.approver_name"> oleh {{ showDetail.approver_name }}</span>
              <span v-if="showDetail.approver_note"> — "{{ showDetail.approver_note }}"</span>
            </div>
            <div v-if="showDetail.implemented_at" class="text-emerald-800">
              Diimplementasikan: {{ fmtDate(showDetail.implemented_at) }}
              <span v-if="showDetail.allowance_recomputed">(allowance ter-recompute)</span>
            </div>
            <div v-if="showDetail.rejection_reason" class="text-red-700">
              Rejected: {{ showDetail.rejection_reason }}
            </div>
            <div v-if="showDetail.cancellation_reason" class="text-gray-500">
              Cancelled: {{ showDetail.cancellation_reason }}
            </div>
          </div>
        </div>

        <div class="px-5 py-3 border-t flex justify-end gap-2 flex-wrap">
          <button class="btn-secondary" @click="showDetail = null">Tutup</button>
          <button v-if="canCreate && showDetail.status === 'DRAFT'"
            class="btn-primary" @click="submitDraft(showDetail)">
            <PaperAirplaneIcon class="w-4 h-4" /> Submit
          </button>
          <button v-if="canApprove && showDetail.status === 'SUBMITTED'"
            class="btn-primary" @click="approve(showDetail)">
            <CheckCircleIcon class="w-4 h-4" /> Approve
          </button>
          <button v-if="canApprove && showDetail.status === 'SUBMITTED'"
            class="px-4 py-2 rounded bg-red-100 hover:bg-red-200 text-red-700"
            @click="showRejectFor = showDetail; rejectReason = ''">
            <XCircleIcon class="w-4 h-4 inline" /> Reject
          </button>
          <button v-if="canImplement && showDetail.status === 'APPROVED'"
            class="px-4 py-2 rounded bg-amber-500 hover:bg-amber-600 text-white"
            @click="implement(showDetail)">
            <BoltIcon class="w-4 h-4 inline" /> Apply Sekarang
          </button>
        </div>
      </div>
    </div>

    <!-- ── Reject Modal ───────────────────────────────────────── -->
    <div v-if="showRejectFor" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b">
          <h3 class="font-semibold">Reject Mutasi</h3>
          <p class="text-xs text-gray-500">{{ showRejectFor.transfer_number }} — {{ showRejectFor.employee_name }}</p>
        </div>
        <div class="p-5 space-y-2">
          <label class="label">Alasan reject (min 10 karakter)</label>
          <textarea v-model="rejectReason" class="input" rows="3"
            placeholder="Mis. Slot di branch tujuan sudah penuh; tunggu Q3"></textarea>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showRejectFor = null; rejectReason = ''">Batal</button>
          <button class="px-4 py-2 rounded bg-red-600 hover:bg-red-700 text-white" @click="rejectSubmit">
            Reject
          </button>
        </div>
      </div>
    </div>

    <!-- ── Cancel Modal ───────────────────────────────────────── -->
    <div v-if="showCancelFor" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b">
          <h3 class="font-semibold">Cancel Mutasi</h3>
          <p class="text-xs text-gray-500">{{ showCancelFor.transfer_number }} — {{ showCancelFor.employee_name }}</p>
        </div>
        <div class="p-5 space-y-2">
          <label class="label">Alasan pembatalan (min 10 karakter)</label>
          <textarea v-model="cancelReason" class="input" rows="3"
            placeholder="Mis. Karyawan resign sebelum effective date"></textarea>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showCancelFor = null; cancelReason = ''">Tutup</button>
          <button class="px-4 py-2 rounded bg-red-600 hover:bg-red-700 text-white" @click="cancelSubmit">
            Batalkan Mutasi
          </button>
        </div>
      </div>
    </div>
  </div>
</template>