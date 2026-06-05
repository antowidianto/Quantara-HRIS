<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import {
  CalendarDaysIcon, ArrowPathIcon,
  ChevronLeftIcon, ChevronRightIcon,
} from '@heroicons/vue/24/outline'
import SearchableSelect from '@/components/SearchableSelect.vue'
import { useI18n } from '@/locales'

/**
 * Kalender cuti tim — visualisasi siapa cuti di rentang tanggal.
 *
 * Berguna untuk manager / HR sebelum approve cuti supaya tidak bentrok
 * dengan rekan se-departemen.
 */

const toast = useToast()
const { t } = useI18n()

const today = new Date()
const filters = reactive({
  start_date: today.toISOString().slice(0, 10),
  end_date: addDays(today.toISOString().slice(0, 10), 30),
  branch_id: '',
  department_id: '',
})
const data = ref({ leaves: [], start_date: '', end_date: '' })
const loading = ref(false)

const branches = ref([])
const departments = ref([])

function addDays(date, days) {
  const d = new Date(date)
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

function shiftMonth(months) {
  const start = new Date(filters.start_date)
  const end = new Date(filters.end_date)
  start.setMonth(start.getMonth() + months)
  end.setMonth(end.getMonth() + months)
  filters.start_date = start.toISOString().slice(0, 10)
  filters.end_date = end.toISOString().slice(0, 10)
  fetchData()
}

async function fetchData() {
  loading.value = true
  try {
    const params = {}
    Object.entries(filters).forEach(([k, v]) => { if (v !== '') params[k] = v })
    const res = await api.get('/leaves/team-calendar', { params })
    data.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || t('leave.cal.load_failed'))
  } finally {
    loading.value = false
  }
}

async function loadMasters() {
  try {
    const [b, d] = await Promise.all([
      api.get('/branches', { params: { per_page: 200 } }).catch(() => null),
      api.get('/departments', { params: { per_page: 200 } }).catch(() => null),
    ])
    branches.value = b?.data?.data || []
    departments.value = d?.data?.data || []
  } catch (_) { /* silent */ }
}

onMounted(() => {
  loadMasters()
  fetchData()
})

// Build daftar tanggal dalam rentang untuk header kolom
const dates = computed(() => {
  const arr = []
  const start = new Date(data.value.start_date || filters.start_date)
  const end = new Date(data.value.end_date || filters.end_date)
  const cursor = new Date(start)
  while (cursor <= end) {
    arr.push(cursor.toISOString().slice(0, 10))
    cursor.setDate(cursor.getDate() + 1)
  }
  return arr
})

// Map employee → leaves[] untuk render swimlane
const swimlanes = computed(() => {
  const byEmp = new Map()
  for (const lv of data.value.leaves || []) {
    if (! byEmp.has(lv.employee_id)) {
      byEmp.set(lv.employee_id, {
        employee_id: lv.employee_id,
        employee_name: lv.employee_name,
        employee_number: lv.employee_number,
        department_name: lv.department_name,
        leaves: [],
      })
    }
    byEmp.get(lv.employee_id).leaves.push(lv)
  }
  return Array.from(byEmp.values())
})

function dayHeader(d) {
  const date = new Date(d)
  const dow = date.getDay()
  const labels = [
    t('leave.cal.day_sun'), t('leave.cal.day_mon'), t('leave.cal.day_tue'),
    t('leave.cal.day_wed'), t('leave.cal.day_thu'), t('leave.cal.day_fri'),
    t('leave.cal.day_sat'),
  ]
  return { dom: date.getDate(), dow: labels[dow], isWeekend: dow === 0 || dow === 6 }
}

function isLeaveOn(leave, date) {
  return leave.start_date <= date && leave.end_date >= date
}

function leaveColor(leave) {
  // PENDING = amber dashed, APPROVED = blue
  if (leave.status === 'PENDING') return 'bg-amber-200 text-amber-800 border-amber-400 border-dashed'
  return 'bg-blue-200 text-blue-800 border-blue-400'
}

function categoryLabel(c) {
  const map = { ANNUAL: 'CT', SICK: 'SK', MATERNITY: 'CM', PATERNITY: 'CA',
    MENSTRUAL: 'CH', MARRIAGE: 'CN', BEREAVEMENT: 'CD', HAJJ: 'CHJ',
    UNPAID: 'CTB', SPECIAL: 'CK' }
  return map[c] || c
}

function tooltip(leave) {
  return t('leave.cal.tooltip', {
    name: leave.employee_name, leave: leave.leave_name,
    from: leave.start_date, to: leave.end_date, days: leave.days, status: leave.status,
  })
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <CalendarDaysIcon class="w-6 h-6" /> {{ t('leave.cal.title') }}
      </h1>
      <div class="flex gap-2">
        <button class="btn-secondary" @click="shiftMonth(-1)" :title="t('leave.cal.prev_month')">
          <ChevronLeftIcon class="w-4 h-4" />
        </button>
        <button class="btn-secondary" @click="shiftMonth(1)" :title="t('leave.cal.next_month')">
          <ChevronRightIcon class="w-4 h-4" />
        </button>
        <button class="btn-secondary" @click="fetchData" :disabled="loading">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
          {{ t('common.refresh') }}
        </button>
      </div>
    </div>

    <!-- Filter -->
    <div class="card mb-4">
      <div class="card-body grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
        <div>
          <label class="label">{{ t('leave.cal.start') }}</label>
          <input v-model="filters.start_date" type="date" class="input" @change="fetchData" />
        </div>
        <div>
          <label class="label">{{ t('leave.cal.end') }}</label>
          <input v-model="filters.end_date" type="date" class="input" @change="fetchData" />
        </div>
        <div>
          <label class="label">{{ t('leave.cal.branch') }}</label>
          <SearchableSelect
            v-model="filters.branch_id"
            :items="branches"
            value-key="id"
            label-key="name"
            :search-keys="['name','code','city']"
            :placeholder="t('leave.cal.all')"
            @change="fetchData"
          />
        </div>
        <div>
          <label class="label">{{ t('leave.cal.department') }}</label>
          <SearchableSelect
            v-model="filters.department_id"
            :items="departments"
            value-key="id"
            label-key="name"
            :search-keys="['name','code']"
            :placeholder="t('leave.cal.all')"
            @change="fetchData"
          />
        </div>
      </div>
    </div>

    <!-- Swimlane -->
    <div class="card overflow-hidden">
      <div class="overflow-auto" style="max-height: calc(100vh - 280px)">
        <table class="w-full text-xs border-separate" style="border-spacing: 0">
          <thead class="sticky top-0 bg-white z-10">
            <tr>
              <th class="sticky left-0 bg-white z-20 px-3 py-2 text-left border-b border-gray-200 min-w-[220px]">
                {{ t('leave.cal.employees', { n: swimlanes.length }) }}
              </th>
              <th
                v-for="d in dates"
                :key="d"
                class="text-center px-1 py-1 border-b border-gray-200 min-w-[36px]"
                :class="{ 'bg-blue-50/40': dayHeader(d).isWeekend }"
              >
                <div class="text-[10px] text-gray-500">{{ dayHeader(d).dow }}</div>
                <div class="font-semibold">{{ dayHeader(d).dom }}</div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="dates.length + 1" class="text-center py-8 text-gray-400">{{ t('leave.cal.loading') }}</td>
            </tr>
            <tr v-else-if="swimlanes.length === 0">
              <td :colspan="dates.length + 1" class="text-center py-12 text-gray-400">
                {{ t('leave.cal.no_leaves') }}
              </td>
            </tr>
            <tr v-for="row in swimlanes" :key="row.employee_id" class="hover:bg-gray-50">
              <td class="sticky left-0 bg-white z-10 px-3 py-2 border-b border-gray-100 align-top">
                <div class="font-medium text-gray-800">{{ row.employee_name }}</div>
                <div class="text-[11px] text-gray-500">
                  {{ row.employee_number }}
                  <span v-if="row.department_name"> · {{ row.department_name }}</span>
                </div>
              </td>
              <td v-for="d in dates" :key="d"
                class="text-center align-middle border-b border-gray-100 p-0.5"
                :class="{ 'bg-blue-50/30': dayHeader(d).isWeekend }">
                <template v-for="lv in row.leaves" :key="lv.id">
                  <span v-if="isLeaveOn(lv, d)"
                    :class="['inline-block w-full h-6 rounded-sm text-[9px] font-bold border', leaveColor(lv)]"
                    :title="tooltip(lv)">
                    {{ categoryLabel(lv.category) }}
                  </span>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <!-- Legend -->
      <div class="card-body flex flex-wrap items-center gap-4 text-xs text-gray-500 border-t border-gray-100">
        <span class="font-medium">{{ t('leave.cal.legend') }}</span>
        <span class="flex items-center gap-1">
          <span class="inline-block w-4 h-4 rounded-sm bg-blue-200 border border-blue-400"></span> {{ t('leave.cal.legend_approved') }}
        </span>
        <span class="flex items-center gap-1">
          <span class="inline-block w-4 h-4 rounded-sm bg-amber-200 border border-amber-400 border-dashed"></span> {{ t('leave.cal.legend_pending') }}
        </span>
        <span class="text-gray-400">{{ t('leave.cal.legend_codes') }}</span>
      </div>
    </div>
  </div>
</template>