<script setup>
import { ref, reactive, onMounted, watch, computed } from 'vue'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon, PencilIcon, TrashIcon, XMarkIcon, ArrowUpIcon, ArrowDownIcon,
  ShieldCheckIcon, AdjustmentsHorizontalIcon,
} from '@heroicons/vue/24/outline'
import SearchableSelect from '@/components/SearchableSelect.vue'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const canManage = computed(() => auth.can('approval_chain.manage'))

const moduleOptions = computed(() => [
  { value: 'LEAVE', label: t('master.approval_chains.mod_leave') },
  { value: 'OVERTIME', label: t('master.approval_chains.mod_overtime') },
  { value: 'ATTENDANCE_CHANGE', label: t('master.approval_chains.mod_attendance_change') },
  { value: 'REIMBURSEMENT', label: t('master.approval_chains.mod_reimbursement') },
  { value: 'LOAN', label: t('master.approval_chains.mod_loan') },
  { value: 'BUSINESS_TRIP', label: 'Perjalanan Dinas / SPPD' },
  { value: 'BONUS', label: 'Bonus' },
  { value: 'PAYROLL', label: 'Payroll Period' },
  { value: 'PAYROLL_ADJUSTMENT', label: 'Koreksi Payroll' },
  { value: 'COMPENSATION_REVIEW', label: 'Perubahan Kompensasi' },
  { value: 'TRAINING_REGISTRATION', label: 'Registrasi Training' },
  { value: 'TIMESHEET', label: 'Timesheet' },
  { value: 'CANDIDATE_OFFER', label: 'Candidate Offer' },
  { value: 'GENERATED_CONTRACT', label: 'Kontrak Kerja' },
  { value: 'ASSET_TRANSACTION', label: 'Transaksi Asset' },
  { value: 'HIRE_CONVERSION', label: 'Konversi Kandidat' },
  { value: 'PERFORMANCE_GOAL', label: 'Performance Goal' },
  { value: 'IDP_ACTIVATION', label: 'Aktivasi IDP' },
  { value: 'EMPLOYEE_SENSITIVE_CHANGE', label: 'Perubahan Data Sensitif Employee' },
  { value: 'MASTER_RATE_CHANGE', label: 'Perubahan Master Rate Payroll' },
  { value: 'PRIVILEGED_ACTION', label: 'Aksi Administratif Berisiko' },
  { value: 'GENERIC', label: t('master.approval_chains.mod_generic') },
])

const approverTypes = computed(() => [
  { value: 'SUPERVISOR', label: t('master.approval_chains.ap_supervisor') },
  { value: 'MANAGER', label: t('master.approval_chains.ap_manager') },
  { value: 'DEPARTMENT_HEAD', label: t('master.approval_chains.ap_dept_head') },
  { value: 'BRANCH_HEAD', label: t('master.approval_chains.ap_branch_head') },
  { value: 'HR', label: t('master.approval_chains.ap_hr') },
  { value: 'FINANCE', label: t('master.approval_chains.ap_finance') },
  { value: 'ROLE', label: t('master.approval_chains.ap_role') },
  { value: 'SPECIFIC_USER', label: t('master.approval_chains.ap_specific_user') },
  { value: 'SPECIFIC_EMPLOYEE', label: t('master.approval_chains.ap_specific_employee') },
  { value: 'HIRING_MANAGER', label: 'Hiring Manager' },
])

const fallbackOptions = computed(() => [
  { value: 'SKIP', label: t('master.approval_chains.fb_skip') },
  { value: 'BLOCK', label: t('master.approval_chains.fb_block') },
  { value: 'HR_FALLBACK', label: t('master.approval_chains.fb_hr_fallback') },
])

const items = ref([])
const loading = ref(false)
const filterModule = ref('')

const showEditor = ref(false)
const editing = ref(false)
const saving = ref(false)
const assignmentSaving = ref(false)
const deletingAssignmentId = ref(null)
const form = ref(emptyForm())
const activeTab = ref('steps') // steps | assignments

const departments = ref([])
const branches = ref([])
const jobGrades = ref([])
const roles = ref([])

const assignments = ref([])
const newAssignment = reactive({
  department_id: '',
  branch_id: '',
  job_grade_id: '',
  priority: 100,
  is_active: 1,
})

function emptyForm() {
  return {
    id: null,
    code: '',
    name: '',
    module: 'LEAVE',
    description: '',
    is_default: 0,
    fallback_strategy: 'HR_FALLBACK',
    is_active: 1,
    steps: [],
  }
}

function emptyStep() {
  return {
    name: '',
    approver_type: 'SUPERVISOR',
    approver_role_id: null,
    approver_user_id: null,
    approver_employee_id: null,
    is_required: 1,
    allow_skip_if_self: 1,
    sla_hours: null,
    condition_field: null,
    condition_op: null,
    condition_value: null,
  }
}

async function fetchData() {
  loading.value = true
  try {
    const params = {}
    if (filterModule.value) params.module = filterModule.value
    const res = await api.get('/approval-chains', { params })
    items.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.approval_chains.load_failed'))
  } finally {
    loading.value = false
  }
}

async function loadMasters() {
  try {
    const [d, b, g, r] = await Promise.all([
      api.get('/departments', { params: { per_page: 200 } }).catch(() => null),
      api.get('/branches', { params: { per_page: 200 } }).catch(() => null),
      api.get('/job-grades', { params: { per_page: 200 } }).catch(() => null),
      api.get('/roles', { params: { per_page: 200 } }).catch(() => null),
    ])
    departments.value = d?.data?.data || []
    branches.value = b?.data?.data || []
    jobGrades.value = g?.data?.data || []
    roles.value = r?.data?.data || []
  } catch (_) { /* silent */ }
}

onMounted(() => {
  fetchData()
  loadMasters()
})

watch(filterModule, () => fetchData())

function openCreate() {
  form.value = emptyForm()
  form.value.steps = [{ ...emptyStep(), name: t('master.approval_chains.step_default_first') }]
  editing.value = false
  activeTab.value = 'steps'
  assignments.value = []
  showEditor.value = true
}

async function openEdit(item) {
  try {
    const res = await api.get(`/approval-chains/${item.id}`)
    const data = res.data.data
    form.value = {
      ...emptyForm(),
      ...data,
      steps: (data.steps || []).map(s => ({ ...emptyStep(), ...s })),
    }
    assignments.value = data.assignments || []
    editing.value = true
    activeTab.value = 'steps'
    showEditor.value = true
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.approval_chains.load_one_failed'))
  }
}

async function save() {
  if (saving.value) return
  if (! form.value.code?.trim()) {
    toast.warning(`${t('master.approval_chains.f_code')} ${t('common.required')}`)
    return
  }
  if (! form.value.name?.trim()) {
    toast.warning(`${t('master.approval_chains.f_name')} ${t('common.required')}`)
    return
  }
  if (! form.value.steps || form.value.steps.length === 0) {
    toast.warning(t('master.approval_chains.need_step'))
    return
  }
  const cleaned = form.value.steps.map((s, i) => ({
    ...s,
    step_order: i + 1,
    name: s.name || t('master.approval_chains.step_label', { n: i + 1 }),
  }))
  const payload = { ...form.value, steps: cleaned }

  saving.value = true
  try {
    if (editing.value) {
      await api.put(`/approval-chains/${payload.id}`, payload)
      toast.success(t('master.approval_chains.saved_update'))
    } else {
      const res = await api.post('/approval-chains', payload)
      form.value.id = res.data.data?.id
      editing.value = true
      toast.success(t('master.approval_chains.saved_create'))
    }
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.approval_chains.save_failed'))
  } finally {
    saving.value = false
  }
}

async function remove(item) {
  const ok = await confirmDialog({
    title: t('master.approval_chains.delete_title'),
    message: t('master.approval_chains.delete_msg', { name: item.name }),
    okText: t('master.approval_chains.delete_ok'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/approval-chains/${item.id}`)
    toast.success(t('master.approval_chains.deleted'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.approval_chains.delete_failed'))
  }
}

// ─── Step manipulation ────────────────────────────────────────
function addStep() {
  form.value.steps.push({
    ...emptyStep(),
    name: t('master.approval_chains.step_label', { n: form.value.steps.length + 1 }),
  })
}

function removeStep(index) {
  form.value.steps.splice(index, 1)
}

function moveStep(index, dir) {
  const ni = index + dir
  if (ni < 0 || ni >= form.value.steps.length) return
  const arr = form.value.steps
  ;[arr[index], arr[ni]] = [arr[ni], arr[index]]
}

// ─── Assignment rules ─────────────────────────────────────────
async function addAssignment() {
  if (assignmentSaving.value) return
  if (! form.value.id) {
    toast.warning(t('master.approval_chains.assignment_save_first'))
    return
  }
  assignmentSaving.value = true
  try {
    const payload = {
      chain_id: form.value.id,
      module: form.value.module,
      department_id: newAssignment.department_id || null,
      branch_id: newAssignment.branch_id || null,
      job_grade_id: newAssignment.job_grade_id || null,
      priority: Number(newAssignment.priority) || 100,
      is_active: 1,
    }
    await api.post('/approval-assignments', payload)
    toast.success(t('master.approval_chains.assignment_added'))
    await refreshAssignments()
    Object.assign(newAssignment, { department_id: '', branch_id: '', job_grade_id: '', priority: 100, is_active: 1 })
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.approval_chains.assignment_add_failed'))
  } finally {
    assignmentSaving.value = false
  }
}

async function refreshAssignments() {
  if (! form.value.id) return
  try {
    const res = await api.get(`/approval-chains/${form.value.id}`)
    assignments.value = res.data.data.assignments || []
  } catch (_) { /* silent */ }
}

async function removeAssignment(a) {
  if (deletingAssignmentId.value !== null) return
  const ok = await confirmDialog({
    title: t('master.approval_chains.assignment_delete_title'),
    message: t('master.approval_chains.assignment_delete_msg'),
    okText: t('master.approval_chains.delete_ok'),
    variant: 'danger',
  })
  if (! ok) return
  deletingAssignmentId.value = a.id
  try {
    await api.delete(`/approval-assignments/${a.id}`)
    toast.success(t('master.approval_chains.deleted'))
    refreshAssignments()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.approval_chains.delete_failed'))
  } finally {
    deletingAssignmentId.value = null
  }
}

function moduleLabel(v) { return moduleOptions.value.find(m => m.value === v)?.label || v }
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <ShieldCheckIcon class="w-6 h-6" /> {{ t('master.approval_chains.title') }}
      </h1>
      <button v-if="canManage" type="button" class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> {{ t('master.approval_chains.btn_create') }}
      </button>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="w-full md:w-64">
          <SearchableSelect
            v-model="filterModule"
            :items="moduleOptions"
            value-key="value"
            label-key="label"
            v-bind="{ placeholder: t('master.approval_chains.filter_all_modules') }"
          />
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('master.approval_chains.col_code') }}</th>
              <th>{{ t('master.approval_chains.col_name') }}</th>
              <th>{{ t('master.approval_chains.col_module') }}</th>
              <th>{{ t('master.approval_chains.col_steps') }}</th>
              <th>{{ t('master.approval_chains.col_default') }}</th>
              <th>{{ t('master.approval_chains.col_status') }}</th>
              <th class="text-right w-32">{{ t('master.approval_chains.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="7" class="text-center py-8 text-gray-400">{{ t('master.approval_chains.loading') }}</td></tr>
            <tr v-else-if="items.length === 0"><td colspan="7" class="text-center py-8 text-gray-400">{{ t('master.approval_chains.no_chains') }}</td></tr>
            <tr v-for="item in items" :key="item.id">
              <td class="font-mono text-sm">{{ item.code }}</td>
              <td class="font-medium">{{ item.name }}</td>
              <td>{{ moduleLabel(item.module) }}</td>
              <td class="text-center">{{ item.step_count }}</td>
              <td>
                <span v-if="item.is_default" class="px-2 py-0.5 rounded-full text-xs bg-amber-50 text-amber-700">{{ t('master.approval_chains.default_badge') }}</span>
              </td>
              <td>
                <span :class="[
                  'px-2 py-0.5 rounded-full text-xs',
                  item.is_active ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'
                ]">
                  {{ item.is_active ? t('master.approval_chains.active') : t('master.approval_chains.inactive') }}
                </span>
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button v-if="canManage" type="button" class="text-gray-500 hover:text-primary-600" @click="openEdit(item)" v-bind="{ title: t('master.approval_chains.edit'), 'aria-label': t('master.approval_chains.edit') }"><PencilIcon class="w-4 h-4" /></button>
                  <button v-if="canManage" type="button" class="text-gray-500 hover:text-red-600" @click="remove(item)" v-bind="{ title: t('master.approval_chains.delete'), 'aria-label': t('master.approval_chains.delete') }"><TrashIcon class="w-4 h-4" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Editor Modal -->
    <div v-if="showEditor" class="fixed inset-0 z-40 flex items-stretch bg-black/40">
      <div class="bg-white w-full m-4 rounded-lg shadow-xl flex flex-col overflow-hidden" role="dialog" aria-modal="true" v-bind="{ 'aria-label': editing ? t('master.approval_chains.modal_edit') : t('master.approval_chains.modal_create') }">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ editing ? t('master.approval_chains.modal_edit') : t('master.approval_chains.modal_create') }}</h2>
          <button type="button" class="text-gray-500" @click="showEditor = false" v-bind="{ title: t('common.close'), 'aria-label': t('common.close') }"><XMarkIcon class="w-5 h-5" /></button>
        </div>

        <!-- Metadata -->
        <div class="grid grid-cols-1 md:grid-cols-6 gap-3 px-6 py-3 border-b border-gray-100 bg-gray-50">
          <div class="md:col-span-1">
            <label class="label">{{ t('master.approval_chains.f_code') }}</label>
            <input v-model="form.code" class="input" :disabled="editing" required />
          </div>
          <div class="md:col-span-2">
            <label class="label">{{ t('master.approval_chains.f_name') }}</label>
            <input v-model="form.name" class="input" required />
          </div>
          <div class="md:col-span-1">
            <label class="label">{{ t('master.approval_chains.f_module') }}</label>
            <SearchableSelect
              v-model="form.module"
              :items="moduleOptions"
              value-key="value"
              label-key="label"
              v-bind="{ placeholder: t('master.approval_chains.f_module_ph') }"
              :clearable="false"
            />
          </div>
          <div class="md:col-span-1">
            <label class="label">{{ t('master.approval_chains.f_fallback') }}</label>
            <SearchableSelect
              v-model="form.fallback_strategy"
              :items="fallbackOptions"
              value-key="value"
              label-key="label"
              v-bind="{ placeholder: t('master.approval_chains.f_fallback_ph') }"
              :clearable="false"
            />
          </div>
          <div class="md:col-span-1 flex items-end gap-3">
            <label class="flex items-center gap-1 text-sm">
              <input type="checkbox" v-model="form.is_active" :true-value="1" :false-value="0" class="w-4 h-4" /> {{ t('master.approval_chains.f_active') }}
            </label>
            <label class="flex items-center gap-1 text-sm">
              <input type="checkbox" v-model="form.is_default" :true-value="1" :false-value="0" class="w-4 h-4" /> {{ t('master.approval_chains.f_default') }}
            </label>
          </div>
        </div>

        <!-- Tabs -->
        <div class="flex border-b border-gray-200 px-6 bg-white">
          <button type="button" :class="['px-4 py-2 text-sm border-b-2', activeTab === 'steps' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500']" @click="activeTab = 'steps'">
            {{ t('master.approval_chains.tab_steps') }}
          </button>
          <button type="button" :class="['px-4 py-2 text-sm border-b-2', activeTab === 'assignments' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500']" @click="activeTab = 'assignments'">
            <AdjustmentsHorizontalIcon class="w-4 h-4 inline -mt-0.5" /> {{ t('master.approval_chains.tab_assignments') }}
          </button>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-6">
          <!-- STEPS -->
          <div v-if="activeTab === 'steps'" class="space-y-4">
            <div class="text-sm text-gray-500">
              {{ t('master.approval_chains.steps_subtitle') }}
            </div>

            <div v-for="(s, i) in form.steps" :key="i" class="border border-gray-200 rounded-md p-4 bg-white">
              <div class="flex items-center justify-between mb-3">
                <div class="font-medium text-gray-700">{{ t('master.approval_chains.step_label', { n: i + 1 }) }}</div>
                <div class="flex gap-1">
                  <button type="button" class="text-gray-500 hover:text-primary-600 disabled:opacity-30"
                    :disabled="i === 0" @click="moveStep(i, -1)" v-bind="{ title: t('common.previous'), 'aria-label': t('common.previous') }"><ArrowUpIcon class="w-4 h-4" /></button>
                  <button type="button" class="text-gray-500 hover:text-primary-600 disabled:opacity-30"
                    :disabled="i === form.steps.length - 1" @click="moveStep(i, 1)" v-bind="{ title: t('common.next'), 'aria-label': t('common.next') }"><ArrowDownIcon class="w-4 h-4" /></button>
                  <button v-if="canManage" type="button" class="text-red-500 hover:text-red-700" @click="removeStep(i)"
                    :disabled="form.steps.length <= 1" v-bind="{ title: t('common.delete'), 'aria-label': t('common.delete') }"><TrashIcon class="w-4 h-4" /></button>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                  <label class="label">{{ t('master.approval_chains.f_step_name') }}</label>
                  <input v-model="s.name" class="input" v-bind="{ placeholder: t('master.approval_chains.f_step_name_ph') }" />
                </div>
                <div>
                  <label class="label">{{ t('master.approval_chains.f_approver_type') }}</label>
                  <SearchableSelect
                    v-model="s.approver_type"
                    :items="approverTypes"
                    value-key="value"
                    label-key="label"
                    :clearable="false"
                  />
                </div>
                <div v-if="s.approver_type === 'ROLE'">
                  <label class="label">{{ t('master.approval_chains.f_pick_role') }}</label>
                  <SearchableSelect
                    v-model="s.approver_role_id"
                    :items="roles"
                    value-key="id"
                    label-key="name"
                    :search-keys="['name','description']"
                    v-bind="{ placeholder: t('master.approval_chains.f_pick_role_ph') }"
                  />
                </div>
                <div v-if="s.approver_type === 'SPECIFIC_USER'">
                  <label class="label">{{ t('master.approval_chains.f_user_id') }}</label>
                  <input v-model.number="s.approver_user_id" type="number" class="input" v-bind="{ placeholder: t('master.approval_chains.f_user_id_ph') }" />
                </div>
                <div v-if="s.approver_type === 'SPECIFIC_EMPLOYEE'">
                  <label class="label">{{ t('master.approval_chains.f_employee') }}</label>
                  <EmployeeSearchSelect
                    v-model="s.approver_employee_id"
                    v-bind="{ placeholder: t('master.approval_chains.f_employee_ph') }"
                    :only-active="false"
                  />
                </div>
                <div>
                  <label class="label">{{ t('master.approval_chains.f_sla_hours') }}</label>
                  <input v-model.number="s.sla_hours" type="number" min="0" class="input" v-bind="{ placeholder: t('master.approval_chains.f_sla_ph') }" />
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                <div>
                  <label class="label">{{ t('master.approval_chains.f_condition_field') }}</label>
                  <input v-model="s.condition_field" class="input" v-bind="{ placeholder: t('master.approval_chains.f_condition_field_ph') }" />
                </div>
                <div>
                  <label class="label">{{ t('master.approval_chains.f_operator') }}</label>
                  <select v-model="s.condition_op" class="input">
                    <option :value="null">{{ t('master.approval_chains.f_op_none') }}</option>
                    <option value=">">{{ t('master.approval_chains.f_op_gt') }}</option>
                    <option value=">=">{{ t('master.approval_chains.f_op_gte') }}</option>
                    <option value="<">{{ t('master.approval_chains.f_op_lt') }}</option>
                    <option value="<=">{{ t('master.approval_chains.f_op_lte') }}</option>
                    <option value="==">{{ t('master.approval_chains.f_op_eq') }}</option>
                  </select>
                </div>
                <div>
                  <label class="label">{{ t('master.approval_chains.f_value') }}</label>
                  <input v-model.number="s.condition_value" type="number" class="input" v-bind="{ placeholder: t('master.approval_chains.f_value_ph') }" />
                </div>
              </div>

              <div class="flex flex-wrap gap-4 mt-3">
                <label class="flex items-center gap-2 text-sm">
                  <input type="checkbox" v-model="s.is_required" :true-value="1" :false-value="0" class="w-4 h-4" />
                  {{ t('master.approval_chains.f_required') }}
                </label>
                <label class="flex items-center gap-2 text-sm">
                  <input type="checkbox" v-model="s.allow_skip_if_self" :true-value="1" :false-value="0" class="w-4 h-4" />
                  {{ t('master.approval_chains.f_skip_self') }}
                </label>
              </div>
            </div>

            <button v-if="canManage" type="button" class="btn-secondary" @click="addStep">
              <PlusIcon class="w-4 h-4" /> {{ t('master.approval_chains.btn_add_step') }}
            </button>
          </div>

          <!-- ASSIGNMENTS -->
          <div v-else-if="activeTab === 'assignments'" class="space-y-4">
            <div class="text-sm text-gray-500" v-if="! form.id">
              {{ t('master.approval_chains.assignment_save_first') }}
            </div>

            <div v-if="form.id" class="card-body bg-gray-50 border border-gray-200 rounded-md">
              <div class="font-medium text-sm mb-3">{{ t('master.approval_chains.assignment_add_title') }}</div>
              <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                <div>
                  <label class="label">{{ t('master.approval_chains.f_department') }}</label>
                  <SearchableSelect
                    v-model="newAssignment.department_id"
                    :items="departments"
                    value-key="id"
                    label-key="name"
                    :search-keys="['name','code']"
                    v-bind="{ placeholder: t('master.approval_chains.f_all') }"
                  />
                </div>
                <div>
                  <label class="label">{{ t('master.approval_chains.f_branch') }}</label>
                  <SearchableSelect
                    v-model="newAssignment.branch_id"
                    :items="branches"
                    value-key="id"
                    label-key="name"
                    :search-keys="['name','code','city']"
                    v-bind="{ placeholder: t('master.approval_chains.f_all') }"
                  />
                </div>
                <div>
                  <label class="label">{{ t('master.approval_chains.f_grade') }}</label>
                  <SearchableSelect
                    v-model="newAssignment.job_grade_id"
                    :items="jobGrades"
                    value-key="id"
                    label-key="name"
                    :search-keys="['name','code']"
                    v-bind="{ placeholder: t('master.approval_chains.f_all') }"
                  />
                </div>
                <div>
                  <label class="label">{{ t('master.approval_chains.f_priority') }}</label>
                  <input v-model.number="newAssignment.priority" type="number" class="input" />
                </div>
                <div class="flex items-end">
                  <button v-if="canManage" type="button" class="btn-primary w-full" :disabled="assignmentSaving" @click="addAssignment">
                    <PlusIcon class="w-4 h-4" /> {{ assignmentSaving ? t('common.processing') : t('master.approval_chains.btn_add') }}
                  </button>
                </div>
              </div>
              <div class="text-xs text-gray-400 mt-2">
                {{ t('master.approval_chains.assignment_priority_hint') }}
              </div>
            </div>

            <div class="overflow-x-auto" v-if="form.id">
              <table class="table">
                <thead>
                  <tr>
                    <th>{{ t('master.approval_chains.col_dept') }}</th>
                    <th>{{ t('master.approval_chains.col_branch') }}</th>
                    <th>{{ t('master.approval_chains.col_grade') }}</th>
                    <th>{{ t('master.approval_chains.col_priority') }}</th>
                    <th class="text-right">{{ t('master.approval_chains.col_actions') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <tr v-if="assignments.length === 0">
                    <td colspan="5" class="text-center py-6 text-gray-400">{{ t('master.approval_chains.no_mappings') }}</td>
                  </tr>
                  <tr v-for="a in assignments" :key="a.id">
                    <td>{{ a.department_name || t('master.approval_chains.f_all') }}</td>
                    <td>{{ a.branch_name || t('master.approval_chains.f_all') }}</td>
                    <td>{{ a.grade_name || t('master.approval_chains.f_all') }}</td>
                    <td>{{ a.priority }}</td>
                    <td class="text-right">
                      <button v-if="canManage" type="button" class="text-gray-500 hover:text-red-600" :disabled="deletingAssignmentId !== null" @click="removeAssignment(a)" v-bind="{ title: t('master.approval_chains.delete'), 'aria-label': t('master.approval_chains.delete') }">
                        <TrashIcon class="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2 bg-white">
          <button type="button" class="btn-secondary" :disabled="saving" @click="showEditor = false">{{ t('master.approval_chains.btn_close') }}</button>
          <button v-if="canManage" type="button" class="btn-primary" :disabled="saving" @click="save">{{ saving ? t('common.saving') : (editing ? t('master.approval_chains.btn_save_changes') : t('master.approval_chains.btn_save')) }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
