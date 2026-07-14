<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { useI18n } from '@/locales'
import {
  CalendarDaysIcon, MagnifyingGlassIcon, ArrowPathIcon,
  ChevronLeftIcon, ChevronRightIcon, TrashIcon, BoltIcon,
} from '@heroicons/vue/24/outline'
import SearchableSelect from '@/components/SearchableSelect.vue'
import ExcelImporter from '@/components/ExcelImporter.vue'

/**
 * Roster — matriks Employee × Tanggal.
 *
 * Filter scope (branch / department / work_location) muat di header.
 * Default rentang: 14 hari ke depan dari hari ini.
 *
 * Interaksi:
 *  - Klik cell → buka popup pilih shift / off / holiday untuk hari itu.
 *  - Klik nama employee → toggle highlight baris.
 *  - Tombol "Generate dari Pola" → dialog pilih pattern + rentang + scope.
 *  - Tombol "Hapus Range" → bersihkan shift_schedules di rentang tampil.
 *
 * Catatan: cell yg sudah punya shift_schedules ditandai border kuning;
 * cell yang berasal dari resolver (pattern/assignment/default) tampil normal.
 */

const toast = useToast()
const auth = useAuthStore()
const { t } = useI18n()

const today = new Date().toISOString().slice(0, 10)
const filters = reactive({
  start_date: today,
  end_date: addDays(today, 13),
  branch_id: '',
  department_id: '',
  work_location_id: '',
  q: '',
})

const branches = ref([])
const departments = ref([])
const workLocations = ref([])
const shifts = ref([])
const patterns = ref([])

const matrix = ref({ rows: [], dates: [] })
const loading = ref(false)

// Cell editor popup state
const editingCell = ref(null) // { employeeId, date, current }

// Bulk generate dialog
const showGenerate = ref(false)
const generateForm = reactive({
  pattern_id: '',
  start_date: today,
  end_date: addDays(today, 30),
  anchor_date: today,
  overwrite: false,
  dry_run: false,
  scope_type: 'visible', // visible | branch | department | wl
})

function addDays(date, days) {
  const d = new Date(date)
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

function shiftMonth(months) {
  // Geser jendela tampilan +/- N bulan, mempertahankan rentang
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
    const res = await api.get('/roster', { params })
    matrix.value = {
      rows: res.data.data.rows || [],
      dates: res.data.data.dates || [],
    }
  } catch (e) {
    toast.error(e.response?.data?.message || t('roster_ui.load_failed'))
  } finally {
    loading.value = false
  }
}

async function loadMasters() {
  try {
    const [b, d, w, s, p] = await Promise.all([
      api.get('/branches', { params: { per_page: 200 } }).catch(() => null),
      api.get('/departments', { params: { per_page: 200 } }).catch(() => null),
      api.get('/work-locations', { params: { per_page: 200 } }).catch(() => null),
      api.get('/shifts', { params: { per_page: 200 } }).catch(() => null),
      api.get('/shift-patterns').catch(() => null),
    ])
    branches.value = b?.data?.data || []
    departments.value = d?.data?.data || []
    workLocations.value = w?.data?.data || []
    shifts.value = s?.data?.data || []
    patterns.value = p?.data?.data || []
  } catch (_) { /* silent */ }
}

onMounted(() => {
  loadMasters()
  fetchData()
})

// ─── Cell editor ─────────────────────────────────────────────────
function openCell(employeeId, date, current) {
  editingCell.value = {
    employeeId,
    date,
    shift_id: current?.shift_id ?? null,
    is_off: current?.is_off ?? false,
    is_holiday: current?.is_holiday ?? false,
    source: current?.source ?? 'none',
    reason: current?.reason ?? '',
  }
}

async function saveCell() {
  const c = editingCell.value
  if (! c) return
  try {
    await api.post('/roster/bulk-update', {
      entries: [{
        employee_id: c.employeeId,
        work_date: c.date,
        shift_id: c.is_off || c.is_holiday ? null : c.shift_id,
        is_off: c.is_off ? 1 : 0,
        is_holiday: c.is_holiday ? 1 : 0,
      }],
    })
    toast.success(t('roster_ui.saved'))
    editingCell.value = null
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('roster_ui.save_failed'))
  }
}

async function clearCell() {
  const c = editingCell.value
  if (! c) return
  const ok = await confirmDialog({
    title: t('roster_ui.delete_override_title'),
    message: t('roster_ui.delete_override_message'),
    okText: t('roster_ui.delete'),
  })
  if (! ok) return
  try {
    await api.post('/roster/clear', {
      employee_ids: [c.employeeId],
      start_date: c.date,
      end_date: c.date,
    })
    toast.success(t('roster_ui.override_deleted'))
    editingCell.value = null
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('roster_ui.delete_failed'))
  }
}

// ─── Generate dari pola ─────────────────────────────────────────
function openGenerate() {
  generateForm.pattern_id = patterns.value[0]?.id || ''
  generateForm.start_date = filters.start_date
  generateForm.end_date = filters.end_date
  generateForm.anchor_date = filters.start_date
  showGenerate.value = true
}

async function runGenerate(opts = {}) {
  if (! generateForm.pattern_id) {
    toast.warning(t('roster_ui.select_pattern_warning'))
    return
  }
  const payload = { ...generateForm, ...opts }
  if (payload.scope_type === 'visible') {
    payload.employee_ids = matrix.value.rows.map(r => r.employee.id)
  } else if (payload.scope_type === 'branch')     payload.branch_id = filters.branch_id || null
  else if (payload.scope_type === 'department')   payload.department_id = filters.department_id || null
  else if (payload.scope_type === 'wl')           payload.work_location_id = filters.work_location_id || null
  delete payload.scope_type

  try {
    const res = await api.post('/roster/generate', payload)
    const s = res.data.data
    if (s.dry_run) {
      toast.info(t('roster_ui.preview_result', s))
    } else {
      toast.success(t('roster_ui.generate_result', s))
      showGenerate.value = false
      fetchData()
    }
  } catch (e) {
    toast.error(e.response?.data?.message || t('roster_ui.generate_failed'))
  }
}

// ─── Clear range ────────────────────────────────────────────────
async function clearRange() {
  const ok = await confirmDialog({
    title: t('roster_ui.clear_title'),
    message: t('roster_ui.clear_message', { start: filters.start_date, end: filters.end_date, count: matrix.value.rows.length }),
    okText: t('roster_ui.delete'), variant: 'danger',
  })
  if (! ok) return
  try {
    await api.post('/roster/clear', {
      employee_ids: matrix.value.rows.map(r => r.employee.id),
      start_date: filters.start_date,
      end_date: filters.end_date,
    })
    toast.success(t('roster_ui.cleared'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('roster_ui.delete_failed'))
  }
}

// ─── Helpers ────────────────────────────────────────────────────
function dayHeader(d) {
  const date = new Date(d)
  const dow = date.getDay() // 0=Min..6=Sab
  const labels = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']
  return { dom: date.getDate(), dow: t(`roster_ui.${labels[dow]}`), isWeekend: dow === 0 || dow === 6 }
}

function cellClass(cell) {
  const cls = []
  if (cell?.is_holiday) cls.push('bg-blue-50 border-blue-200')
  else if (cell?.is_off) cls.push('bg-gray-100 border-gray-200 text-gray-500')
  else if (cell?.shift_id) cls.push('text-white')
  else cls.push('bg-white border-gray-200 text-gray-400')
  if (cell?.source === 'schedule') cls.push('ring-1 ring-amber-400')
  return cls.join(' ')
}

function cellLabel(cell) {
  if (cell?.is_holiday) return t('roster_ui.cell_holiday')
  if (cell?.is_off) return 'OFF'
  if (cell?.shift_code) return cell.shift_code.replace(/^FACTORY_/, '')
  return '—'
}

const visibleEmployeeCount = computed(() => matrix.value.rows.length)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <CalendarDaysIcon class="w-6 h-6" /> {{ t('roster_ui.title') }}
      </h1>
      <div class="flex gap-2 flex-wrap">
        <!-- Sprint 3 — bulk import roster dari xlsx -->
        <ExcelImporter
          v-if="auth.can('shift_schedules.bulk_import')"
          resource="shift_schedules"
          :button-label="t('roster_ui.import')"
          @committed="fetchData()"
        />
        <button class="btn-secondary" @click="fetchData" :disabled="loading">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" /> {{ t('roster_ui.refresh') }}
        </button>
        <button class="btn-secondary" @click="openGenerate">
          <BoltIcon class="w-4 h-4" /> {{ t('roster_ui.generate_pattern') }}
        </button>
        <button class="btn-secondary text-red-600 hover:bg-red-50" @click="clearRange">
          <TrashIcon class="w-4 h-4" /> {{ t('roster_ui.clear_range') }}
        </button>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="card mb-4">
      <div class="card-body grid grid-cols-1 md:grid-cols-7 gap-3 items-end">
        <div>
          <label class="label">{{ t('roster_ui.start') }}</label>
          <input v-model="filters.start_date" type="date" class="input" @change="fetchData" />
        </div>
        <div>
          <label class="label">{{ t('roster_ui.end') }}</label>
          <input v-model="filters.end_date" type="date" class="input" @change="fetchData" />
        </div>
        <div>
          <label class="label">{{ t('roster_ui.branch') }}</label>
          <SearchableSelect
            v-model="filters.branch_id"
            :items="branches"
            value-key="id"
            label-key="name"
            :search-keys="['name','code','city']"
            v-bind="{ placeholder: t('roster_ui.all') }"
            @change="fetchData"
          />
        </div>
        <div>
          <label class="label">{{ t('roster_ui.department') }}</label>
          <SearchableSelect
            v-model="filters.department_id"
            :items="departments"
            value-key="id"
            label-key="name"
            :search-keys="['name','code']"
            v-bind="{ placeholder: t('roster_ui.all') }"
            @change="fetchData"
          />
        </div>
        <div>
          <label class="label">{{ t('roster_ui.work_location') }}</label>
          <SearchableSelect
            v-model="filters.work_location_id"
            :items="workLocations"
            value-key="id"
            label-key="name"
            :search-keys="['name','code','address']"
            v-bind="{ placeholder: t('roster_ui.all') }"
            @change="fetchData"
          />
        </div>
        <div>
          <label class="label">{{ t('roster_ui.employee_search') }}</label>
          <div class="relative">
            <MagnifyingGlassIcon class="w-4 h-4 absolute left-2 top-3 text-gray-400" />
            <input v-model="filters.q" class="input pl-8" v-bind="{ placeholder: t('roster_ui.employee_placeholder') }"
              @keyup.enter="fetchData" />
          </div>
        </div>
        <div class="flex gap-1">
          <button class="btn-secondary" @click="shiftMonth(-1)" v-bind="{ title: t('roster_ui.previous_month') }">
            <ChevronLeftIcon class="w-4 h-4" />
          </button>
          <button class="btn-secondary" @click="shiftMonth(1)" v-bind="{ title: t('roster_ui.next_month') }">
            <ChevronRightIcon class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>

    <!-- Matrix -->
    <div class="card overflow-hidden">
      <div class="overflow-auto" style="max-height: calc(100vh - 320px)">
        <table class="w-full text-xs border-separate" style="border-spacing: 0">
          <thead class="sticky top-0 bg-white z-10">
            <tr>
              <th class="sticky left-0 bg-white z-20 px-3 py-2 text-left border-b border-gray-200 min-w-[220px]">
                {{ t('roster_ui.employees', { count: visibleEmployeeCount }) }}
              </th>
              <th
                v-for="d in matrix.dates"
                :key="d"
                class="text-center px-1 py-1 border-b border-gray-200 min-w-[44px]"
                :class="{ 'bg-blue-50/50': dayHeader(d).isWeekend }"
              >
                <div class="text-[10px] text-gray-500">{{ dayHeader(d).dow }}</div>
                <div class="font-semibold">{{ dayHeader(d).dom }}</div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="matrix.dates.length + 1" class="text-center py-12 text-gray-400">
                {{ t('roster_ui.loading') }}
              </td>
            </tr>
            <tr v-else-if="matrix.rows.length === 0">
              <td :colspan="matrix.dates.length + 1" class="text-center py-12 text-gray-400">
                {{ t('roster_ui.empty') }}
              </td>
            </tr>
            <tr v-for="row in matrix.rows" :key="row.employee.id" class="hover:bg-gray-50">
              <td class="sticky left-0 bg-white z-10 px-3 py-2 border-b border-gray-100 align-top">
                <div class="font-medium text-gray-800">{{ row.employee.full_name }}</div>
                <div class="text-[11px] text-gray-500">
                  {{ row.employee.employee_number }}
                  <span v-if="row.employee.department_name"> · {{ row.employee.department_name }}</span>
                </div>
              </td>
              <td v-for="d in matrix.dates" :key="d"
                class="text-center align-middle border-b border-gray-100 p-0.5">
                <button
                  class="w-full h-9 rounded-sm text-[10px] font-semibold border transition hover:scale-105 hover:shadow-sm"
                  :class="cellClass(row.days[d])"
                  :style="row.days[d]?.shift_id && ! row.days[d]?.is_off && ! row.days[d]?.is_holiday
                    ? { background: row.days[d]?.color || '#64748b', borderColor: 'transparent' } : {}"
                  :title="row.days[d]?.reason || ''"
                  @click="openCell(row.employee.id, d, row.days[d])"
                >
                  {{ cellLabel(row.days[d]) }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <!-- Legend -->
      <div class="card-body flex flex-wrap items-center gap-4 text-xs text-gray-500 border-t border-gray-100">
        <span class="font-medium">{{ t('roster_ui.legend') }}</span>
        <span class="flex items-center gap-1">
          <span class="inline-block w-4 h-4 rounded-sm ring-1 ring-amber-400 bg-emerald-500"></span>
          {{ t('roster_ui.manual_override') }}
        </span>
        <span class="flex items-center gap-1">
          <span class="inline-block w-4 h-4 rounded-sm bg-emerald-500"></span> {{ t('roster_ui.resolver_shift') }}
        </span>
        <span class="flex items-center gap-1">
          <span class="inline-block w-4 h-4 rounded-sm bg-gray-200 border border-gray-300"></span> {{ t('roster_ui.off') }}
        </span>
        <span class="flex items-center gap-1">
          <span class="inline-block w-4 h-4 rounded-sm bg-blue-50 border border-blue-200"></span> {{ t('roster_ui.holiday') }}
        </span>
      </div>
    </div>

    <!-- Cell editor popup -->
    <div v-if="editingCell" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ t('roster_ui.edit_title', { date: editingCell.date }) }}</h2>
          <button class="text-gray-500" v-bind="{ 'aria-label': t('roster_ui.close') }" @click="editingCell = null">&times;</button>
        </div>
        <div class="p-6 space-y-3">
          <div class="text-xs text-gray-500">
            {{ t('roster_ui.current_source') }} <span class="font-medium">{{ editingCell.source }}</span>
            <span v-if="editingCell.reason"> — {{ editingCell.reason }}</span>
          </div>
          <div>
            <label class="label">{{ t('roster_ui.shift') }}</label>
            <SearchableSelect
              v-model="editingCell.shift_id"
              :items="shifts"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              v-bind="{ placeholder: t('roster_ui.select_shift') }"
              :disabled="editingCell.is_off || editingCell.is_holiday"
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
          <label class="flex items-center gap-2 text-sm">
            <input v-model="editingCell.is_off" type="checkbox" />
            {{ t('roster_ui.mark_off') }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="editingCell.is_holiday" type="checkbox" />
            {{ t('roster_ui.mark_holiday') }}
          </label>
        </div>
        <div class="px-6 py-3 border-t border-gray-200 flex justify-between gap-2">
          <button class="btn-secondary text-red-600" @click="clearCell">{{ t('roster_ui.delete_override') }}</button>
          <div class="flex gap-2">
            <button class="btn-secondary" @click="editingCell = null">{{ t('roster_ui.cancel') }}</button>
            <button class="btn-primary" @click="saveCell">{{ t('roster_ui.save') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Generate dialog -->
    <div v-if="showGenerate" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ t('roster_ui.generate_title') }}</h2>
          <button class="text-gray-500" v-bind="{ 'aria-label': t('roster_ui.close') }" @click="showGenerate = false">&times;</button>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <label class="label">{{ t('roster_ui.select_pattern') }}</label>
            <SearchableSelect
              v-model="generateForm.pattern_id"
              :items="patterns"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              v-bind="{ placeholder: t('roster_ui.pattern_placeholder') }"
            >
              <template #item="{ item }">
                <span class="block text-sm truncate">
                  {{ item.name }}
                  <span class="text-xs text-gray-500">{{ t('roster_ui.cycle_days', { count: item.cycle_days }) }}</span>
                </span>
              </template>
              <template #selected="{ item }">
                <span class="block truncate font-medium">
                  {{ item.name }}
                  <span class="text-xs text-gray-500">{{ t('roster_ui.cycle_days', { count: item.cycle_days }) }}</span>
                </span>
              </template>
            </SearchableSelect>
          </div>
          <div>
            <label class="label">{{ t('roster_ui.start') }}</label>
            <input v-model="generateForm.start_date" type="date" class="input" />
          </div>
          <div>
            <label class="label">{{ t('roster_ui.end') }}</label>
            <input v-model="generateForm.end_date" type="date" class="input" />
          </div>
          <div class="md:col-span-2">
            <label class="label">{{ t('roster_ui.anchor_date') }}</label>
            <input v-model="generateForm.anchor_date" type="date" class="input" />
            <div class="text-xs text-gray-500 mt-1">
              {{ t('roster_ui.anchor_hint') }}
            </div>
          </div>
          <div class="md:col-span-2">
            <label class="label">{{ t('roster_ui.target') }}</label>
            <select v-model="generateForm.scope_type" class="input">
              <option value="visible">{{ t('roster_ui.target_visible', { count: visibleEmployeeCount }) }}</option>
              <option value="branch" :disabled="! filters.branch_id">{{ t('roster_ui.target_branch') }}</option>
              <option value="department" :disabled="! filters.department_id">{{ t('roster_ui.target_department') }}</option>
              <option value="wl" :disabled="! filters.work_location_id">{{ t('roster_ui.target_location') }}</option>
            </select>
          </div>
          <div class="md:col-span-2 flex flex-wrap gap-4">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="generateForm.overwrite" type="checkbox" />
              {{ t('roster_ui.overwrite') }}
            </label>
          </div>
        </div>
        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2">
          <button class="btn-secondary" @click="showGenerate = false">{{ t('roster_ui.cancel') }}</button>
          <button class="btn-secondary" @click="runGenerate({ dry_run: true })">{{ t('roster_ui.preview') }}</button>
          <button class="btn-primary" @click="runGenerate()">{{ t('roster_ui.generate') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
