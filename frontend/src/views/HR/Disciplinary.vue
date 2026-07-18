<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtDate } from '@/composables/useResource'
import {
  ExclamationTriangleIcon, DocumentArrowDownIcon, PencilIcon,
  TrashIcon, CheckBadgeIcon, NoSymbolIcon, PlusIcon,
} from '@heroicons/vue/24/outline'
import SearchableSelect from '@/components/SearchableSelect.vue'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'

const auth = useAuthStore()
const toast = useToast()

const records = ref([])
const total = ref(0)
const loading = ref(false)
const categories = ref([])
const employees = ref([])

const filters = reactive({
  q: '',
  level: '',
  status: 'ACTIVE',
  employee_id: '',
  page: 1,
  per_page: 20,
})

// Modal state
const showForm = ref(false)
const editing = ref(null)
const showLetter = ref(null) // record yang sedang ditampilkan letter-nya
const showRevoke = ref(null)
const revokeReason = ref('')

const canManage = computed(() => auth.can('disciplinary.manage'))
const canViewAll = computed(() => auth.can('disciplinary.view') || auth.can('disciplinary.manage'))

const LEVELS = [
  { value: 'VERBAL', label: 'Teguran Lisan', badge: 'bg-gray-100 text-gray-800' },
  { value: 'SP1', label: 'SP 1', badge: 'bg-yellow-100 text-yellow-800' },
  { value: 'SP2', label: 'SP 2', badge: 'bg-orange-100 text-orange-800' },
  { value: 'SP3', label: 'SP 3 (Final)', badge: 'bg-red-100 text-red-800' },
  { value: 'SUSPEND', label: 'Skorsing', badge: 'bg-purple-100 text-purple-800' },
  { value: 'TERMINATION', label: 'PHK', badge: 'bg-red-200 text-red-900 font-semibold' },
]
const STATUSES = [
  { value: 'DRAFT',   label: 'Draft',     badge: 'bg-gray-100 text-gray-700' },
  { value: 'ACTIVE',  label: 'Aktif',     badge: 'bg-emerald-100 text-emerald-800' },
  { value: 'EXPIRED', label: 'Berakhir',  badge: 'bg-gray-100 text-gray-500' },
  { value: 'REVOKED', label: 'Dibatalkan',badge: 'bg-blue-100 text-blue-800' },
]

function levelMeta(v) { return LEVELS.find(l => l.value === v) || { label: v, badge: 'bg-gray-100' } }
function statusMeta(v) { return STATUSES.find(s => s.value === v) || { label: v, badge: 'bg-gray-100' } }

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/disciplinary-records', { params: filters })
    records.value = res.data.data
    total.value = res.data.meta?.total || 0
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat data')
  } finally {
    loading.value = false
  }
}

async function fetchMaster() {
  try {
    // Karyawan TIDAK lagi di-load semua sekaligus — picker pakai
    // EmployeeSearchSelect server-side untuk dataset besar.
    const cats = await api.get('/disciplinary-records/categories')
    categories.value = cats.data.data || []
  } catch (e) { /* silent */ }
}

onMounted(() => { fetchMaster(); fetchData() })

function openCreate() {
  editing.value = {
    employee_id: '',
    category_id: '',
    level: 'SP1',
    subject: '',
    description: '',
    incident_date: new Date().toISOString().slice(0, 10),
    effective_date: new Date().toISOString().slice(0, 10),
    expires_at: '',
    status: 'ACTIVE',
  }
  showForm.value = true
}

function openEdit(row) {
  editing.value = JSON.parse(JSON.stringify(row))
  showForm.value = true
}

// Update default expires_at saat level berubah
function onLevelChange() {
  if (! editing.value.effective_date) return
  if (['SP1','SP2','SP3'].includes(editing.value.level)) {
    const d = new Date(editing.value.effective_date)
    d.setMonth(d.getMonth() + 6)
    editing.value.expires_at = d.toISOString().slice(0, 10)
  } else {
    editing.value.expires_at = ''
  }
}

async function save() {
  try {
    const payload = { ...editing.value }
    if (! payload.expires_at) delete payload.expires_at
    if (! payload.category_id) delete payload.category_id
    let res
    if (editing.value.id) {
      res = await api.put(`/disciplinary-records/${editing.value.id}`, payload)
      toast.success('SP diupdate')
    } else {
      res = await api.post('/disciplinary-records', payload)
      const warn = res.data.data?.warning
      if (warn) toast.warning(warn, { timeout: 8000 })
      toast.success('SP diterbitkan')
    }
    showForm.value = false
    fetchData()
  } catch (e) {
    const msg = e.response?.data?.message || 'Gagal'
    const errs = e.response?.data?.errors
    toast.error(errs ? msg + ': ' + Object.values(errs).join(', ') : msg)
  }
}

async function revoke() {
  if (! showRevoke.value) return
  if (revokeReason.value.trim().length < 10) {
    toast.error('Alasan minimal 10 karakter')
    return
  }
  try {
    await api.post(`/disciplinary-records/${showRevoke.value.id}/revoke`, { reason: revokeReason.value })
    toast.success('SP dibatalkan')
    showRevoke.value = null
    revokeReason.value = ''
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  }
}

async function remove(row) {
  const ok = await confirmDialog({
    title: 'Hapus SP?',
    message: `Akan menghapus ${row.level} #${row.reference_number}.`,
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/disciplinary-records/${row.id}`)
    toast.success('Dihapus')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function acknowledge(row) {
  const ok = await confirmDialog({
    title: 'Terima SP ini?',
    message: 'Dengan acknowledge, Anda mengakui menerima SP ini. Anda tetap bisa memberi tanggapan/pembelaan.',
    okText: 'Saya menerima',
  })
  if (! ok) return
  try {
    await api.post(`/disciplinary-records/${row.id}/acknowledge`, { note: '' })
    toast.success('SP diakui')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function downloadLetter(row) {
  try {
    const res = await api.get(`/disciplinary-records/${row.id}/letter`, { responseType: 'blob' })
    const blob = new Blob([res.data], { type: 'application/pdf' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `SP_${row.reference_number}.pdf`.replace(/\//g, '-')
    document.body.appendChild(a); a.click(); a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (e) { toast.error('Gagal mengunduh') }
}

function applyFilter() { filters.page = 1; fetchData() }
function pageChange(p) { filters.page = p; fetchData() }
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / filters.per_page)))
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ExclamationTriangleIcon class="w-6 h-6 text-orange-500" />
          Surat Peringatan & Disiplin
        </h1>
        <p class="text-sm text-gray-500">Kelola SP1/SP2/SP3, skorsing, hingga PHK sesuai UU Cipta Kerja & PP 35/2021.</p>
      </div>
      <button v-if="canManage" class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> Terbitkan SP Baru
      </button>
    </div>

    <!-- Filters -->
    <div class="card card-body mb-4">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div>
          <label class="label">Cari</label>
          <input v-model="filters.q" class="input" placeholder="No SP, judul, nama..."
            @keydown.enter="applyFilter" />
        </div>
        <div>
          <label class="label">Level</label>
          <SearchableSelect
            v-model="filters.level"
            :items="LEVELS"
            value-key="value"
            label-key="label"
            placeholder="Semua"
          />
        </div>
        <div>
          <label class="label">Status</label>
          <SearchableSelect
            v-model="filters.status"
            :items="STATUSES"
            value-key="value"
            label-key="label"
            placeholder="Semua"
          />
        </div>
        <div class="flex items-end">
          <button class="btn-secondary w-full" @click="applyFilter">Filter</button>
        </div>
      </div>
    </div>

    <!-- Records table -->
    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>No SP</th>
              <th>Level</th>
              <th>Karyawan</th>
              <th>Hal</th>
              <th>Kategori</th>
              <th>Berlaku</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="8" class="text-center py-8 text-gray-400">Memuat…</td></tr>
            <tr v-else-if="records.length === 0">
              <td colspan="8" class="text-center py-12 text-gray-400">Belum ada SP. Klik "Terbitkan SP Baru" untuk membuat.</td>
            </tr>
            <tr v-for="r in records" :key="r.id">
              <td class="font-mono text-xs">{{ r.reference_number }}</td>
              <td>
                <span class="px-2 py-0.5 rounded text-xs" :class="levelMeta(r.level).badge">
                  {{ levelMeta(r.level).label }}
                </span>
              </td>
              <td>
                <div class="font-medium">{{ r.employee_name }}</div>
                <div class="text-xs text-gray-500">{{ r.employee_number }}</div>
              </td>
              <td class="max-w-xs truncate" :title="r.subject">{{ r.subject }}</td>
              <td class="text-xs text-gray-600">{{ r.category_name || '-' }}</td>
              <td class="text-xs">
                {{ fmtDate(r.effective_date) }}<br>
                <span v-if="r.expires_at" class="text-gray-500">→ {{ fmtDate(r.expires_at) }}</span>
                <span v-else class="text-gray-400">→ permanen</span>
              </td>
              <td>
                <span class="px-2 py-0.5 rounded text-xs" :class="statusMeta(r.status).badge">
                  {{ statusMeta(r.status).label }}
                </span>
                <div v-if="r.acknowledged_at" class="text-xs text-emerald-700 mt-1">
                  ✓ Diakui {{ fmtDate(r.acknowledged_at) }}
                </div>
              </td>
              <td class="text-right">
                <div class="flex items-center justify-end gap-1">
                  <button class="text-gray-600 hover:text-primary-600 p-1" title="Download Surat" @click="downloadLetter(r)">
                    <DocumentArrowDownIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage && r.status === 'DRAFT'" class="text-gray-600 hover:text-primary-600 p-1" title="Edit" @click="openEdit(r)">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button v-if="!r.acknowledged_at && r.status === 'ACTIVE' && r.employee_id === auth.employee?.id"
                    class="text-emerald-600 hover:text-emerald-800 p-1" title="Saya menerima" @click="acknowledge(r)">
                    <CheckBadgeIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage && ['ACTIVE','DRAFT'].includes(r.status)"
                    class="text-orange-600 hover:text-orange-800 p-1" title="Batalkan" @click="showRevoke = r; revokeReason = ''">
                    <NoSymbolIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage && r.status === 'DRAFT'" class="text-red-600 hover:text-red-800 p-1" title="Hapus" @click="remove(r)">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <!-- Pagination -->
      <div v-if="totalPages > 1" class="px-4 py-3 flex items-center justify-between border-t border-gray-100 text-sm">
        <span class="text-gray-500">{{ total }} record · halaman {{ filters.page }} / {{ totalPages }}</span>
        <div class="flex gap-1">
          <button :disabled="filters.page <= 1" class="btn-secondary px-3 py-1 text-sm" @click="pageChange(filters.page - 1)">Prev</button>
          <button :disabled="filters.page >= totalPages" class="btn-secondary px-3 py-1 text-sm" @click="pageChange(filters.page + 1)">Next</button>
        </div>
      </div>
    </div>

    <!-- Form modal -->
    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ editing.id ? 'Edit SP' : 'Terbitkan SP Baru' }}</h2>
          <button class="text-gray-500" @click="showForm = false">&times;</button>
        </div>
        <div class="p-6 space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Karyawan *</label>
              <EmployeeSearchSelect
                v-model="editing.employee_id"
                placeholder="— Pilih karyawan —"
                :disabled="!! editing.id"
                :only-active="false"
              />
            </div>
            <div>
              <label class="label">Level *</label>
              <SearchableSelect
                v-model="editing.level"
                :items="LEVELS"
                value-key="value"
                label-key="label"
                :clearable="false"
                @change="onLevelChange"
              />
            </div>
          </div>
          <div>
            <label class="label">Kategori</label>
            <SearchableSelect
              v-model="editing.category_id"
              :items="categories"
              value-key="id"
              label-key="name"
              sub-label-key="recommended_level"
              :search-keys="['name','code','recommended_level']"
              placeholder="— Pilih (opsional) —"
            >
              <template #item="{ item }">
                <span class="block text-sm truncate">
                  {{ item.name }}
                  <span v-if="item.recommended_level" class="text-xs text-gray-500">
                    — rekomendasi: {{ item.recommended_level }}
                  </span>
                </span>
              </template>
            </SearchableSelect>
          </div>
          <div>
            <label class="label">Hal / Judul *</label>
            <input v-model="editing.subject" class="input" placeholder="mis. Keterlambatan Berulang" />
          </div>
          <div>
            <label class="label">Uraian Pelanggaran *</label>
            <textarea v-model="editing.description" class="input" rows="4"
              placeholder="Jelaskan kronologi, bukti, dan dasar penilaian..."></textarea>
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="label">Tanggal Kejadian *</label>
              <AppDatePicker  v-model="editing.incident_date" class="input" />
            </div>
            <div>
              <label class="label">Berlaku Sejak *</label>
              <AppDatePicker  v-model="editing.effective_date" class="input" @change="onLevelChange" />
            </div>
            <div>
              <label class="label">Berlaku Sampai</label>
              <AppDatePicker  v-model="editing.expires_at" class="input" />
            </div>
          </div>
          <div>
            <label class="label">Status</label>
            <select v-model="editing.status" class="input">
              <option value="DRAFT">Draft (belum diberlakukan)</option>
              <option value="ACTIVE">Active (langsung berlaku)</option>
            </select>
          </div>
          <p class="text-xs text-gray-500">
            Surat akan otomatis di-generate dari template SP/PHK perusahaan
            (atau template build-in). Anda bisa download PDF setelah disimpan.
          </p>
        </div>
        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2">
          <button class="btn-secondary" @click="showForm = false">Batal</button>
          <button class="btn-primary" @click="save">Simpan</button>
        </div>
      </div>
    </div>

    <!-- Revoke modal -->
    <div v-if="showRevoke" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">Batalkan {{ showRevoke.level }} #{{ showRevoke.reference_number }}</h2>
          <button class="text-gray-500" @click="showRevoke = null">&times;</button>
        </div>
        <div class="p-6">
          <label class="label">Alasan pembatalan (min 10 karakter) *</label>
          <textarea v-model="revokeReason" class="input" rows="4"
            placeholder="Alasan/catatan pembatalan SP ini..."></textarea>
        </div>
        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2">
          <button class="btn-secondary" @click="showRevoke = null">Batal</button>
          <button class="btn-danger" @click="revoke">Konfirmasi Batalkan</button>
        </div>
      </div>
    </div>
  </div>
</template>