<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { fmtCurrency } from '@/composables/useResource'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { PlusIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import SearchableSelect from '@/components/SearchableSelect.vue'
import ExcelImporter from '@/components/ExcelImporter.vue'

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
    toast.error(e.response?.data?.message || 'Gagal memuat tipe allowance')
  }
}
async function fetchRates() {
  try {
    const params = {}
    if (filterTypeId.value) params.allowance_type_id = filterTypeId.value
    const res = await api.get('/allowance-rates', { params })
    rates.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat tarif')
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
function openEditType(t) {
  editingType.value = t
  typeForm.value = { ...t }
  showTypeModal.value = true
}
async function saveType() {
  try {
    if (editingType.value) {
      await api.put(`/allowance-types/${editingType.value.id}`, typeForm.value)
      toast.success('Tipe diperbarui')
    } else {
      await api.post('/allowance-types', typeForm.value)
      toast.success('Tipe ditambahkan')
    }
    showTypeModal.value = false
    fetchTypes()
    fetchRates()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}
async function removeType(t) {
  const ok = await confirmDialog({
    title: 'Hapus tipe tunjangan?',
    message: `Tipe "${t.name}" akan dinonaktifkan beserta semua tarifnya.`,
    okText: 'Hapus',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/allowance-types/${t.id}`)
    toast.success('Tipe dihapus')
    fetchTypes()
    fetchRates()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
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
    toast.warning('Pilih tipe allowance terlebih dahulu')
    return
  }
  try {
    if (editingRate.value) {
      await api.put(`/allowance-rates/${editingRate.value.id}`, rateForm.value)
      toast.success('Tarif diperbarui')
    } else {
      await api.post('/allowance-rates', rateForm.value)
      toast.success('Tarif ditambahkan')
    }
    showRateModal.value = false
    fetchRates()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}
async function removeRate(r) {
  const ok = await confirmDialog({
    title: 'Hapus tarif?',
    message: 'Tarif ini akan dihapus dari sistem.',
    okText: 'Hapus',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/allowance-rates/${r.id}`)
    toast.success('Tarif dihapus')
    fetchRates()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

async function recomputeAll() {
  const ok = await confirmDialog({
    title: 'Hitung ulang seluruh karyawan?',
    message: 'Snapshot tunjangan untuk seluruh karyawan dalam company akan diperbarui. Mungkin butuh beberapa detik.',
    okText: 'Hitung Ulang',
  })
  if (! ok) return
  try {
    const res = await api.post('/allowances/recompute-company')
    toast.success(`Recompute selesai: ${res.data.data?.count || 0} entri`)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal recompute')
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold">Pemetaan Tunjangan</h1>
        <p class="text-sm text-gray-500">
          Konfigurasi tunjangan berbasis cabang & grade. Default threshold 30 km:
          karyawan dengan jarak rumah ↔ cabang ≥ 30 km akan mendapat tunjangan.
        </p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <ExcelImporter
          v-if="auth.can('allowance_types.bulk_import')"
          resource="allowance_types"
          button-label="Import Excel"
          @committed="fetchTypes()"
        />
        <button v-if="canManage" class="btn-secondary" @click="recomputeAll">Hitung Ulang Semua</button>
      </div>
    </div>

    <div class="flex gap-2 mb-4 border-b border-gray-200">
      <button
        :class="['px-4 py-2 -mb-px border-b-2',
          tab === 'types' ? 'border-primary-600 text-primary-700 font-semibold' : 'border-transparent text-gray-600']"
        @click="tab = 'types'"
      >Tipe Tunjangan</button>
      <button
        :class="['px-4 py-2 -mb-px border-b-2',
          tab === 'rates' ? 'border-primary-600 text-primary-700 font-semibold' : 'border-transparent text-gray-600']"
        @click="tab = 'rates'"
      >Tarif per Grade/Cabang</button>
    </div>

    <!-- ── TYPES ─────────────────────────────────────────── -->
    <div v-if="tab === 'types'" class="card">
      <div class="card-header justify-between">
        <span class="text-sm text-gray-600">Definisi tipe tunjangan per perusahaan.</span>
        <button v-if="canManage" class="btn-primary" @click="openCreateType">
          <PlusIcon class="w-4 h-4" /> Tambah Tipe
        </button>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr>
            <th>Kode</th><th>Nama</th><th>Kategori</th><th>Threshold</th>
            <th>Distance-based</th><th>Kena Pajak</th><th>Status</th>
            <th class="text-right">Aksi</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="types.length === 0"><td colspan="8" class="text-center py-6 text-gray-400">Belum ada tipe tunjangan</td></tr>
            <tr v-for="t in types" :key="t.id">
              <td class="font-mono">{{ t.code }}</td>
              <td>
                <div class="font-medium">{{ t.name }}</div>
                <div class="text-xs text-gray-500">{{ t.description }}</div>
              </td>
              <td><span class="badge-blue">{{ t.category }}</span></td>
              <td>{{ Number(t.default_threshold_km).toFixed(2) }} km</td>
              <td>{{ t.is_distance_based ? 'Ya' : 'Flat' }}</td>
              <td>{{ t.is_taxable ? 'Ya' : 'Tidak' }}</td>
              <td><span :class="t.is_active ? 'badge-green' : 'badge-gray'">{{ t.is_active ? 'Aktif' : 'Non-aktif' }}</span></td>
              <td class="text-right">
                <button v-if="canManage" class="text-gray-500 hover:text-primary-600 mr-2" @click="openEditType(t)"><PencilIcon class="w-4 h-4" /></button>
                <button v-if="canManage" class="text-gray-500 hover:text-red-600" @click="removeType(t)"><TrashIcon class="w-4 h-4" /></button>
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
            placeholder="Semua tipe"
            @change="fetchRates"
          />
        </div>
        <button v-if="canManage" class="btn-primary" @click="openCreateRate" :disabled="types.length === 0">
          <PlusIcon class="w-4 h-4" /> Tambah Tarif
        </button>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr>
            <th>Tipe</th><th>Cabang</th><th>Grade</th>
            <th>Threshold</th><th>Tarif < Threshold</th><th>Tarif ≥ Threshold</th>
            <th>Periode</th>
            <th class="text-right">Aksi</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="rates.length === 0"><td colspan="8" class="text-center py-6 text-gray-400">Belum ada tarif</td></tr>
            <tr v-for="r in rates" :key="r.id">
              <td>
                <div class="font-medium">{{ r.type_name }}</div>
                <div class="text-xs text-gray-500">{{ r.type_code }}</div>
              </td>
              <td>{{ r.branch_name || '— Semua Cabang —' }}</td>
              <td>{{ r.grade_name || '— Semua Grade —' }}</td>
              <td>{{ Number(r.threshold_km).toFixed(2) }} km</td>
              <td>{{ fmtCurrency(r.rate_below) }}</td>
              <td class="font-semibold">{{ fmtCurrency(r.rate_above) }}</td>
              <td class="text-xs">
                <div v-if="r.effective_from || r.effective_to">
                  {{ r.effective_from || '—' }} s/d {{ r.effective_to || '—' }}
                </div>
                <div v-else class="text-gray-400">selalu aktif</div>
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
          <h2 class="font-semibold">{{ editingType ? 'Edit Tipe' : 'Tambah Tipe' }} Tunjangan</h2>
          <button class="text-gray-400 hover:text-gray-600 text-2xl leading-none" @click="showTypeModal = false">&times;</button>
        </div>
        <form @submit.prevent="saveType" class="p-5 space-y-3 text-sm">
          <div class="grid grid-cols-2 gap-3">
            <div><label class="label">Kode *</label><input v-model="typeForm.code" class="input" required :disabled="!!editingType" /></div>
            <div><label class="label">Kategori</label>
              <select v-model="typeForm.category" class="input">
                <option value="TRANSPORT">Transport</option>
                <option value="MEAL">Makan</option>
                <option value="HOUSING">Perumahan</option>
                <option value="ALLOWANCE">Tunjangan Umum</option>
                <option value="OTHER">Lainnya</option>
              </select>
            </div>
          </div>
          <div><label class="label">Nama *</label><input v-model="typeForm.name" class="input" required /></div>
          <div><label class="label">Deskripsi</label><textarea v-model="typeForm.description" class="input" rows="2"></textarea></div>
          <div class="grid grid-cols-2 gap-3">
            <label class="flex items-center gap-2"><input type="checkbox" v-model="typeForm.is_distance_based" :true-value="1" :false-value="0" /><span>Berbasis jarak</span></label>
            <label class="flex items-center gap-2"><input type="checkbox" v-model="typeForm.is_taxable" :true-value="1" :false-value="0" /><span>Kena pajak</span></label>
          </div>
          <div v-if="typeForm.is_distance_based">
            <label class="label">Default Threshold (km)</label>
            <input v-model.number="typeForm.default_threshold_km" type="number" min="0" step="0.5" class="input" />
            <p class="text-xs text-gray-500 mt-1">Karyawan dengan jarak ≥ angka ini akan dapat tunjangan.</p>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showTypeModal = false">Batal</button>
            <button type="submit" class="btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── RATE MODAL ────────────────────────────────────── -->
    <div v-if="showRateModal" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/50">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ editingRate ? 'Edit Tarif' : 'Tambah Tarif' }}</h2>
          <button class="text-gray-400 hover:text-gray-600 text-2xl leading-none" @click="showRateModal = false">&times;</button>
        </div>
        <form @submit.prevent="saveRate" class="p-5 space-y-3 text-sm">
          <div>
            <label class="label">Tipe Tunjangan *</label>
            <SearchableSelect
              v-model="rateForm.allowance_type_id"
              :items="types"
              value-key="id"
              label-key="name"
              sub-label-key="category"
              :search-keys="['name','code','category']"
              placeholder="— Pilih tipe —"
              :clearable="false"
            />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Cabang</label>
              <SearchableSelect
                v-model="rateForm.branch_id"
                :items="branches"
                value-key="id"
                label-key="name"
                :search-keys="['name','code','city']"
                placeholder="— Semua cabang —"
              />
            </div>
            <div>
              <label class="label">Job Grade</label>
              <SearchableSelect
                v-model="rateForm.job_grade_id"
                :items="grades"
                value-key="id"
                label-key="name"
                :search-keys="['name','code']"
                placeholder="— Semua grade —"
              >
                <template #item="{ item }">
                  <span class="block text-sm truncate">
                    {{ item.name }}
                    <span class="text-xs text-gray-500">(Lvl {{ item.level }})</span>
                  </span>
                </template>
                <template #selected="{ item }">
                  <span class="block truncate font-medium">
                    {{ item.name }}
                    <span class="text-xs text-gray-500">(Lvl {{ item.level }})</span>
                  </span>
                </template>
              </SearchableSelect>
            </div>
          </div>
          <div>
            <label class="label">Threshold Jarak (km)</label>
            <input v-model.number="rateForm.threshold_km" type="number" min="0" step="0.5" class="input" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Tarif jika < threshold</label>
              <input v-model.number="rateForm.rate_below" type="number" min="0" step="1000" class="input" />
              <p class="text-xs text-gray-500">Default 0 (tidak dapat).</p>
            </div>
            <div>
              <label class="label">Tarif jika ≥ threshold *</label>
              <input v-model.number="rateForm.rate_above" type="number" min="0" step="1000" class="input" required />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="label">Berlaku dari</label><input v-model="rateForm.effective_from" type="date" class="input" /></div>
            <div><label class="label">Berlaku sampai</label><input v-model="rateForm.effective_to" type="date" class="input" /></div>
          </div>
          <div><label class="label">Catatan</label><textarea v-model="rateForm.notes" class="input" rows="2"></textarea></div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showRateModal = false">Batal</button>
            <button type="submit" class="btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>