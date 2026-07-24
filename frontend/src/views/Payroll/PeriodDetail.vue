<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { useAsyncActions, useLatestRequest } from '@/composables/useAsyncAction'
import { useI18n } from '@/locales'
import {
  ArrowDownTrayIcon,
  BanknotesIcon,
  DocumentTextIcon,
  ShieldCheckIcon,
  ExclamationTriangleIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

const route = useRoute()
const toast = useToast()
const auth = useAuthStore()
const { t, locale } = useI18n()
const period = ref(null)
const slips = ref([])
const showSlipDetailModal = ref(false)
const selectedSlip = ref(null)
const slipDetailLoading = ref(false)
const showApprovalValidationModal = ref(false)
const approvalReviewConfirmed = ref(false)
const approvalConfirmationCode = ref('')
const approvalSubmitting = ref(false)

// Pending approvals warning (transaksi yang masih menggantung approval)
const pendingApprovals = ref(null)
const pendingLoading = ref(false)

// Export dialog
const showExportDialog = ref(false)
const exportSummary = ref(null)
const exportLoading = ref(false)
const selectedBank = ref('BCA')
const isFinalForm = ref(false)
const varianceNote = ref('')
const { runAction, isActionPending } = useAsyncActions()
const { runLatest: runLatestFetch } = useLatestRequest()

const apiBase = computed(() => api.defaults.baseURL || '/api/v1')

function parseStoredJson(value) {
  if (!value) return null
  if (typeof value === 'object') return value
  try {
    return JSON.parse(value)
  } catch {
    return null
  }
}

const variance = computed(() => parseStoredJson(period.value?.variance_json))
const reconciliation = computed(() => parseStoredJson(period.value?.reconciliation_json))
const reconciliationGroups = computed(() => {
  const groups = new Map()
  for (const item of reconciliation.value?.exceptions || []) {
    if (!groups.has(item.code)) groups.set(item.code, { code: item.code, items: [] })
    groups.get(item.code).items.push(item)
  }
  return [...groups.values()]
})
const needsVarianceReview = computed(
  () =>
    period.value?.status === 'CALCULATED' &&
    Number(period.value?.variance_requires_review) === 1 &&
    !period.value?.variance_reviewed_at
)
const slipEarnings = computed(() =>
  (selectedSlip.value?.details || []).filter((item) => item.type === 'EARNING')
)
const slipDeductions = computed(() =>
  (selectedSlip.value?.details || []).filter((item) => item.type === 'DEDUCTION')
)
const canConfirmApproval = computed(
  () =>
    approvalReviewConfirmed.value && approvalConfirmationCode.value.trim() === period.value?.code
)
const canCalculate = computed(() => auth.can('payroll.calculate'))
const canFinalize = computed(() => auth.can('payroll.finalize'))

function reconciliationMessage(item) {
  const key = `payroll.period_detail.reconciliation_${item.code}`
  if (item.code === 'REGISTER_TOTAL_MISMATCH') {
    return t(key, { expected: fmtCurrency(item.expected), actual: fmtCurrency(item.actual) })
  }
  if (item.code === 'DUPLICATE_BANK_ACCOUNT') {
    return t(key, { employees: (item.employee_ids || []).join(', ') })
  }
  return t(key, { employee: item.employee_id })
}

function reconciliationGroupMessage(group) {
  if (group.code === 'REGISTER_TOTAL_MISMATCH') return reconciliationMessage(group.items[0])
  return t(`payroll.period_detail.reconciliation_summary_${group.code}`, {
    count: group.items.length,
  })
}

async function fetchData() {
  const id = route.params.id
  await runLatestFetch(
    () =>
      Promise.all([
        api.get(`/payroll-periods/${id}`),
        api.get('/payslips', { params: { period_id: id, per_page: 200 } }),
      ]),
    ([p, s]) => {
      period.value = p.data.data
      slips.value = s.data.data
      fetchPendingApprovals()
    },
    () => toast.error(t('payroll.period_detail.load_failed'))
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
    ? `\n\n${locale.value === 'en' ? 'Warning' : 'Peringatan'}: ${t('payroll.period_detail.pending_warnings', { count: pendingApprovals.value.total_count })} (` +
      pendingApprovals.value.warnings.map((w) => `${w.count} ${labelType(w.type)}`).join(', ') +
      `). ${locale.value === 'en' ? 'These transactions will NOT be included in this period calculation until they are approved.' : 'Transaksi tersebut TIDAK akan masuk ke perhitungan periode ini sampai di-approve.'}`
    : ''
  const ok = await confirmDialog({
    title: hasPending
      ? t('payroll.period_detail.calc_pending_title')
      : t('payroll.period_detail.calc_title'),
    message: baseMessage + pendingMessage,
    okText: hasPending
      ? t('payroll.period_detail.calc_ok_text_pending')
      : t('payroll.period_detail.calc_ok_text'),
    variant: hasPending ? 'warning' : undefined,
  })
  if (!ok) return
  try {
    const res = await api.post(`/payroll-periods/${route.params.id}/calculate`)
    const summary = res.data?.data
    const warnings = summary?.warnings ?? []
    if (warnings.length > 0) {
      toast.warning(t('payroll.period_detail.calc_warnings_msg', { count: warnings.length }), {
        timeout: 6000,
      })
    } else {
      toast.success(t('payroll.period_detail.calc_success'))
    }
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('payroll.period_detail.calc_failed'))
  }
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
    if (!ok) return
    try {
      await api.post(`/payroll-periods/${route.params.id}/finalize`)
      toast.success(t('payroll.period_detail.finalize_success'))
      await fetchData()
    } catch (e) {
      toast.error(e.response?.data?.message || t('payroll.period_detail.finalize_failed'))
    }
  })
}

const submitPayroll = () =>
  runAction('submit', async () => {
    try {
      const response = await api.post(`/payroll-periods/${route.params.id}/submit`)
      const approval = response.data?.data?.approval
      if (approval?.next_approvers?.length > 0) {
        toast.success(
          t('payroll.period_detail.submit_success_checkers', {
            count: approval.next_approvers.length,
            roles: t('payroll.period_detail.first_chain_step'),
          })
        )
      } else {
        toast.warning(t('payroll.period_detail.submit_no_checker'))
      }
    } catch (e) {
      toast.error(e.response?.data?.message || t('payroll.period_detail.submit_failed'))
    } finally {
      // A blocked submit can still persist reconciliation diagnostics.
      await fetchData()
    }
  })
async function openSlipDetail(id) {
  showSlipDetailModal.value = true
  slipDetailLoading.value = true
  selectedSlip.value = null
  try {
    const response = await api.get(`/payslips/${id}`)
    selectedSlip.value = response.data?.data || null
  } catch (e) {
    toast.error(e.response?.data?.message || t('payroll.slip_detail.load_failed'))
    showSlipDetailModal.value = false
  } finally {
    slipDetailLoading.value = false
  }
}

function closeSlipDetail() {
  if (slipDetailLoading.value) return
  showSlipDetailModal.value = false
  selectedSlip.value = null
}

function openApprovalValidation() {
  approvalReviewConfirmed.value = false
  approvalConfirmationCode.value = ''
  showApprovalValidationModal.value = true
}

function closeApprovalValidation() {
  if (approvalSubmitting.value) return
  showApprovalValidationModal.value = false
}

async function approvePayroll() {
  if (!period.value?.approval_action?.can_approve || !canConfirmApproval.value) return
  approvalSubmitting.value = true
  try {
    await api.post(`/payroll-periods/${route.params.id}/approve`, {
      review_confirmed: true,
      confirmation_code: approvalConfirmationCode.value.trim(),
    })
    toast.success(t('payroll.period_list.approval_success'))
    showApprovalValidationModal.value = false
    await fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('payroll.period_list.approval_failed'))
  } finally {
    approvalSubmitting.value = false
  }
}

async function reviewVariance() {
  if (varianceNote.value.trim().length < 10) {
    toast.error(t('payroll.period_detail.variance_note_min'))
    return
  }
  return runAction('review-variance', async () => {
    try {
      await api.post(`/payroll-periods/${route.params.id}/review-variance`, {
        note: varianceNote.value.trim(),
      })
      varianceNote.value = ''
      toast.success(t('payroll.period_detail.variance_review_success'))
      await fetchData()
    } catch (e) {
      toast.error(e.response?.data?.message || t('payroll.period_detail.variance_review_failed'))
    }
  })
}

async function disburseSalary() {
  const ok = await confirmDialog({
    title: t('payroll.period_detail.disburse_title'),
    message: t('payroll.period_detail.disburse_message'),
    okText: t('payroll.period_detail.disburse_confirm'),
    variant: 'warning',
  })
  if (!ok) return
  try {
    await api.post(`/payroll-periods/${route.params.id}/disburse`)
    toast.success(t('payroll.period_detail.disburse_success'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('payroll.period_detail.disburse_failed'))
  }
}

async function checkDisbursementStatus() {
  try {
    await api.get(`/payroll-periods/${route.params.id}/disburse-status`)
    toast.info(t('payroll.period_detail.disburse_status_checked'))
    fetchData()
  } catch (e) {
    toast.error(t('payroll.period_detail.disburse_status_failed'))
  }
}

async function syncEbupot() {
  const ok = await confirmDialog({
    title: 'Lapor e-Bupot via API?',
    message: 'Data PPh 21 dari slip gaji akan dikirim langsung ke server DJP melalui PJAP.',
    okText: 'Lapor Sekarang',
    variant: 'warning',
  })
  if (!ok) return
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
    if (
      res.status === 202 ||
      String(res.headers['content-type'] || '').includes('application/json')
    ) {
      const text = res.data instanceof Blob ? await res.data.text() : String(res.data || '')
      const pending = JSON.parse(text)
      toast.warning(
        pending.message || 'Export menunggu approval. Ulangi download setelah disetujui.'
      )
      return
    }
    const disposition = res.headers['content-disposition'] || ''
    const match = disposition.match(/filename="?([^";]+)"?/)
    const filename = match ? match[1] : 'export.csv'
    const blob = new Blob([res.data], {
      type: res.headers['content-type'] || 'application/octet-stream',
    })
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
      } catch (_) {
        /* */
      }
    }
    toast.error(e.response?.data?.message || t('payroll.period_detail.download_failed'))
  }
}

function downloadBank() {
  download(`/payroll-periods/${route.params.id}/bank-export`, { bank: selectedBank.value })
}
function downloadPph21() {
  download(
    `/payroll-periods/${route.params.id}/pph21-export`,
    isFinalForm.value ? { final: 1 } : {}
  )
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
        <div class="text-gray-500 text-sm">
          {{ period.code }} · {{ fmtDate(period.start_date) }} → {{ fmtDate(period.end_date) }}
        </div>
      </div>
      <div class="flex gap-2 flex-wrap">
        <button
          v-if="canCalculate && ['DRAFT', 'CALCULATED'].includes(period.status)"
          class="btn-secondary"
          @click="calculate"
        >
          {{ t('payroll.period_detail.recalculate') }}
        </button>
        <button
          v-if="period.can_submit"
          class="btn-success"
          @click="submitPayroll"
        >
          {{ t('payroll.period_detail.submit_approval') }}
        </button>
        <button
          v-if="period.approval_action?.can_approve"
          class="btn-success"
          :title="period.approval_action.current_step_name"
          @click="openApprovalValidation"
        >
          <ShieldCheckIcon class="w-4 h-4" />
          {{ t('payroll.period_list.approval_action') }}
        </button>
        <button
          v-if="canFinalize && period.status === 'APPROVED'"
          class="btn-success"
          :disabled="isActionPending('finalize')"
          @click="finalize"
        >
          {{ t('payroll.period_detail.finalize') }}
        </button>
        <button
          v-if="
            canFinalize &&
            period.status === 'FINALIZED' &&
            !['IN_PROGRESS', 'COMPLETED'].includes(period.disbursement_status)
          "
          class="btn-success"
          @click="disburseSalary"
        >
          <BanknotesIcon class="w-4 h-4" /> {{ t('payroll.period_detail.disburse_start') }}
        </button>
        <button
          v-if="period.disbursement_reference && period.status === 'FINALIZED'"
          class="btn-secondary"
          @click="checkDisbursementStatus"
        >
          {{ t('payroll.period_detail.disburse_check') }}
        </button>
        <button
          v-if="['CALCULATED', 'APPROVED', 'FINALIZED', 'PAID', 'CLOSED'].includes(period.status)"
          class="btn-primary"
          @click="openExport"
        >
          <ArrowDownTrayIcon class="w-4 h-4" /> {{ t('payroll.period_detail.export_setor') }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      <div class="card card-body">
        <div class="text-xs text-gray-500">{{ t('payroll.period_detail.status') }}</div>
        <div class="text-lg font-bold">{{ period.status }}</div>
      </div>
      <div class="card card-body">
        <div class="text-xs text-gray-500">{{ t('payroll.period_detail.total_gross') }}</div>
        <div class="text-lg font-bold">{{ fmtCurrency(period.total_gross) }}</div>
      </div>
      <div class="card card-body">
        <div class="text-xs text-gray-500">{{ t('payroll.period_detail.total_deduction') }}</div>
        <div class="text-lg font-bold">{{ fmtCurrency(period.total_deduction) }}</div>
      </div>
      <div class="card card-body">
        <div class="text-xs text-gray-500">{{ t('payroll.period_detail.take_home_pay') }}</div>
        <div class="text-lg font-bold text-primary-600">{{ fmtCurrency(period.total_net) }}</div>
      </div>
    </div>

    <div
      v-if="needsVarianceReview"
      class="border border-orange-300 bg-orange-50 rounded-lg p-4 mb-6"
    >
      <div class="font-semibold text-orange-900">
        {{ t('payroll.period_detail.variance_title') }}
      </div>
      <p class="text-sm text-orange-800 mt-1">
        {{
          t('payroll.period_detail.variance_desc', { threshold: variance?.threshold_percent ?? 10 })
        }}
      </p>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 my-3 text-sm">
        <div>
          <span class="text-orange-700">{{ t('payroll.period_detail.variance_previous') }}</span>
          <div class="font-semibold">{{ fmtCurrency(variance?.previous_total_net || 0) }}</div>
        </div>
        <div>
          <span class="text-orange-700">{{ t('payroll.period_detail.variance_current') }}</span>
          <div class="font-semibold">{{ fmtCurrency(variance?.current_total_net || 0) }}</div>
        </div>
        <div>
          <span class="text-orange-700">{{ t('payroll.period_detail.variance_change') }}</span>
          <div class="font-semibold">{{ variance?.total_variance_percent ?? 0 }}%</div>
        </div>
      </div>
      <label class="label">{{ t('payroll.period_detail.variance_note') }}</label>
      <textarea
        v-model="varianceNote"
        class="input w-full"
        rows="3"
        :placeholder="t('payroll.period_detail.variance_note_ph')"
      ></textarea>
      <button
        v-if="canCalculate"
        class="btn-primary mt-3"
        :disabled="isActionPending('review-variance')"
        @click="reviewVariance"
      >
        {{
          isActionPending('review-variance')
            ? t('payroll.period_detail.variance_reviewing')
            : t('payroll.period_detail.variance_review')
        }}
      </button>
    </div>

    <div
      v-if="reconciliation && !reconciliation.is_reconciled"
      class="border border-red-300 bg-red-50 rounded-lg p-4 mb-6"
    >
      <div class="font-semibold text-red-900">
        {{ t('payroll.period_detail.reconciliation_title') }}
      </div>
      <p class="text-sm text-red-800 mt-1">{{ t('payroll.period_detail.reconciliation_desc') }}</p>
      <div class="mt-3 space-y-2 text-sm text-red-900">
        <div v-for="group in reconciliationGroups" :key="group.code">
          <div class="font-medium">{{ reconciliationGroupMessage(group) }}</div>
          <details v-if="group.code !== 'REGISTER_TOTAL_MISMATCH'" class="mt-1">
            <summary class="cursor-pointer text-red-700">
              {{
                t('payroll.period_detail.reconciliation_show_details', {
                  count: group.items.length,
                })
              }}
            </summary>
            <ul class="list-disc pl-5 mt-1 max-h-48 overflow-y-auto space-y-1">
              <li v-for="(item, index) in group.items" :key="`${item.code}-${index}`">
                {{ reconciliationMessage(item) }}
              </li>
            </ul>
          </details>
        </div>
      </div>
    </div>

    <!-- Pending approval warning panel — muncul kalau ada pengajuan
         transaksi (overtime/koreksi absensi/reimbursement/kasbon/bonus)
         yang masih PENDING & menyentuh window periode ini. Hanya
         informatif; tidak block tombol Hitung. HR boleh tetap lanjut
         dan re-calculate setelah approval masuk. -->
    <div
      v-if="pendingApprovals && pendingApprovals.total_count > 0"
      class="border border-amber-300 bg-amber-50 rounded-lg p-4 mb-6"
    >
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
            <li
              v-for="w in pendingApprovals.warnings"
              :key="w.type"
              class="flex items-start gap-2 text-sm"
            >
              <span
                class="inline-flex items-center justify-center min-w-[1.5rem] h-6 px-2 rounded-full bg-amber-200 text-amber-900 text-xs font-bold"
              >
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
                      #{{ r.id }} · {{ r.employee_number || '-' }} · {{ r.employee_name || '-' }} ·
                      {{ fmtDate(r.work_date) }}
                    </template>
                    <template v-else-if="key === 'reimbursement'">
                      #{{ r.request_number || r.id }} · {{ r.employee_name || '-' }} ·
                      {{ fmtDate(r.expense_date) }} · {{ fmtCurrency(r.amount) }}
                    </template>
                    <template v-else-if="key === 'loan'">
                      #{{ r.loan_number || r.id }} · {{ r.employee_name || '-' }} ·
                      {{ fmtCurrency(r.principal) }}
                    </template>
                    <template v-else-if="key === 'bonus_run'">
                      #{{ r.id }} · {{ r.name }} ({{ r.status }}) ·
                      {{ fmtCurrency(r.total_amount) }}
                    </template>
                    <template v-else> #{{ r.id }} </template>
                  </li>
                  <li v-if="rows.length > 10" class="italic">
                    … +{{ rows.length - 10 }} {{ locale === 'en' ? 'others' : 'lainnya' }}
                  </li>
                </ul>
              </div>
            </div>
          </details>
          <button
            type="button"
            class="mt-3 text-xs text-amber-800 underline hover:text-amber-900"
            @click="fetchPendingApprovals"
            :disabled="pendingLoading"
          >
            {{
              pendingLoading
                ? t('payroll.period_detail.reloading')
                : t('payroll.period_detail.refresh')
            }}
          </button>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <span class="font-semibold"
          >{{ t('payroll.period_detail.slips_title') }} ({{ slips.length }})</span
        >
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('payroll.period_detail.col_slip_no') }}</th>
              <th>{{ t('payroll.period_detail.col_nip') }}</th>
              <th>{{ t('payroll.period_detail.col_name') }}</th>
              <th>{{ t('payroll.period_detail.col_gross') }}</th>
              <th>{{ t('payroll.period_detail.col_pph21') }}</th>
              <th>{{ t('payroll.period_detail.col_bpjs') }}</th>
              <th>{{ t('payroll.period_detail.col_thp') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="slips.length === 0">
              <td colspan="8" class="text-center py-8 text-gray-400">
                {{ t('payroll.period_detail.no_slips') }}
              </td>
            </tr>
            <tr v-for="s in slips" :key="s.id">
              <td class="font-mono text-xs">{{ s.slip_number }}</td>
              <td>{{ s.employee_number }}</td>
              <td>{{ s.employee_name }}</td>
              <td>{{ fmtCurrency(s.gross_earning) }}</td>
              <td>{{ fmtCurrency(s.pph21_amount) }}</td>
              <td>
                {{
                  fmtCurrency(
                    Number(s.bpjs_kes_employee) +
                      Number(s.bpjs_jht_employee) +
                      Number(s.bpjs_jp_employee)
                  )
                }}
              </td>
              <td class="font-semibold">{{ fmtCurrency(s.take_home_pay) }}</td>
              <td>
                <button
                  type="button"
                  class="text-primary-600 hover:underline text-sm"
                  @click="openSlipDetail(s.id)"
                >
                  {{ t('payroll.period_detail.detail') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Detail slip tetap di halaman payroll agar checker tidak berpindah konteks. -->
    <div
      v-if="showSlipDetailModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      @click.self="closeSlipDetail"
    >
      <div class="bg-white rounded-lg shadow-xl w-full max-w-[96vw] max-h-[92vh] overflow-y-auto">
        <div class="sticky top-0 z-10 bg-white px-6 py-4 border-b flex justify-between gap-4">
          <div>
            <h2 class="font-semibold text-lg">{{ t('payroll.slip_detail.detail_title') }}</h2>
            <p v-if="selectedSlip" class="text-sm text-gray-500">
              {{ selectedSlip.slip_number }} · {{ selectedSlip.employee_name }} ({{
                selectedSlip.employee_number
              }})
            </p>
          </div>
          <button type="button" class="text-gray-500" @click="closeSlipDetail">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div v-if="slipDetailLoading" class="p-12 text-center text-gray-400">
          {{ t('payroll.slip_detail.loading') }}
        </div>
        <div v-else-if="selectedSlip" class="p-6 space-y-6">
          <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="rounded border bg-gray-50 p-3">
              <div class="text-xs text-gray-500">{{ t('payroll.slip_detail.basic_salary') }}</div>
              <div class="font-semibold">{{ fmtCurrency(selectedSlip.basic_salary) }}</div>
            </div>
            <div class="rounded border bg-blue-50 p-3">
              <div class="text-xs text-blue-700">{{ t('payroll.period_detail.total_gross') }}</div>
              <div class="font-semibold text-blue-900">
                {{ fmtCurrency(selectedSlip.gross_earning) }}
              </div>
            </div>
            <div class="rounded border bg-amber-50 p-3">
              <div class="text-xs text-amber-700">
                {{ t('payroll.slip_detail.total_deductions') }}
              </div>
              <div class="font-semibold text-amber-900">
                {{ fmtCurrency(selectedSlip.total_deduction) }}
              </div>
            </div>
            <div class="rounded border bg-emerald-50 p-3">
              <div class="text-xs text-emerald-700">{{ t('payroll.slip_detail.thp') }}</div>
              <div class="font-bold text-emerald-900">
                {{ fmtCurrency(selectedSlip.take_home_pay) }}
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <section class="border rounded-lg p-4">
              <h3 class="font-semibold mb-3">{{ t('payroll.slip_detail.employee_data') }}</h3>
              <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <div>
                  <dt class="text-gray-500">NIP</dt>
                  <dd>{{ selectedSlip.employee_number }}</dd>
                </div>
                <div>
                  <dt class="text-gray-500">{{ t('payroll.period_detail.col_name') }}</dt>
                  <dd>{{ selectedSlip.employee_name }}</dd>
                </div>
                <div>
                  <dt class="text-gray-500">{{ t('payroll.slip_detail.department') }}</dt>
                  <dd>{{ selectedSlip.department_name || '-' }}</dd>
                </div>
                <div>
                  <dt class="text-gray-500">{{ t('payroll.slip_detail.position') }}</dt>
                  <dd>{{ selectedSlip.position_name || '-' }}</dd>
                </div>
                <div>
                  <dt class="text-gray-500">{{ t('payroll.slip_detail.ptkp') }}</dt>
                  <dd>{{ selectedSlip.ptkp_status || '-' }}</dd>
                </div>
                <div>
                  <dt class="text-gray-500">{{ t('payroll.slip_detail.tax_method') }}</dt>
                  <dd>
                    {{ selectedSlip.pph21_method || '-' }} / {{ selectedSlip.ter_category || '-' }}
                  </dd>
                </div>
              </dl>
            </section>
            <section class="border rounded-lg p-4">
              <h3 class="font-semibold mb-3">{{ t('payroll.slip_detail.attendance_tax') }}</h3>
              <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <div>
                  <dt class="text-gray-500">{{ t('payroll.slip_detail.work_days') }}</dt>
                  <dd>{{ selectedSlip.work_days }}</dd>
                </div>
                <div>
                  <dt class="text-gray-500">{{ t('payroll.slip_detail.present') }}</dt>
                  <dd>{{ selectedSlip.present_days }}</dd>
                </div>
                <div>
                  <dt class="text-gray-500">{{ t('payroll.slip_detail.late') }}</dt>
                  <dd>
                    {{ t('payroll.slip_detail.late_val', { minutes: selectedSlip.late_minutes }) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-gray-500">{{ t('payroll.slip_detail.overtime') }}</dt>
                  <dd>
                    {{
                      t('payroll.slip_detail.overtime_val', { hours: selectedSlip.overtime_hours })
                    }}
                  </dd>
                </div>
                <div>
                  <dt class="text-gray-500">TER</dt>
                  <dd>{{ Number(selectedSlip.ter_rate || 0) }}%</dd>
                </div>
                <div>
                  <dt class="text-gray-500">PPh 21</dt>
                  <dd>{{ fmtCurrency(selectedSlip.pph21_amount) }}</dd>
                </div>
              </dl>
            </section>
          </div>

          <section class="border rounded-lg overflow-hidden">
            <h3 class="font-semibold px-4 py-3 bg-gray-50 border-b">
              {{ t('payroll.slip_detail.bpjs_breakdown') }}
            </h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 p-4 text-sm">
              <div
                v-for="item in [
                  ['BPJS Kesehatan', 'bpjs_kes'],
                  ['BPJS JHT', 'bpjs_jht'],
                  ['BPJS JP', 'bpjs_jp'],
                  ['BPJS JKK', 'bpjs_jkk'],
                  ['BPJS JKM', 'bpjs_jkm'],
                ]"
                :key="item[1]"
                class="rounded bg-gray-50 p-3"
              >
                <div class="font-medium">{{ item[0] }}</div>
                <div class="text-xs text-gray-500 mt-1">
                  {{ t('payroll.slip_detail.employee_share') }}:
                  {{ fmtCurrency(selectedSlip[`${item[1]}_employee`] || 0) }}
                </div>
                <div class="text-xs text-gray-500">
                  {{ t('payroll.slip_detail.company_share') }}:
                  {{ fmtCurrency(selectedSlip[`${item[1]}_company`] || 0) }}
                </div>
              </div>
            </div>
          </section>

          <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <section class="border rounded-lg overflow-hidden">
              <h3 class="font-semibold px-4 py-3 bg-emerald-50 border-b">
                {{ t('payroll.slip_detail.earnings') }}
              </h3>
              <table class="table w-full">
                <tbody class="divide-y">
                  <tr v-for="item in slipEarnings" :key="item.id">
                    <td>
                      <div class="font-medium">{{ item.name }}</div>
                      <div class="text-xs text-gray-500">{{ item.code }} · {{ item.category }}</div>
                    </td>
                    <td class="text-right">{{ fmtCurrency(item.amount) }}</td>
                  </tr>
                  <tr v-if="slipEarnings.length === 0">
                    <td class="text-gray-400">-</td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr class="font-semibold bg-gray-50">
                    <td>{{ t('payroll.slip_detail.total_earnings') }}</td>
                    <td class="text-right">{{ fmtCurrency(selectedSlip.total_earning) }}</td>
                  </tr>
                </tfoot>
              </table>
            </section>
            <section class="border rounded-lg overflow-hidden">
              <h3 class="font-semibold px-4 py-3 bg-red-50 border-b">
                {{ t('payroll.slip_detail.deductions') }}
              </h3>
              <table class="table w-full">
                <tbody class="divide-y">
                  <tr v-for="item in slipDeductions" :key="item.id">
                    <td>
                      <div class="font-medium">{{ item.name }}</div>
                      <div class="text-xs text-gray-500">{{ item.code }} · {{ item.category }}</div>
                    </td>
                    <td class="text-right">{{ fmtCurrency(item.amount) }}</td>
                  </tr>
                  <tr v-if="slipDeductions.length === 0">
                    <td class="text-gray-400">-</td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr class="font-semibold bg-gray-50">
                    <td>{{ t('payroll.slip_detail.total_deductions') }}</td>
                    <td class="text-right">{{ fmtCurrency(selectedSlip.total_deduction) }}</td>
                  </tr>
                </tfoot>
              </table>
            </section>
          </div>
        </div>

        <div class="sticky bottom-0 bg-white px-6 py-3 border-t flex justify-end">
          <button type="button" class="btn-secondary" @click="closeSlipDetail">
            {{ t('payroll.period_detail.close') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Approval pada halaman detail tetap mengikuti chain dan validasi ganda. -->
    <div
      v-if="showApprovalValidationModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      @click.self="closeApprovalValidation"
    >
      <div class="w-full max-w-xl rounded-xl bg-white shadow-xl">
        <div class="flex items-start justify-between border-b px-6 py-4">
          <div>
            <h2 class="text-lg font-semibold text-gray-900">
              {{ t('payroll.period_list.double_validation_title') }}
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              {{ period.code }} · {{ period.approval_action?.current_step_name }}
            </p>
          </div>
          <button
            class="rounded p-1 text-gray-400 hover:bg-gray-100"
            @click="closeApprovalValidation"
          >
            <XMarkIcon class="h-5 w-5" />
          </button>
        </div>
        <div class="space-y-4 p-6">
          <label
            class="flex items-start gap-3 rounded-lg border bg-emerald-50 p-4 text-sm text-emerald-900"
          >
            <input v-model="approvalReviewConfirmed" type="checkbox" class="mt-1" />
            <span>{{ t('payroll.period_list.review_confirmation') }}</span>
          </label>
          <div>
            <label class="block text-sm font-medium text-gray-800">
              {{ t('payroll.period_list.type_period_code', { code: period.code }) }}
            </label>
            <input
              v-model="approvalConfirmationCode"
              class="input mt-2 w-full"
              :placeholder="period.code"
              autocomplete="off"
            />
          </div>
        </div>
        <div class="flex justify-end gap-2 border-t px-6 py-4">
          <button class="btn-secondary" @click="closeApprovalValidation">
            {{ t('payroll.period_list.approval_close') }}
          </button>
          <button
            class="btn-success"
            :disabled="!canConfirmApproval || approvalSubmitting"
            @click="approvePayroll"
          >
            {{
              approvalSubmitting
                ? t('payroll.period_list.approval_processing')
                : t('payroll.period_list.approval_confirm_button')
            }}
          </button>
        </div>
      </div>
    </div>

    <!-- Export dialog -->
    <div
      v-if="showExportDialog"
      class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
    >
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold flex items-center gap-2">
            <ArrowDownTrayIcon class="w-5 h-5" /> {{ t('payroll.period_detail.export_title') }}
            {{ period.name }}
          </h2>
          <button class="text-gray-500" @click="showExportDialog = false">&times;</button>
        </div>

        <div v-if="exportLoading" class="p-12 text-center text-gray-400">
          {{ t('payroll.period_detail.export_summary') }}
        </div>

        <div v-else-if="exportSummary" class="p-6 space-y-5">
          <!-- Summary -->
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-blue-50 rounded p-3 border border-blue-100">
              <div class="text-xs text-blue-700">{{ t('payroll.period_detail.employees') }}</div>
              <div class="text-xl font-bold text-blue-900">{{ exportSummary.employees }}</div>
            </div>
            <div class="bg-emerald-50 rounded p-3 border border-emerald-100">
              <div class="text-xs text-emerald-700">{{ t('payroll.period_detail.total_thp') }}</div>
              <div class="text-xl font-bold text-emerald-900">
                {{ fmtCurrency(exportSummary.total_net) }}
              </div>
            </div>
            <div class="bg-amber-50 rounded p-3 border border-amber-100">
              <div class="text-xs text-amber-700">{{ t('payroll.period_detail.total_pph21') }}</div>
              <div class="text-xl font-bold text-amber-900">
                {{ fmtCurrency(exportSummary.total_pph21) }}
              </div>
            </div>
            <div class="bg-purple-50 rounded p-3 border border-purple-100">
              <div class="text-xs text-purple-700">{{ t('payroll.period_detail.bank_mix') }}</div>
              <div class="text-xs font-medium text-purple-900">
                <span
                  v-for="(n, b) in exportSummary.bank_breakdown"
                  :key="b"
                  class="inline-block mr-2"
                >
                  {{ b }}: {{ n }}
                </span>
                <span v-if="Object.keys(exportSummary.bank_breakdown).length === 0">-</span>
              </div>
            </div>
          </div>

          <!-- Warnings missing data -->
          <div
            v-if="
              exportSummary.missing.bank_account.length > 0 ||
              exportSummary.missing.npwp.length > 0 ||
              exportSummary.missing.bpjs_kes.length > 0 ||
              exportSummary.missing.bpjs_tk.length > 0
            "
            class="border border-amber-300 bg-amber-50 rounded p-3 text-sm"
          >
            <div class="flex items-center gap-2 text-amber-800 font-medium mb-2">
              <ExclamationTriangleIcon class="w-5 h-5" />
              {{ t('payroll.period_detail.missing_data_title') }}
            </div>
            <ul class="text-amber-900 text-xs space-y-1">
              <li v-if="exportSummary.missing.bank_account.length > 0">
                {{
                  t('payroll.period_detail.missing_bank', {
                    count: exportSummary.missing.bank_account.length,
                  })
                }}
              </li>
              <li v-if="exportSummary.missing.npwp.length > 0">
                {{
                  t('payroll.period_detail.missing_npwp', {
                    count: exportSummary.missing.npwp.length,
                  })
                }}
              </li>
              <li v-if="exportSummary.missing.bpjs_kes.length > 0">
                {{
                  t('payroll.period_detail.missing_bpjs_kes', {
                    count: exportSummary.missing.bpjs_kes.length,
                  })
                }}
              </li>
              <li v-if="exportSummary.missing.bpjs_tk.length > 0">
                {{
                  t('payroll.period_detail.missing_bpjs_tk', {
                    count: exportSummary.missing.bpjs_tk.length,
                  })
                }}
              </li>
            </ul>
          </div>

          <!-- Bank transfer -->
          <div class="border border-gray-200 rounded">
            <div
              class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium flex items-center gap-2"
            >
              <BanknotesIcon class="w-5 h-5 text-emerald-600" />
              {{ t('payroll.period_detail.bank_transfer_batch') }}
            </div>
            <div class="p-4 grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
              <div class="md:col-span-2">
                <label class="label">{{ t('payroll.period_detail.choose_bank') }}</label>
                <select v-model="selectedBank" class="input">
                  <option
                    v-for="(label, code) in exportSummary.supported_banks"
                    :key="code"
                    :value="code"
                  >
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
            <div
              class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium flex items-center gap-2"
            >
              <DocumentTextIcon class="w-5 h-5 text-amber-600" />
              {{ t('payroll.period_detail.espt_pph21') }}
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
            <div
              class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium flex items-center gap-2"
            >
              <ShieldCheckIcon class="w-5 h-5 text-blue-600" />
              {{ t('payroll.period_detail.bpjs') }}
            </div>
            <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
              <button class="btn-secondary" @click="downloadBpjs('TK')">
                <ArrowDownTrayIcon class="w-4 h-4" /> {{ t('payroll.period_detail.sipp_bpjs_tk') }}
              </button>
              <button class="btn-secondary" @click="downloadBpjs('KES')">
                <ArrowDownTrayIcon class="w-4 h-4" />
                {{ t('payroll.period_detail.edabu_bpjs_kes') }}
              </button>
            </div>
          </div>

          <p class="text-xs text-gray-500">
            {{ t('payroll.period_detail.csv_note') }}
          </p>
        </div>

        <div class="px-6 py-3 border-t border-gray-200 flex justify-end">
          <button class="btn-secondary" @click="showExportDialog = false">
            {{ t('payroll.period_detail.close') }}
          </button>
        </div>
      </div>
    </div>
  </div>
  <div v-else class="text-center py-12 text-gray-400">
    {{ t('payroll.period_detail.loading_summary') }}
  </div>
</template>
