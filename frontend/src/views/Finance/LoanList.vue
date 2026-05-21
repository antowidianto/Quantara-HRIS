<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import {
  PlusIcon, CheckCircleIcon, XCircleIcon, BanknotesIcon, ClockIcon,
  ArrowPathIcon, InformationCircleIcon,
} from '@heroicons/vue/24/outline'

/**
 * Halaman Kasbon / Pinjaman.
 *
 * Integrasi ke payroll:
 *  - Saat HR/Finance approve loan (PENDING → RUNNING), `installment_amount`
 *    otomatis dipotong dari gaji setiap kali HR jalankan calculatePeriod.
 *  - Saat periode payroll difinalisasi, `paid_installments` bertambah dan
 *    `remaining` berkurang per slip. Loan habis → status COMPLETED.
 *  - Loan dengan status PENDING / REJECTED / CANCELLED tidak ikut dipotong.
 *
 * UI ini menyatukan: pengajuan (untuk semua karyawan), persetujuan
 * (HR/Finance), dan tracking sisa cicilan dengan kolom yang menunjukkan
 * berapa periode tersisa supaya jelas terhubung ke payroll.
 */

const toast = useToast()
const auth = useAuthStore()

const items = ref([])
const meta = ref({ total: 0, page: 1, pages: 1 })
const loading = ref(false)
const filters = reactive({ page: 1, per_page: 20, status: '', mine: 0 })

const showForm = ref(false)
const employees = ref([])
const form = ref({ employee_id: null, principal: 0, installments: 6, reason: '' })

// Hak akses HR/Finance bisa approve atau create utk orang lain.
const canApprove = computed(() => auth.can('loan.approve') || auth.isSuperAdmin)
const canCreateForOthers = computed(() => auth.can('loan.create_for_others') || canApprove.value)

async function fetchData() {
  loading.value = true
  try {
    const params = { page: filters.page, per_page: filters.per_page }
    if (filters.status) params.status = filters.status
    if (filters.mine && auth.employee?.id) params.employee_id = auth.employee.id
    const res = await api.get('/loans', { params })
    items.value = res.data.data || []
    meta.value = res.data.meta || meta.value
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat data')
  } finally {
    loading.value = false
  }
}

async function fetchEmployees() {
  if (! canCreateForOthers.value) return
  try {
    const res = await api.get('/employees', { params: { per_page: 500 } })
    employees.value = res.data.data || []
  } catch (_) { /* silent */ }
}

onMounted(() => { fetchData(); fetchEmployees() })

function openForm() {
  form.value = {
    employee_id: canCreateForOthers.value ? null : (auth.employee?.id || null),
    principal: 0,
    installments: 6,
    reason: '',
  }
  showForm.value = true
}

const installmentPreview = computed(() => {
  const p = Number(form.value.principal) || 0
  const n = Number(form.value.installments) || 0
  if (p <= 0 || n <= 0) return 0
  return Math.round(p / n)
})

async function submit() {
  if (Number(form.value.principal) <= 0) { toast.warning('Jumlah harus > 0'); return }
  if (Number(form.value.installments) <= 0 || Number(form.value.installments) > 24) {
    toast.warning('Cicilan 1–24 bulan'); return
  }
  try {
    const payload = { ...form.value }
    if (! canCreateForOthers.value) delete payload.employee_id
    await api.post('/loans', payload)
    toast.success('Pengajuan kasbon dikirim')
    showForm.value = false
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  }
}

async function approve(loan) {
  const ok = await confirmDialog({
    title: 'Setujui kasbon?',
    message: `Kasbon ${loan.loan_number} sebesar ${fmtCurrency(loan.principal)} akan otomatis dipotong ${fmtCurrency(loan.installment_amount)} per periode payroll selama ${loan.installments} bulan.`,
    okText: 'Setujui',
  })
  if (! ok) return
  try {
    await api.put(`/loans/${loan.id}`, { action: 'approve' })
    toast.success('Kasbon disetujui — akan otomatis dipotong di payroll berikutnya')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyetujui')
  }
}

async function reject(loan) {
  const ok = await confirmDialog({
    title: 'Tolak kasbon?',
    message: `Tolak pengajuan ${loan.loan_number}? Karyawan dapat mengajukan ulang.`,
    okText: 'Tolak',
    variant: 'warning',
  })
  if (! ok) return
  try {
    await api.put(`/loans/${loan.id}`, { action: 'reject' })
    toast.success('Pengajuan ditolak')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menolak')
  }
}

async function cancel(loan) {
  const ok = await confirmDialog({
    title: 'Batalkan pengajuan?',
    message: `Batalkan ${loan.loan_number}? Hanya pengajuan PENDING yang bisa dibatalkan.`,
    okText: 'Batalkan',
    variant: 'warning',
  })
  if (! ok) return
  try {
    await api.delete(`/loans/${loan.id}`)
    toast.success('Pengajuan dibatalkan')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal membatalkan')
  }
}

const statusBadge = (s) => ({
  PENDING: 'bg-amber-100 text-amber-800',
  RUNNING: 'bg-blue-100 text-blue-800',
  COMPLETED: 'bg-emerald-100 text-emerald-800',
  REJECTED: 'bg-red-100 text-red-800',
  CANCELLED: 'bg-gray-100 text-gray-700',
}[s] || 'bg-gray-100 text-gray-700')

// Sisa periode = ceil(remaining / installment_amount). Membantu user lihat
// berapa kali lagi loan ini akan muncul sebagai potongan di payroll.
function remainingPeriods(loan) {
  const inst = Number(loan.installment_amount) || 0
  const rem = Number(loan.remaining) || 0
  if (inst <= 0 || rem <= 0) return 0
  return Math.ceil(rem / inst)
}

function go(page) { filters.page = page; fetchData() }
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <BanknotesIcon class="w-7 h-7 text-emerald-600" />
          Kasbon / Pinjaman Karyawan
        </h1>
        <p class="text-sm text-gray-500">
          Kasbon yang disetujui otomatis dipotong dari gaji bulanan saat payroll dihitung.
        </p>
      </div>
      <button class="btn-primary" @click="openForm">
        <PlusIcon class="w-4 h-4" /> Ajukan Kasbon
      </button>
    </div>

    <!-- Info banner integrasi payroll -->
    <div class="rounded-md p-3 flex items-start gap-2 bg-blue-50 text-blue-900 text-sm border border-blue-200">
      <InformationCircleIcon class="w-5 h-5 mt-0.5 flex-shrink-0" />
      <div>
        <div class="font-medium">Cara kerja integrasi dengan payroll</div>
        <ol class="list-decimal list-inside text-xs mt-1 space-y-0.5">
          <li>Karyawan/HR ajukan kasbon → status <span class="font-mono">PENDING</span>.</li>
          <li>HR/Finance approve → status berubah ke <span class="font-mono">RUNNING</span>.</li>
          <li>Setiap kali periode payroll dihitung (Hitung Ulang), cicilan otomatis muncul sebagai potongan "Potongan Kasbon" di slip gaji.</li>
          <li>Saat periode di-finalize, sisa pinjaman berkurang. Loan habis → status <span class="font-mono">COMPLETED</span>.</li>
        </ol>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="card card-body flex flex-wrap items-center gap-3">
      <div>
        <label class="block text-xs text-gray-500 mb-0.5">Status</label>
        <select v-model="filters.status" @change="go(1)" class="input text-sm">
          <option value="">Semua status</option>
          <option value="PENDING">Menunggu approval</option>
          <option value="RUNNING">Berjalan (sedang dicicil)</option>
          <option value="COMPLETED">Selesai dibayar</option>
          <option value="REJECTED">Ditolak</option>
          <option value="CANCELLED">Dibatalkan</option>
        </select>
      </div>
      <div v-if="canApprove" class="flex items-center gap-2 mt-5">
        <input type="checkbox" v-model="filters.mine" :true-value="1" :false-value="0"
          @change="go(1)" class="w-4 h-4" id="mine" />
        <label for="mine" class="text-sm text-gray-700">Hanya saya</label>
      </div>
      <button class="btn-secondary text-sm ml-auto mt-5" @click="fetchData">
        <ArrowPathIcon class="w-4 h-4" /> Refresh
      </button>
    </div>

    <div class="card overflow-x-auto">
      <table class="table">
        <thead>
          <tr>
            <th>No. Kasbon</th>
            <th>Karyawan</th>
            <th>Pokok</th>
            <th>Cicilan / Bulan</th>
            <th>Progress</th>
            <th>Sisa</th>
            <th>Periode Tersisa</th>
            <th>Status</th>
            <th class="text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="loading">
            <td colspan="9" class="text-center py-8 text-gray-400">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="9" class="text-center py-8 text-gray-400">
              Belum ada data kasbon
            </td>
          </tr>
          <tr v-for="l in items" :key="l.id" class="hover:bg-gray-50">
            <td class="font-mono text-xs">{{ l.loan_number }}</td>
            <td>
              <div class="font-medium">{{ l.employee_name }}</div>
              <div class="text-xs text-gray-500">{{ l.employee_number }}</div>
            </td>
            <td class="font-medium">{{ fmtCurrency(l.principal) }}</td>
            <td>
              <div>{{ fmtCurrency(l.installment_amount) }}</div>
              <div class="text-xs text-gray-500">× {{ l.installments }} bulan</div>
            </td>
            <td>
              <div class="w-28">
                <div class="bg-gray-200 rounded-full h-2 overflow-hidden">
                  <div class="bg-emerald-500 h-full transition-all"
                    :style="{ width: ((Number(l.paid_installments) / Number(l.installments)) * 100) + '%' }"></div>
                </div>
                <div class="text-xs text-gray-600 mt-0.5">
                  {{ l.paid_installments }} / {{ l.installments }} cicilan
                </div>
              </div>
            </td>
            <td class="font-medium">{{ fmtCurrency(l.remaining) }}</td>
            <td>
              <span v-if="l.status === 'RUNNING'" class="inline-flex items-center gap-1 text-xs">
                <ClockIcon class="w-3.5 h-3.5 text-blue-500" />
                {{ remainingPeriods(l) }} periode
              </span>
              <span v-else class="text-xs text-gray-400">—</span>
            </td>
            <td>
              <span class="px-2 py-0.5 rounded text-xs font-medium" :class="statusBadge(l.status)">
                {{ l.status }}
              </span>
            </td>
            <td class="text-right">
              <div class="inline-flex gap-1">
                <button v-if="canApprove && l.status === 'PENDING'"
                  class="text-emerald-600 hover:text-emerald-800" title="Setujui & aktifkan ke payroll"
                  @click="approve(l)">
                  <CheckCircleIcon class="w-5 h-5" />
                </button>
                <button v-if="canApprove && l.status === 'PENDING'"
                  class="text-red-600 hover:text-red-800" title="Tolak"
                  @click="reject(l)">
                  <XCircleIcon class="w-5 h-5" />
                </button>
                <button v-if="l.status === 'PENDING' && Number(auth.employee?.id) === Number(l.employee_id)"
                  class="text-gray-500 hover:text-red-600 text-xs" title="Batalkan"
                  @click="cancel(l)">
                  Batal
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div class="card-body flex items-center justify-between">
        <div class="text-sm text-gray-500">Total: {{ meta.total }}</div>
        <div class="flex gap-2">
          <button class="btn-secondary text-sm" :disabled="meta.page <= 1" @click="go(meta.page - 1)">‹ Sebelumnya</button>
          <span class="px-3 py-2 text-sm">{{ meta.page }} / {{ meta.pages || 1 }}</span>
          <button class="btn-secondary text-sm" :disabled="meta.page >= meta.pages" @click="go(meta.page + 1)">Berikutnya ›</button>
        </div>
      </div>
    </div>

    <!-- Form modal -->
    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40"
      @click.self="showForm = false">
      <form @submit.prevent="submit"
        class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 space-y-4">
        <h2 class="font-semibold text-lg">Ajukan Kasbon</h2>

        <div v-if="canCreateForOthers">
          <label class="label">Karyawan</label>
          <select v-model.number="form.employee_id" class="input" required>
            <option :value="null">— pilih karyawan —</option>
            <option v-for="e in employees" :key="e.id" :value="e.id">
              {{ e.full_name }} ({{ e.employee_number }})
            </option>
          </select>
        </div>

        <div>
          <label class="label">Jumlah Kasbon (Rp)</label>
          <input v-model.number="form.principal" type="number" min="1" step="50000" class="input" required />
        </div>

        <div>
          <label class="label">Jumlah Cicilan (bulan)</label>
          <input v-model.number="form.installments" type="number" min="1" max="24" class="input" required />
          <div class="flex flex-wrap gap-1 mt-1">
            <button v-for="n in [3, 6, 12, 18, 24]" :key="n" type="button"
              class="text-xs px-2 py-0.5 rounded-full"
              :class="form.installments === n ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-primary-100'"
              @click="form.installments = n">
              {{ n }}×
            </button>
          </div>
        </div>

        <div v-if="installmentPreview > 0"
          class="rounded-md p-3 bg-emerald-50 border border-emerald-200 text-sm">
          <div class="text-emerald-900">
            Cicilan per bulan: <span class="font-semibold">{{ fmtCurrency(installmentPreview) }}</span>
          </div>
          <div class="text-xs text-emerald-700 mt-0.5">
            Akan otomatis dipotong dari gaji selama {{ form.installments }} bulan setelah disetujui.
          </div>
        </div>

        <div>
          <label class="label">Alasan</label>
          <textarea v-model="form.reason" rows="3" class="input"
            placeholder="Mis. biaya berobat orang tua, biaya sekolah anak..." required></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="btn-secondary" @click="showForm = false">Batal</button>
          <button type="submit" class="btn-primary">Kirim Pengajuan</button>
        </div>
      </form>
    </div>
  </div>
</template>