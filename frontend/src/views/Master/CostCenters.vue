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
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtCurrency } from '@/composables/useResource'
import {
  PlusIcon, PencilIcon, TrashIcon, MagnifyingGlassIcon,
  ChartBarIcon, BanknotesIcon, CheckCircleIcon, ChevronRightIcon,
  ChevronDownIcon, ArrowPathIcon, BuildingOffice2Icon,
} from '@heroicons/vue/24/outline'
import CostCenterNode from './CostCenterNode.vue'
import EmployeePicker from '@/components/EmployeePicker.vue'
import ExcelImporter from '@/components/ExcelImporter.vue'

const toast = useToast()

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
const CATEGORIES = [
  { value: '',           label: '— Semua kategori —' },
  { value: 'SALARY',     label: 'Gaji' },
  { value: 'ALLOWANCE',  label: 'Tunjangan' },
  { value: 'OVERTIME',   label: 'Lembur' },
  { value: 'BONUS',      label: 'Bonus / THR' },
  { value: 'BENEFIT',    label: 'BPJS Employer' },
  { value: 'TRAINING',   label: 'Training' },
  { value: 'TRAVEL',     label: 'Business Trip' },
  { value: 'SEVERANCE',  label: 'Pesangon' },
  { value: 'REIMBURSE',  label: 'Reimbursement' },
  { value: 'ASSET',      label: 'Asset' },
  { value: 'OTHER',      label: 'Lain-lain' },
]

const PERIOD_TYPES = [
  { value: 'ANNUAL',    label: 'Tahunan' },
  { value: 'QUARTERLY', label: 'Triwulan' },
  { value: 'MONTHLY',   label: 'Bulanan' },
]

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
    toast.error(e.response?.data?.message || 'Gagal memuat data')
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
    toast.error(e.response?.data?.message || 'Gagal memuat tree')
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
      toast.success('Cost center diperbarui')
    } else {
      await api.post('/cost-centers', form.value)
      toast.success('Cost center dibuat')
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
      toast.error(msg || 'Gagal menyimpan')
    }
  } finally {
    saving.value = false
  }
}

async function remove(item) {
  const ok = await confirmDialog({
    title: 'Hapus cost center',
    message: `Yakin hapus cost center ${item.code} - ${item.name}?`,
    okText: 'Hapus',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/cost-centers/${item.id}`)
    toast.success('Cost center dihapus')
    fetchList()
    reloadParentOptions()
    if (tab.value === 'tree') fetchTree()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

async function backfillEmployees() {
  const ok = await confirmDialog({
    title: 'Backfill cost center karyawan',
    message: 'Apply default cost_center_id ke karyawan yang belum di-tag (idempotent — yang sudah di-tag tidak diubah). Lanjutkan?',
    okText: 'Backfill',
  })
  if (! ok) return
  try {
    const res = await api.post('/cost-centers/backfill-employees')
    const d = res.data.data
    toast.success(`Selesai: ${d.updated} karyawan ditag, ${d.skipped} dilewati`)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal backfill')
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
    toast.error(e.response?.data?.message || 'Gagal memuat budget')
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
    toast.error(e.response?.data?.message || 'Gagal memuat laporan')
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
    toast.error('Amount tidak boleh negatif')
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
    toast.success('Budget disimpan')
    showBudgetForm.value = false
    await fetchBudgets()
    await fetchBudgetVsActual()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan budget')
  }
}

async function removeBudget(row) {
  const ok = await confirmDialog({
    title: 'Hapus budget',
    message: `Hapus budget ${row.period_type} ${row.period_year}${row.period_no ? '-' + row.period_no : ''}?`,
    okText: 'Hapus',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/cost-center-budgets/${row.id}`)
    toast.success('Budget dihapus')
    fetchBudgets()
    fetchBudgetVsActual()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

// ─── Helpers ────────────────────────────────────────────────────
function periodLabel(row) {
  if (row.period_type === 'ANNUAL') return `Tahunan ${row.period_year}`
  if (row.period_type === 'QUARTERLY') return `Q${row.period_no} ${row.period_year}`
  if (row.period_type === 'MONTHLY') {
    const months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des']
    return `${months[(row.period_no - 1)] || row.period_no} ${row.period_year}`
  }
  return `${row.period_type} ${row.period_year}`
}

function categoryLabel(value) {
  const found = CATEGORIES.find(c => c.value === value)
  return found?.label || value || '— Semua —'
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
          Cost Center
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">
          Dimensi accounting untuk transaksi payroll, reimbursement, loan, travel, bonus, dan severance.
        </p>
      </div>
      <div class="flex gap-2 flex-wrap">
        <ExcelImporter
          resource="cost_centers"
          button-label="Import Excel"
          @committed="fetchList(); fetchTree()"
        />
        <button class="btn-secondary" @click="backfillEmployees">
          <ArrowPathIcon class="w-4 h-4" />
          Backfill Karyawan
        </button>
        <button class="btn-primary" @click="openCreate">
          <PlusIcon class="w-4 h-4" />
          Tambah
        </button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 dark:border-gray-700 mb-4">
      <nav class="-mb-px flex gap-4">
        <button
          v-for="t in [
            { id: 'list',   label: 'List',                icon: BuildingOffice2Icon },
            { id: 'tree',   label: 'Hirarki',             icon: ChevronRightIcon },
            { id: 'budget', label: 'Budget vs Actual',    icon: ChartBarIcon },
          ]"
          :key="t.id"
          @click="tab = t.id"
          class="py-2 px-1 border-b-2 font-medium text-sm flex items-center gap-1.5"
          :class="tab === t.id
            ? 'border-primary-600 text-primary-600'
            : 'border-transparent text-gray-500 hover:text-gray-700'"
        >
          <component :is="t.icon" class="w-4 h-4" />
          {{ t.label }}
        </button>
      </nav>
    </div>

    <!-- ─── LIST TAB ─── -->
    <div v-if="tab === 'list'" class="card">
      <div class="card-header flex flex-wrap gap-3 items-center">
        <div class="relative w-full md:w-64">
          <MagnifyingGlassIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input v-model="params.q" placeholder="Cari kode/nama..." class="input pl-9" />
        </div>
        <select v-model="params.is_active" class="input w-auto">
          <option value="">Semua status</option>
          <option value="1">Aktif</option>
          <option value="0">Non-aktif</option>
        </select>
      </div>

      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Kode</th>
              <th>Nama</th>
              <th>Parent</th>
              <th>Manager</th>
              <th class="text-right">Budget Tahunan</th>
              <th>Status</th>
              <th class="text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            <tr v-if="loading">
              <td colspan="7" class="text-center py-8 text-gray-400">Memuat...</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td colspan="7" class="text-center py-8 text-gray-400">Tidak ada cost center</td>
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
                <span v-else class="text-xs text-gray-400">root</span>
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
                  {{ item.is_active ? 'Aktif' : 'Non-aktif' }}
                </span>
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button
                    class="text-gray-500 hover:text-primary-600"
                    title="Lihat budget"
                    @click="tab = 'budget'; selectCostCenterForBudget(item.id)"
                  >
                    <ChartBarIcon class="w-4 h-4" />
                  </button>
                  <button class="text-gray-500 hover:text-primary-600" @click="openEdit(item)">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button
                    v-if="item.code !== 'DEFAULT'"
                    class="text-gray-500 hover:text-red-600"
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
        <div class="text-sm text-gray-500">Total: {{ meta.total }}</div>
        <div class="flex gap-2">
          <button class="btn-secondary" :disabled="meta.page <= 1" @click="go(meta.page - 1)">Sebelumnya</button>
          <span class="px-3 py-2 text-sm">{{ meta.page }} / {{ meta.pages || 1 }}</span>
          <button class="btn-secondary" :disabled="meta.page >= meta.pages" @click="go(meta.page + 1)">Berikutnya</button>
        </div>
      </div>
    </div>

    <!-- ─── TREE TAB ─── -->
    <div v-if="tab === 'tree'" class="card">
      <div class="card-body">
        <div v-if="treeLoading" class="text-center py-8 text-gray-400">Memuat...</div>
        <div v-else-if="treeData.length === 0" class="text-center py-8 text-gray-400">
          Belum ada cost center
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
          <label class="label mb-0">Cost Center:</label>
          <select
            :value="selectedCcId"
            @change="(e) => selectCostCenterForBudget(Number(e.target.value) || null)"
            class="input w-auto min-w-[16rem]"
          >
            <option :value="null">— Pilih cost center —</option>
            <option v-for="cc in parentOptions" :key="cc.id" :value="cc.id">
              {{ cc.code }} — {{ cc.name }}
              <template v-if="cc.parent_code">({{ cc.parent_code }})</template>
            </option>
          </select>

          <label class="label mb-0">Tahun:</label>
          <input
            v-model.number="budgetYear"
            type="number"
            class="input w-28"
            :min="2000"
            :max="2100"
          />

          <button v-if="selectedCcId" class="btn-primary ml-auto" @click="openBudgetForm">
            <PlusIcon class="w-4 h-4" />
            Tambah Budget
          </button>
        </div>
      </div>

      <!-- Budget vs Actual summary -->
      <div v-if="selectedCcId && bvaReport" class="grid gap-4 md:grid-cols-4">
        <div class="card">
          <div class="card-body">
            <div class="text-xs text-gray-500 uppercase">Budget {{ budgetYear }}</div>
            <div class="text-xl font-bold mt-1">{{ fmtCurrency(bvaReport.budget) }}</div>
          </div>
        </div>
        <div class="card">
          <div class="card-body">
            <div class="text-xs text-gray-500 uppercase">Actual YTD</div>
            <div class="text-xl font-bold mt-1">{{ fmtCurrency(bvaReport.actual) }}</div>
            <div class="text-xs mt-1" :class="utilColor(bvaReport.utilization_pct)">
              Utilization: {{ bvaReport.utilization_pct }}%
            </div>
            <div class="text-[10px] text-gray-400 mt-0.5">Sudah direalisasi (payslip + reimbursement, dll)</div>
          </div>
        </div>
        <div class="card">
          <div class="card-body">
            <div class="text-xs text-gray-500 uppercase">Committed</div>
            <div class="text-xl font-bold mt-1 text-amber-700">
              {{ fmtCurrency(bvaReport.committed || 0) }}
            </div>
            <div class="text-[10px] text-gray-400 mt-1">
              MPP APPROVED, hire baru, bonus DRAFT, reimburse pending
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-body">
            <div class="text-xs text-gray-500 uppercase">Forecast Total</div>
            <div
              class="text-xl font-bold mt-1"
              :class="(bvaReport.forecast_variance ?? 0) >= 0 ? 'text-green-600' : 'text-red-600'"
            >
              {{ fmtCurrency(bvaReport.forecast || bvaReport.actual) }}
            </div>
            <div class="text-xs mt-1" :class="utilColor(bvaReport.forecast_pct)">
              Forecast: {{ bvaReport.forecast_pct }}%
              <span v-if="(bvaReport.forecast_variance ?? 0) < 0" class="ml-1 text-red-600 font-semibold">
                ⚠ over budget
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Committed breakdown — sumber estimasi forecast -->
      <div v-if="selectedCcId && bvaReport && (bvaReport.committed || 0) > 0" class="card">
        <div class="card-header">
          <h3 class="font-semibold flex items-center gap-2">
            <span>Sumber Committed</span>
            <span class="text-xs font-normal text-gray-500">— biaya yang sudah di-pesan tapi belum direalisasi</span>
          </h3>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Sumber</th>
                <th class="text-right">Jumlah Item</th>
                <th class="text-right">Estimasi Biaya</th>
                <th>Catatan</th>
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
          <h3 class="font-semibold">Breakdown per Kategori</h3>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Kategori</th>
                <th class="text-right">Budget</th>
                <th class="text-right">Actual</th>
                <th class="text-right">Committed</th>
                <th class="text-right">Forecast</th>
                <th class="text-right">Forecast vs Budget</th>
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
          <h3 class="font-semibold">Detail Budget {{ budgetYear }}</h3>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Periode</th>
                <th>Kategori</th>
                <th class="text-right">Amount</th>
                <th>Notes</th>
                <th class="text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <tr v-if="budgetLoading">
                <td colspan="5" class="text-center py-6 text-gray-400">Memuat...</td>
              </tr>
              <tr v-else-if="budgetRows.length === 0">
                <td colspan="5" class="text-center py-6 text-gray-400">
                  Belum ada budget untuk tahun ini
                </td>
              </tr>
              <tr v-for="row in budgetRows" :key="row.id">
                <td>{{ periodLabel(row) }}</td>
                <td>{{ categoryLabel(row.category) }}</td>
                <td class="text-right tabular-nums">{{ fmtCurrency(row.amount) }}</td>
                <td class="text-xs text-gray-500">{{ row.notes || '—' }}</td>
                <td class="text-right">
                  <button class="text-gray-500 hover:text-red-600" @click="removeBudget(row)">
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
      <div class="bg-white dark:bg-gray-900 rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
          <h2 class="font-semibold">{{ editing ? 'Edit Cost Center' : 'Tambah Cost Center' }}</h2>
          <button class="text-gray-500" @click="showModal = false">&times;</button>
        </div>
        <form @submit.prevent="saveForm" class="px-6 py-4 space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Kode *</label>
              <input
                v-model="form.code"
                class="input font-mono uppercase"
                required
                :disabled="editing"
                placeholder="e.g. HQ-FIN"
              />
              <p class="text-xs text-gray-500 mt-1">Huruf, angka, hyphen, underscore. Min 1 huruf.</p>
            </div>
            <div>
              <label class="label">Nama *</label>
              <input v-model="form.name" class="input" required />
            </div>
          </div>

          <div>
            <label class="label">Deskripsi</label>
            <textarea v-model="form.description" class="input" rows="2"></textarea>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Parent Cost Center</label>
              <select v-model="form.parent_id" class="input">
                <option :value="null">— Root (no parent) —</option>
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
                Manager
                <span class="text-xs font-normal text-gray-500">
                  (level Manager keatas)
                </span>
              </label>
              <EmployeePicker
                v-model="form.manager_employee_id"
                :min-grade-level="7"
                placeholder="— Tidak ada / cari & pilih manager —"
                help-text="Hanya menampilkan Manager, GM/VP, Direktur, dan Komisaris. Ketik untuk cari nama, NIK, atau email."
              />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Default Branch</label>
              <select v-model="form.default_branch_id" class="input">
                <option :value="null">—</option>
                <option v-for="b in branches" :key="b.id" :value="b.id">
                  {{ b.code }} — {{ b.name }}
                </option>
              </select>
            </div>
            <div>
              <label class="label">Default Department</label>
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
              <label class="label">Budget Tahunan (cache)</label>
              <input v-model.number="form.budget_yearly" type="number" class="input" min="0" />
              <p class="text-xs text-gray-500 mt-1">Auto-update saat set budget detail.</p>
            </div>
            <div>
              <label class="label">Sort Order</label>
              <input v-model.number="form.sort_order" type="number" class="input" />
            </div>
            <div>
              <label class="label">Status</label>
              <select v-model.number="form.is_active" class="input">
                <option :value="1">Aktif</option>
                <option :value="0">Non-aktif</option>
              </select>
            </div>
          </div>

          <div>
            <label class="label">Notes</label>
            <textarea v-model="form.notes" class="input" rows="2"></textarea>
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showModal = false">Batal</button>
            <button type="submit" class="btn-primary" :disabled="saving">
              <CheckCircleIcon class="w-4 h-4" />
              {{ saving ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ─── BUDGET FORM MODAL ─── -->
    <div v-if="showBudgetForm" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white dark:bg-gray-900 rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
          <h2 class="font-semibold">Tambah / Update Budget</h2>
          <button class="text-gray-500" @click="showBudgetForm = false">&times;</button>
        </div>
        <form @submit.prevent="saveBudget" class="px-6 py-4 space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Tahun *</label>
              <input v-model.number="budgetForm.period_year" type="number" class="input" required />
            </div>
            <div>
              <label class="label">Tipe Periode *</label>
              <select v-model="budgetForm.period_type" class="input">
                <option v-for="p in PERIOD_TYPES" :key="p.value" :value="p.value">{{ p.label }}</option>
              </select>
            </div>
          </div>

          <div v-if="showPeriodNo">
            <label class="label">
              Periode No
              <span class="text-xs text-gray-500">
                ({{ budgetForm.period_type === 'QUARTERLY' ? 'Q1-Q4' : 'Jan=1 ... Des=12' }})
              </span>
            </label>
            <input v-model.number="budgetForm.period_no" type="number" class="input"
              :min="1" :max="budgetForm.period_type === 'QUARTERLY' ? 4 : 12" required />
          </div>

          <div>
            <label class="label">Kategori (opsional)</label>
            <select v-model="budgetForm.category" class="input">
              <option v-for="c in CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">
              Kosongkan kalau ini budget gabungan (semua kategori).
            </p>
          </div>

          <div>
            <label class="label">Amount (Rp) *</label>
            <input v-model.number="budgetForm.amount" type="number" class="input" min="0" required />
          </div>

          <div>
            <label class="label">Notes</label>
            <textarea v-model="budgetForm.notes" class="input" rows="2"></textarea>
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showBudgetForm = false">Batal</button>
            <button type="submit" class="btn-primary">
              <BanknotesIcon class="w-4 h-4" />
              Simpan Budget
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

