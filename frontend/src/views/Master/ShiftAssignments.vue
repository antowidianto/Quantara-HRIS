<script setup>
import { ref, reactive, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { PlusIcon, TrashIcon, ClockIcon, BeakerIcon } from '@heroicons/vue/24/outline'
import SearchableSelect from '@/components/SearchableSelect.vue'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'

/**
 * Aturan Shift per Scope.
 *
 * Setiap rule memetakan satu shift ke kombinasi scope (branch / department /
 * work_location / job_grade). Saat sistem perlu menentukan shift karyawan
 * (mis. clock-in atau roster), `ShiftResolver` di backend memilih rule paling
 * spesifik yang cocok.
 */

const toast = useToast()

const items = ref([])
const loading = ref(false)
const filters = reactive({ shift_id: '', branch_id: '', department_id: '', is_active: '' })

const shifts = ref([])
const branches = ref([])
const departments = ref([])
const workLocations = ref([])
const jobGrades = ref([])

const showCreate = ref(false)
const form = reactive(emptyForm())

// Preview tester — cek shift yang akan digunakan untuk employee+date.
const employees = ref([])
const preview = reactive({ employee_id: '', date: new Date().toISOString().slice(0, 10) })
const previewResult = ref(null)
const previewLoading = ref(false)

function emptyForm() {
  return {
    shift_id: '',
    branch_id: '',
    department_id: '',
    work_location_id: '',
    job_grade_id: '',
    priority: 100,
    effective_from: '',
    effective_to: '',
    is_active: 1,
    notes: '',
  }
}

async function fetchData() {
  loading.value = true
  try {
    const params = {}
    Object.entries(filters).forEach(([k, v]) => { if (v !== '') params[k] = v })
    const res = await api.get('/shift-assignments', { params })
    items.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat data')
  } finally {
    loading.value = false
  }
}

async function loadMasters() {
  try {
    // Karyawan TIDAK lagi di-load semua sekaligus — picker preview pakai
    // EmployeeSearchSelect server-side untuk dataset besar.
    const [s, b, d, w, g] = await Promise.all([
      api.get('/shifts', { params: { per_page: 200 } }).catch(() => null),
      api.get('/branches', { params: { per_page: 200 } }).catch(() => null),
      api.get('/departments', { params: { per_page: 200 } }).catch(() => null),
      api.get('/work-locations', { params: { per_page: 200 } }).catch(() => null),
      api.get('/job-grades', { params: { per_page: 200 } }).catch(() => null),
    ])
    shifts.value = s?.data?.data || []
    branches.value = b?.data?.data || []
    departments.value = d?.data?.data || []
    workLocations.value = w?.data?.data || []
    jobGrades.value = g?.data?.data || []
  } catch (_) { /* silent */ }
}

onMounted(() => {
  fetchData()
  loadMasters()
})

function openCreate() {
  Object.assign(form, emptyForm())
  showCreate.value = true
}

async function save() {
  if (! form.shift_id) {
    toast.warning('Pilih shift dulu')
    return
  }
  const hasScope = form.branch_id || form.department_id || form.work_location_id || form.job_grade_id
  if (! hasScope) {
    toast.warning('Pilih minimal satu scope (branch / department / work location / job grade).')
    return
  }
  try {
    const payload = { ...form }
    Object.keys(payload).forEach(k => { if (payload[k] === '') payload[k] = null })
    await api.post('/shift-assignments', payload)
    toast.success('Aturan ditambahkan')
    showCreate.value = false
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}

async function remove(item) {
  const ok = await confirmDialog({
    title: 'Hapus Aturan',
    message: `Hapus aturan untuk shift "${item.shift_name}"?`,
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/shift-assignments/${item.id}`)
    toast.success('Berhasil dihapus')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

async function runPreview() {
  if (! preview.employee_id || ! preview.date) {
    toast.warning('Pilih karyawan & tanggal')
    return
  }
  previewLoading.value = true
  try {
    const res = await api.get('/shift-assignments/preview', { params: preview })
    previewResult.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal preview')
  } finally {
    previewLoading.value = false
  }
}

function scopeLabel(item) {
  const parts = []
  if (item.branch_name) parts.push(`Cabang: ${item.branch_name}`)
  if (item.work_location_name) parts.push(`Lokasi: ${item.work_location_name}`)
  if (item.department_name) parts.push(`Dept: ${item.department_name}`)
  if (item.grade_name) parts.push(`Grade: ${item.grade_name}`)
  return parts.length ? parts.join(' · ') : 'Company-wide'
}

function sourceLabel(s) {
  const map = {
    schedule: 'Jadwal harian (override)',
    employee: 'Default karyawan',
    pattern: 'Pola siklus',
    assignment: 'Rule scope',
    shift_default: 'Shift default',
    fallback: 'Fallback',
    none: 'Tidak ada shift',
  }
  return map[s] || s
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <ClockIcon class="w-6 h-6" /> Aturan Shift per Scope
      </h1>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> Tambah Aturan
      </button>
    </div>

    <!-- Preview tester -->
    <div class="card mb-4">
      <div class="card-header">
        <span class="font-medium flex items-center gap-2">
          <BeakerIcon class="w-5 h-5 text-primary-600" /> Cek Shift Karyawan
        </span>
      </div>
      <div class="card-body grid grid-cols-1 md:grid-cols-4 gap-3">
        <div>
          <label class="label">Karyawan</label>
          <EmployeeSearchSelect
            v-model="preview.employee_id"
            placeholder="— Pilih karyawan —"
            :only-active="true"
          />
        </div>
        <div>
          <label class="label">Tanggal</label>
          <input v-model="preview.date" type="date" class="input" />
        </div>
        <div class="flex items-end">
          <button class="btn-primary w-full" :disabled="previewLoading" @click="runPreview">
            {{ previewLoading ? 'Memproses…' : 'Cek' }}
          </button>
        </div>
        <div v-if="previewResult" class="md:col-span-1 bg-gray-50 rounded p-3 text-sm">
          <div v-if="previewResult.shift">
            <div class="font-semibold" :style="{ color: previewResult.shift.color || '#1f2937' }">
              {{ previewResult.shift.name }} ({{ String(previewResult.shift.start_time).slice(0,5) }}–{{ String(previewResult.shift.end_time).slice(0,5) }})
            </div>
          </div>
          <div v-else class="text-gray-500">Tidak ada shift</div>
          <div class="text-xs text-gray-500 mt-1">
            Sumber: <span class="font-medium">{{ sourceLabel(previewResult.source) }}</span>
            <template v-if="previewResult.is_off"> · OFF</template>
            <template v-if="previewResult.is_holiday"> · Libur</template>
          </div>
          <div class="text-xs text-gray-400 mt-1">{{ previewResult.reason }}</div>
        </div>
      </div>
    </div>

    <!-- Filter + List -->
    <div class="card">
      <div class="card-header grid grid-cols-1 md:grid-cols-4 gap-3 w-full">
        <SearchableSelect
          v-model="filters.shift_id"
          :items="shifts"
          value-key="id"
          label-key="name"
          :search-keys="['name','code']"
          placeholder="— Semua Shift —"
          @change="fetchData"
        />
        <SearchableSelect
          v-model="filters.branch_id"
          :items="branches"
          value-key="id"
          label-key="name"
          :search-keys="['name','code','city']"
          placeholder="— Semua Cabang —"
          @change="fetchData"
        />
        <SearchableSelect
          v-model="filters.department_id"
          :items="departments"
          value-key="id"
          label-key="name"
          :search-keys="['name','code']"
          placeholder="— Semua Departemen —"
          @change="fetchData"
        />
        <select v-model="filters.is_active" class="input" @change="fetchData">
          <option value="">— Status —</option>
          <option value="1">Aktif</option>
          <option value="0">Non-aktif</option>
        </select>
      </div>

      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Shift</th>
              <th>Scope</th>
              <th>Prioritas</th>
              <th>Berlaku</th>
              <th>Status</th>
              <th class="text-right w-20">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="6" class="text-center py-8 text-gray-400">Memuat…</td></tr>
            <tr v-else-if="items.length === 0"><td colspan="6" class="text-center py-8 text-gray-400">Belum ada aturan</td></tr>
            <tr v-for="item in items" :key="item.id">
              <td>
                <div class="font-medium">{{ item.shift_name }}</div>
                <div class="text-xs text-gray-500">
                  {{ String(item.start_time).slice(0,5) }}–{{ String(item.end_time).slice(0,5) }}
                </div>
              </td>
              <td class="text-sm">{{ scopeLabel(item) }}</td>
              <td class="text-center">{{ item.priority }}</td>
              <td class="text-sm text-gray-500">
                {{ item.effective_from || '-' }}<span v-if="item.effective_from || item.effective_to"> s/d </span>{{ item.effective_to || (item.effective_from ? '∞' : '-') }}
              </td>
              <td>
                <span :class="['px-2 py-0.5 rounded-full text-xs',
                  Number(item.is_active) === 1 ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'
                ]">
                  {{ Number(item.is_active) === 1 ? 'Aktif' : 'Non-aktif' }}
                </span>
              </td>
              <td class="text-right">
                <button class="text-gray-500 hover:text-red-600" @click="remove(item)">
                  <TrashIcon class="w-4 h-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create modal -->
    <div v-if="showCreate" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">Tambah Aturan Shift</h2>
          <button class="text-gray-500" @click="showCreate = false">&times;</button>
        </div>
        <form @submit.prevent="save" class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <label class="label">Shift *</label>
            <SearchableSelect
              v-model="form.shift_id"
              :items="shifts"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="— Pilih shift —"
            >
              <template #item="{ item }">
                <span class="block text-sm truncate">
                  {{ item.name }}
                  <span class="text-xs text-gray-500">
                    ({{ String(item.start_time).slice(0,5) }}–{{ String(item.end_time).slice(0,5) }})
                  </span>
                </span>
              </template>
              <template #selected="{ item }">
                <span class="block truncate font-medium">
                  {{ item.name }}
                  <span class="text-xs text-gray-500">
                    ({{ String(item.start_time).slice(0,5) }}–{{ String(item.end_time).slice(0,5) }})
                  </span>
                </span>
              </template>
            </SearchableSelect>
          </div>

          <div>
            <label class="label">Cabang</label>
            <SearchableSelect
              v-model="form.branch_id"
              :items="branches"
              value-key="id"
              label-key="name"
              :search-keys="['name','code','city']"
              placeholder="— Tidak dibatasi —"
            />
          </div>
          <div>
            <label class="label">Lokasi Kerja</label>
            <SearchableSelect
              v-model="form.work_location_id"
              :items="workLocations"
              value-key="id"
              label-key="name"
              :search-keys="['name','code','address']"
              placeholder="— Tidak dibatasi —"
            />
          </div>
          <div>
            <label class="label">Departemen</label>
            <SearchableSelect
              v-model="form.department_id"
              :items="departments"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="— Tidak dibatasi —"
            />
          </div>
          <div>
            <label class="label">Job Grade</label>
            <SearchableSelect
              v-model="form.job_grade_id"
              :items="jobGrades"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="— Tidak dibatasi —"
            />
          </div>

          <div>
            <label class="label">Berlaku Mulai</label>
            <input v-model="form.effective_from" type="date" class="input" />
          </div>
          <div>
            <label class="label">Berlaku Sampai (opsional)</label>
            <input v-model="form.effective_to" type="date" class="input" />
          </div>
          <div>
            <label class="label">Prioritas (kecil = tinggi)</label>
            <input v-model.number="form.priority" type="number" class="input" />
          </div>
          <div class="flex items-end">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.is_active" type="checkbox" :true-value="1" :false-value="0" class="w-4 h-4" />
              Aktif
            </label>
          </div>

          <div class="md:col-span-2">
            <label class="label">Catatan</label>
            <textarea v-model="form.notes" class="input" rows="2"></textarea>
          </div>

          <div class="md:col-span-2 flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showCreate = false">Batal</button>
            <button type="submit" class="btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>