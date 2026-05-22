<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { fmtDate, fmtDateTime } from '@/composables/useResource'
import SearchableSelect from '@/components/SearchableSelect.vue'

const toast = useToast()
const auth = useAuthStore()

const items = ref([])
const meta = ref({ total: 0, page: 1, per_page: 30, pages: 1 })
const branches = ref([])
const departments = ref([])
const loading = ref(false)

const filters = ref({
  start_date: '',
  end_date: '',
  branch_id: '',
  department_id: '',
  status: '',
  q: '',
})

const canCrossBranch = computed(() =>
  auth.isSuperAdmin || auth.permissions.includes('attendance.view_all_branches')
)

async function loadMasters() {
  try {
    const [bRes, dRes] = await Promise.all([
      api.get('/branches', { params: { per_page: 100 } }),
      api.get('/departments', { params: { per_page: 100 } }),
    ])
    branches.value = bRes.data.data || []
    departments.value = dRes.data.data || []
  } catch (_) { /* ignore */ }
}

async function fetchData(page = 1) {
  loading.value = true
  try {
    const params = { page, per_page: 30, ...filters.value }
    Object.keys(params).forEach(k => {
      if (params[k] === '' || params[k] === null) delete params[k]
    })
    const res = await api.get('/attendance', { params })
    items.value = res.data.data
    meta.value = res.data.meta
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat absensi')
  } finally {
    loading.value = false
  }
}

const statusBadge = {
  PRESENT:  'badge-green',
  LATE:     'badge-yellow',
  ABSENT:   'badge-red',
  LEAVE:    'badge-blue',
  HOLIDAY:  'badge-blue',
  SICK:     'badge-yellow',
  HALF_DAY: 'badge-gray',
}

onMounted(async () => {
  await loadMasters()
  // Default ke bulan berjalan
  filters.value.start_date = new Date(new Date().setDate(1)).toISOString().slice(0, 10)
  filters.value.end_date = new Date().toISOString().slice(0, 10)
  fetchData()
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-bold">Absensi Karyawan</h1>
        <p class="text-sm text-gray-500">
          <span v-if="auth.isSuperAdmin">Mode super admin: lintas perusahaan & cabang.</span>
          <span v-else-if="canCrossBranch">Anda dapat melihat absensi seluruh cabang dalam {{ auth.company?.name }}.</span>
          <span v-else>
            Anda hanya melihat absensi cabang Anda sendiri ({{ auth.branch?.name || '—' }}).
          </span>
        </p>
      </div>
      <RouterLink to="/attendance/change-requests/approvals" class="btn-secondary">
        Kelola Pengajuan Perubahan
      </RouterLink>
    </div>

    <div class="card">
      <div class="card-header gap-3 flex-wrap">
        <input v-model="filters.start_date" type="date" class="input w-44" />
        <input v-model="filters.end_date" type="date" class="input w-44" />
        <div v-if="canCrossBranch" class="w-56">
          <SearchableSelect
            v-model="filters.branch_id"
            :items="branches"
            value-key="id"
            label-key="name"
            :search-keys="['name','code','city']"
            placeholder="Semua cabang"
          />
        </div>
        <div class="w-56">
          <SearchableSelect
            v-model="filters.department_id"
            :items="departments"
            value-key="id"
            label-key="name"
            :search-keys="['name','code']"
            placeholder="Semua departemen"
          />
        </div>
        <select v-model="filters.status" class="input w-44">
          <option value="">Semua status</option>
          <option value="PRESENT">Hadir</option>
          <option value="LATE">Terlambat</option>
          <option value="ABSENT">Tanpa Kabar</option>
          <option value="LEAVE">Cuti</option>
          <option value="SICK">Sakit</option>
          <option value="HOLIDAY">Libur</option>
          <option value="HALF_DAY">Setengah Hari</option>
        </select>
        <input v-model="filters.q" type="text" placeholder="Nama / NIK karyawan" class="input w-56"
          @keyup.enter="fetchData(1)" />
        <button class="btn-primary" @click="fetchData(1)" :disabled="loading">Filter</button>
      </div>

      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>Karyawan</th>
              <th>Cabang</th>
              <th>Dept</th>
              <th>Clock In</th>
              <th>Clock Out</th>
              <th>Jam Kerja</th>
              <th>Telat</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="!loading && items.length === 0">
              <td colspan="9" class="text-center py-8 text-gray-400">Tidak ada data</td>
            </tr>
            <tr v-for="a in items" :key="a.id">
              <td>{{ fmtDate(a.work_date) }}</td>
              <td>
                <div class="font-medium">{{ a.employee_name }}</div>
                <div class="text-xs text-gray-500">{{ a.employee_number }}</div>
              </td>
              <td class="text-xs">{{ a.branch_name || '—' }}</td>
              <td class="text-xs">{{ a.department_name || '—' }}</td>
              <td>{{ a.clock_in_at ? fmtDateTime(a.clock_in_at) : '-' }}</td>
              <td>{{ a.clock_out_at ? fmtDateTime(a.clock_out_at) : '-' }}</td>
              <td>{{ Math.round((a.work_minutes || 0) / 6) / 10 }} jam</td>
              <td>{{ a.late_minutes }} menit</td>
              <td>
                <span :class="statusBadge[a.status] || 'badge-gray'">{{ a.status }}</span>
                <div v-if="a.is_corrected" class="text-[10px] text-amber-700 mt-0.5">⚐ dikoreksi</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="meta.pages > 1" class="px-4 py-3 flex items-center justify-between border-t border-gray-100 text-sm">
        <span class="text-gray-500">Total: {{ meta.total }}</span>
        <div class="flex gap-1">
          <button class="btn-secondary" :disabled="meta.page <= 1" @click="fetchData(meta.page - 1)">‹</button>
          <span class="px-3 py-1">Hal {{ meta.page }} / {{ meta.pages }}</span>
          <button class="btn-secondary" :disabled="meta.page >= meta.pages" @click="fetchData(meta.page + 1)">›</button>
        </div>
      </div>
    </div>
  </div>
</template>