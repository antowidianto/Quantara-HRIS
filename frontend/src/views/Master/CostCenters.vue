<script setup>
/**
 * Cost Center Master — list, tree, CRUD, budget per periode, dan
 * laporan budget vs actual.
 *
 * Tab:
 *   1. List   — flat table dengan filter & paging
 *   2. Tree   — hirarki visual (parent/child)
 *   3. Budget — per cost center: set budget annual/quarterly/monthly,
 *               dan lihat utilization vs actual
 *
 * Dipakai untuk dimensi accounting transaksi: payroll, reimbursement,
 * loan, business trip, bonus, severance. Lihat docs/COST_CENTER.md.
 */
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon, PencilIcon, TrashIcon, MagnifyingGlassIcon,
  ChartBarIcon, BanknotesIcon, CheckCircleIcon, ChevronRightIcon,
  ArrowPathIcon, BuildingOffice2Icon,
} from '@heroicons/vue/24/outline'
import CostCenterNode from './CostCenterNode.vue'
import EmployeePicker from '@/components/EmployeePicker.vue'
import ExcelImporter from '@/components/ExcelImporter.vue'

const toast = useToast()
const { t, locale } = useI18n()

// ─── State ──────────────────────────────────────────────────────
const tab = ref('list') // 'list' | 'tree' | 'budget'

const items = ref([])
const meta = ref({ total: 0, page: 1, pages: 1, per_page: 50 })
const loading = ref(false)
const params = reactive({ page: 1, per_page: 50, q: '', is_active: '' })

const treeData = ref([])
const treeLoading = ref(false)
const treeExpanded = ref({}) // id → bool

// CRUD modal
const showModal = ref(false)
const editing = ref(false)
const form = ref(emptyForm())
const saving = ref(false)
const parentOptions = ref([]) // picker for parent select
const departments = ref([])
const branches = ref([])

// Budget tab
const selectedCcId = ref(null)
const selectedCc = ref(null)
const budgetYear = ref(new Date().getFullYear())
const budgetRows = ref([])
const budgetLoading = ref(false)
const bvaReport = ref(null)
const bvaLoading = ref(false)

// Budget add row
const showBudgetForm = ref(false)
const budgetForm = ref(emptyBudgetForm())

// ─── Categories (sync with backend CostCenterService::BUDGET_CATEGORIES) ──
const CATEGORIES = computed(() => [
  { value: '',          label: t('cost_centers.category_all') },
  { value: 'SALARY',    label: t('cost_centers.category_salary') },
  { value: 'ALLOWANCE', label: t('cost_centers.category_allowance') },
  { value: 'OVERTIME',  label: t('cost_centers.category_overtime') },
  { value: 'BONUS',     label: t('cost_centers.category_bonus') },
  { value: 'BENEFIT',   label: t('cost_centers.category_benefit') },
  { value: 'TRAINING',  label: t('cost_centers.category_training') },
  { value: 'TRAVEL',    label: t('cost_centers.category_travel') },
  { value: 'SEVERANCE', label: t('cost_centers.category_severance') },
  { value: 'REIMBURSE', label: t('cost_centers.category_reimburse') },
  { value: 'ASSET',     label: t('cost_centers.category_asset') },
  { value: 'OTHER',     label: t('cost_centers.category_other') },
])

const PERIOD_TYPES = computed(() => [
  { value: 'ANNUAL',    label: t('cost_centers.period_annual') },
  { value: 'QUARTERLY', label: t('cost_centers.period_quarterly') },
  { value: 'MONTHLY',   label: t('cost_centers.period_monthly') },
])

const tabs = computed(() => [
  { id: 'list', label: t('cost_centers.tab_list'), icon: BuildingOffice2Icon },
  { id: 'tree', label: t('cost_centers.tab_tree'), icon: ChevronRightIcon },
  { id: 'budget', label: t('cost_centers.tab_budget'), icon: ChartBarIcon },
])

function emptyForm() {
  return {
    code: '',
    name: '',
    description: '',
    parent_id: null,
    default_branch_id: null,
    default_department_id: null,
    manager_employee_id: null,
    budget_yearly: 0,
    is_active: 1,
    sort_order: 0,
    notes: '',
  }
}

function emptyBudgetForm() {
  return {
    period_year: new Date().getFullYear(),
    period_type: 'ANNUAL',
    period_no: null,
    category: '',
    amount: 0,
    notes: '',
  }
}

// ─── List tab ───────────────────────────────────────────────────
async function fetchList() {
  loading.value = true
  try {
    const res = await api.get('/cost-centers', { params })
    items.value = res.data.data || []
    meta.value = res.data.meta || meta.value
  } catch (e) {
    toast.error(e.response?.data?.message || t('cost_centers.load_failed'))
  } finally {
    loading.value = false
  }
}

let qTimer = null
watch(() => params.q, () => {
  if (qTimer) clearTimeout(qTimer)
  qTimer = setTimeout(() => { params.page = 1; fetchList() }, 300)
})
watch(() => params.is_active, () => { params.page = 1; fetchList() })

function go(page) { params.page = page; fetchList() }

// ─── Tree tab ───────────────────────────────────────────────────
async function fetchTree() {
  treeLoading.value = true
  try {
    const res = await api.get('/cost-centers/tree', {
      params: { include_inactive: 1 },
    })
    treeData.value = res.data.data || []
    // Expand top-level by default
    treeData.value.forEach(n => { treeExpanded.value[n.id] = true })
  } catch (e) {
    toast.error(e.response?.data?.message || t('cost_centers.tree_load_failed'))
  } finally {
    treeLoading.value = false
  }
}

function toggleNode(id) {
  treeExpanded.value[id] = ! treeExpanded.value[id]
}

// ─── Picker / refs ──────────────────────────────────────────────
async function loadPickers() {
  try {
    const [picker, deps, brs] = await Promise.all([
      api.get('/cost-centers/picker'),
      api.get('/departments', { params: { per_page: 500 } }),
      api.get('/branches', { params: { per_page: 500 } }),
    ])
    parentOptions.value = picker.data.data || []
    departments.value = deps.data.data || []
    branches.value = brs.data.data || []
  } catch (_) { /* silent */ }
}

/**
 * Refresh hanya picker parent (dipanggil sebelum buka modal Create/Edit
 * supaya cost center yang baru dibuat ikut muncul di dropdown).
 */
async function reloadParentOptions() {
  try {
    const res = await api.get('/cost-centers/picker')
    parentOptions.value = res.data.data || []
  } catch (_) { /* silent */ }
}

// ─── CRUD ──────────────────────────────────────────────────────
function openCreate() {
  form.value = emptyForm()
  editing.value = false
  showModal.value = true
  // Refresh parent dropdown supaya CC yang baru dibuat ikut muncul.
  reloadParentOptions()
}

function openEdit(item) {
  form.value = { ...emptyForm(), ...item }
  // Coerce nullables to null (not 0/empty string) for parent/select
  for (const k of ['parent_id', 'default_branch_id', 'default_department_id', 'manager_employee_id']) {
    if (! form.value[k]) form.value[k] = null
  }
  editing.value = true
  showModal.value = true
  // Refresh parent dropdown supaya semua CC terbaru muncul (kecuali diri sendiri).
  reloadParentOptions()
}

async function saveForm() {
  saving.value = true
  try {
    if (editing.value) {
      await api.put(`/cost-centers/${form.value.id}`, form.value)
      toast.success(t('cost_centers.updated'))
    } else {
      await api.post('/cost-centers', form.value)
      toast.success(t('cost_centers.created'))
    }
    showModal.value = false
    // Refresh list + picker supaya CC baru langsung tersedia sebagai parent.
    await Promise.all([fetchList(), reloadParentOptions()])
    if (tab.value === 'tree') await fetchTree()
  } catch (e) {
    const msg = e.response?.data?.message
    const errs = e.response?.data?.errors
    if (Array.isArray(errs)) {
      toast.error(`${msg}: ${errs.join(', ')}`)
    } else {
      toast.error(msg || t('cost_centers.save_failed'))
    }
  } finally {
    saving.value = false
  }
}

async function remove(item) {
  const ok = await confirmDialog({
    title: t('cost_centers.delete_title'),
    message: t('cost_centers.delete_message', { code: item.code, name: item.name }),
    okText: t('cost_centers.delete_action'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/cost-centers/${item.id}`)
    toast.success(t('cost_centers.deleted'))
    fetchList()
    reloadParentOptions()
    if (tab.value === 'tree') fetchTree()
  } catch (e) {
    toast.error(e.response?.data?.message || t('cost_centers.delete_failed'))
  }
}

async function backfillEmployees() {
  const ok = await confirmDialog({
    title: t('cost_centers.backfill_title'),
    message: t('cost_centers.backfill_message'),
    okText: t('cost_centers.backfill'),
  })
  if (! ok) return
  try {
    const res = await api.post('/cost-centers/backfill-employees')
    const d = res.data.data
    toast.success(t('cost_centers.backfill_result', { updated: d.updated, skipped: d.skipped }))
  } catch (e) {
    toast.error(e.response?.data?.message || t('cost_centers.backfill_failed'))
  }
}

// ─── Budget tab ─────────────────────────────────────────────────
async function selectCostCenterForBudget(id) {
  selectedCcId.value = id
  selectedCc.value = items.value.find(i => i.id === id)
    || parentOptions.value.find(i => i.id === id)
    || null
  if (! selectedCc.value && id) {
    try {
      const res = await api.get(`/cost-centers/${id}`)
      selectedCc.value = res.data.data
    } catch (_) {}
  }
  await Promise.all([fetchBudgets(), fetchBudgetVsActual()])
}

async function fetchBudgets() {
  if (! selectedCcId.value) return
  budgetLoading.value = true
  try {
    const res = await api.get(`/cost-centers/${selectedCcId.value}/budgets`, {
      params: { year: budgetYear.value },
    })
    budgetRows.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || t('cost_centers.budget_load_failed'))
  } finally {
    budgetLoading.value = false
  }
}

async function fetchBudgetVsActual() {
  if (! selectedCcId.value) return
  bvaLoading.value = true
  try {
    const res = await api.get(
      `/cost-centers/${selectedCcId.value}/budget-vs-actual`,
      { params: { year: budgetYear.value } },
    )
    bvaReport.value = res.data.data
  } catch (e) {
    bvaReport.value = null
    toast.error(e.response?.data?.message || t('cost_centers.report_load_failed'))
  } finally {
    bvaLoading.value = false
  }
}

watch(budgetYear, () => {
  if (selectedCcId.value) {
    fetchBudgets()
    fetchBudgetVsActual()
  }
})

function openBudgetForm() {
  budgetForm.value = emptyBudgetForm()
  budgetForm.value.period_year = budgetYear.value
  showBudgetForm.value = true
}

async function saveBudget() {
  if (! selectedCcId.value) return
  if (budgetForm.value.amount < 0) {
    toast.error(t('cost_centers.amount_negative'))
    return
  }
  // Normalize period_no
  const payload = { ...budgetForm.value }
  if (payload.period_type === 'ANNUAL') payload.period_no = null
  if (! payload.category) payload.category = null

  try {
    await api.post(`/cost-centers/${selectedCcId.value}/budgets`, {
      budgets: [payload],
    })
    toast.success(t('cost_centers.budget_saved'))
    showBudgetForm.value = false
    await fetchBudgets()
    await fetchBudgetVsActual()
  } catch (e) {
    toast.error(e.response?.data?.message || t('cost_centers.budget_save_failed'))
  }
}

async function removeBudget(row) {
  const ok = await confirmDialog({
    title: t('cost_centers.budget_delete_title'),
    message: t('cost_centers.budget_delete_message', { periodType: periodTypeLabel(row.period_type), year: row.period_year, periodNo: row.period_no ? `-${row.period_no}` : '' }),
    okText: t('cost_centers.delete_action'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/cost-center-budgets/${row.id}`)
    toast.success(t('cost_centers.budget_deleted'))
    fetchBudgets()
    fetchBudgetVsActual()
  } catch (e) {
    toast.error(e.response?.data?.message || t('cost_centers.delete_failed'))
  }
}

// ─── Helpers ────────────────────────────────────────────────────
function periodTypeLabel(type) {
  return PERIOD_TYPES.value.find(period => period.value === type)?.label || type
}

function periodLabel(row) {
  if (row.period_type === 'ANNUAL') return t('cost_centers.annual_label', { year: row.period_year })
  if (row.period_type === 'QUARTERLY') return `Q${row.period_no} ${row.period_year}`
  if (row.period_type === 'MONTHLY') {
    const date = new Date(row.period_year, row.period_no - 1, 1)
    return new Intl.DateTimeFormat(locale.value === 'en' ? 'en-US' : 'id-ID', { month: 'short', year: 'numeric' }).format(date)
  }
  return `${row.period_type} ${row.period_year}`
}

function categoryLabel(value) {
  const found = CATEGORIES.value.find(c => c.value === value)
  return found?.label || value || t('cost_centers.category_all')
}

function fmtCurrency(value) {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value) || 0)
}

function utilColor(pct) {
  if (pct == null) return 'text-gray-400'
  if (pct < 80) return 'text-green-600'
  if (pct < 100) return 'text-amber-600'
  return 'text-red-600'
}

const showPeriodNo = computed(() => budgetForm.value.period_type !== 'ANNUAL')

// ─── Init ───────────────────────────────────────────────────────
onMounted(async () => {
  await loadPickers()
  await fetchList()
})

watch(tab, async (val) => {
  if (val === 'tree' && treeData.value.length === 0) await fetchTree()
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <BuildingOffice2Icon class="w-6 h-6 text-primary-600" />
          {{ t('cost_centers.title') }}
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">
          {{ t('cost_centers.subtitle') }}
        </p>
      </div>
      <div class="flex gap-2 flex-wrap">
        <ExcelImporter
          resource="cost_centers"
          :button-label="t('cost_centers.import_excel')"
          @committed="fetchList(); fetchTree()"
        />
        <button type="button" class="btn-secondary" @click="backfillEmployees">
          <ArrowPathIcon class="w-4 h-4" />
          {{ t('cost_centers.backfill') }}
        </button>
        <button type="button" class="btn-primary" @click="openCreate">
          <PlusIcon class="w-4 h-4" />
          {{ t('cost_centers.add') }}
        </button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 dark:border-gray-700 mb-4">
      <nav class="-mb-px flex gap-4">
        <button
          v-for="tabOption in tabs"
          :key="tabOption.id"
          type="button"
          @click="tab = tabOption.id"
          class="py-2 px-1 border-b-2 font-medium text-sm flex items-center gap-1.5"
          :class="tab === tabOption.id
            ? 'border-primary-600 text-primary-600'
            : 'border-transparent text-gray-500 hover:text-gray-700'"
        >
          <component :is="tabOption.icon" class="w-4 h-4" />
          {{ tabOption.label }}
        </button>
      </nav>
    </div>

    <!-- ─── LIST TAB ─── -->
    <div v-if="tab === 'list'" class="card">
      <div class="card-header flex flex-wrap gap-3 items-center">
        <div class="relative w-full md:w-64">
          <MagnifyingGlassIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input v-model="params.q" v-bind="{ placeholder: t('cost_centers.search_ph') }" class="input pl-9" />
        </div>
        <select v-model="params.is_active" class="input w-auto">
          <option value="">{{ t('cost_centers.all_statuses') }}</option>
          <option value="1">{{ t('cost_centers.active') }}</option>
          <option value="0">{{ t('cost_centers.inactive') }}</option>
        </select>
      </div>

      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('cost_centers.col_code') }}</th>
              <th>{{ t('cost_centers.col_name') }}</th>
              <th>{{ t('cost_centers.col_parent') }}</th>
              <th>{{ t('cost_centers.col_manager') }}</th>
              <th class="text-right">{{ t('cost_centers.col_annual_budget') }}</th>
              <th>{{ t('cost_centers.col_status') }}</th>
              <th class="text-right">{{ t('cost_centers.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            <tr v-if="loading">
              <td colspan="7" class="text-center py-8 text-gray-400">{{ t('cost_centers.loading') }}</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td colspan="7" class="text-center py-8 text-gray-400">{{ t('cost_centers.no_cost_centers') }}</td>
            </tr>
            <tr v-for="item in items" :key="item.id">
              <td class="font-mono">{{ item.code }}</td>
              <td>
                <div class="font-medium">{{ item.name }}</div>
                <div v-if="item.description" class="text-xs text-gray-500">{{ item.description }}</div>
              </td>
              <td>
                <span v-if="item.parent_code" class="text-xs">
                  {{ item.parent_code }} — {{ item.parent_name }}
                </span>
                <span v-else class="text-xs text-gray-400">{{ t('cost_centers.root') }}</span>
              </td>
              <td>
                <span v-if="item.manager_name" class="text-xs">{{ item.manager_name }}</span>
                <span v-else class="text-xs text-gray-400">—</span>
              </td>
              <td class="text-right tabular-nums">{{ fmtCurrency(item.budget_yearly) }}</td>
              <td>
                <span
                  class="px-2 py-0.5 rounded-full text-xs font-medium"
                  :class="item.is_active
                    ? 'bg-green-100 text-green-700'
                    : 'bg-gray-100 text-gray-600'"
                >
                  {{ item.is_active ? t('cost_centers.active') : t('cost_centers.inactive') }}
                </span>
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button
                    type="button"
                    class="text-gray-500 hover:text-primary-600"
                    v-bind="{ title: t('cost_centers.view_budget'), 'aria-label': t('cost_centers.view_budget') }"
                    @click="tab = 'budget'; selectCostCenterForBudget(item.id)"
                  >
                    <ChartBarIcon class="w-4 h-4" />
                  </button>
                  <button type="button" class="text-gray-500 hover:text-primary-600" v-bind="{ title: t('cost_centers.edit'), 'aria-label': t('cost_centers.edit') }" @click="openEdit(item)">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button
                    v-if="item.code !== 'DEFAULT'"
                    type="button" class="text-gray-500 hover:text-red-600"
                    v-bind="{ title: t('cost_centers.delete_action'), 'aria-label': t('cost_centers.delete_action') }"
                    @click="remove(item)"
                  >
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card-body flex items-center justify-between">
        <div class="text-sm text-gray-500">{{ t('cost_centers.total', { count: meta.total }) }}</div>
        <div class="flex gap-2">
          <button type="button" class="btn-secondary" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('cost_centers.previous') }}</button>
          <span class="px-3 py-2 text-sm">{{ meta.page }} / {{ meta.pages || 1 }}</span>
          <button type="button" class="btn-secondary" :disabled="meta.page >= meta.pages" @click="go(meta.page + 1)">{{ t('cost_centers.next') }}</button>
        </div>
      </div>
    </div>

    <!-- ─── TREE TAB ─── -->
    <div v-if="tab === 'tree'" class="card">
      <div class="card-body">
        <div v-if="treeLoading" class="text-center py-8 text-gray-400">{{ t('cost_centers.loading') }}</div>
        <div v-else-if="treeData.length === 0" class="text-center py-8 text-gray-400">
          {{ t('cost_centers.no_tree') }}
        </div>
        <ul v-else class="space-y-1">
          <li v-for="node in treeData" :key="node.id">
            <CostCenterNode
              :node="node"
              :expanded="treeExpanded"
              @toggle="toggleNode"
              @edit="openEdit"
              @view-budget="(id) => { tab = 'budget'; selectCostCenterForBudget(id) }"
            />
          </li>
        </ul>
      </div>
    </div>

    <!-- ─── BUDGET TAB ─── -->
    <div v-if="tab === 'budget'" class="space-y-4">
      <!-- Picker -->
      <div class="card">
        <div class="card-body flex flex-wrap gap-3 items-center">
          <label class="label mb-0">{{ t('cost_centers.cost_center') }}</label>
          <select
            :value="selectedCcId"
            @change="(e) => selectCostCenterForBudget(Number(e.target.value) || null)"
            class="input w-auto min-w-[16rem]"
          >
            <option :value="null">{{ t('cost_centers.select_cost_center') }}</option>
            <option v-for="cc in parentOptions" :key="cc.id" :value="cc.id">
              {{ cc.code }} — {{ cc.name }}
              <template v-if="cc.parent_code">({{ cc.parent_code }})</template>
            </option>
          </select>

          <label class="label mb-0">{{ t('cost_centers.year') }}</label>
          <input
            v-model.number="budgetYear"
            type="number"
            class="input w-28"
            :min="2000"
            :max="2100"
          />

          <button v-if="selectedCcId" type="button" class="btn-primary ml-auto" @click="openBudgetForm">
            <PlusIcon class="w-4 h-4" />
            {{ t('cost_centers.add_budget') }}
          </button>
        </div>
      </div>

      <!-- Budget vs Actual summary -->
      <div v-if="selectedCcId && bvaReport" class="grid gap-4 md:grid-cols-4">
        <div class="card">
          <div class="card-body">
            <div class="text-xs text-gray-500 uppercase">{{ t('cost_centers.budget_year', { year: budgetYear }) }}</div>
            <div class="text-xl font-bold mt-1">{{ fmtCurrency(bvaReport.budget) }}</div>
          </div>
        </div>
        <div class="card">
          <div class="card-body">
            <div class="text-xs text-gray-500 uppercase">{{ t('cost_centers.actual_ytd') }}</div>
            <div class="text-xl font-bold mt-1">{{ fmtCurrency(bvaReport.actual) }}</div>
            <div class="text-xs mt-1" :class="utilColor(bvaReport.utilization_pct)">
              {{ t('cost_centers.utilization', { percent: bvaReport.utilization_pct }) }}
            </div>
            <div class="text-[10px] text-gray-400 mt-0.5">{{ t('cost_centers.actual_help') }}</div>
          </div>
        </div>
        <div class="card">
          <div class="card-body">
            <div class="text-xs text-gray-500 uppercase">{{ t('cost_centers.committed') }}</div>
            <div class="text-xl font-bold mt-1 text-amber-700">
              {{ fmtCurrency(bvaReport.committed || 0) }}
            </div>
            <div class="text-[10px] text-gray-400 mt-1">
              {{ t('cost_centers.committed_help') }}
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-body">
            <div class="text-xs text-gray-500 uppercase">{{ t('cost_centers.forecast_total') }}</div>
            <div
              class="text-xl font-bold mt-1"
              :class="(bvaReport.forecast_variance ?? 0) >= 0 ? 'text-green-600' : 'text-red-600'"
            >
              {{ fmtCurrency(bvaReport.forecast || bvaReport.actual) }}
            </div>
            <div class="text-xs mt-1" :class="utilColor(bvaReport.forecast_pct)">
              {{ t('cost_centers.forecast', { percent: bvaReport.forecast_pct }) }}
              <span v-if="(bvaReport.forecast_variance ?? 0) < 0" class="ml-1 text-red-600 font-semibold">
                {{ t('cost_centers.over_budget') }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Committed breakdown — sumber estimasi forecast -->
      <div v-if="selectedCcId && bvaReport && (bvaReport.committed || 0) > 0" class="card">
        <div class="card-header">
          <h3 class="font-semibold flex items-center gap-2">
            <span>{{ t('cost_centers.committed_sources') }}</span>
            <span class="text-xs font-normal text-gray-500">{{ t('cost_centers.committed_sources_help') }}</span>
          </h3>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>{{ t('cost_centers.col_source') }}</th>
                <th class="text-right">{{ t('cost_centers.col_item_count') }}</th>
                <th class="text-right">{{ t('cost_centers.col_estimated_cost') }}</th>
                <th>{{ t('cost_centers.col_notes') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <tr v-for="(b, i) in (bvaReport.committed_breakdown || [])" :key="i">
                <td class="font-mono text-xs">{{ b.source }}</td>
                <td class="text-right tabular-nums">{{ b.count }}</td>
                <td class="text-right tabular-nums text-amber-700">{{ fmtCurrency(b.amount) }}</td>
                <td class="text-xs text-gray-500">{{ b.note || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Per-category breakdown -->
      <div v-if="selectedCcId && bvaReport" class="card">
        <div class="card-header">
          <h3 class="font-semibold">{{ t('cost_centers.category_breakdown') }}</h3>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>{{ t('cost_centers.col_category') }}</th>
                <th class="text-right">{{ t('cost_centers.col_budget') }}</th>
                <th class="text-right">{{ t('cost_centers.col_actual') }}</th>
                <th class="text-right">{{ t('cost_centers.col_committed') }}</th>
                <th class="text-right">{{ t('cost_centers.col_forecast') }}</th>
                <th class="text-right">{{ t('cost_centers.col_forecast_vs_budget') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <tr v-for="(row, cat) in bvaReport.by_category" :key="cat">
                <td class="font-medium">{{ categoryLabel(cat) }}</td>
                <td class="text-right tabular-nums">{{ fmtCurrency(row.budget) }}</td>
                <td class="text-right tabular-nums">{{ fmtCurrency(row.actual) }}</td>
                <td class="text-right tabular-nums text-amber-700">
                  {{ fmtCurrency(row.committed || 0) }}
                </td>
                <td class="text-right tabular-nums font-medium">
                  {{ fmtCurrency(row.forecast || row.actual) }}
                </td>
                <td class="text-right tabular-nums" :class="utilColor(row.forecast_pct)">
                  <span v-if="row.forecast_pct != null">{{ row.forecast_pct }}%</span>
                  <span v-else class="text-gray-400">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Budget rows -->
      <div v-if="selectedCcId" class="card">
        <div class="card-header">
          <h3 class="font-semibold">{{ t('cost_centers.budget_detail', { year: budgetYear }) }}</h3>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>{{ t('cost_centers.col_period') }}</th>
                <th>{{ t('cost_centers.col_category') }}</th>
                <th class="text-right">{{ t('cost_centers.col_amount') }}</th>
                <th>{{ t('cost_centers.col_notes') }}</th>
                <th class="text-right">{{ t('cost_centers.col_actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <tr v-if="budgetLoading">
                <td colspan="5" class="text-center py-6 text-gray-400">{{ t('cost_centers.loading') }}</td>
              </tr>
              <tr v-else-if="budgetRows.length === 0">
                <td colspan="5" class="text-center py-6 text-gray-400">
                  {{ t('cost_centers.no_budget_year') }}
                </td>
              </tr>
              <tr v-for="row in budgetRows" :key="row.id">
                <td>{{ periodLabel(row) }}</td>
                <td>{{ categoryLabel(row.category) }}</td>
                <td class="text-right tabular-nums">{{ fmtCurrency(row.amount) }}</td>
                <td class="text-xs text-gray-500">{{ row.notes || '—' }}</td>
                <td class="text-right">
                  <button type="button" class="text-gray-500 hover:text-red-600" v-bind="{ title: t('cost_centers.delete_action'), 'aria-label': t('cost_centers.delete_action') }" @click="removeBudget(row)">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ─── CRUD MODAL ─── -->
    <div v-if="showModal" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white dark:bg-gray-900 rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto" role="dialog" aria-modal="true" v-bind="{ 'aria-label': editing ? t('cost_centers.edit_title') : t('cost_centers.add_title') }">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
          <h2 class="font-semibold">{{ editing ? t('cost_centers.edit_title') : t('cost_centers.add_title') }}</h2>
          <button type="button" class="text-gray-500" v-bind="{ title: t('cost_centers.close'), 'aria-label': t('cost_centers.close') }" @click="showModal = false">&times;</button>
        </div>
        <form @submit.prevent="saveForm" class="px-6 py-4 space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">{{ t('cost_centers.code_required') }}</label>
              <input
                v-model="form.code"
                class="input font-mono uppercase"
                required
                :disabled="editing"
                v-bind="{ placeholder: t('cost_centers.code_ph') }"
              />
              <p class="text-xs text-gray-500 mt-1">{{ t('cost_centers.code_help') }}</p>
            </div>
            <div>
              <label class="label">{{ t('cost_centers.name_required') }}</label>
              <input v-model="form.name" class="input" required />
            </div>
          </div>

          <div>
            <label class="label">{{ t('cost_centers.description') }}</label>
            <textarea v-model="form.description" class="input" rows="2"></textarea>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">{{ t('cost_centers.parent_cost_center') }}</label>
              <select v-model="form.parent_id" class="input">
                <option :value="null">{{ t('cost_centers.root_option') }}</option>
                <option
                  v-for="o in parentOptions.filter(o => !editing || o.id !== form.id)"
                  :key="o.id"
                  :value="o.id"
                >
                  {{ o.code }} — {{ o.name }}
                </option>
              </select>
            </div>
            <div>
              <label class="label">
                {{ t('cost_centers.manager') }}
                <span class="text-xs font-normal text-gray-500">
                  {{ t('cost_centers.manager_level') }}
                </span>
              </label>
              <EmployeePicker
                v-model="form.manager_employee_id"
                :min-grade-level="7"
                v-bind="{ placeholder: t('cost_centers.manager_ph'), 'help-text': t('cost_centers.manager_help') }"
              />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">{{ t('cost_centers.default_branch') }}</label>
              <select v-model="form.default_branch_id" class="input">
                <option :value="null">—</option>
                <option v-for="b in branches" :key="b.id" :value="b.id">
                  {{ b.code }} — {{ b.name }}
                </option>
              </select>
            </div>
            <div>
              <label class="label">{{ t('cost_centers.default_department') }}</label>
              <select v-model="form.default_department_id" class="input">
                <option :value="null">—</option>
                <option v-for="d in departments" :key="d.id" :value="d.id">
                  {{ d.code }} — {{ d.name }}
                </option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="label">{{ t('cost_centers.annual_budget_cache') }}</label>
              <input v-model.number="form.budget_yearly" type="number" class="input" min="0" />
              <p class="text-xs text-gray-500 mt-1">{{ t('cost_centers.annual_budget_help') }}</p>
            </div>
            <div>
              <label class="label">{{ t('cost_centers.sort_order') }}</label>
              <input v-model.number="form.sort_order" type="number" class="input" />
            </div>
            <div>
              <label class="label">{{ t('cost_centers.status') }}</label>
              <select v-model.number="form.is_active" class="input">
                <option :value="1">{{ t('cost_centers.active') }}</option>
                <option :value="0">{{ t('cost_centers.inactive') }}</option>
              </select>
            </div>
          </div>

          <div>
            <label class="label">{{ t('cost_centers.notes') }}</label>
            <textarea v-model="form.notes" class="input" rows="2"></textarea>
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showModal = false">{{ t('cost_centers.cancel') }}</button>
            <button type="submit" class="btn-primary" :disabled="saving">
              <CheckCircleIcon class="w-4 h-4" />
              {{ saving ? t('cost_centers.saving') : t('cost_centers.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ─── BUDGET FORM MODAL ─── -->
    <div v-if="showBudgetForm" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white dark:bg-gray-900 rounded-lg shadow-xl w-full max-w-md" role="dialog" aria-modal="true" v-bind="{ 'aria-label': t('cost_centers.budget_form_title') }">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
          <h2 class="font-semibold">{{ t('cost_centers.budget_form_title') }}</h2>
          <button type="button" class="text-gray-500" v-bind="{ title: t('cost_centers.close'), 'aria-label': t('cost_centers.close') }" @click="showBudgetForm = false">&times;</button>
        </div>
        <form @submit.prevent="saveBudget" class="px-6 py-4 space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">{{ t('cost_centers.year_required') }}</label>
              <input v-model.number="budgetForm.period_year" type="number" class="input" required />
            </div>
            <div>
              <label class="label">{{ t('cost_centers.period_type_required') }}</label>
              <select v-model="budgetForm.period_type" class="input">
                <option v-for="p in PERIOD_TYPES" :key="p.value" :value="p.value">{{ p.label }}</option>
              </select>
            </div>
          </div>

          <div v-if="showPeriodNo">
            <label class="label">
              {{ t('cost_centers.period_number') }}
              <span class="text-xs text-gray-500">
                ({{ budgetForm.period_type === 'QUARTERLY' ? t('cost_centers.period_quarter_hint') : t('cost_centers.period_month_hint') }})
              </span>
            </label>
            <input v-model.number="budgetForm.period_no" type="number" class="input"
              :min="1" :max="budgetForm.period_type === 'QUARTERLY' ? 4 : 12" required />
          </div>

          <div>
            <label class="label">{{ t('cost_centers.category_optional') }}</label>
            <select v-model="budgetForm.category" class="input">
              <option v-for="c in CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">
              {{ t('cost_centers.category_help') }}
            </p>
          </div>

          <div>
            <label class="label">{{ t('cost_centers.amount_required') }}</label>
            <input v-model.number="budgetForm.amount" type="number" class="input" min="0" required />
          </div>

          <div>
            <label class="label">{{ t('cost_centers.notes') }}</label>
            <textarea v-model="budgetForm.notes" class="input" rows="2"></textarea>
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showBudgetForm = false">{{ t('cost_centers.cancel') }}</button>
            <button type="submit" class="btn-primary">
              <BanknotesIcon class="w-4 h-4" />
              {{ t('cost_centers.save_budget') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

