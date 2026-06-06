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

// Pending approvals warning (transaksi yang masih menggantung approval)
const pendingApprovals = ref(null)
const pendingLoading = ref(false)

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
  // Refresh pending approvals warning supaya HR aware sebelum klik Hitung
  fetchPendingApprovals()
}
onMounted(fetchData)

// Pre-check: ambil daftar pengajuan yang masih PENDING approval &
// menyentuh perhitungan periode ini. Endpoint read-only — boleh
// dipanggil bebas tanpa side-effect.
async function fetchPendingApprovals() {
  const id = route.params.id
  pendingLoading.value = true
  try {
    const res = await api.get(`/payroll-periods/${id}/pending-approvals`)
    pendingApprovals.value = res.data.data
  } catch (e) {
    // Non-fatal: jangan ganggu page kalau gagal load warning
    pendingApprovals.value = null
  } finally {
    pendingLoading.value = false
  }
}

async function calculate() {
  // Confirmation message di-augment kalau ada pending approval supaya HR
  // benar-benar aware tradeoff: hitung tetap bisa, tapi transaksi
  // pending akan ke-skip sampai di-approve & re-calculate.
  const hasPending = (pendingApprovals.value?.total_count ?? 0) > 0
  const baseMessage = 'Semua slip yang sudah ada akan dihitung ulang.'
  const pendingMessage = hasPending
    ? `\n\nPeringatan: Ada ${pendingApprovals.value.total_count} pengajuan transaksi yang masih menunggu approval (`
      + pendingApprovals.value.warnings.map(w => `${w.count} ${labelType(w.type)}`).join(', ')
      + '). Transaksi tersebut TIDAK akan masuk ke perhitungan periode ini sampai di-approve.'
    : ''
  const ok = await confirmDialog({
    title: hasPending
      ? 'Lanjut hitung payroll dengan pending approval?'
      : 'Hitung payroll periode ini?',
    message: baseMessage + pendingMessage,
    okText: hasPending ? 'Tetap Hitung' : 'Hitung',
    variant: hasPending ? 'warning' : undefined,
  })
  if (! ok) return
  try {
    const res = await api.post(`/payroll-periods/${route.params.id}/calculate`)
    const summary = res.data?.data
    const warnings = summary?.warnings ?? []
    if (warnings.length > 0) {
      toast.warning(
        `Payroll dihitung dengan ${warnings.length} warning. Cek panel pending approval di atas.`,
        { timeout: 6000 },
      )
    } else {
      toast.success('Payroll dihitung')
    }
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

// Mapping label tipe untuk display
function labelType(type) {
  const labels = {
    overtime: 'lembur',
    attendance_change: 'koreksi absensi',
    reimbursement: 'reimbursement',
    loan: 'kasbon',
    bonus_run: 'bonus run',
  }
  return labels[type] || type
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

async function disburseSalary() {
  const ok = await confirmDialog({
    title: 'Mulai Transfer Gaji via Midtrans?',
    message: 'Ini akan mengirimkan instruksi transfer ke bank masing-masing karyawan secara otomatis via Midtrans IRIS.',
    okText: 'Transfer Sekarang',
    variant: 'warning',
  })
  if (! ok) return
  try {
    await api.post(`/payroll-periods/${route.params.id}/disburse`)
    toast.success('Disbursement batch berhasil dibuat')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal memulai disbursement') }
}

async function checkDisbursementStatus() {
  try {
    await api.get(`/payroll-periods/${route.params.id}/disburse-status`)
    toast.info('Status transfer dicek.')
    fetchData()
  } catch (e) { toast.error('Gagal mengecek status') }
}

async function syncEbupot() {
  const ok = await confirmDialog({
    title: 'Lapor e-Bupot via API?',
    message: 'Data PPh 21 dari slip gaji akan dikirim langsung ke server DJP melalui PJAP.',
    okText: 'Lapor Sekarang',
    variant: 'warning',
  })
  if (! ok) return
  exportLoading.value = true
  try {
    const res = await api.post(`/payroll-periods/${route.params.id}/ebupot-sync`)
    toast.success('Batch e-Bupot berhasil dibuat: ' + res.data.data.batch_id)
    fetchData()
  } catch (e) { 
    toast.error(e.response?.data?.message || 'Gagal memulai sinkronisasi e-Bupot') 
  } finally {
    exportLoading.value = false
    showExportDialog.value = false
  }
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
        <button v-if="period.status === 'FINALIZED' && period.disbursement_status !== 'COMPLETED' && period.disbursement_status !== 'IN_PROGRESS'" class="btn-primary bg-indigo-600 hover:bg-indigo-700 text-white" @click="disburseSalary">
          Disburse Salary
        </button>
        <button v-if="period.disbursement_status === 'IN_PROGRESS'" class="btn-secondary" @click="checkDisbursementStatus">
          Cek Status Transfer
        </button>
        <button v-if="['CALCULATED','FINALIZED','APPROVED','PAID','CLOSED'].includes(period.status)"
          class="btn-primary" @click="openExport">
          <ArrowDownTrayIcon class="w-4 h-4" /> Export & Setor
        </button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
      <div class="card card-body"><div class="text-xs text-gray-500">Status</div><div class="text-lg font-bold">{{ period.status }}</div></div>
      <div class="card card-body"><div class="text-xs text-gray-500">Total Gross</div><div class="text-lg font-bold">{{ fmtCurrency(period.total_gross) }}</div></div>
      <div class="card card-body"><div class="text-xs text-gray-500">Total Potongan</div><div class="text-lg font-bold">{{ fmtCurrency(period.total_deduction) }}</div></div>
      <div class="card card-body"><div class="text-xs text-gray-500">Take Home Pay</div><div class="text-lg font-bold text-primary-600">{{ fmtCurrency(period.total_net) }}</div></div>
      <div class="card card-body"><div class="text-xs text-gray-500">Transfer via API</div><div class="text-lg font-bold" :class="{'text-amber-500': period.disbursement_status === 'IN_PROGRESS', 'text-emerald-500': period.disbursement_status === 'COMPLETED', 'text-rose-500': period.disbursement_status === 'FAILED'}">{{ period.disbursement_status || 'PENDING' }}</div></div>
    </div>

    <!-- Pending approval warning panel — muncul kalau ada pengajuan
         transaksi (overtime/koreksi absensi/reimbursement/kasbon/bonus)
         yang masih PENDING & menyentuh window periode ini. Hanya
         informatif; tidak block tombol Hitung. HR boleh tetap lanjut
         dan re-calculate setelah approval masuk. -->
    <div v-if="pendingApprovals && pendingApprovals.total_count > 0"
      class="border border-amber-300 bg-amber-50 rounded-lg p-4 mb-6">
      <div class="flex items-start gap-3">
        <ExclamationTriangleIcon class="w-6 h-6 text-amber-600 flex-shrink-0 mt-0.5" />
        <div class="flex-1 min-w-0">
          <div class="font-semibold text-amber-900 mb-1">
            {{ pendingApprovals.total_count }} pengajuan masih menunggu approval
          </div>
          <p class="text-sm text-amber-800 mb-3">
            Transaksi berikut masih PENDING dan akan <strong>diabaikan</strong> dari
            perhitungan periode ini sampai di-approve & periode di-hitung ulang.
          </p>
          <ul class="space-y-2">
            <li v-for="w in pendingApprovals.warnings" :key="w.type"
              class="flex items-start gap-2 text-sm">
              <span class="inline-flex items-center justify-center min-w-[1.5rem] h-6 px-2 rounded-full bg-amber-200 text-amber-900 text-xs font-bold">
                {{ w.count }}
              </span>
              <span class="text-amber-900 capitalize">{{ labelType(w.type) }}</span>
            </li>
          </ul>
          <details class="mt-3 text-xs">
            <summary class="cursor-pointer text-amber-800 hover:text-amber-900 font-medium">
              Lihat daftar pengajuan
            </summary>
            <div class="mt-2 space-y-3 text-amber-900">
              <div v-for="(rows, key) in pendingApprovals.items" :key="key">
                <div class="font-semibold mb-1 capitalize">{{ labelType(key) }}</div>
                <ul class="ml-4 space-y-0.5">
                  <li v-for="r in rows.slice(0, 10)" :key="r.id" class="font-mono text-[11px]">
                    <template v-if="key === 'overtime' || key === 'attendance_change'">
                      #{{ r.id }} · {{ r.employee_number || '-' }} · {{ r.employee_name || '-' }} · {{ fmtDate(r.work_date) }}
                    </template>
                    <template v-else-if="key === 'reimbursement'">
                      #{{ r.request_number || r.id }} · {{ r.employee_name || '-' }} · {{ fmtDate(r.expense_date) }} · {{ fmtCurrency(r.amount) }}
                    </template>
                    <template v-else-if="key === 'loan'">
                      #{{ r.loan_number || r.id }} · {{ r.employee_name || '-' }} · {{ fmtCurrency(r.principal) }}
                    </template>
                    <template v-else-if="key === 'bonus_run'">
                      #{{ r.id }} · {{ r.name }} ({{ r.status }}) · {{ fmtCurrency(r.total_amount) }}
                    </template>
                    <template v-else>
                      #{{ r.id }}
                    </template>
                  </li>
                  <li v-if="rows.length > 10" class="italic">
                    … +{{ rows.length - 10 }} lainnya
                  </li>
                </ul>
              </div>
            </div>
          </details>
          <button type="button"
            class="mt-3 text-xs text-amber-800 underline hover:text-amber-900"
            @click="fetchPendingApprovals" :disabled="pendingLoading">
            {{ pendingLoading ? 'Memuat ulang…' : 'Refresh' }}
          </button>
        </div>
      </div>
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
              <td class="font-semibold flex flex-col">
                {{ fmtCurrency(s.take_home_pay) }}
                <span v-if="s.disbursement_status === 'COMPLETED'" class="text-[10px] text-emerald-500 font-normal">Sent</span>
                <span v-if="s.disbursement_status === 'FAILED'" class="text-[10px] text-rose-500 font-normal">Failed</span>
              </td>
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
            <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium flex items-center justify-between">
              <div class="flex items-center gap-2">
                <DocumentTextIcon class="w-5 h-5 text-amber-600" /> e-Bupot PPh21 (DJP)
              </div>
              <span v-if="period.ebupot_status" class="text-xs px-2 py-1 rounded bg-amber-100 text-amber-800">
                Status: {{ period.ebupot_status }}
              </span>
            </div>
            <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3 items-end">
              <div>
                <label class="flex items-center gap-2 text-sm">
                  <input type="checkbox" v-model="isFinalForm" class="w-4 h-4" />
                  Bukti Potong Tahunan (1721-A1) — centang untuk masa Desember
                </label>
                <div class="text-xs text-gray-500 mt-1">
                  Default: 1721-VI (tidak final, untuk masa bulanan).
                </div>
              </div>
              <div class="flex gap-2 justify-end">
                <button class="btn-secondary" @click="downloadPph21">
                  <ArrowDownTrayIcon class="w-4 h-4" /> Download CSV
                </button>
                <button v-if="period.status === 'PAID'" class="btn-primary" @click="syncEbupot">
                  Lapor via API
                </button>
              </div>
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