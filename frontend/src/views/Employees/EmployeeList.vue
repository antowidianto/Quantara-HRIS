<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fmtDate } from '@/composables/useResource'
import { useAuthStore } from '@/stores/auth'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon, MagnifyingGlassIcon, PencilIcon, EyeIcon, TrashIcon,
  FunnelIcon, XMarkIcon, ArrowPathIcon, GiftIcon,
} from '@heroicons/vue/24/outline'
import EmployeeModal from './EmployeeModal.vue'
import SearchableSelect from '@/components/SearchableSelect.vue'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const toast = useToast()

// ──────────────────────────────────────────────────────────────────────────
// State — list + filters
// ──────────────────────────────────────────────────────────────────────────
const items = ref([])
const meta = ref({ total: 0, page: 1, per_page: 20, pages: 1 })
const loading = ref(false)

// Semua filter dikumpulkan di satu objek supaya gampang di-reset.
// Tetap dipertahankan saat user pindah halaman pagination.
const filters = reactive({
  search: '',
  department_id: '',
  branch_id: '',
  position_id: '',
  job_grade_id: '',
  status: '',              // active|inactive|''
  employment_status: '',   // PKWTT|PKWT|PROBATION|HARIAN|MAGANG|OUTSOURCING
  employment_type: '',     // FULL_TIME|PART_TIME|CONTRACT|INTERN
  gender: '',              // M|F
})
const page = ref(1)
const perPage = ref(20)

// Toggle panel filter — collapsed default supaya layar bersih.
const showFilters = ref(false)

// Master dropdown options di-load sekali saat mount.
const departments = ref([])
const branches    = ref([])
const positions   = ref([])
const jobGrades   = ref([])

// ──────────────────────────────────────────────────────────────────────────
// Derived
// ──────────────────────────────────────────────────────────────────────────
const activeFilterCount = computed(() => {
  let n = 0
  if (filters.department_id) n++
  if (filters.branch_id) n++
  if (filters.position_id) n++
  if (filters.job_grade_id) n++
  if (filters.status) n++
  if (filters.employment_status) n++
  if (filters.employment_type) n++
  if (filters.gender) n++
  return n
})

// Position list di-narrow oleh dept yg dipilih supaya tidak overwhelming.
const filteredPositions = computed(() => {
  if (! filters.department_id) return positions.value
  return positions.value.filter(
    p => Number(p.department_id) === Number(filters.department_id),
  )
})

// ──────────────────────────────────────────────────────────────────────────
// Modal state
// ──────────────────────────────────────────────────────────────────────────
const modalOpen = ref(false)
const modalEmployeeId = ref(null)
const modalMode = ref('view')

function openCreate() {
  modalEmployeeId.value = null
  modalMode.value = 'edit'
  modalOpen.value = true
}
function openView(id) {
  modalEmployeeId.value = id
  modalMode.value = 'view'
  modalOpen.value = true
}
function openEdit(id) {
  modalEmployeeId.value = id
  modalMode.value = 'edit'
  modalOpen.value = true
}
function closeModal() {
  modalOpen.value = false
  modalEmployeeId.value = null
}
function onSaved() {
  fetch()
}

function syncFromRoute() {
  const path = route.path
  if (path.endsWith('/employees/new')) {
    openCreate()
  } else {
    const m = path.match(/\/employees\/(\d+)(\/edit)?$/)
    if (m) {
      modalEmployeeId.value = Number(m[1])
      modalMode.value = m[2] ? 'edit' : 'view'
      modalOpen.value = true
    }
  }
}

// ──────────────────────────────────────────────────────────────────────────
// API helpers
// ──────────────────────────────────────────────────────────────────────────
async function fetch() {
  loading.value = true
  try {
    // Hilangkan key kosong supaya URL bersih + backend tidak salah parse '0'.
    const params = { page: page.value, per_page: perPage.value }
    Object.entries(filters).forEach(([k, v]) => {
      if (v !== '' && v !== null && v !== undefined) params[k] = v
    })
    const res = await api.get('/employees', { params })
    items.value = res.data.data || []
    meta.value = res.data.meta || meta.value
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat data karyawan')
  } finally {
    loading.value = false
  }
}

async function loadFilterOptions() {
  // Master dropdown — di-load satu kali. Per-page besar supaya semua dapat.
  const opts = { params: { per_page: 200 } }
  const tasks = [
    api.get('/departments', opts).then(r => { departments.value = r.data.data || [] }).catch(() => {}),
    api.get('/branches',    opts).then(r => { branches.value    = r.data.data || [] }).catch(() => {}),
    api.get('/positions',   opts).then(r => { positions.value   = r.data.data || [] }).catch(() => {}),
    api.get('/job-grades',  opts).then(r => { jobGrades.value   = r.data.data || [] }).catch(() => {}),
  ]
  await Promise.allSettled(tasks)
}

async function removeEmployee(emp) {
  const ok = await confirmDialog({
    title: 'Nonaktifkan karyawan?',
    message: `${emp.full_name} (${emp.employee_number}) akan ditandai non-aktif.`,
    okText: 'Nonaktifkan',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete('/employees/' + emp.id)
    toast.success('Karyawan dinonaktifkan')
    fetch()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menonaktifkan')
  }
}

// ──────────────────────────────────────────────────────────────────────────
// Lifecycle + watchers
// ──────────────────────────────────────────────────────────────────────────
onMounted(async () => {
  await loadFilterOptions()
  fetch()
  syncFromRoute()
})

let timer = null
// Search di-debounce 300ms supaya tidak spamming saat user mengetik.
watch(() => filters.search, () => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => { page.value = 1; fetch() }, 300)
})

// Filter dropdown lainnya tidak perlu debounce.
watch(
  () => [
    filters.department_id, filters.branch_id, filters.position_id,
    filters.job_grade_id, filters.status, filters.employment_status,
    filters.employment_type, filters.gender,
  ],
  () => { page.value = 1; fetch() },
)

// Saat dept berubah, reset position kalau yang dipilih bukan lagi di dept itu.
watch(() => filters.department_id, () => {
  if (! filters.position_id) return
  const stillValid = filteredPositions.value.some(
    p => Number(p.id) === Number(filters.position_id),
  )
  if (! stillValid) filters.position_id = ''
})

watch(() => route.path, syncFromRoute)

function go(p) { page.value = p; fetch() }

function resetFilters() {
  filters.search = ''
  filters.department_id = ''
  filters.branch_id = ''
  filters.position_id = ''
  filters.job_grade_id = ''
  filters.status = ''
  filters.employment_status = ''
  filters.employment_type = ''
  filters.gender = ''
  page.value = 1
  fetch()
}

// Mapping nilai untuk badge employment_status warna-warni.
function statusBadgeClass(s) {
  switch (s) {
    case 'PKWTT':      return 'bg-emerald-50 text-emerald-700 border-emerald-200'
    case 'PKWT':       return 'bg-blue-50 text-blue-700 border-blue-200'
    case 'PROBATION':  return 'bg-amber-50 text-amber-700 border-amber-200'
    case 'HARIAN':     return 'bg-slate-50 text-slate-700 border-slate-200'
    case 'MAGANG':     return 'bg-purple-50 text-purple-700 border-purple-200'
    case 'OUTSOURCING':return 'bg-pink-50 text-pink-700 border-pink-200'
    default:           return 'bg-gray-50 text-gray-700 border-gray-200'
  }
}

/**
 * Bikin inisial dari nama lengkap untuk avatar fallback.
 * "Agus Saputra" → "AS", "Eko" → "E".
 */
function getInitials(name) {
  if (! name) return '?'
  return name.split(/\s+/).filter(Boolean).slice(0, 2)
    .map(s => s[0]).join('').toUpperCase()
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-bold">Karyawan</h1>
        <p class="text-gray-500 text-sm">Kelola data karyawan perusahaan</p>
      </div>
      <button v-if="auth.can('employee.create')" class="btn-primary self-start md:self-auto" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> Tambah Karyawan
      </button>
    </div>

    <div class="card">
      <!-- Toolbar: search + filter toggle -->
      <div class="card-header flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div class="relative w-full md:w-96">
          <MagnifyingGlassIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input v-model="filters.search"
            placeholder="Cari nama, NIP, atau email..."
            class="input pl-9 pr-9 w-full" />
          <button v-if="filters.search" @click="filters.search = ''"
            class="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded hover:bg-gray-100 text-gray-400">
            <XMarkIcon class="w-4 h-4" />
          </button>
        </div>

        <div class="flex items-center gap-2">
          <button
            type="button"
            @click="showFilters = !showFilters"
            class="btn-secondary inline-flex items-center gap-1.5 relative"
            :class="showFilters ? 'ring-1 ring-primary-300 bg-primary-50 text-primary-700' : ''"
          >
            <FunnelIcon class="w-4 h-4" />
            <span>Filter</span>
            <span v-if="activeFilterCount > 0"
              class="ml-1 inline-flex items-center justify-center w-5 h-5 text-xs font-semibold rounded-full bg-primary-600 text-white">
              {{ activeFilterCount }}
            </span>
          </button>
          <button v-if="activeFilterCount > 0"
            type="button"
            @click="resetFilters"
            class="text-sm text-gray-500 hover:text-gray-700 inline-flex items-center gap-1">
            <ArrowPathIcon class="w-4 h-4" /> Reset
          </button>
        </div>
      </div>

      <!-- Filter panel -->
      <div v-if="showFilters" class="px-4 pb-4 pt-2 border-b border-gray-100 bg-gray-50/40">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <div>
            <label class="text-xs font-medium text-gray-600">Departemen</label>
            <SearchableSelect
              v-model="filters.department_id"
              :items="departments"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="Semua departemen"
              class="mt-1"
            />
          </div>
          <div>
            <label class="text-xs font-medium text-gray-600">Cabang</label>
            <SearchableSelect
              v-model="filters.branch_id"
              :items="branches"
              value-key="id"
              label-key="name"
              :search-keys="['name','code','city']"
              placeholder="Semua cabang"
              class="mt-1"
            />
          </div>
          <div>
            <label class="text-xs font-medium text-gray-600">Jabatan</label>
            <SearchableSelect
              v-model="filters.position_id"
              :items="filteredPositions"
              value-key="id"
              label-key="name"
              sub-label-key="department_name"
              :search-keys="['name','code','tier','department_name']"
              placeholder="Semua jabatan"
              class="mt-1"
            />
          </div>
          <div>
            <label class="text-xs font-medium text-gray-600">Job Grade</label>
            <SearchableSelect
              v-model="filters.job_grade_id"
              :items="jobGrades"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="Semua grade"
              class="mt-1"
            >
              <template #item="{ item }">
                <span class="block text-sm truncate">
                  {{ item.code ? `${item.code} — ${item.name}` : item.name }}
                </span>
              </template>
              <template #selected="{ item }">
                <span class="block truncate font-medium">
                  {{ item.code ? `${item.code} — ${item.name}` : item.name }}
                </span>
              </template>
            </SearchableSelect>
          </div>
          <div>
            <label class="text-xs font-medium text-gray-600">Tipe Karyawan</label>
            <select v-model="filters.employment_type" class="input mt-1">
              <option value="">Semua tipe</option>
              <option value="FULL_TIME">Full Time</option>
              <option value="PART_TIME">Part Time</option>
              <option value="CONTRACT">Contract</option>
              <option value="INTERN">Intern / Magang</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-medium text-gray-600">Status Kepegawaian</label>
            <select v-model="filters.employment_status" class="input mt-1">
              <option value="">Semua status</option>
              <option value="PKWTT">PKWTT (Tetap)</option>
              <option value="PKWT">PKWT (Kontrak)</option>
              <option value="PROBATION">Probation</option>
              <option value="HARIAN">Harian</option>
              <option value="MAGANG">Magang</option>
              <option value="OUTSOURCING">Outsourcing</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-medium text-gray-600">Aktif / Non-aktif</label>
            <select v-model="filters.status" class="input mt-1">
              <option value="">Semua</option>
              <option value="active">Aktif</option>
              <option value="inactive">Non-aktif</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-medium text-gray-600">Gender</label>
            <select v-model="filters.gender" class="input mt-1">
              <option value="">Semua</option>
              <option value="M">Laki-laki</option>
              <option value="F">Perempuan</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>NIP</th>
              <th>Nama</th>
              <th>Cabang</th>
              <th>Departemen</th>
              <th>Jabatan</th>
              <th>Grade</th>
              <th>Status</th>
              <th>Bergabung</th>
              <th class="text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="9" class="text-center py-8 text-gray-400">Memuat...</td></tr>
            <tr v-else-if="items.length === 0">
              <td colspan="9" class="text-center py-10 text-gray-400">
                <div class="flex flex-col items-center gap-2">
                  <span>Tidak ada karyawan yang cocok dengan filter.</span>
                  <button v-if="activeFilterCount > 0 || filters.search"
                    class="text-sm text-primary-600 hover:underline"
                    @click="resetFilters">Hapus semua filter</button>
                </div>
              </td>
            </tr>
            <tr v-for="e in items" :key="e.id"
                class="hover:bg-primary-50/30 cursor-pointer transition-colors"
                @click="openView(e.id)">
              <td class="font-mono text-xs text-gray-600">{{ e.employee_number }}</td>
              <td>
                <div class="flex items-center gap-3 min-w-0">
                  <div class="shrink-0 w-9 h-9 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 text-white flex items-center justify-center text-xs font-semibold overflow-hidden">
                    <img v-if="e.avatar_url" :src="e.avatar_url" :alt="e.full_name" class="w-9 h-9 rounded-full object-cover" />
                    <span v-else>{{ getInitials(e.full_name) }}</span>
                  </div>
                  <div class="min-w-0">
                    <div class="font-medium text-gray-900 truncate">{{ e.full_name }}</div>
                    <div class="text-xs text-gray-500 truncate">{{ e.email || '—' }}</div>
                  </div>
                </div>
              </td>
              <td class="text-sm text-gray-700">{{ e.branch_name || '—' }}</td>
              <td class="text-sm text-gray-700">{{ e.department_name || '—' }}</td>
              <td class="text-sm text-gray-700">{{ e.position_name || '—' }}</td>
              <td>
                <span v-if="e.job_grade_code"
                      class="inline-flex items-center px-2 py-0.5 rounded font-mono text-xs bg-slate-100 text-slate-700 border border-slate-200">
                  {{ e.job_grade_code }}
                </span>
                <span v-else class="text-gray-300">—</span>
              </td>
              <td>
                <div class="flex flex-col gap-1">
                  <span :class="['inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full border font-medium w-fit',
                                 e.is_active
                                   ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                   : 'bg-gray-100 text-gray-500 border-gray-200']">
                    <span :class="e.is_active ? 'w-1.5 h-1.5 rounded-full bg-emerald-500' : 'w-1.5 h-1.5 rounded-full bg-gray-400'"></span>
                    {{ e.is_active ? 'Aktif' : 'Non-aktif' }}
                  </span>
                  <span :class="['text-xs px-2 py-0.5 rounded-full border font-medium w-fit',
                                 statusBadgeClass(e.employment_status)]">
                    {{ e.employment_status }}
                  </span>
                </div>
              </td>
              <td class="text-sm text-gray-700 whitespace-nowrap">{{ fmtDate(e.join_date) }}</td>
              <td class="text-right" @click.stop>
                <div class="inline-flex gap-1">
                  <button class="p-1.5 rounded hover:bg-primary-100 text-gray-500 hover:text-primary-600 transition"
                          @click="openView(e.id)" title="Detail">
                    <EyeIcon class="w-4 h-4" />
                  </button>
                  <button v-if="auth.can('bonus.view')"
                          class="p-1.5 rounded hover:bg-amber-100 text-gray-500 hover:text-amber-600 transition"
                          @click="$router.push(`/employees/${e.id}/bonus-history`)" title="Bonus history">
                    <GiftIcon class="w-4 h-4" />
                  </button>
                  <button v-if="auth.can('employee.update')"
                          class="p-1.5 rounded hover:bg-primary-100 text-gray-500 hover:text-primary-600 transition"
                          @click="openEdit(e.id)" title="Edit">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button v-if="auth.can('employee.delete')"
                          class="p-1.5 rounded hover:bg-red-100 text-gray-500 hover:text-red-600 transition"
                          @click="removeEmployee(e)" title="Nonaktifkan">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="card-body flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div class="text-sm text-gray-500">
          Total: <strong>{{ meta.total }}</strong> karyawan
          <span v-if="activeFilterCount > 0 || filters.search" class="text-gray-400">
            (filter aktif: {{ activeFilterCount + (filters.search ? 1 : 0) }})
          </span>
        </div>
        <div class="flex items-center gap-2">
          <button class="btn-secondary" :disabled="meta.page <= 1" @click="go(meta.page - 1)">Sebelumnya</button>
          <span class="px-3 py-2 text-sm">Halaman {{ meta.page }} / {{ meta.pages || 1 }}</span>
          <button class="btn-secondary" :disabled="meta.page >= meta.pages" @click="go(meta.page + 1)">Berikutnya</button>
        </div>
      </div>
    </div>

    <EmployeeModal
      v-if="modalOpen"
      :employee-id="modalEmployeeId"
      :mode="modalMode"
      @close="closeModal"
      @saved="onSaved"
    />
  </div>
</template>