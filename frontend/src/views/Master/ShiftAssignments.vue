<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { PlusIcon, TrashIcon, ClockIcon, BeakerIcon } from '@heroicons/vue/24/outline'
import SearchableSelect from '@/components/SearchableSelect.vue'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'

const { t } = useI18n()
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
const saving = ref(false)
const deletingId = ref(null)
const createDialogLabel = computed(() => t('master.shift_assignments.modal_title'))

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
    toast.error(e.response?.data?.message || t('master.shift_assignments.load_failed'))
  } finally {
    loading.value = false
  }
}

async function loadMasters() {
  try {
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
  if (saving.value) return
  if (! form.shift_id) {
    toast.warning(t('master.shift_assignments.pick_shift_first'))
    return
  }
  const hasScope = form.branch_id || form.department_id || form.work_location_id || form.job_grade_id
  if (! hasScope) {
    toast.warning(t('master.shift_assignments.need_scope'))
    return
  }
  if (form.effective_from && form.effective_to && form.effective_to < form.effective_from) {
    toast.warning(t('master.shift_assignments.save_failed'))
    return
  }
  saving.value = true
  try {
    const payload = { ...form }
    Object.keys(payload).forEach(k => { if (payload[k] === '') payload[k] = null })
    await api.post('/shift-assignments', payload)
    toast.success(t('master.shift_assignments.saved'))
    showCreate.value = false
    await fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.shift_assignments.save_failed'))
  } finally {
    saving.value = false
  }
}

async function remove(item) {
  if (deletingId.value !== null) return
  const ok = await confirmDialog({
    title: t('master.shift_assignments.delete_title'),
    message: t('master.shift_assignments.delete_msg', { name: item.shift_name }),
    okText: t('master.shift_assignments.delete_ok'),
    variant: 'danger',
  })
  if (! ok) return
  deletingId.value = item.id
  try {
    await api.delete(`/shift-assignments/${item.id}`)
    toast.success(t('master.shift_assignments.deleted'))
    await fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.shift_assignments.delete_failed'))
  } finally {
    deletingId.value = null
  }
}

async function runPreview() {
  if (previewLoading.value) return
  if (! preview.employee_id || ! preview.date) {
    toast.warning(t('master.shift_assignments.preview_required'))
    return
  }
  previewResult.value = null
  previewLoading.value = true
  try {
    const res = await api.get('/shift-assignments/preview', { params: preview })
    previewResult.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.shift_assignments.preview_failed'))
  } finally {
    previewLoading.value = false
  }
}

function scopeLabel(item) {
  const parts = []
  if (item.branch_name) parts.push(t('master.shift_assignments.scope_branch', { name: item.branch_name }))
  if (item.work_location_name) parts.push(t('master.shift_assignments.scope_location', { name: item.work_location_name }))
  if (item.department_name) parts.push(t('master.shift_assignments.scope_dept', { name: item.department_name }))
  if (item.grade_name) parts.push(t('master.shift_assignments.scope_grade', { name: item.grade_name }))
  return parts.length ? parts.join(t('master.shift_assignments.scope_separator')) : t('master.shift_assignments.scope_company_wide')
}

function sourceLabel(s) {
  const map = {
    schedule: t('master.shift_assignments.src_schedule'),
    employee: t('master.shift_assignments.src_employee'),
    pattern: t('master.shift_assignments.src_pattern'),
    assignment: t('master.shift_assignments.src_assignment'),
    shift_default: t('master.shift_assignments.src_shift_default'),
    fallback: t('master.shift_assignments.src_fallback'),
    none: t('master.shift_assignments.src_none'),
  }
  return map[s] || s
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <ClockIcon class="w-6 h-6" /> {{ t('master.shift_assignments.title') }}
      </h1>
      <button type="button" class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> {{ t('master.shift_assignments.btn_create') }}
      </button>
    </div>

    <div class="card mb-4">
      <div class="card-header">
        <span class="font-medium flex items-center gap-2">
          <BeakerIcon class="w-5 h-5 text-primary-600" /> {{ t('master.shift_assignments.preview_title') }}
        </span>
      </div>
      <div class="card-body grid grid-cols-1 md:grid-cols-4 gap-3">
        <div>
          <label class="label">{{ t('master.shift_assignments.f_employee') }}</label>
          <EmployeeSearchSelect
            v-model="preview.employee_id"
            v-bind="{ placeholder: t('master.shift_assignments.f_employee_ph') }"
            :only-active="true"
          />
        </div>
        <div>
          <label class="label">{{ t('master.shift_assignments.f_date') }}</label>
          <AppDatePicker v-model="preview.date"  class="input" />
        </div>
        <div class="flex items-end">
          <button type="button" class="btn-primary w-full" :disabled="previewLoading" @click="runPreview">
            {{ previewLoading ? t('master.shift_assignments.btn_processing') : t('master.shift_assignments.btn_check') }}
          </button>
        </div>
        <div v-if="previewResult" class="md:col-span-1 bg-gray-50 rounded p-3 text-sm">
          <div v-if="previewResult.shift">
            <div class="font-semibold" :style="{ color: previewResult.shift.color || '#1f2937' }">
              {{ previewResult.shift.name }} ({{ String(previewResult.shift.start_time).slice(0,5) }}–{{ String(previewResult.shift.end_time).slice(0,5) }})
            </div>
          </div>
          <div v-else class="text-gray-500">{{ t('master.shift_assignments.no_shift') }}</div>
          <div class="text-xs text-gray-500 mt-1">
            {{ t('master.shift_assignments.source_label', { label: sourceLabel(previewResult.source) }) }}
            <template v-if="previewResult.is_off">{{ t('master.shift_assignments.off_suffix') }}</template>
            <template v-if="previewResult.is_holiday">{{ t('master.shift_assignments.holiday_suffix') }}</template>
          </div>
          <div class="text-xs text-gray-400 mt-1">{{ previewResult.reason }}</div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header grid grid-cols-1 md:grid-cols-4 gap-3 w-full">
        <SearchableSelect
          v-model="filters.shift_id"
          :items="shifts"
          value-key="id"
          label-key="name"
          :search-keys="['name','code']"
          v-bind="{ placeholder: t('master.shift_assignments.filter_all_shifts') }"
          @change="fetchData"
        />
        <SearchableSelect
          v-model="filters.branch_id"
          :items="branches"
          value-key="id"
          label-key="name"
          :search-keys="['name','code','city']"
          v-bind="{ placeholder: t('master.shift_assignments.filter_all_branches') }"
          @change="fetchData"
        />
        <SearchableSelect
          v-model="filters.department_id"
          :items="departments"
          value-key="id"
          label-key="name"
          :search-keys="['name','code']"
          v-bind="{ placeholder: t('master.shift_assignments.filter_all_departments') }"
          @change="fetchData"
        />
        <select v-model="filters.is_active" class="input" @change="fetchData">
          <option value="">{{ t('master.shift_assignments.filter_status') }}</option>
          <option value="1">{{ t('master.shift_assignments.st_active') }}</option>
          <option value="0">{{ t('master.shift_assignments.st_inactive') }}</option>
        </select>
      </div>

      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('master.shift_assignments.col_shift') }}</th>
              <th>{{ t('master.shift_assignments.col_scope') }}</th>
              <th>{{ t('master.shift_assignments.col_priority') }}</th>
              <th>{{ t('master.shift_assignments.col_effective') }}</th>
              <th>{{ t('master.shift_assignments.col_status') }}</th>
              <th class="text-right w-20">{{ t('master.shift_assignments.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="6" class="text-center py-8 text-gray-400">{{ t('master.shift_assignments.loading') }}</td></tr>
            <tr v-else-if="items.length === 0"><td colspan="6" class="text-center py-8 text-gray-400">{{ t('master.shift_assignments.no_rules') }}</td></tr>
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
                {{ item.effective_from || t('master.shift_assignments.effective_dash') }}<span v-if="item.effective_from || item.effective_to">{{ t('master.shift_assignments.effective_to') }}</span>{{ item.effective_to || (item.effective_from ? t('master.shift_assignments.effective_inf') : t('master.shift_assignments.effective_dash')) }}
              </td>
              <td>
                <span :class="['px-2 py-0.5 rounded-full text-xs',
                  Number(item.is_active) === 1 ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'
                ]">
                  {{ Number(item.is_active) === 1 ? t('master.shift_assignments.st_active') : t('master.shift_assignments.st_inactive') }}
                </span>
              </td>
              <td class="text-right">
                <button type="button" class="text-gray-500 hover:text-red-600 disabled:opacity-50" @click="remove(item)" :disabled="deletingId !== null" v-bind="{ title: t('master.shift_assignments.delete_ok'), 'aria-label': t('master.shift_assignments.delete_ok') }">
                  <TrashIcon class="w-4 h-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="showCreate" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" v-bind="{ 'aria-label': createDialogLabel }">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ t('master.shift_assignments.modal_title') }}</h2>
          <button type="button" class="text-gray-500" @click="showCreate = false" v-bind="{ title: t('master.shift_assignments.f_cancel'), 'aria-label': t('master.shift_assignments.f_cancel') }">&times;</button>
        </div>
        <form @submit.prevent="save" class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <label class="label">{{ t('master.shift_assignments.f_shift') }}</label>
            <SearchableSelect
              v-model="form.shift_id"
              :items="shifts"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              v-bind="{ placeholder: t('master.shift_assignments.f_shift_ph') }"
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
            <label class="label">{{ t('master.shift_assignments.f_branch') }}</label>
            <SearchableSelect
              v-model="form.branch_id"
              :items="branches"
              value-key="id"
              label-key="name"
              :search-keys="['name','code','city']"
              v-bind="{ placeholder: t('master.shift_assignments.f_branch_ph') }"
            />
          </div>
          <div>
            <label class="label">{{ t('master.shift_assignments.f_work_location') }}</label>
            <SearchableSelect
              v-model="form.work_location_id"
              :items="workLocations"
              value-key="id"
              label-key="name"
              :search-keys="['name','code','address']"
              v-bind="{ placeholder: t('master.shift_assignments.f_branch_ph') }"
            />
          </div>
          <div>
            <label class="label">{{ t('master.shift_assignments.f_department') }}</label>
            <SearchableSelect
              v-model="form.department_id"
              :items="departments"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              v-bind="{ placeholder: t('master.shift_assignments.f_branch_ph') }"
            />
          </div>
          <div>
            <label class="label">{{ t('master.shift_assignments.f_grade') }}</label>
            <SearchableSelect
              v-model="form.job_grade_id"
              :items="jobGrades"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              v-bind="{ placeholder: t('master.shift_assignments.f_branch_ph') }"
            />
          </div>

          <div>
            <label class="label">{{ t('master.shift_assignments.f_effective_from') }}</label>
            <AppDatePicker v-model="form.effective_from"  class="input" />
          </div>
          <div>
            <label class="label">{{ t('master.shift_assignments.f_effective_to') }}</label>
            <AppDatePicker v-model="form.effective_to"  class="input" />
          </div>
          <div>
            <label class="label">{{ t('master.shift_assignments.f_priority') }}</label>
            <input v-model.number="form.priority" type="number" class="input" />
          </div>
          <div class="flex items-end">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.is_active" type="checkbox" :true-value="1" :false-value="0" class="w-4 h-4" />
              {{ t('master.shift_assignments.f_active') }}
            </label>
          </div>

          <div class="md:col-span-2">
            <label class="label">{{ t('master.shift_assignments.f_notes') }}</label>
            <textarea v-model="form.notes" class="input" rows="2"></textarea>
          </div>

          <div class="md:col-span-2 flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showCreate = false">{{ t('master.shift_assignments.f_cancel') }}</button>
            <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? t('common.saving') : t('master.shift_assignments.f_save') }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
