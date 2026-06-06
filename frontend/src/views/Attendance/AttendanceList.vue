<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { fmtDate, fmtDateTime } from '@/composables/useResource'
import SearchableSelect from '@/components/SearchableSelect.vue'
import ExcelImporter from '@/components/ExcelImporter.vue'
import { useI18n } from '@/locales'

const toast = useToast()
const auth = useAuthStore()
const { t } = useI18n()

const items = ref([])
const meta = ref({ total: 0, page: 1, per_page: 30, pages: 1 })
const perPage = ref(30)
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
    const params = { page, per_page: perPage.value, ...filters.value }
    Object.keys(params).forEach(k => {
      if (params[k] === '' || params[k] === null) delete params[k]
    })
    const res = await api.get('/attendance', { params })
    items.value = res.data.data
    meta.value = res.data.meta
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.list.load_failed'))
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
        <h1 class="text-2xl font-bold">{{ t('attendance.list.title') }}</h1>
        <p class="text-sm text-gray-500">
          <span v-if="auth.isSuperAdmin">{{ t('attendance.list.mode_super') }}</span>
          <span v-else-if="canCrossBranch">{{ t('attendance.list.mode_cross', { company: auth.company?.name }) }}</span>
          <span v-else>
            {{ t('attendance.list.mode_branch', { branch: auth.branch?.name || '—' }) }}
          </span>
        </p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <!-- Sprint 3 — bulk import attendance backfill dari xlsx -->
        <ExcelImporter
          v-if="auth.can('attendances.bulk_import')"
          resource="attendances"
          :button-label="t('attendance.list.import_button')"
          @committed="fetchData()"
        />
        <RouterLink to="/attendance/change-requests/approvals" class="btn-secondary">
          {{ t('attendance.list.manage_changes') }}
        </RouterLink>
      </div>
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
            :placeholder="t('attendance.list.all_branches')"
          />
        </div>
        <div class="w-56">
          <SearchableSelect
            v-model="filters.department_id"
            :items="departments"
            value-key="id"
            label-key="name"
            :search-keys="['name','code']"
            :placeholder="t('attendance.list.all_departments')"
          />
        </div>
        <select v-model="filters.status" class="input w-44">
          <option value="">{{ t('attendance.list.all_status') }}</option>
          <option value="PRESENT">{{ t('attendance.list.st_present') }}</option>
          <option value="LATE">{{ t('attendance.list.st_late') }}</option>
          <option value="ABSENT">{{ t('attendance.list.st_absent') }}</option>
          <option value="LEAVE">{{ t('attendance.list.st_leave') }}</option>
          <option value="SICK">{{ t('attendance.list.st_sick') }}</option>
          <option value="HOLIDAY">{{ t('attendance.list.st_holiday') }}</option>
          <option value="HALF_DAY">{{ t('attendance.list.st_half_day') }}</option>
        </select>
        <input v-model="filters.q" type="text" :placeholder="t('attendance.list.search_emp')" class="input w-56"
          @keyup.enter="fetchData(1)" />
        <button class="btn-primary" @click="fetchData(1)" :disabled="loading">{{ t('common.filter') }}</button>
      </div>

      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('attendance.list.col_date') }}</th>
              <th>{{ t('attendance.list.col_employee') }}</th>
              <th>{{ t('attendance.list.col_branch') }}</th>
              <th>{{ t('attendance.list.col_dept') }}</th>
              <th>{{ t('attendance.list.col_clock_in') }}</th>
              <th>{{ t('attendance.list.col_clock_out') }}</th>
              <th>{{ t('attendance.list.col_work_hours') }}</th>
              <th>{{ t('attendance.list.col_late') }}</th>
              <th>{{ t('attendance.list.col_status') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="!loading && items.length === 0">
              <td colspan="9" class="text-center py-8 text-gray-400">{{ t('attendance.list.no_data') }}</td>
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
              <td>{{ Math.round((a.work_minutes || 0) / 6) / 10 }} {{ t('attendance.list.hours_unit') }}</td>
              <td>{{ a.late_minutes }} {{ t('attendance.list.minutes_unit') }}</td>
              <td>
                <span :class="statusBadge[a.status] || 'badge-gray'">{{ a.status }}</span>
                <div v-if="a.is_corrected" class="text-[10px] text-amber-700 mt-0.5">{{ t('attendance.list.corrected') }}</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="px-4 py-3 flex items-center justify-between border-t border-gray-100 text-sm">
        <span class="text-gray-500">{{ t('attendance.list.total', { n: meta.total }) }}</span>
        <div class="flex items-center gap-2">
          <label class="text-gray-500">{{ t('common.per_page') }}:</label>
          <select v-model.number="perPage" class="input py-1 w-20 text-sm" @change="fetchData(1)">
            <option :value="10">10</option>
            <option :value="20">20</option>
            <option :value="30">30</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
          <button class="btn-secondary" :disabled="meta.page <= 1" @click="fetchData(meta.page - 1)">‹</button>
          <span class="px-3 py-1">{{ t('attendance.list.page_of', { page: meta.page, pages: meta.pages }) }}</span>
          <button class="btn-secondary" :disabled="meta.page >= meta.pages" @click="fetchData(meta.page + 1)">›</button>
        </div>
      </div>
    </div>
  </div>
</template>