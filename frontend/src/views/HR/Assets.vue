<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import {
  ComputerDesktopIcon, PlusIcon, ArrowUpTrayIcon, ArrowDownTrayIcon,
  DocumentArrowDownIcon, PencilIcon, TrashIcon,
} from '@heroicons/vue/24/outline'

const auth = useAuthStore()
const toast = useToast()

const items = ref([])
const total = ref(0)
const loading = ref(false)
const employees = ref([])

const filters = reactive({ q: '', status: '', category: '', employee_id: '', page: 1, per_page: 20 })

const showForm = ref(false)
const showAssign = ref(null)   // asset row
const showReturn = ref(null)
const editing = ref(null)

const formAssign = reactive({
  employee_id: '', expected_return: '',
  condition_when_assigned: 'GOOD', purpose: '',
})
const formReturn = reactive({
  condition_when_returned: 'GOOD', damage_note: '',
})

const canManage = computed(() => auth.can('asset.manage'))

const CATEGORIES = ['LAPTOP','DESKTOP','PHONE','VEHICLE','FURNITURE','TOOL','ID_CARD','ACCESS_CARD','OTHER']
const CONDITIONS = ['NEW','GOOD','FAIR','POOR','DAMAGED']
const RETURN_CONDITIONS = ['NEW','GOOD','FAIR','POOR','DAMAGED','LOST']
const STATUS_BADGE = {
  AVAILABLE: { label: 'Tersedia',   cls: 'bg-emerald-100 text-emerald-700' },
  ASSIGNED:  { label: 'Dipakai',    cls: 'bg-blue-100 text-blue-700' },
  REPAIR:    { label: 'Reparasi',   cls: 'bg-amber-100 text-amber-700' },
  RETIRED:   { label: 'Pensiun',    cls: 'bg-gray-100 text-gray-500' },
  LOST:      { label: 'Hilang',     cls: 'bg-red-100 text-red-700' },
}

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/assets', { params: filters })
    items.value = res.data.data
    total.value = res.data.meta?.total || 0
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  } finally {
    loading.value = false
  }
}

async function fetchEmployees() {
  try {
    const res = await api.get('/employees', { params: { per_page: 200, is_active: 1 } })
    employees.value = res.data.data || []
  } catch (_) {}
}

onMounted(() => { fetchEmployees(); fetchData() })

function openCreate() {
  editing.value = {
    asset_number: '', category: 'LAPTOP', name: '', brand: '', model: '',
    serial_number: '', description: '', purchase_date: '', purchase_price: 0,
    purchase_invoice: '', condition: 'NEW', notes: '',
  }
  showForm.value = true
}
function openEdit(row) {
  editing.value = JSON.parse(JSON.stringify(row))
  showForm.value = true
}

async function save() {
  try {
    if (editing.value.id) {
      await api.put(`/assets/${editing.value.id}`, editing.value)
      toast.success('Asset diupdate')
    } else {
      await api.post('/assets', editing.value)
      toast.success('Asset dibuat')
    }
    showForm.value = false
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  }
}

async function remove(row) {
  const ok = await confirmDialog({
    title: 'Hapus aset?',
    message: `Akan hapus ${row.asset_number} (${row.name}).`,
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/assets/${row.id}`)
    toast.success('Dihapus')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  }
}

function openAssign(row) {
  showAssign.value = row
  Object.assign(formAssign, {
    employee_id: '', expected_return: '',
    condition_when_assigned: row.condition || 'GOOD',
    purpose: '',
  })
}

async function doAssign() {
  if (! formAssign.employee_id) { toast.error('Pilih karyawan'); return }
  try {
    const payload = { ...formAssign }
    Object.keys(payload).forEach(k => { if (payload[k] === '') delete payload[k] })
    await api.post(`/assets/${showAssign.value.id}/assign`, payload)
    toast.success('Asset di-assign')
    showAssign.value = null
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  }
}

function openReturn(row) {
  showReturn.value = row
  Object.assign(formReturn, { condition_when_returned: 'GOOD', damage_note: '' })
}

async function doReturn() {
  try {
    await api.post(`/assets/${showReturn.value.id}/return`, formReturn)
    toast.success('Asset di-return')
    showReturn.value = null
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  }
}

async function downloadBast(row, mode = 'ASSIGN') {
  if (! row.current_assignment_id) {
    toast.error('Asset belum punya assignment aktif')
    return
  }
  try {
    const res = await api.get(`/asset-assignments/${row.current_assignment_id}/bast`,
      { params: { mode }, responseType: 'blob' })
    const blob = new Blob([res.data], { type: 'application/pdf' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `BAST_${mode}_${row.asset_number}.pdf`.replace(/\//g, '-')
    document.body.appendChild(a); a.click(); a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (e) { toast.error('Gagal mengunduh') }
}

async function downloadBastAssignment(assignmentId, mode, assetNumber = '') {
  try {
    const res = await api.get(`/asset-assignments/${assignmentId}/bast`,
      { params: { mode }, responseType: 'blob' })
    const blob = new Blob([res.data], { type: 'application/pdf' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `BAST_${mode}_${assetNumber || assignmentId}.pdf`.replace(/\//g, '-')
    document.body.appendChild(a); a.click(); a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (e) { toast.error('Gagal mengunduh') }
}

function applyFilter() { filters.page = 1; fetchData() }
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / filters.per_page)))
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ComputerDesktopIcon class="w-6 h-6 text-primary-600" />
          Aset Perusahaan
        </h1>
        <p class="text-sm text-gray-500">Tracking laptop, HP, kendaraan, ID card, dll dengan BAST otomatis.</p>
      </div>
      <button v-if="canManage" class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> Tambah Aset
      </button>
    </div>

    <div class="card card-body mb-4">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <div><label class="label">Cari</label>
          <input v-model="filters.q" class="input" placeholder="No, nama, brand, SN..."
            @keydown.enter="applyFilter" /></div>
        <div><label class="label">Kategori</label>
          <select v-model="filters.category" class="input">
            <option value="">Semua</option>
            <option v-for="c in CATEGORIES" :key="c" :value="c">{{ c }}</option>
          </select></div>
        <div><label class="label">Status</label>
          <select v-model="filters.status" class="input">
            <option value="">Semua</option>
            <option v-for="(b, k) in STATUS_BADGE" :key="k" :value="k">{{ b.label }}</option>
          </select></div>
        <div><label class="label">Karyawan</label>
          <select v-model="filters.employee_id" class="input">
            <option value="">Semua</option>
            <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
          </select></div>
        <div class="flex items-end"><button class="btn-secondary w-full" @click="applyFilter">Filter</button></div>
      </div>
    </div>

    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr><th>No Aset</th><th>Kategori</th><th>Nama</th><th>Brand/Model</th><th>SN</th>
              <th>Kondisi</th><th>Status</th><th>Dipegang</th><th></th></tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="9" class="text-center py-8 text-gray-400">Memuat…</td></tr>
            <tr v-else-if="items.length === 0">
              <td colspan="9" class="text-center py-12 text-gray-400">Belum ada aset.</td>
            </tr>
            <tr v-for="r in items" :key="r.id">
              <td class="font-mono text-xs">{{ r.asset_number }}</td>
              <td class="text-xs">{{ r.category }}</td>
              <td class="font-medium">{{ r.name }}</td>
              <td class="text-xs">{{ r.brand }} {{ r.model }}</td>
              <td class="text-xs font-mono">{{ r.serial_number || '-' }}</td>
              <td class="text-xs">{{ r.condition }}</td>
              <td>
                <span class="px-2 py-0.5 rounded text-xs" :class="STATUS_BADGE[r.status]?.cls">
                  {{ STATUS_BADGE[r.status]?.label || r.status }}
                </span>
              </td>
              <td class="text-xs">
                <div v-if="r.employee_name">
                  <div class="font-medium">{{ r.employee_name }}</div>
                  <div class="text-gray-500">{{ r.employee_no }}</div>
                </div>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="text-right">
                <div class="flex items-center justify-end gap-1">
                  <button v-if="canManage && r.status === 'AVAILABLE'"
                    class="text-blue-600 hover:text-blue-800 p-1 text-xs" title="Assign" @click="openAssign(r)">
                    <ArrowUpTrayIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage && r.status === 'ASSIGNED'"
                    class="text-emerald-600 hover:text-emerald-800 p-1" title="Return" @click="openReturn(r)">
                    <ArrowDownTrayIcon class="w-4 h-4" />
                  </button>
                  <button v-if="r.current_assignment_id"
                    class="text-gray-600 hover:text-primary-600 p-1" title="BAST PDF"
                    @click="downloadBast(r, 'ASSIGN')">
                    <DocumentArrowDownIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage" class="text-gray-600 hover:text-primary-600 p-1" title="Edit" @click="openEdit(r)">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage && ['AVAILABLE','RETIRED'].includes(r.status)"
                    class="text-red-600 hover:text-red-800 p-1" title="Hapus" @click="remove(r)">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="totalPages > 1" class="px-4 py-3 flex items-center justify-between border-t text-sm">
        <span class="text-gray-500">{{ total }} aset · halaman {{ filters.page }} / {{ totalPages }}</span>
        <div class="flex gap-1">
          <button :disabled="filters.page <= 1" class="btn-secondary px-3 py-1 text-sm" @click="filters.page--; fetchData()">Prev</button>
          <button :disabled="filters.page >= totalPages" class="btn-secondary px-3 py-1 text-sm" @click="filters.page++; fetchData()">Next</button>
        </div>
      </div>
    </div>

    <!-- Form modal -->
    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">{{ editing.id ? 'Edit Aset' : 'Tambah Aset Baru' }}</h2>
          <button class="text-gray-500" @click="showForm = false">&times;</button>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="label">No. Aset</label>
            <input v-model="editing.asset_number" class="input" placeholder="Auto-generate kalau kosong" />
          </div>
          <div>
            <label class="label">Kategori *</label>
            <select v-model="editing.category" class="input">
              <option v-for="c in CATEGORIES" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div class="md:col-span-2">
            <label class="label">Nama *</label>
            <input v-model="editing.name" class="input" placeholder="mis. Lenovo ThinkPad T14" />
          </div>
          <div><label class="label">Brand</label><input v-model="editing.brand" class="input" /></div>
          <div><label class="label">Model</label><input v-model="editing.model" class="input" /></div>
          <div><label class="label">Serial Number</label><input v-model="editing.serial_number" class="input" /></div>
          <div>
            <label class="label">Kondisi</label>
            <select v-model="editing.condition" class="input">
              <option v-for="c in CONDITIONS" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div><label class="label">Tgl Pembelian</label><input type="date" v-model="editing.purchase_date" class="input" /></div>
          <div><label class="label">Harga Beli</label><input type="number" v-model="editing.purchase_price" class="input" /></div>
          <div class="md:col-span-2">
            <label class="label">Catatan</label>
            <textarea v-model="editing.description" class="input" rows="2"></textarea>
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showForm = false">Batal</button>
          <button class="btn-primary" @click="save">Simpan</button>
        </div>
      </div>
    </div>

    <!-- Assign modal -->
    <div v-if="showAssign" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">Assign {{ showAssign.asset_number }}</h2>
          <button class="text-gray-500" @click="showAssign = null">&times;</button>
        </div>
        <div class="p-6 space-y-3">
          <div>
            <label class="label">Karyawan *</label>
            <select v-model="formAssign.employee_id" class="input">
              <option value="">— Pilih —</option>
              <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }} ({{ e.employee_number }})</option>
            </select>
          </div>
          <div>
            <label class="label">Kondisi saat penyerahan</label>
            <select v-model="formAssign.condition_when_assigned" class="input">
              <option v-for="c in CONDITIONS" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div>
            <label class="label">Diharapkan kembali (opsional)</label>
            <input type="date" v-model="formAssign.expected_return" class="input" />
          </div>
          <div>
            <label class="label">Tujuan / catatan</label>
            <textarea v-model="formAssign.purpose" class="input" rows="2"></textarea>
          </div>
          <p class="text-xs text-gray-500">BAST otomatis di-generate setelah disimpan, bisa di-download dari list.</p>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showAssign = null">Batal</button>
          <button class="btn-primary" @click="doAssign">Assign</button>
        </div>
      </div>
    </div>

    <!-- Return modal -->
    <div v-if="showReturn" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">Return {{ showReturn.asset_number }}</h2>
          <button class="text-gray-500" @click="showReturn = null">&times;</button>
        </div>
        <div class="p-6 space-y-3">
          <p class="text-sm">Pemegang saat ini: <strong>{{ showReturn.employee_name }}</strong></p>
          <div>
            <label class="label">Kondisi saat pengembalian *</label>
            <select v-model="formReturn.condition_when_returned" class="input">
              <option v-for="c in RETURN_CONDITIONS" :key="c" :value="c">{{ c }}</option>
            </select>
            <p v-if="formReturn.condition_when_returned === 'DAMAGED'" class="text-xs text-amber-700 mt-1">
              ⚠ Asset akan otomatis di-set status REPAIR
            </p>
            <p v-if="formReturn.condition_when_returned === 'LOST'" class="text-xs text-red-700 mt-1">
              ⚠ Asset akan otomatis di-set status LOST
            </p>
          </div>
          <div>
            <label class="label">Catatan kerusakan / kehilangan</label>
            <textarea v-model="formReturn.damage_note" class="input" rows="3"></textarea>
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showReturn = null">Batal</button>
          <button class="btn-primary" @click="doReturn">Konfirmasi Return</button>
        </div>
      </div>
    </div>
  </div>
</template>