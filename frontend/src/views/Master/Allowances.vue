<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { fmtCurrency } from '@/composables/useResource'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { PlusIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import SearchableSelect from '@/components/SearchableSelect.vue'
import ExcelImporter from '@/components/ExcelImporter.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const tab = ref('types')   // 'types' | 'rates'

// Types
const types = ref([])
const showTypeModal = ref(false)
const editingType = ref(null)
const typeForm = ref(null)

// Rates
const rates = ref([])
const branches = ref([])
const grades = ref([])
const showRateModal = ref(false)
const editingRate = ref(null)
const rateForm = ref(null)
const filterTypeId = ref('')

const loading = ref(false)

const canManage = computed(() =>
  auth.isSuperAdmin || auth.permissions.includes('payroll.manage')
)

function blankType() {
  return {
    code: '',
    name: '',
    description: '',
    category: 'TRANSPORT',
    is_taxable: 1,
    is_distance_based: 1,
    default_threshold_km: 30,
    payroll_component_id: null,
    is_active: 1,
    // H2.10 — PMK 66/2023 Natura/Kenikmatan
    is_taxable_natura: 0,
    natura_tax_threshold: null,
  }
}
function blankRate() {
  return {
    allowance_type_id: types.value[0]?.id || null,
    job_grade_id: null,
    branch_id: null,
    threshold_km: 30,
    rate_below: 0,
    rate_above: 0,
    effective_from: '',
    effective_to: '',
    notes: '',
  }
}

async function fetchTypes() {
  try {
    const res = await api.get('/allowance-types')
    types.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.allowances.load_failed_types'))
  }
}
async function fetchRates() {
  try {
    const params = {}
    if (filterTypeId.value) params.allowance_type_id = filterTypeId.value
    const res = await api.get('/allowance-rates', { params })
    rates.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.allowances.load_failed_rates'))
  }
}
async function fetchMasters() {
  const [b, g] = await Promise.all([
    api.get('/branches', { params: { per_page: 100 } }),
    api.get('/job-grades', { params: { per_page: 100 } }),
  ])
  branches.value = b.data.data
  grades.value = g.data.data
}

onMounted(async () => {
  loading.value = true
  await Promise.all([fetchTypes(), fetchMasters()])
  await fetchRates()
  loading.value = false
})

// ── Types CRUD ──
function openCreateType() {
  editingType.value = null
  typeForm.value = blankType()
  showTypeModal.value = true
}
function openEditType(it) {
  editingType.value = it
  typeForm.value = { ...it }
  showTypeModal.value = true
}
async function saveType() {
  try {
    if (editingType.value) {
      await api.put(`/allowance-types/${editingType.value.id}`, typeForm.value)
      toast.success(t('master.allowances.type_updated'))
    } else {
      await api.post('/allowance-types', typeForm.value)
      toast.success(t('master.allowances.type_added'))
    }
    showTypeModal.value = false
    fetchTypes()
    fetchRates()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.allowances.save_failed'))
  }
}
async function removeType(it) {
  const ok = await confirmDialog({
    title: t('master.allowances.type_delete_title'),
    message: t('master.allowances.type_delete_msg', { name: it.name }),
    okText: t('master.allowances.delete_ok'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/allowance-types/${it.id}`)
    toast.success(t('master.allowances.type_deleted'))
    fetchTypes()
    fetchRates()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.allowances.delete_failed'))
  }
}

// ── Rates CRUD ──
function openCreateRate() {
  editingRate.value = null
  rateForm.value = blankRate()
  showRateModal.value = true
}
function openEditRate(r) {
  editingRate.value = r
  rateForm.value = { ...r }
  showRateModal.value = true
}
async function saveRate() {
  if (! rateForm.value.allowance_type_id) {
    toast.warning(t('master.allowances.pick_type_first'))
    return
  }
  try {
    if (editingRate.value) {
      await api.put(`/allowance-rates/${editingRate.value.id}`, rateForm.value)
      toast.success(t('master.allowances.rate_updated'))
    } else {
      await api.post('/allowance-rates', rateForm.value)
      toast.success(t('master.allowances.rate_added'))
    }
    showRateModal.value = false
    fetchRates()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.allowances.save_failed'))
  }
}
async function removeRate(r) {
  const ok = await confirmDialog({
    title: t('master.allowances.rate_delete_title'),
    message: t('master.allowances.rate_delete_msg'),
    okText: t('master.allowances.delete_ok'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/allowance-rates/${r.id}`)
    toast.success(t('master.allowances.rate_deleted'))
    fetchRates()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.allowances.delete_failed'))
  }
}

async function recomputeAll() {
  const ok = await confirmDialog({
    title: t('master.allowances.recompute_title'),
    message: t('master.allowances.recompute_msg'),
    okText: t('master.allowances.recompute_ok'),
  })
  if (! ok) return
  try {
    const res = await api.post('/allowances/recompute-company')
    toast.success(t('master.allowances.recompute_done', { count: res.data.data?.count || 0 }))
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.allowances.recompute_failed'))
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('master.allowances.title') }}</h1>
        <p class="text-sm text-gray-500">
          {{ t('master.allowances.subtitle') }}
        </p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <ExcelImporter
          v-if="auth.can('allowance_types.bulk_import')"
          resource="allowance_types"
          :button-label="t('master.allowances.import_excel')"
          @committed="fetchTypes()"
        />
        <button v-if="canManage" class="btn-secondary" @click="recomputeAll">{{ t('master.allowances.recompute_all') }}</button>
      </div>
    </div>

    <div class="flex gap-2 mb-4 border-b border-gray-200">
      <button
        :class="['px-4 py-2 -mb-px border-b-2',
          tab === 'types' ? 'border-primary-600 text-primary-700 font-semibold' : 'border-transparent text-gray-600']"
        @click="tab = 'types'"
      >{{ t('master.allowances.tab_types') }}</button>
      <button
        :class="['px-4 py-2 -mb-px border-b-2',
          tab === 'rates' ? 'border-primary-600 text-primary-700 font-semibold' : 'border-transparent text-gray-600']"
        @click="tab = 'rates'"
      >{{ t('master.allowances.tab_rates') }}</button>
    </div>

    <!-- ── TYPES ─────────────────────────────────────────── -->
    <div v-if="tab === 'types'" class="card">
      <div class="card-header justify-between">
        <span class="text-sm text-gray-600">{{ t('master.allowances.types_subtitle') }}</span>
        <button v-if="canManage" class="btn-primary" @click="openCreateType">
          <PlusIcon class="w-4 h-4" /> {{ t('master.allowances.add_type') }}
        </button>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr>
            <th>{{ t('master.allowances.col_code') }}</th>
            <th>{{ t('master.allowances.col_name') }}</th>
            <th>{{ t('master.allowances.col_category') }}</th>
            <th>{{ t('master.allowances.col_threshold') }}</th>
            <th>{{ t('master.allowances.col_distance_based') }}</th>
            <th>{{ t('master.allowances.col_taxable') }}</th>
            <th>{{ t('master.allowances.col_status') }}</th>
            <th class="text-right">{{ t('master.allowances.col_actions') }}</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="types.length === 0"><td colspan="8" class="text-center py-6 text-gray-400">{{ t('master.allowances.no_types') }}</td></tr>
            <tr v-for="it in types" :key="it.id">
              <td class="font-mono">{{ it.code }}</td>
              <td>
                <div class="font-medium">{{ it.name }}</div>
                <div class="text-xs text-gray-500">{{ it.description }}</div>
              </td>
              <td><span class="badge-blue">{{ it.category }}</span></td>
              <td>{{ t('master.allowances.km_unit', { n: Number(it.default_threshold_km).toFixed(2) }) }}</td>
              <td>{{ it.is_distance_based ? t('master.allowances.yes') : t('master.allowances.flat') }}</td>
              <td>{{ it.is_taxable ? t('master.allowances.yes') : t('master.allowances.no') }}</td>
              <td><span :class="it.is_active ? 'badge-green' : 'badge-gray'">{{ it.is_active ? t('master.allowances.active') : t('master.allowances.inactive') }}</span></td>
              <td class="text-right">
                <button v-if="canManage" class="text-gray-500 hover:text-primary-600 mr-2" @click="openEditType(it)"><PencilIcon class="w-4 h-4" /></button>
                <button v-if="canManage" class="text-gray-500 hover:text-red-600" @click="removeType(it)"><TrashIcon class="w-4 h-4" /></button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── RATES ─────────────────────────────────────────── -->
    <div v-else class="card">
      <div class="card-header justify-between gap-3">
        <div class="w-64">
          <SearchableSelect
            v-model="filterTypeId"
            :items="types"
            value-key="id"
            label-key="name"
            :search-keys="['name','code','category']"
            :placeholder="t('master.allowances.all_types_filter')"
            @change="fetchRates"
          />
        </div>
        <button v-if="canManage" class="btn-primary" @click="openCreateRate" :disabled="types.length === 0">
          <PlusIcon class="w-4 h-4" /> {{ t('master.allowances.add_rate') }}
        </button>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr>
            <th>{{ t('master.allowances.col_type') }}</th>
            <th>{{ t('master.allowances.col_branch') }}</th>
            <th>{{ t('master.allowances.col_grade') }}</th>
            <th>{{ t('master.allowances.col_threshold') }}</th>
            <th>{{ t('master.allowances.col_rate_below') }}</th>
            <th>{{ t('master.allowances.col_rate_above') }}</th>
            <th>{{ t('master.allowances.col_period') }}</th>
            <th class="text-right">{{ t('master.allowances.col_actions') }}</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="rates.length === 0"><td colspan="8" class="text-center py-6 text-gray-400">{{ t('master.allowances.no_rates') }}</td></tr>
            <tr v-for="r in rates" :key="r.id">
              <td>
                <div class="font-medium">{{ r.type_name }}</div>
                <div class="text-xs text-gray-500">{{ r.type_code }}</div>
              </td>
              <td>{{ r.branch_name || t('master.allowances.all_branches') }}</td>
              <td>{{ r.grade_name || t('master.allowances.all_grades') }}</td>
              <td>{{ t('master.allowances.km_unit', { n: Number(r.threshold_km).toFixed(2) }) }}</td>
              <td>{{ fmtCurrency(r.rate_below) }}</td>
              <td class="font-semibold">{{ fmtCurrency(r.rate_above) }}</td>
              <td class="text-xs">
                <div v-if="r.effective_from || r.effective_to">
                  {{ t('master.allowances.until_to', {
                    from: r.effective_from || t('master.allowances.empty_dash'),
                    to: r.effective_to || t('master.allowances.empty_dash'),
                  }) }}
                </div>
                <div v-else class="text-gray-400">{{ t('master.allowances.always_active') }}</div>
              </td>
              <td class="text-right">
                <button v-if="canManage" class="text-gray-500 hover:text-primary-600 mr-2" @click="openEditRate(r)"><PencilIcon class="w-4 h-4" /></button>
                <button v-if="canManage" class="text-gray-500 hover:text-red-600" @click="removeRate(r)"><TrashIcon class="w-4 h-4" /></button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── TYPE MODAL ────────────────────────────────────── -->
    <div v-if="showTypeModal" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/50">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ editingType ? t('master.allowances.modal_type_edit') : t('master.allowances.modal_type_add') }}</h2>
          <button class="text-gray-400 hover:text-gray-600 text-2xl leading-none" @click="showTypeModal = false">&times;</button>
        </div>
        <form @submit.prevent="saveType" class="p-5 space-y-3 text-sm">
          <div class="grid grid-cols-2 gap-3">
            <div><label class="label">{{ t('master.allowances.f_code_required') }}</label><input v-model="typeForm.code" class="input" required :disabled="!!editingType" /></div>
            <div><label class="label">{{ t('master.allowances.f_category') }}</label>
              <select v-model="typeForm.category" class="input">
                <option value="TRANSPORT">{{ t('master.allowances.cat_transport') }}</option>
                <option value="MEAL">{{ t('master.allowances.cat_meal') }}</option>
                <option value="HOUSING">{{ t('master.allowances.cat_housing') }}</option>
                <option value="ALLOWANCE">{{ t('master.allowances.cat_allowance') }}</option>
                <option value="OTHER">{{ t('master.allowances.cat_other') }}</option>
              </select>
            </div>
          </div>
          <div><label class="label">{{ t('master.allowances.f_name_required') }}</label><input v-model="typeForm.name" class="input" required /></div>
          <div><label class="label">{{ t('master.allowances.f_description') }}</label><textarea v-model="typeForm.description" class="input" rows="2"></textarea></div>
          <div class="grid grid-cols-2 gap-3">
            <label class="flex items-center gap-2"><input type="checkbox" v-model="typeForm.is_distance_based" :true-value="1" :false-value="0" /><span>{{ t('master.allowances.f_distance_based') }}</span></label>
            <label class="flex items-center gap-2"><input type="checkbox" v-model="typeForm.is_taxable" :true-value="1" :false-value="0" /><span>{{ t('master.allowances.f_taxable') }}</span></label>
          </div>
          <div v-if="typeForm.is_distance_based">
            <label class="label">{{ t('master.allowances.f_default_threshold') }}</label>
            <input v-model.number="typeForm.default_threshold_km" type="number" min="0" step="0.5" class="input" />
            <p class="text-xs text-gray-500 mt-1">{{ t('master.allowances.f_default_threshold_hint') }}</p>
          </div>

          <!-- H2.10 — Natura / Kenikmatan (PMK 66/2023) -->
          <div class="rounded-md border border-amber-200 bg-amber-50 p-3 space-y-2">
            <label class="flex items-start gap-2">
              <input type="checkbox" v-model="typeForm.is_taxable_natura"
                     :true-value="1" :false-value="0" class="mt-1" />
              <span>
                <span class="font-medium">Natura / Kenikmatan (PMK 66/2023)</span>
                <span class="block text-xs text-amber-800">
                  Komponen non-tunai (mobil dinas, perumahan, gym, dll). Akan menambah
                  basis Penghasilan Bruto PPh21 namun tidak menambah cash Take Home Pay.
                </span>
              </span>
            </label>
            <div v-if="typeForm.is_taxable_natura">
              <label class="label text-xs">Ambang batas tahunan bebas pajak (Rp)</label>
              <input v-model.number="typeForm.natura_tax_threshold" type="number"
                     min="0" step="100000" class="input"
                     placeholder="Contoh: 3000000 untuk paket sembako" />
              <p class="text-xs text-amber-800 mt-1">
                Kosongkan jika seluruh nilai natura langsung taxable. Threshold dipecah
                proporsional bulanan saat perhitungan PPh21.
              </p>
            </div>
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showTypeModal = false">{{ t('master.allowances.f_cancel') }}</button>
            <button type="submit" class="btn-primary">{{ t('master.allowances.f_save') }}</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── RATE MODAL ────────────────────────────────────── -->
    <div v-if="showRateModal" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/50">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ editingRate ? t('master.allowances.modal_rate_edit') : t('master.allowances.modal_rate_add') }}</h2>
          <button class="text-gray-400 hover:text-gray-600 text-2xl leading-none" @click="showRateModal = false">&times;</button>
        </div>
        <form @submit.prevent="saveRate" class="p-5 space-y-3 text-sm">
          <div>
            <label class="label">{{ t('master.allowances.f_type_required') }}</label>
            <SearchableSelect
              v-model="rateForm.allowance_type_id"
              :items="types"
              value-key="id"
              label-key="name"
              sub-label-key="category"
              :search-keys="['name','code','category']"
              :placeholder="t('master.allowances.f_type_ph')"
              :clearable="false"
            />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">{{ t('master.allowances.f_branch') }}</label>
              <SearchableSelect
                v-model="rateForm.branch_id"
                :items="branches"
                value-key="id"
                label-key="name"
                :search-keys="['name','code','city']"
                :placeholder="t('master.allowances.f_branch_ph')"
              />
            </div>
            <div>
              <label class="label">{{ t('master.allowances.f_grade') }}</label>
              <SearchableSelect
                v-model="rateForm.job_grade_id"
                :items="grades"
                value-key="id"
                label-key="name"
                :search-keys="['name','code']"
                :placeholder="t('master.allowances.f_grade_ph')"
              >
                <template #item="{ item }">
                  <span class="block text-sm truncate">
                    {{ item.name }}
                    <span class="text-xs text-gray-500">{{ t('master.allowances.f_grade_level', { level: item.level }) }}</span>
                  </span>
                </template>
                <template #selected="{ item }">
                  <span class="block truncate font-medium">
                    {{ item.name }}
                    <span class="text-xs text-gray-500">{{ t('master.allowances.f_grade_level', { level: item.level }) }}</span>
                  </span>
                </template>
              </SearchableSelect>
            </div>
          </div>
          <div>
            <label class="label">{{ t('master.allowances.f_threshold') }}</label>
            <input v-model.number="rateForm.threshold_km" type="number" min="0" step="0.5" class="input" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">{{ t('master.allowances.f_rate_below') }}</label>
              <input v-model.number="rateForm.rate_below" type="number" min="0" step="1000" class="input" />
              <p class="text-xs text-gray-500">{{ t('master.allowances.f_rate_below_hint') }}</p>
            </div>
            <div>
              <label class="label">{{ t('master.allowances.f_rate_above') }}</label>
              <input v-model.number="rateForm.rate_above" type="number" min="0" step="1000" class="input" required />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="label">{{ t('master.allowances.f_effective_from') }}</label><input v-model="rateForm.effective_from" type="date" class="input" /></div>
            <div><label class="label">{{ t('master.allowances.f_effective_to') }}</label><input v-model="rateForm.effective_to" type="date" class="input" /></div>
          </div>
          <div><label class="label">{{ t('master.allowances.f_notes') }}</label><textarea v-model="rateForm.notes" class="input" rows="2"></textarea></div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showRateModal = false">{{ t('master.allowances.f_cancel') }}</button>
            <button type="submit" class="btn-primary">{{ t('master.allowances.f_save') }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>