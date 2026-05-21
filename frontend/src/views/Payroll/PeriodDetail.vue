<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '@/api/client'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  ArrowDownTrayIcon, BanknotesIcon, DocumentTextIcon, ShieldCheckIcon,
  ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

const route = useRoute()
const toast = useToast()
const period = ref(null)
const slips = ref([])

// Export dialog
const showExportDialog = ref(false)
const exportSummary = ref(null)
const exportLoading = ref(false)
const selectedBank = ref('BCA')
const isFinalForm = ref(false)

const apiBase = computed(() => api.defaults.baseURL || '/api/v1')

async function fetchData() {
  const id = route.params.id
  const [p, s] = await Promise.all([
    api.get(`/payroll-periods/${id}`),
    api.get('/payslips', { params: { period_id: id, per_page: 200 } }),
  ])
  period.value = p.data.data
  slips.value = s.data.data
}
onMounted(fetchData)

async function calculate() {
  const ok = await confirmDialog({
    title: 'Hitung payroll periode ini?',
    message: 'Semua slip yang sudah ada akan dihitung ulang.',
    okText: 'Hitung',
  })
  if (! ok) return
  try {
    await api.post(`/payroll-periods/${route.params.id}/calculate`)
    toast.success('Payroll dihitung')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}
async function finalize() {
  const ok = await confirmDialog({
    title: 'Finalisasi periode payroll?',
    message: 'Setelah final, slip dianggap sudah dibayar dan tidak bisa di-rollback.',
    okText: 'Finalisasi',
    variant: 'warning',
  })
  if (! ok) return
  try {
    await api.post(`/payroll-periods/${route.params.id}/finalize`)
    toast.success('Periode difinalisasi')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function openExport() {
  exportLoading.value = true
  showExportDialog.value = true
  try {
    const res = await api.get(`/payroll-periods/${route.params.id}/export-summary`)
    exportSummary.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat summary')
    showExportDialog.value = false
  } finally {
    exportLoading.value = false
  }
}

// Helper: download file dari endpoint backend lewat axios.
async function download(url, params = {}) {
  try {
    const res = await api.get(url, { params, responseType: 'blob' })
    const disposition = res.headers['content-disposition'] || ''
    const match = disposition.match(/filename="?([^";]+)"?/)
    const filename = match ? match[1] : 'export.csv'
    const blob = new Blob([res.data], { type: res.headers['content-type'] || 'application/octet-stream' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = filename
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(link.href)
    toast.success(`Mengunduh ${filename}`)
  } catch (e) {
    // Kalau backend balikin JSON error (mis. status DRAFT), coba parse
    if (e.response?.data instanceof Blob) {
      const text = await e.response.data.text()
      try {
        const j = JSON.parse(text)
        toast.error(j.message || 'Gagal mengunduh')
        return
      } catch (_) { /* */ }
    }
    toast.error(e.response?.data?.message || 'Gagal mengunduh')
  }
}

function downloadBank() {
  download(`/payroll-periods/${route.params.id}/bank-export`, { bank: selectedBank.value })
}
function downloadPph21() {
  download(`/payroll-periods/${route.params.id}/pph21-export`,
    isFinalForm.value ? { final: 1 } : {})
}
function downloadBpjs(type) {
  download(`/payroll-periods/${route.params.id}/bpjs-export`, { type })
}
</script>

<template>
  <div v-if="period">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold">{{ period.name }}</h1>
        <div class="text-gray-500 text-sm">{{ period.code }} · {{ fmtDate(period.start_date) }} → {{ fmtDate(period.end_date) }}</div>
      </div>
      <div class="flex gap-2 flex-wrap">
        <button v-if="['DRAFT','CALCULATED'].includes(period.status)" class="btn-secondary" @click="calculate">Hitung Ulang</button>
        <button v-if="period.status === 'CALCULATED'" class="btn-success" @click="finalize">Finalisasi</button>
        <button v-if="['CALCULATED','APPROVED','PAID','CLOSED'].includes(period.status)"
          class="btn-primary" @click="openExport">
          <ArrowDownTrayIcon class="w-4 h-4" /> Export & Setor
        </button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      <div class="card card-body"><div class="text-xs text-gray-500">Status</div><div class="text-lg font-bold">{{ period.status }}</div></div>
      <div class="card card-body"><div class="text-xs text-gray-500">Total Gross</div><div class="text-lg font-bold">{{ fmtCurrency(period.total_gross) }}</div></div>
      <div class="card card-body"><div class="text-xs text-gray-500">Total Potongan</div><div class="text-lg font-bold">{{ fmtCurrency(period.total_deduction) }}</div></div>
      <div class="card card-body"><div class="text-xs text-gray-500">Take Home Pay</div><div class="text-lg font-bold text-primary-600">{{ fmtCurrency(period.total_net) }}</div></div>
    </div>

    <div class="card">
      <div class="card-header"><span class="font-semibold">Slip Gaji ({{ slips.length }})</span></div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr><th>No Slip</th><th>NIP</th><th>Nama</th><th>Bruto</th><th>PPh21</th><th>BPJS</th><th>THP</th><th></th></tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="slips.length === 0"><td colspan="8" class="text-center py-8 text-gray-400">Belum ada slip. Klik Hitung untuk mengkalkulasi.</td></tr>
            <tr v-for="s in slips" :key="s.id">
              <td class="font-mono text-xs">{{ s.slip_number }}</td>
              <td>{{ s.employee_number }}</td>
              <td>{{ s.employee_name }}</td>
              <td>{{ fmtCurrency(s.gross_earning) }}</td>
              <td>{{ fmtCurrency(s.pph21_amount) }}</td>
              <td>{{ fmtCurrency(Number(s.bpjs_kes_employee) + Number(s.bpjs_jht_employee) + Number(s.bpjs_jp_employee)) }}</td>
              <td class="font-semibold">{{ fmtCurrency(s.take_home_pay) }}</td>
              <td><RouterLink :to="`/payslips/${s.id}`" class="text-primary-600 hover:underline text-sm">Detail</RouterLink></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Export dialog -->
    <div v-if="showExportDialog" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold flex items-center gap-2">
            <ArrowDownTrayIcon class="w-5 h-5" /> Export Payroll {{ period.name }}
          </h2>
          <button class="text-gray-500" @click="showExportDialog = false">&times;</button>
        </div>

        <div v-if="exportLoading" class="p-12 text-center text-gray-400">Memuat ringkasan…</div>

        <div v-else-if="exportSummary" class="p-6 space-y-5">
          <!-- Summary -->
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-blue-50 rounded p-3 border border-blue-100">
              <div class="text-xs text-blue-700">Karyawan</div>
              <div class="text-xl font-bold text-blue-900">{{ exportSummary.employees }}</div>
            </div>
            <div class="bg-emerald-50 rounded p-3 border border-emerald-100">
              <div class="text-xs text-emerald-700">Total THP</div>
              <div class="text-xl font-bold text-emerald-900">{{ fmtCurrency(exportSummary.total_net) }}</div>
            </div>
            <div class="bg-amber-50 rounded p-3 border border-amber-100">
              <div class="text-xs text-amber-700">Total PPh21</div>
              <div class="text-xl font-bold text-amber-900">{{ fmtCurrency(exportSummary.total_pph21) }}</div>
            </div>
            <div class="bg-purple-50 rounded p-3 border border-purple-100">
              <div class="text-xs text-purple-700">Bank Mix</div>
              <div class="text-xs font-medium text-purple-900">
                <span v-for="(n, b) in exportSummary.bank_breakdown" :key="b" class="inline-block mr-2">
                  {{ b }}: {{ n }}
                </span>
                <span v-if="Object.keys(exportSummary.bank_breakdown).length === 0">-</span>
              </div>
            </div>
          </div>

          <!-- Warnings missing data -->
          <div v-if="exportSummary.missing.bank_account.length > 0
            || exportSummary.missing.npwp.length > 0
            || exportSummary.missing.bpjs_kes.length > 0
            || exportSummary.missing.bpjs_tk.length > 0"
            class="border border-amber-300 bg-amber-50 rounded p-3 text-sm">
            <div class="flex items-center gap-2 text-amber-800 font-medium mb-2">
              <ExclamationTriangleIcon class="w-5 h-5" /> Data Karyawan Belum Lengkap
            </div>
            <ul class="text-amber-900 text-xs space-y-1">
              <li v-if="exportSummary.missing.bank_account.length > 0">
                {{ exportSummary.missing.bank_account.length }} karyawan tanpa rekening bank — akan di-skip dari export bank
              </li>
              <li v-if="exportSummary.missing.npwp.length > 0">
                {{ exportSummary.missing.npwp.length }} karyawan tanpa NPWP — kolom NPWP di e-SPT akan kosong
              </li>
              <li v-if="exportSummary.missing.bpjs_kes.length > 0">
                {{ exportSummary.missing.bpjs_kes.length }} karyawan tanpa No. BPJS Kesehatan
              </li>
              <li v-if="exportSummary.missing.bpjs_tk.length > 0">
                {{ exportSummary.missing.bpjs_tk.length }} karyawan tanpa No. BPJS Ketenagakerjaan
              </li>
            </ul>
          </div>

          <!-- Bank transfer -->
          <div class="border border-gray-200 rounded">
            <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium flex items-center gap-2">
              <BanknotesIcon class="w-5 h-5 text-emerald-600" /> Bank Transfer Batch
            </div>
            <div class="p-4 grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
              <div class="md:col-span-2">
                <label class="label">Pilih Bank</label>
                <select v-model="selectedBank" class="input">
                  <option v-for="(label, code) in exportSummary.supported_banks" :key="code" :value="code">
                    {{ code }} — {{ label }}
                  </option>
                </select>
              </div>
              <button class="btn-primary w-full" @click="downloadBank">
                <ArrowDownTrayIcon class="w-4 h-4" /> Download CSV
              </button>
            </div>
          </div>

          <!-- e-SPT PPh21 -->
          <div class="border border-gray-200 rounded">
            <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium flex items-center gap-2">
              <DocumentTextIcon class="w-5 h-5 text-amber-600" /> e-SPT PPh21 (DJP)
            </div>
            <div class="p-4 grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
              <div class="md:col-span-2">
                <label class="flex items-center gap-2 text-sm">
                  <input type="checkbox" v-model="isFinalForm" class="w-4 h-4" />
                  Bukti Potong Tahunan (1721-A1) — centang untuk masa Desember
                </label>
                <div class="text-xs text-gray-500 mt-1">
                  Default: 1721-VI (tidak final, untuk masa bulanan).
                </div>
              </div>
              <button class="btn-primary w-full" @click="downloadPph21">
                <ArrowDownTrayIcon class="w-4 h-4" /> Download CSV
              </button>
            </div>
          </div>

          <!-- BPJS -->
          <div class="border border-gray-200 rounded">
            <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium flex items-center gap-2">
              <ShieldCheckIcon class="w-5 h-5 text-blue-600" /> BPJS
            </div>
            <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
              <button class="btn-secondary" @click="downloadBpjs('TK')">
                <ArrowDownTrayIcon class="w-4 h-4" /> SiPP BPJS Ketenagakerjaan
              </button>
              <button class="btn-secondary" @click="downloadBpjs('KES')">
                <ArrowDownTrayIcon class="w-4 h-4" /> e-Dabu BPJS Kesehatan
              </button>
            </div>
          </div>

          <p class="text-xs text-gray-500">
            File CSV pakai delimiter <code>;</code> dan UTF-8 BOM agar Excel di
            Windows membuka tanpa korup karakter Indonesia.
          </p>
        </div>

        <div class="px-6 py-3 border-t border-gray-200 flex justify-end">
          <button class="btn-secondary" @click="showExportDialog = false">Tutup</button>
        </div>
      </div>
    </div>
  </div>
  <div v-else class="text-center py-12 text-gray-400">Memuat...</div>
</template>