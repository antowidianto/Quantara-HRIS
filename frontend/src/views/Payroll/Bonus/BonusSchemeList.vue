<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/api/client'
import { fmtCurrency } from '@/composables/useResource'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon,
  PencilSquareIcon,
  TrashIcon,
  BeakerIcon,
  SparklesIcon,
} from '@heroicons/vue/24/outline'
import { templatesByCategory } from '@/composables/bonusTemplates'

/**
 * Bonus Engine — Scheme List & Form (Sprint 19+)
 *
 * Mengelola template skema bonus reusable. HR define formula sekali,
 * lalu bisa dipakai berkali-kali untuk run berbeda (annual 2027,
 * KPI Q1, retention bonus, dst).
 *
 * Backend: docs/BONUS_ENGINE.md untuk struktur & validasi.
 */
const toast = useToast()
const items = ref([])
const loading = ref(false)
const showForm = ref(false)
const showPreview = ref(false)
const showTemplates = ref(false)
const editing = ref(null)

const templateGroups = templatesByCategory()

const KIND_OPTIONS = [
  { value: 'ANNUAL',        label: 'Annual / Tahunan' },
  { value: 'KPI',           label: 'KPI / Performance' },
  { value: 'MILESTONE',     label: 'Milestone (tenure / project)' },
  { value: 'SIGNON',        label: 'Sign-on Bonus' },
  { value: 'RETENTION',     label: 'Retention Bonus' },
  { value: 'DISCRETIONARY', label: 'Discretionary' },
]
const FORMULA_TYPES = [
  { value: 'FIXED_AMOUNT', label: 'Nominal Tetap' },
  { value: 'PCT_OF_BASIC', label: '% dari Basic Salary' },
  { value: 'PCT_OF_GROSS', label: '% dari Gross (basic + allowance)' },
  { value: 'TENURE_BASED', label: 'Tenure × Rate' },
  { value: 'KPI_SCORE',    label: 'Basic × % × KPI Score' },
  { value: 'FORMULA_EXPR', label: 'Custom Expression' },
]
const TAX_TREATMENTS = [
  { value: 'NORMAL',      label: 'Normal — taxable, dipotong PPh21 dari amount' },
  { value: 'NON_TAXABLE', label: 'Non-Pajak — tidak masuk grossTaxable' },
  { value: 'GROSS_UP',    label: 'Gross-Up — employer absorb pajak (Sprint 18+)' },
  { value: 'NET',         label: 'Net — nominal di kontrak adalah net (Sprint 18+)' },
]

const blankForm = () => ({
  code: '',
  name: '',
  kind: 'ANNUAL',
  formula_type: 'FIXED_AMOUNT',
  formula_value: 0,
  formula_expr: '',
  cap_amount: '',
  tax_treatment: 'NORMAL',
  description: '',
  eligibility: {
    employment_status: ['PKWTT'],
    min_tenure_months: 0,
    exclude_resigned: true,
    department_ids: [],
    branch_ids: [],
    grade_ids: [],
    position_ids: [],
  },
  is_active: true,
})

const form = ref(blankForm())
const errors = ref({})

// Preview state
const previewForm = ref({ employee_id: null, reference_date: new Date().toISOString().slice(0, 10) })
const previewResult = ref(null)
const previewLoading = ref(false)

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/bonus-schemes', { params: { per_page: 100 } })
    items.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat skema')
  } finally {
    loading.value = false
  }
}
onMounted(fetchData)

function openCreate() {
  editing.value = null
  form.value = blankForm()
  errors.value = {}
  // Tampilkan picker template dulu — HR boleh skip dan mulai dari blank form
  showTemplates.value = true
}

function openBlankForm() {
  showTemplates.value = false
  showForm.value = true
}

function applyTemplate(tpl) {
  // Reset form ke blank dulu supaya field lama tidak bocor antar template
  form.value = blankForm()
  tpl.apply(form.value)
  errors.value = {}
  showTemplates.value = false
  showForm.value = true
  toast.success(`Template "${tpl.label}" diterapkan. Sesuaikan angka, lalu simpan.`)
}
function openEdit(row) {
  editing.value = row
  form.value = {
    ...blankForm(),
    ...row,
    cap_amount: row.cap_amount ?? '',
    eligibility: row.eligibility || blankForm().eligibility,
  }
  errors.value = {}
  showForm.value = true
}
async function save() {
  errors.value = {}
  if (! form.value.code) errors.value.code = 'Wajib diisi'
  if (! form.value.name) errors.value.name = 'Wajib diisi'
  if (form.value.formula_type === 'FORMULA_EXPR' && ! form.value.formula_expr) {
    errors.value.formula_expr = 'Expression wajib diisi'
  }
  if (Object.keys(errors.value).length > 0) return

  const payload = { ...form.value }
  if (payload.cap_amount === '' || payload.cap_amount === null) {
    payload.cap_amount = null
  }
  try {
    if (editing.value) {
      await api.put(`/bonus-schemes/${editing.value.id}`, payload)
      toast.success('Skema diperbarui')
    } else {
      await api.post('/bonus-schemes', payload)
      toast.success('Skema dibuat')
    }
    showForm.value = false
    fetchData()
  } catch (e) {
    if (e.response?.data?.errors) errors.value = e.response.data.errors
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}

async function remove(row) {
  const ok = await confirmDialog({
    title: 'Hapus skema bonus?',
    message: `Skema "${row.name}" akan dihapus (soft-delete). Run yang sudah dibuat tidak terpengaruh.`,
    okText: 'Hapus',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/bonus-schemes/${row.id}`)
    toast.success('Skema dihapus')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

function openPreview(row) {
  editing.value = row
  previewForm.value = { employee_id: null, reference_date: new Date().toISOString().slice(0, 10) }
  previewResult.value = null
  showPreview.value = true
}

async function runPreview() {
  if (! previewForm.value.employee_id) {
    toast.error('Pilih karyawan dulu')
    return
  }
  previewLoading.value = true
  previewResult.value = null
  try {
    const res = await api.post('/bonus-schemes/preview', {
      bonus_scheme_id: editing.value.id,
      employee_id: previewForm.value.employee_id,
      reference_date: previewForm.value.reference_date,
    })
    previewResult.value = res.data.data
  } catch (e) {
    // Tampilkan pesan dari backend secara lengkap supaya user/HR bisa
    // tahu sebab kegagalan (mis. employee tidak ditemukan, formula_type
    // invalid, dll). Fallback ke status code kalau body tidak ada.
    const status = e.response?.status
    const msg = e.response?.data?.message
      || e.response?.data?.error
      || (status ? `Server error ${status}` : 'Gagal preview')
    toast.error(msg, { timeout: 6000 })
    // Simpan detail di console untuk debugging tanpa expose ke user
    console.error('[bonus preview]', status, e.response?.data || e.message)
  } finally {
    previewLoading.value = false
  }
}

const formulaPlaceholder = computed(() => {
  switch (form.value.formula_type) {
    case 'FIXED_AMOUNT': return 'Nominal Rp (mis. 5000000)'
    case 'PCT_OF_BASIC':
    case 'PCT_OF_GROSS': return 'Persen (mis. 100 untuk 100% / 1× basic)'
    case 'TENURE_BASED': return 'Rate per tahun (mis. 100000 untuk 100rb / tahun masa kerja)'
    case 'KPI_SCORE':    return 'Persen base (mis. 100); akan dikali kpi_score karyawan'
    case 'FORMULA_EXPR': return '0 (pakai formula_expr untuk logic kompleks)'
    default: return ''
  }
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold">Bonus Schemes</h1>
        <p class="text-sm text-gray-500">Template skema bonus reusable. Buat sekali, pakai berkali-kali.</p>
      </div>
      <div class="flex items-center gap-2">
        <button @click="showTemplates = true" class="btn-secondary" title="Pilih dari template bonus Indonesia">
          <SparklesIcon class="w-4 h-4" /> Template
        </button>
        <button @click="openCreate" class="btn-primary">
          <PlusIcon class="w-4 h-4" /> Skema Baru
        </button>
      </div>
    </div>

    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Code</th>
              <th>Nama</th>
              <th>Kind</th>
              <th>Formula</th>
              <th>Tax</th>
              <th>Cap</th>
              <th>Status</th>
              <th class="text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="8" class="text-center py-8 text-gray-400">Memuat...</td></tr>
            <tr v-else-if="items.length === 0"><td colspan="8" class="text-center py-8 text-gray-400">Belum ada skema</td></tr>
            <tr v-for="s in items" :key="s.id">
              <td class="font-mono text-xs">{{ s.code }}</td>
              <td>{{ s.name }}</td>
              <td><span class="badge-blue">{{ s.kind }}</span></td>
              <td>
                <span class="text-xs">{{ s.formula_type }}</span>
                <span v-if="s.formula_type !== 'FIXED_AMOUNT' && s.formula_type !== 'FORMULA_EXPR'" class="text-xs text-gray-500 ml-1">
                  ({{ s.formula_value }})
                </span>
              </td>
              <td>
                <span :class="{
                  'badge-gray':  s.tax_treatment === 'NORMAL' || ! s.tax_treatment,
                  'badge-green': s.tax_treatment === 'NON_TAXABLE',
                  'badge-yellow': s.tax_treatment === 'GROSS_UP' || s.tax_treatment === 'NET',
                }">{{ s.tax_treatment || 'NORMAL' }}</span>
              </td>
              <td>{{ s.cap_amount ? fmtCurrency(s.cap_amount) : '—' }}</td>
              <td>
                <span :class="s.is_active ? 'badge-green' : 'badge-gray'">
                  {{ s.is_active ? 'Aktif' : 'Non-aktif' }}
                </span>
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button class="btn-secondary" @click="openPreview(s)" title="Preview compute">
                    <BeakerIcon class="w-4 h-4" />
                  </button>
                  <button class="btn-secondary" @click="openEdit(s)" title="Edit">
                    <PencilSquareIcon class="w-4 h-4" />
                  </button>
                  <button class="btn-danger" @click="remove(s)" title="Hapus">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Form Modal -->
    <div v-if="showForm" class="fixed inset-0 z-50 bg-black/40 flex items-start justify-center p-4 overflow-y-auto"
         @click.self="showForm = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl my-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 class="text-lg font-semibold">
            {{ editing ? 'Edit' : 'Buat' }} Bonus Scheme
          </h2>
          <button @click="showForm = false" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <div class="px-6 py-4 space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Code</label>
              <input v-model="form.code" class="input font-mono" placeholder="ANN-2027" />
              <p v-if="errors.code" class="text-xs text-red-600 mt-1">{{ errors.code }}</p>
            </div>
            <div>
              <label class="label">Nama</label>
              <input v-model="form.name" class="input" placeholder="Annual Bonus 2027" />
              <p v-if="errors.name" class="text-xs text-red-600 mt-1">{{ errors.name }}</p>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Kind</label>
              <select v-model="form.kind" class="input">
                <option v-for="k in KIND_OPTIONS" :key="k.value" :value="k.value">{{ k.label }}</option>
              </select>
            </div>
            <div>
              <label class="label">Tax Treatment</label>
              <select v-model="form.tax_treatment" class="input">
                <option v-for="t in TAX_TREATMENTS" :key="t.value" :value="t.value">{{ t.label }}</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Formula Type</label>
              <select v-model="form.formula_type" class="input">
                <option v-for="f in FORMULA_TYPES" :key="f.value" :value="f.value">{{ f.label }}</option>
              </select>
            </div>
            <div>
              <label class="label">Formula Value</label>
              <input v-model.number="form.formula_value" type="number" step="0.01" class="input"
                     :placeholder="formulaPlaceholder" />
            </div>
          </div>

          <div v-if="form.formula_type === 'FORMULA_EXPR'">
            <label class="label">Expression</label>
            <input v-model="form.formula_expr" class="input font-mono"
                   placeholder="basic * 0.1 + tenure_years * 50000" />
            <p class="text-xs text-gray-500 mt-1">
              Variable: <code>basic</code>, <code>gross</code>, <code>tenure_months</code>,
              <code>tenure_years</code>, <code>kpi_score</code>, <code>value</code>.
              Operator: <code>+ - * / ( )</code> + literal angka.
            </p>
            <p v-if="errors.formula_expr" class="text-xs text-red-600 mt-1">{{ errors.formula_expr }}</p>
          </div>

          <div>
            <label class="label">Cap Amount (max nominal per karyawan, opsional)</label>
            <input v-model.number="form.cap_amount" type="number" step="0.01" class="input"
                   placeholder="50000000 (kosongkan kalau tanpa cap)" />
          </div>

          <hr class="border-gray-200" />
          <h3 class="font-semibold text-sm">Eligibility</h3>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Min Tenure (bulan)</label>
              <input v-model.number="form.eligibility.min_tenure_months" type="number" min="0" class="input" />
            </div>
            <div>
              <label class="label">Exclude Resigned</label>
              <label class="inline-flex items-center gap-2 mt-2">
                <input v-model="form.eligibility.exclude_resigned" type="checkbox" />
                <span class="text-sm">Skip karyawan yang sudah resign per reference_date</span>
              </label>
            </div>
          </div>

          <div>
            <label class="label">Employment Status (whitelist; kosong = semua)</label>
            <div class="flex flex-wrap gap-3 mt-1">
              <label v-for="st in ['PKWTT', 'PKWT', 'PROBATION', 'HARIAN', 'MAGANG', 'OUTSOURCING']"
                     :key="st" class="inline-flex items-center gap-1 text-sm">
                <input type="checkbox"
                       :checked="form.eligibility.employment_status?.includes(st)"
                       @change="(e) => {
                         const arr = form.eligibility.employment_status || []
                         form.eligibility.employment_status = e.target.checked
                           ? [...arr, st]
                           : arr.filter(x => x !== st)
                       }" />
                {{ st }}
              </label>
            </div>
          </div>

          <div>
            <label class="label">Description (opsional)</label>
            <textarea v-model="form.description" class="input" rows="2"
                      placeholder="Catatan internal HR (contoh: 'Annual bonus 2027 — 1× gaji untuk PKWTT > 1 tahun')"></textarea>
          </div>

          <div>
            <label class="inline-flex items-center gap-2">
              <input v-model="form.is_active" type="checkbox" />
              <span class="text-sm">Aktif (siap dipakai untuk bikin run)</span>
            </label>
          </div>
        </div>
        <div class="px-6 py-3 border-t border-gray-200 bg-gray-50 flex justify-end gap-2">
          <button @click="showForm = false" class="btn-secondary">Batal</button>
          <button @click="save" class="btn-primary">
            {{ editing ? 'Simpan' : 'Buat' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Template Picker Modal -->
    <div v-if="showTemplates" class="fixed inset-0 z-50 bg-black/40 flex items-start justify-center p-4 overflow-y-auto"
         @click.self="showTemplates = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl my-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <div>
            <h2 class="text-lg font-semibold flex items-center gap-2">
              <SparklesIcon class="w-5 h-5 text-amber-500" />
              Pilih Template Bonus
            </h2>
            <p class="text-xs text-gray-500 mt-0.5">
              Template berdasarkan praktik HR Indonesia (THR, KPI, retention, dst). Pilih satu untuk
              pre-fill form, lalu sesuaikan angka sesuai kebijakan perusahaan.
            </p>
          </div>
          <button @click="showTemplates = false" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        <div class="px-6 py-4 max-h-[70vh] overflow-y-auto">
          <div v-for="group in templateGroups" :key="group.id" class="mb-6 last:mb-0">
            <div class="flex items-baseline gap-2 mb-3 border-b border-gray-100 pb-1">
              <h3 class="font-semibold text-sm text-gray-700">{{ group.label }}</h3>
              <span class="text-xs text-gray-400">{{ group.hint }}</span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <button
                v-for="tpl in group.templates"
                :key="tpl.id"
                type="button"
                @click="applyTemplate(tpl)"
                class="text-left p-3 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50/40 transition group"
              >
                <div class="flex items-start gap-2">
                  <span class="text-2xl leading-none">{{ tpl.icon }}</span>
                  <div class="flex-1 min-w-0">
                    <div class="font-semibold text-sm text-gray-900 group-hover:text-blue-700">
                      {{ tpl.label }}
                    </div>
                    <p class="text-xs text-gray-600 mt-1 leading-snug">{{ tpl.description }}</p>
                    <p class="text-xs text-gray-400 mt-1.5 italic">
                      <span class="font-medium not-italic text-gray-500">Contoh:</span> {{ tpl.example }}
                    </p>
                    <p class="text-[11px] text-gray-400 mt-1">
                      <span class="font-medium text-gray-500">Kapan:</span> {{ tpl.usage_note }}
                    </p>
                  </div>
                </div>
              </button>
            </div>
          </div>
        </div>

        <div class="px-6 py-3 border-t border-gray-200 bg-gray-50 flex items-center justify-between">
          <p class="text-xs text-gray-500">
            Tidak menemukan yang cocok? Mulai dari blank form dan custom sendiri.
          </p>
          <div class="flex gap-2">
            <button @click="showTemplates = false" class="btn-secondary">Batal</button>
            <button @click="openBlankForm" class="btn-primary">Mulai Blank</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Preview Modal -->
    <div v-if="showPreview" class="fixed inset-0 z-50 bg-black/40 flex items-start justify-center p-4 overflow-y-auto"
         @click.self="showPreview = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl my-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <div>
            <h2 class="text-lg font-semibold">Preview Compute — {{ editing?.name }}</h2>
            <p class="text-xs text-gray-500">Hitung pure tanpa simpan, untuk validasi formula</p>
          </div>
          <button @click="showPreview = false" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <div class="px-6 py-4 space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Employee ID</label>
              <input v-model.number="previewForm.employee_id" type="number" class="input"
                     placeholder="ID karyawan untuk simulasi" />
            </div>
            <div>
              <label class="label">Reference Date</label>
              <AppDatePicker v-model="previewForm.reference_date"  class="input" />
            </div>
          </div>
          <button @click="runPreview" :disabled="previewLoading" class="btn-primary">
            <BeakerIcon class="w-4 h-4" />
            {{ previewLoading ? 'Menghitung...' : 'Preview' }}
          </button>

          <div v-if="previewResult" class="border border-gray-200 rounded-lg p-4 bg-gray-50 mt-4">
            <h4 class="font-semibold text-sm mb-2">Hasil</h4>
            <dl class="text-sm space-y-1">
              <div class="flex justify-between">
                <dt class="text-gray-600">Eligible</dt>
                <dd>
                  <span :class="previewResult.eligible ? 'badge-green' : 'badge-red'">
                    {{ previewResult.eligible ? 'Yes' : 'No' }}
                  </span>
                </dd>
              </div>
              <div v-if="!previewResult.eligible && previewResult.reason" class="flex justify-between">
                <dt class="text-gray-600">Reason</dt>
                <dd>{{ previewResult.reason }}</dd>
              </div>
              <div v-if="previewResult.eligible" class="flex justify-between">
                <dt class="text-gray-600">Amount</dt>
                <dd class="font-bold text-lg">{{ fmtCurrency(previewResult.amount) }}</dd>
              </div>
              <div v-if="previewResult.eligible && previewResult.capped" class="flex justify-between">
                <dt class="text-gray-600">Capped</dt>
                <dd>
                  <span class="badge-yellow">Yes — original > cap_amount</span>
                </dd>
              </div>
            </dl>
            <details v-if="previewResult.breakdown" class="mt-3">
              <summary class="text-xs text-gray-500 cursor-pointer">Breakdown computation steps</summary>
              <pre class="text-xs bg-white p-2 rounded border border-gray-200 mt-2 overflow-x-auto">{{ JSON.stringify(previewResult.breakdown, null, 2) }}</pre>
            </details>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>