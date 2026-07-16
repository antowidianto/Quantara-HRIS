<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '@/api/client'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { useAsyncActions, useLatestRequest } from '@/composables/useAsyncAction'
import { useI18n } from '@/locales'
import {
  ArrowDownTrayIcon, BanknotesIcon, DocumentTextIcon, ShieldCheckIcon,
  ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

const route = useRoute()
const toast = useToast()
const { t, locale } = useI18n()
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
const { runAction, isActionPending } = useAsyncActions()
const { runLatest: runLatestFetch } = useLatestRequest()

const apiBase = computed(() => api.defaults.baseURL || '/api/v1')

async function fetchData() {
  const id = route.params.id
  await runLatestFetch(
    () => Promise.all([
      api.get(`/payroll-periods/${id}`),
      api.get('/payslips', { params: { period_id: id, per_page: 200 } }),
    ]),
    ([p, s]) => {
      period.value = p.data.data
      slips.value = s.data.data
      fetchPendingApprovals()
    },
    () => toast.error(t('payroll.period_detail.load_failed')),
  )
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
  const baseMessage = t('payroll.period_detail.calc_base_msg')
  const pendingMessage = hasPending
    ? `\n\n${locale.value === 'en' ? 'Warning' : 'Peringatan'}: ${t('payroll.period_detail.pending_warnings', { count: pendingApprovals.value.total_count })} (`
      + pendingApprovals.value.warnings.map(w => `${w.count} ${labelType(w.type)}`).join(', ')
      + `). ${locale.value === 'en' ? 'These transactions will NOT be included in this period calculation until they are approved.' : 'Transaksi tersebut TIDAK akan masuk ke perhitungan periode ini sampai di-approve.'}`
    : ''
  const ok = await confirmDialog({
    title: hasPending
      ? t('payroll.period_detail.calc_pending_title')
      : t('payroll.period_detail.calc_title'),
    message: baseMessage + pendingMessage,
    okText: hasPending ? t('payroll.period_detail.calc_ok_text_pending') : t('payroll.period_detail.calc_ok_text'),
    variant: hasPending ? 'warning' : undefined,
  })
  if (! ok) return
  try {
    const res = await api.post(`/payroll-periods/${route.params.id}/calculate`)
    const summary = res.data?.data
    const warnings = summary?.warnings ?? []
    if (warnings.length > 0) {
      toast.warning(
        t('payroll.period_detail.calc_warnings_msg', { count: warnings.length }),
        { timeout: 6000 },
      )
    } else {
      toast.success(t('payroll.period_detail.calc_success'))
    }
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || t('payroll.period_detail.calc_failed')) }
}

// Mapping label tipe untuk display
function labelType(type) {
  if (locale.value === 'en') {
    const labels = {
      overtime: 'overtime',
      attendance_change: 'attendance change',
      reimbursement: 'reimbursement',
      loan: 'loan',
      bonus_run: 'bonus run',
    }
    return labels[type] || type
  }
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
  return runAction('finalize', async () => {
    const ok = await confirmDialog({
      title: t('payroll.period_detail.finalize_title'),
      message: t('payroll.period_detail.finalize_msg'),
      okText: t('payroll.period_detail.finalize'),
      variant: 'warning',
    })
    if (! ok) return
    try {
      await api.post(`/payroll-periods/${route.params.id}/finalize`)
      toast.success(t('payroll.period_detail.finalize_success'))
      await fetchData()
    } catch (e) {
      toast.error(e.response?.data?.message || t('payroll.period_detail.finalize_failed'))
    }
  })
}

async function transition(action, successKey, failureKey) {
  return runAction(action, async () => {
    try {
      await api.post(`/payroll-periods/${route.params.id}/${action}`)
      toast.success(t(successKey))
      await fetchData()
    } catch (e) {
      toast.error(e.response?.data?.message || t(failureKey))
    }
  })
}

const submitPayroll = () => transition(
  'submit', 'payroll.period_detail.submit_success', 'payroll.period_detail.submit_failed',
)
const approvePayroll = () => transition(
  'approve', 'payroll.period_detail.approve_success', 'payroll.period_detail.approve_failed',
)

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
    toast.error(e.response?.data?.message || t('payroll.period_detail.export_summary_failed'))
    showExportDialog.value = false
  } finally {
    exportLoading.value = false
  }
}

// Helper: download file dari endpoint backend lewat axios.
async function download(url, params = {}) {
  try {
    const res = await api.get(url, { params, responseType: 'blob' })
    if (res.status === 202 || String(res.headers['content-type'] || '').includes('application/json')) {
      const text = res.data instanceof Blob ? await res.data.text() : String(res.data || '')
      const pending = JSON.parse(text)
      toast.warning(pending.message || 'Export menunggu approval. Ulangi download setelah disetujui.')
      return
    }
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
    toast.success(t('payroll.period_detail.download_success', { filename }))
  } catch (e) {
    // Kalau backend balikin JSON error (mis. status DRAFT), coba parse
    if (e.response?.data instanceof Blob) {
      const text = await e.response.data.text()
      try {
        const j = JSON.parse(text)
        toast.error(j.message || t('payroll.period_detail.download_failed'))
        return
      } catch (_) { /* */ }
    }
    toast.error(e.response?.data?.message || t('payroll.period_detail.download_failed'))
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
        <button v-if="['DRAFT','CALCULATED'].includes(period.status)" class="btn-secondary" @click="calculate">{{ t('payroll.period_detail.recalculate') }}</button>
        <button v-if="period.status === 'CALCULATED' && !period.submitted_at" class="btn-success" @click="submitPayroll">{{ t('payroll.period_detail.submit_approval') }}</button>
        <button v-if="period.status === 'CALCULATED' && period.submitted_at" class="btn-success" :disabled="isActionPending('approve')" @click="approvePayroll">{{ t('payroll.period_detail.approve') }}</button>
        <button v-if="period.status === 'APPROVED'" class="btn-success" :disabled="isActionPending('finalize')" @click="finalize">{{ t('payroll.period_detail.finalize') }}</button>
        <button v-if="['CALCULATED','APPROVED','PAID','CLOSED'].includes(period.status)"
          class="btn-primary" @click="openExport">
          <ArrowDownTrayIcon class="w-4 h-4" /> {{ t('payroll.period_detail.export_setor') }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      <div class="card card-body"><div class="text-xs text-gray-500">{{ t('payroll.period_detail.status') }}</div><div class="text-lg font-bold">{{ period.status }}</div></div>
      <div class="card card-body"><div class="text-xs text-gray-500">{{ t('payroll.period_detail.total_gross') }}</div><div class="text-lg font-bold">{{ fmtCurrency(period.total_gross) }}</div></div>
      <div class="card card-body"><div class="text-xs text-gray-500">{{ t('payroll.period_detail.total_deduction') }}</div><div class="text-lg font-bold">{{ fmtCurrency(period.total_deduction) }}</div></div>
      <div class="card card-body"><div class="text-xs text-gray-500">{{ t('payroll.period_detail.take_home_pay') }}</div><div class="text-lg font-bold text-primary-600">{{ fmtCurrency(period.total_net) }}</div></div>
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
            {{ pendingApprovals.total_count }} {{ t('payroll.period_detail.pending_warnings') }}
          </div>
          <p class="text-sm text-amber-800 mb-3">
            {{ t('payroll.period_detail.pending_desc') }}
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
              {{ t('payroll.period_detail.view_requests') }}
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
                    … +{{ rows.length - 10 }} {{ locale === 'en' ? 'others' : 'lainnya' }}
                  </li>
                </ul>
              </div>
            </div>
          </details>
          <button type="button"
            class="mt-3 text-xs text-amber-800 underline hover:text-amber-900"
            @click="fetchPendingApprovals" :disabled="pendingLoading">
            {{ pendingLoading ? t('payroll.period_detail.reloading') : t('payroll.period_detail.refresh') }}
          </button>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="font-semibold">{{ t('payroll.period_detail.slips_title') }} ({{ slips.length }})</span></div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr><th>{{ t('payroll.period_detail.col_slip_no') }}</th><th>{{ t('payroll.period_detail.col_nip') }}</th><th>{{ t('payroll.period_detail.col_name') }}</th><th>{{ t('payroll.period_detail.col_gross') }}</th><th>{{ t('payroll.period_detail.col_pph21') }}</th><th>{{ t('payroll.period_detail.col_bpjs') }}</th><th>{{ t('payroll.period_detail.col_thp') }}</th><th></th></tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="slips.length === 0"><td colspan="8" class="text-center py-8 text-gray-400">{{ t('payroll.period_detail.no_slips') }}</td></tr>
            <tr v-for="s in slips" :key="s.id">
              <td class="font-mono text-xs">{{ s.slip_number }}</td>
              <td>{{ s.employee_number }}</td>
              <td>{{ s.employee_name }}</td>
              <td>{{ fmtCurrency(s.gross_earning) }}</td>
              <td>{{ fmtCurrency(s.pph21_amount) }}</td>
              <td>{{ fmtCurrency(Number(s.bpjs_kes_employee) + Number(s.bpjs_jht_employee) + Number(s.bpjs_jp_employee)) }}</td>
              <td class="font-semibold">{{ fmtCurrency(s.take_home_pay) }}</td>
              <td><RouterLink :to="`/payslips/${s.id}`" class="text-primary-600 hover:underline text-sm">{{ t('payroll.period_detail.detail') }}</RouterLink></td>
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
            <ArrowDownTrayIcon class="w-5 h-5" /> {{ t('payroll.period_detail.export_title') }} {{ period.name }}
          </h2>
          <button class="text-gray-500" @click="showExportDialog = false">&times;</button>
        </div>

        <div v-if="exportLoading" class="p-12 text-center text-gray-400">{{ t('payroll.period_detail.export_summary') }}</div>

        <div v-else-if="exportSummary" class="p-6 space-y-5">
          <!-- Summary -->
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-blue-50 rounded p-3 border border-blue-100">
              <div class="text-xs text-blue-700">{{ t('payroll.period_detail.employees') }}</div>
              <div class="text-xl font-bold text-blue-900">{{ exportSummary.employees }}</div>
            </div>
            <div class="bg-emerald-50 rounded p-3 border border-emerald-100">
              <div class="text-xs text-emerald-700">{{ t('payroll.period_detail.total_thp') }}</div>
              <div class="text-xl font-bold text-emerald-900">{{ fmtCurrency(exportSummary.total_net) }}</div>
            </div>
            <div class="bg-amber-50 rounded p-3 border border-amber-100">
              <div class="text-xs text-amber-700">{{ t('payroll.period_detail.total_pph21') }}</div>
              <div class="text-xl font-bold text-amber-900">{{ fmtCurrency(exportSummary.total_pph21) }}</div>
            </div>
            <div class="bg-purple-50 rounded p-3 border border-purple-100">
              <div class="text-xs text-purple-700">{{ t('payroll.period_detail.bank_mix') }}</div>
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
              <ExclamationTriangleIcon class="w-5 h-5" /> {{ t('payroll.period_detail.missing_data_title') }}
            </div>
            <ul class="text-amber-900 text-xs space-y-1">
              <li v-if="exportSummary.missing.bank_account.length > 0">
                {{ t('payroll.period_detail.missing_bank', { count: exportSummary.missing.bank_account.length }) }}
              </li>
              <li v-if="exportSummary.missing.npwp.length > 0">
                {{ t('payroll.period_detail.missing_npwp', { count: exportSummary.missing.npwp.length }) }}
              </li>
              <li v-if="exportSummary.missing.bpjs_kes.length > 0">
                {{ t('payroll.period_detail.missing_bpjs_kes', { count: exportSummary.missing.bpjs_kes.length }) }}
              </li>
              <li v-if="exportSummary.missing.bpjs_tk.length > 0">
                {{ t('payroll.period_detail.missing_bpjs_tk', { count: exportSummary.missing.bpjs_tk.length }) }}
              </li>
            </ul>
          </div>

          <!-- Bank transfer -->
          <div class="border border-gray-200 rounded">
            <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium flex items-center gap-2">
              <BanknotesIcon class="w-5 h-5 text-emerald-600" /> {{ t('payroll.period_detail.bank_transfer_batch') }}
            </div>
            <div class="p-4 grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
              <div class="md:col-span-2">
                <label class="label">{{ t('payroll.period_detail.choose_bank') }}</label>
                <select v-model="selectedBank" class="input">
                  <option v-for="(label, code) in exportSummary.supported_banks" :key="code" :value="code">
                    {{ code }} — {{ label }}
                  </option>
                </select>
              </div>
              <button class="btn-primary w-full" @click="downloadBank">
                <ArrowDownTrayIcon class="w-4 h-4" /> {{ t('payroll.period_detail.download_csv') }}
              </button>
            </div>
          </div>

          <!-- e-SPT PPh21 -->
          <div class="border border-gray-200 rounded">
            <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium flex items-center gap-2">
              <DocumentTextIcon class="w-5 h-5 text-amber-600" /> {{ t('payroll.period_detail.espt_pph21') }}
            </div>
            <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3 items-end">
              <div>
                <label class="flex items-center gap-2 text-sm">
                  <input type="checkbox" v-model="isFinalForm" class="w-4 h-4" />
                  {{ t('payroll.period_detail.december_form') }}
                </label>
                <div class="text-xs text-gray-500 mt-1">
                  {{ t('payroll.period_detail.december_help') }}
                </div>
              </div>
              <button class="btn-primary w-full" @click="downloadPph21">
                <ArrowDownTrayIcon class="w-4 h-4" /> {{ t('payroll.period_detail.download_csv') }}
              </button>
            </div>
          </div>

          <!-- BPJS -->
          <div class="border border-gray-200 rounded">
            <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium flex items-center gap-2">
              <ShieldCheckIcon class="w-5 h-5 text-blue-600" /> {{ t('payroll.period_detail.bpjs') }}
            </div>
            <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
              <button class="btn-secondary" @click="downloadBpjs('TK')">
                <ArrowDownTrayIcon class="w-4 h-4" /> {{ t('payroll.period_detail.sipp_bpjs_tk') }}
              </button>
              <button class="btn-secondary" @click="downloadBpjs('KES')">
                <ArrowDownTrayIcon class="w-4 h-4" /> {{ t('payroll.period_detail.edabu_bpjs_kes') }}
              </button>
            </div>
          </div>

          <p class="text-xs text-gray-500">
            {{ t('payroll.period_detail.csv_note') }}
          </p>
        </div>

        <div class="px-6 py-3 border-t border-gray-200 flex justify-end">
          <button class="btn-secondary" @click="showExportDialog = false">{{ t('payroll.period_detail.close') }}</button>
        </div>
      </div>
    </div>
  </div>
  <div v-else class="text-center py-12 text-gray-400">{{ t('payroll.period_detail.loading_summary') }}</div>
</template>
