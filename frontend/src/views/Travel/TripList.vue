<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import {
  PaperAirplaneIcon, PlusIcon, DocumentArrowDownIcon, PencilIcon, TrashIcon,
  CheckCircleIcon, XCircleIcon, PaperAirplaneIcon as SubmitIcon,
  PlayIcon, FlagIcon, BanknotesIcon,
} from '@heroicons/vue/24/outline'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'

const auth = useAuthStore()
const toast = useToast()

const items = ref([])
const total = ref(0)
const loading = ref(false)
const employees = ref([])

const filters = reactive({ q: '', status: '', employee_id: '', mine: 0, page: 1, per_page: 20 })

const showForm = ref(false)
const editing = ref(null)
const detailRow = ref(null)
const showExpense = ref(null)
const expenseInput = reactive({
  category: 'TRANSPORT', description: '', amount: 0, expense_date: '', is_billable: 1,
})
const showReject = ref(null)
const rejectReason = ref('')
const settleResult = ref(null)

const TRANSPORT = ['PESAWAT','KERETA','BUS','MOBIL_DINAS','MOBIL_PRIBADI','KAPAL','OTHER']
const EXPENSE_CATS = ['TRANSPORT','ACCOMMODATION','MEAL','DAILY_ALLOWANCE','FUEL','PARKING','TOLL','OTHER']

const STATUS_BADGE = {
  DRAFT:       { label: 'Draft',         cls: 'bg-gray-100 text-gray-600' },
  PENDING:     { label: 'Menunggu',      cls: 'bg-amber-100 text-amber-700' },
  APPROVED:    { label: 'Disetujui',     cls: 'bg-blue-100 text-blue-700' },
  REJECTED:    { label: 'Ditolak',       cls: 'bg-red-100 text-red-700' },
  IN_PROGRESS: { label: 'Berjalan',      cls: 'bg-indigo-100 text-indigo-700' },
  COMPLETED:   { label: 'Selesai',       cls: 'bg-emerald-100 text-emerald-700' },
  SETTLED:     { label: 'Lunas',         cls: 'bg-emerald-200 text-emerald-800 font-semibold' },
  CANCELLED:   { label: 'Dibatalkan',    cls: 'bg-gray-100 text-gray-500' },
}

const canManage = computed(() => auth.can('business_trip.manage'))
const canApprove = computed(() => auth.can('business_trip.approve'))
const canSettle = computed(() => auth.can('business_trip.settle'))

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/business-trips', { params: filters })
    items.value = res.data.data
    total.value = res.data.meta?.total || 0
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  } finally {
    loading.value = false
  }
}
// Karyawan tidak lagi di-load semua sekaligus — picker pakai
// EmployeeSearchSelect server-side untuk dataset besar.
async function fetchEmployees() { /* deprecated: server-side search */ }

onMounted(() => { fetchData() })

function openCreate() {
  editing.value = {
    employee_id: '', purpose: '', destination: '', destination_type: 'DOMESTIC',
    start_date: '', end_date: '', transport_mode: '', accommodation: '',
    advance_amount: 0, notes: '',
  }
  showForm.value = true
}
function openEdit(row) {
  editing.value = JSON.parse(JSON.stringify(row))
  showForm.value = true
}

async function save() {
  try {
    const payload = { ...editing.value }
    if (! payload.employee_id) delete payload.employee_id
    if (editing.value.id) {
      await api.put(`/business-trips/${editing.value.id}`, payload)
      toast.success('SPPD diupdate')
    } else {
      await api.post('/business-trips', payload)
      toast.success('SPPD draft dibuat')
    }
    showForm.value = false
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  }
}

async function submit(row) {
  const ok = await confirmDialog({
    title: 'Submit untuk approval?',
    message: `SPPD ${row.reference_number} akan dikirim untuk persetujuan.`,
    okText: 'Submit',
  })
  if (! ok) return
  try {
    await api.post(`/business-trips/${row.id}/submit`)
    toast.success('Berhasil submit')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function approve(row) {
  const ok = await confirmDialog({
    title: 'Approve SPPD?',
    message: `${row.reference_number} ke ${row.destination}, advance ${fmtCurrency(row.advance_amount)}.`,
    okText: 'Approve',
  })
  if (! ok) return
  try {
    await api.post(`/business-trips/${row.id}/approve`, {})
    toast.success('Disetujui')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

function openReject(row) { showReject.value = row; rejectReason.value = '' }
async function doReject() {
  if (rejectReason.value.length < 5) { toast.error('Alasan minimal 5 karakter'); return }
  try {
    await api.post(`/business-trips/${showReject.value.id}/reject`, { reason: rejectReason.value })
    toast.success('Ditolak')
    showReject.value = null
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function startTrip(row) {
  try {
    await api.post(`/business-trips/${row.id}/start`)
    toast.success('Status: berjalan')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function complete(row) {
  const ok = await confirmDialog({
    title: 'Tandai selesai?',
    message: 'Pastikan semua bukti pengeluaran sudah ditambahkan.',
    okText: 'Selesai',
  })
  if (! ok) return
  try {
    await api.post(`/business-trips/${row.id}/complete`)
    toast.success('Selesai')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function settle(row) {
  const diff = (Number(row.actual_amount) || 0) - (Number(row.advance_amount) || 0)
  const msg = diff > 0
    ? `Karyawan tombok ${fmtCurrency(diff)}. Reimbursement otomatis akan dibuat.`
    : diff < 0
    ? `Kelebihan advance ${fmtCurrency(Math.abs(diff))}. Karyawan harus kembalikan.`
    : 'Actual sama dengan advance, tidak ada selisih.'
  const ok = await confirmDialog({
    title: 'Settle SPPD?',
    message: `Selisih: ${fmtCurrency(diff)}. ${msg}`,
    okText: 'Settle',
  })
  if (! ok) return
  try {
    const res = await api.post(`/business-trips/${row.id}/settle`)
    settleResult.value = res.data.data
    toast.success('Settled')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function cancel(row) {
  const ok = await confirmDialog({
    title: 'Batalkan SPPD?',
    message: `${row.reference_number} akan dibatalkan.`,
    okText: 'Batalkan', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.post(`/business-trips/${row.id}/cancel`, {})
    toast.success('Dibatalkan')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function downloadSppd(row) {
  try {
    const res = await api.get(`/business-trips/${row.id}/sppd`, { responseType: 'blob' })
    const blob = new Blob([res.data], { type: 'application/pdf' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `SPPD_${row.reference_number.replace(/\//g, '-')}.pdf`
    document.body.appendChild(a); a.click(); a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (e) { toast.error('Gagal download') }
}

async function openDetail(row) {
  try {
    const res = await api.get(`/business-trips/${row.id}`)
    detailRow.value = res.data.data
  } catch (e) { toast.error('Gagal') }
}

function openAddExpense(row) {
  showExpense.value = row
  Object.assign(expenseInput, {
    category: 'TRANSPORT', description: '', amount: 0,
    expense_date: row.start_date || '', is_billable: 1,
  })
}
async function addExpense() {
  if (! expenseInput.description || ! expenseInput.amount) {
    toast.error('Isi deskripsi & amount')
    return
  }
  try {
    await api.post(`/business-trips/${showExpense.value.id}/expenses`, expenseInput)
    toast.success('Expense ditambahkan')
    // refresh detail
    if (detailRow.value && detailRow.value.id === showExpense.value.id) {
      const res = await api.get(`/business-trips/${showExpense.value.id}`)
      detailRow.value = res.data.data
    }
    showExpense.value = null
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function deleteExpense(exp) {
  const ok = await confirmDialog({
    title: 'Hapus expense?', message: exp.description,
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/business-trip-expenses/${exp.id}`)
    if (detailRow.value) {
      const res = await api.get(`/business-trips/${detailRow.value.id}`)
      detailRow.value = res.data.data
    }
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

function applyFilter() { filters.page = 1; fetchData() }
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / filters.per_page)))
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <PaperAirplaneIcon class="w-6 h-6 text-primary-600" />
          Perjalanan Dinas (SPPD)
        </h1>
        <p class="text-sm text-gray-500">Request perjalanan dinas dengan advance & settlement otomatis.</p>
      </div>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> Ajukan SPPD
      </button>
    </div>

    <div class="card card-body mb-4">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <div><label class="label">Cari</label>
          <input v-model="filters.q" class="input" placeholder="No, tujuan, maksud..."
            @keydown.enter="applyFilter" /></div>
        <div><label class="label">Status</label>
          <select v-model="filters.status" class="input">
            <option value="">Semua</option>
            <option v-for="(b, k) in STATUS_BADGE" :key="k" :value="k">{{ b.label }}</option>
          </select></div>
        <div v-if="canManage || canApprove">
          <label class="label">Karyawan</label>
          <EmployeeSearchSelect
            v-model="filters.employee_id"
            placeholder="Semua"
            :only-active="false"
          />
        </div>
        <div class="flex items-end gap-2">
          <label class="flex items-center gap-2 text-sm whitespace-nowrap">
            <input type="checkbox" v-model="filters.mine" :true-value="1" :false-value="0" />
            Hanya milik saya
          </label>
        </div>
        <div class="flex items-end"><button class="btn-secondary w-full" @click="applyFilter">Filter</button></div>
      </div>
    </div>

    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr><th>No SPPD</th><th>Karyawan</th><th>Tujuan</th><th>Tanggal</th>
              <th>Advance</th><th>Actual</th><th>Status</th><th></th></tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="8" class="text-center py-8 text-gray-400">Memuat…</td></tr>
            <tr v-else-if="items.length === 0">
              <td colspan="8" class="text-center py-12 text-gray-400">Belum ada SPPD.</td>
            </tr>
            <tr v-for="r in items" :key="r.id" class="cursor-pointer" @click="openDetail(r)">
              <td class="font-mono text-xs">{{ r.reference_number }}</td>
              <td>
                <div class="font-medium">{{ r.employee_name }}</div>
                <div class="text-xs text-gray-500">{{ r.department_name || '-' }}</div>
              </td>
              <td>
                <div class="font-medium">{{ r.destination }}</div>
                <div class="text-xs text-gray-500">{{ r.destination_type }} · {{ r.duration_days }} hari</div>
              </td>
              <td class="text-xs">
                {{ fmtDate(r.start_date) }}<br />
                <span class="text-gray-500">→ {{ fmtDate(r.end_date) }}</span>
              </td>
              <td class="text-right font-mono">{{ fmtCurrency(r.advance_amount) }}</td>
              <td class="text-right font-mono">
                {{ fmtCurrency(r.actual_amount) }}
                <div v-if="r.status === 'SETTLED'" class="text-xs"
                  :class="r.settlement_amount > 0 ? 'text-amber-600' : r.settlement_amount < 0 ? 'text-red-600' : 'text-gray-500'">
                  {{ r.settlement_amount > 0 ? '+' : '' }}{{ fmtCurrency(r.settlement_amount) }}
                </div>
              </td>
              <td>
                <span class="px-2 py-0.5 rounded text-xs" :class="STATUS_BADGE[r.status]?.cls">
                  {{ STATUS_BADGE[r.status]?.label || r.status }}
                </span>
              </td>
              <td class="text-right" @click.stop>
                <div class="flex items-center justify-end gap-1">
                  <button v-if="r.status === 'DRAFT'" class="text-blue-600 hover:text-blue-800 p-1"
                    title="Submit" @click="submit(r)">
                    <SubmitIcon class="w-4 h-4" />
                  </button>
                  <template v-if="canApprove && r.status === 'PENDING'">
                    <button class="text-emerald-600 hover:text-emerald-800 p-1" title="Approve" @click="approve(r)">
                      <CheckCircleIcon class="w-4 h-4" />
                    </button>
                    <button class="text-red-600 hover:text-red-800 p-1" title="Reject" @click="openReject(r)">
                      <XCircleIcon class="w-4 h-4" />
                    </button>
                  </template>
                  <button v-if="r.status === 'APPROVED'" class="text-indigo-600 hover:text-indigo-800 p-1"
                    title="Mulai" @click="startTrip(r)">
                    <PlayIcon class="w-4 h-4" />
                  </button>
                  <button v-if="['APPROVED','IN_PROGRESS'].includes(r.status)"
                    class="text-emerald-600 hover:text-emerald-800 p-1" title="Selesai" @click="complete(r)">
                    <FlagIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canSettle && r.status === 'COMPLETED'"
                    class="text-purple-600 hover:text-purple-800 p-1" title="Settle" @click="settle(r)">
                    <BanknotesIcon class="w-4 h-4" />
                  </button>
                  <button v-if="['APPROVED','IN_PROGRESS','COMPLETED','SETTLED'].includes(r.status)"
                    class="text-gray-600 hover:text-primary-600 p-1" title="SPPD PDF" @click="downloadSppd(r)">
                    <DocumentArrowDownIcon class="w-4 h-4" />
                  </button>
                  <button v-if="r.status === 'DRAFT'" class="text-gray-600 hover:text-primary-600 p-1"
                    title="Edit" @click="openEdit(r)">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button v-if="['DRAFT','REJECTED','CANCELLED'].includes(r.status)"
                    class="text-red-600 hover:text-red-800 p-1" title="Hapus"
                    @click="async () => { const ok = await confirmDialog({ title: 'Hapus?', okText: 'Hapus', variant: 'danger' }); if (ok) { await api.delete(`/business-trips/${r.id}`); fetchData() } }">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="totalPages > 1" class="px-4 py-3 flex items-center justify-between border-t text-sm">
        <span class="text-gray-500">{{ total }} SPPD · halaman {{ filters.page }} / {{ totalPages }}</span>
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
          <h2 class="font-semibold">{{ editing.id ? 'Edit SPPD' : 'Ajukan SPPD' }}</h2>
          <button class="text-gray-500" @click="showForm = false">&times;</button>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-3">
          <div v-if="canManage" class="md:col-span-2">
            <label class="label">Karyawan (kosong = saya sendiri)</label>
            <EmployeeSearchSelect
              v-model="editing.employee_id"
              placeholder="— Saya sendiri —"
              :only-active="true"
            />
          </div>
          <div class="md:col-span-2">
            <label class="label">Maksud / Tujuan Perjalanan *</label>
            <textarea v-model="editing.purpose" class="input" rows="2"></textarea>
          </div>
          <div>
            <label class="label">Kota Tujuan *</label>
            <input v-model="editing.destination" class="input" placeholder="Surabaya" />
          </div>
          <div>
            <label class="label">Domestik / Luar Negeri</label>
            <select v-model="editing.destination_type" class="input">
              <option value="DOMESTIC">Domestik</option>
              <option value="INTERNATIONAL">Luar Negeri</option>
            </select>
          </div>
          <div>
            <label class="label">Tgl Berangkat *</label>
            <AppDatePicker  v-model="editing.start_date" class="input" />
          </div>
          <div>
            <label class="label">Tgl Kembali *</label>
            <AppDatePicker  v-model="editing.end_date" class="input" />
          </div>
          <div>
            <label class="label">Transportasi</label>
            <select v-model="editing.transport_mode" class="input">
              <option value="">— Pilih —</option>
              <option v-for="t in TRANSPORT" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div>
            <label class="label">Akomodasi</label>
            <input v-model="editing.accommodation" class="input" placeholder="Hotel..." />
          </div>
          <div class="md:col-span-2">
            <label class="label">Estimasi Uang Muka (Rp)</label>
            <input type="number" v-model="editing.advance_amount" class="input" />
          </div>
          <div class="md:col-span-2">
            <label class="label">Catatan</label>
            <textarea v-model="editing.notes" class="input" rows="2"></textarea>
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showForm = false">Batal</button>
          <button class="btn-primary" @click="save">Simpan Draft</button>
        </div>
      </div>
    </div>

    <!-- Detail modal -->
    <div v-if="detailRow" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <div>
            <h2 class="font-semibold">{{ detailRow.reference_number }}</h2>
            <div class="text-xs text-gray-500">{{ detailRow.destination }} · {{ detailRow.duration_days }} hari</div>
          </div>
          <button class="text-gray-500" @click="detailRow = null">&times;</button>
        </div>

        <div class="px-6 py-4 grid grid-cols-1 md:grid-cols-3 gap-3 text-sm border-b bg-gray-50">
          <div>
            <div class="text-xs text-gray-500">Status</div>
            <span class="px-2 py-0.5 rounded text-xs" :class="STATUS_BADGE[detailRow.status]?.cls">
              {{ STATUS_BADGE[detailRow.status]?.label }}
            </span>
          </div>
          <div>
            <div class="text-xs text-gray-500">Advance</div>
            <div class="font-mono font-bold">{{ fmtCurrency(detailRow.advance_amount) }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">Actual</div>
            <div class="font-mono font-bold">{{ fmtCurrency(detailRow.actual_amount) }}</div>
          </div>
          <div v-if="detailRow.status === 'SETTLED'" class="md:col-span-3">
            <div class="text-xs text-gray-500">Settlement</div>
            <div class="font-mono font-bold"
              :class="detailRow.settlement_amount > 0 ? 'text-amber-600' : detailRow.settlement_amount < 0 ? 'text-red-600' : ''">
              {{ detailRow.settlement_amount > 0 ? '+' : '' }}{{ fmtCurrency(detailRow.settlement_amount) }}
              <span v-if="detailRow.settlement_amount > 0" class="text-xs">(reimburse)</span>
              <span v-else-if="detailRow.settlement_amount < 0" class="text-xs">(kembalikan)</span>
            </div>
          </div>
        </div>

        <div class="px-6 py-4">
          <div class="flex items-center justify-between mb-2">
            <h3 class="font-semibold">Bukti Pengeluaran</h3>
            <button v-if="['APPROVED','IN_PROGRESS','COMPLETED'].includes(detailRow.status)"
              class="btn-secondary text-xs px-2 py-1" @click="openAddExpense(detailRow)">
              <PlusIcon class="w-3 h-3" /> Tambah
            </button>
          </div>
          <table class="table">
            <thead>
              <tr><th>Tanggal</th><th>Kategori</th><th>Deskripsi</th><th class="text-right">Amount</th><th></th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="exp in detailRow.expenses" :key="exp.id">
                <td class="text-xs">{{ exp.expense_date ? fmtDate(exp.expense_date) : '-' }}</td>
                <td class="text-xs">{{ exp.category }}</td>
                <td class="text-sm">{{ exp.description }}</td>
                <td class="text-right font-mono">{{ fmtCurrency(exp.amount) }}</td>
                <td class="text-xs">
                  <span v-if="! exp.is_billable" class="px-2 py-0.5 rounded bg-gray-100 text-gray-600">Pribadi</span>
                </td>
                <td class="text-right">
                  <button v-if="detailRow.status !== 'SETTLED'" class="text-red-600 hover:text-red-800 p-1"
                    @click="deleteExpense(exp)">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </td>
              </tr>
              <tr v-if="!detailRow.expenses?.length">
                <td colspan="6" class="text-center py-4 text-gray-400 text-sm">Belum ada bukti pengeluaran</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="px-6 py-3 border-t flex justify-end">
          <button class="btn-secondary" @click="detailRow = null">Tutup</button>
        </div>
      </div>
    </div>

    <!-- Add expense modal -->
    <div v-if="showExpense" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">Tambah Bukti Pengeluaran</h2>
          <button class="text-gray-500" @click="showExpense = null">&times;</button>
        </div>
        <div class="p-6 space-y-3">
          <div>
            <label class="label">Kategori *</label>
            <select v-model="expenseInput.category" class="input">
              <option v-for="c in EXPENSE_CATS" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div>
            <label class="label">Tanggal</label>
            <AppDatePicker  v-model="expenseInput.expense_date" class="input" />
          </div>
          <div>
            <label class="label">Deskripsi *</label>
            <input v-model="expenseInput.description" class="input" placeholder="Tiket pesawat..." />
          </div>
          <div>
            <label class="label">Jumlah (Rp) *</label>
            <input type="number" v-model="expenseInput.amount" class="input" />
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" v-model="expenseInput.is_billable" :true-value="1" :false-value="0" />
            Tagihkan ke perusahaan
            <span class="text-xs text-gray-500">(uncheck = pengeluaran pribadi tidak ditagih)</span>
          </label>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showExpense = null">Batal</button>
          <button class="btn-primary" @click="addExpense">Simpan</button>
        </div>
      </div>
    </div>

    <!-- Reject modal -->
    <div v-if="showReject" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">Tolak SPPD {{ showReject.reference_number }}</h2>
          <button class="text-gray-500" @click="showReject = null">&times;</button>
        </div>
        <div class="p-6">
          <label class="label">Alasan penolakan *</label>
          <textarea v-model="rejectReason" class="input" rows="3"></textarea>
          <p class="text-xs text-gray-500 mt-1">Min 5 karakter. Akan dikirim sebagai notifikasi ke karyawan.</p>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showReject = null">Batal</button>
          <button class="btn-danger" @click="doReject">Tolak</button>
        </div>
      </div>
    </div>
  </div>
</template>