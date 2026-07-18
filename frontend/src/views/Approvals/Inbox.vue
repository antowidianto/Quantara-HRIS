<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import {
  InboxArrowDownIcon, CheckCircleIcon, XCircleIcon, ClockIcon,
  ClipboardDocumentCheckIcon,
} from '@heroicons/vue/24/outline'

/**
 * Inbox approval — daftar request yang menunggu keputusan user saat ini.
 *
 * Endpoint: GET /api/v1/approval-requests/inbox?module=...
 *           POST /api/v1/approval-requests/{id}/approve  { note }
 *           POST /api/v1/approval-requests/{id}/reject   { note }
 *
 * Dirancang ringkas: list di kiri, detail + form keputusan di kanan.
 */

const toast = useToast()
const route = useRoute()
const { t } = useI18n()

const moduleOptions = computed(() => [
  { value: '', label: t('approval_inbox.all_modules') },
  { value: 'LEAVE', label: t('delegation.modules.leave') },
  { value: 'OVERTIME', label: t('delegation.modules.overtime') },
  { value: 'ATTENDANCE_CHANGE', label: t('delegation.modules.attendance_change') },
  { value: 'WFH', label: 'Work From Home' },
  { value: 'REIMBURSEMENT', label: t('delegation.modules.reimbursement') },
  { value: 'LOAN', label: t('delegation.modules.loan') },
  { value: 'BUSINESS_TRIP', label: 'Perjalanan Dinas / SPPD' },
  { value: 'BONUS', label: 'Bonus' },
  { value: 'PAYROLL_ADJUSTMENT', label: 'Koreksi Payroll' },
  { value: 'COMPENSATION_REVIEW', label: 'Perubahan Kompensasi' },
  { value: 'TRAINING_REGISTRATION', label: 'Registrasi Training' },
  { value: 'TIMESHEET', label: 'Timesheet' },
  { value: 'CANDIDATE_OFFER', label: 'Candidate Offer' },
  { value: 'GENERATED_CONTRACT', label: 'Kontrak Kerja' },
  { value: 'ASSET_TRANSACTION', label: 'Transaksi Asset' },
  { value: 'HIRE_CONVERSION', label: 'Konversi Kandidat' },
  { value: 'PERFORMANCE_GOAL', label: 'Performance Goal' },
  { value: 'IDP_ACTIVATION', label: 'Aktivasi IDP' },
  { value: 'EMPLOYEE_SENSITIVE_CHANGE', label: 'Perubahan Data Sensitif Employee' },
  { value: 'MASTER_RATE_CHANGE', label: 'Perubahan Master Rate Payroll' },
  { value: 'PRIVILEGED_ACTION', label: 'Aksi Administratif Berisiko' },
  { value: 'GENERIC', label: 'Generic' },
])

const moduleLabel = computed(() => ({
  LEAVE: t('delegation.modules.leave'),
  OVERTIME: t('delegation.modules.overtime'),
  ATTENDANCE_CHANGE: t('delegation.modules.attendance_change'),
  WFH: 'Work From Home',
  REIMBURSEMENT: t('delegation.modules.reimbursement'),
  LOAN: t('delegation.modules.loan'),
  BUSINESS_TRIP: 'Perjalanan Dinas / SPPD',
  BONUS: 'Bonus',
  PAYROLL_ADJUSTMENT: 'Koreksi Payroll',
  COMPENSATION_REVIEW: 'Perubahan Kompensasi',
  TRAINING_REGISTRATION: 'Registrasi Training',
  TIMESHEET: 'Timesheet',
  CANDIDATE_OFFER: 'Candidate Offer',
  GENERATED_CONTRACT: 'Kontrak Kerja',
  ASSET_TRANSACTION: 'Transaksi Asset',
  HIRE_CONVERSION: 'Konversi Kandidat',
  PERFORMANCE_GOAL: 'Performance Goal',
  IDP_ACTIVATION: 'Aktivasi IDP',
  EMPLOYEE_SENSITIVE_CHANGE: 'Perubahan Data Sensitif Employee',
  MASTER_RATE_CHANGE: 'Perubahan Master Rate Payroll',
  PRIVILEGED_ACTION: 'Aksi Administratif Berisiko',
  GENERIC: 'Generic',
}))

const items = ref([])
const loading = ref(false)
const filterModule = ref('')
const activeTab = ref('inbox')

const selected = ref(null)
const detail = ref(null)
const detailLoading = ref(false)
const note = ref('')
const submitting = ref(false)

// Sprint 5-6 item O — bulk action di Approval Inbox.
// Set ID yang dicentang (Set untuk O(1) toggle, di-spread ke array saat dipakai).
const bulkSelected = ref(new Set())
const bulkProcessing = ref(false)
const bulkRejectOpen = ref(false)
const bulkRejectNote = ref('')
const bulkRejectError = ref('')

const bulkSelectableIds = computed(() =>
  activeTab.value === 'inbox'
    ? items.value
      .filter(item => ! String(item.id).startsWith('wfh-'))
      .map(item => item.id)
    : []
)
const allSelected = computed(() =>
  bulkSelectableIds.value.length > 0
  && bulkSelected.value.size === bulkSelectableIds.value.length
)
const someSelected = computed(() =>
  bulkSelected.value.size > 0 && bulkSelected.value.size < bulkSelectableIds.value.length
)
function toggleAll() {
  if (allSelected.value) {
    bulkSelected.value = new Set()
  } else {
    bulkSelected.value = new Set(bulkSelectableIds.value)
  }
}
function toggleOne(id) {
  if (String(id).startsWith('wfh-')) {
    toast.info('WFH diproses satu per satu melalui panel detail')
    return
  }
  // Trigger reactivity dengan rebuild Set
  const next = new Set(bulkSelected.value)
  next.has(id) ? next.delete(id) : next.add(id)
  bulkSelected.value = next
}

async function fetchData() {
  loading.value = true
  try {
    const params = {}
    if (filterModule.value && filterModule.value !== 'WFH') params.module = filterModule.value

    const includeGeneric = filterModule.value !== 'WFH'
    const includeWfh = ! filterModule.value || filterModule.value === 'WFH'
    const isHistory = activeTab.value === 'history'
    const [approvalRes, wfhRes] = await Promise.all([
      includeGeneric
        ? api.get(isHistory ? '/approval-requests/history' : '/approval-requests/inbox', { params })
        : Promise.resolve({ data: { data: [] } }),
      includeWfh
        ? api.get('/wfh-requests', {
          params: {
            scope: isHistory ? 'processed' : 'inbox',
            ...(! isHistory ? { status: 'PENDING' } : {}),
            per_page: 100,
          },
        })
        : Promise.resolve({ data: { data: [] } }),
    ])

    const approvalItems = approvalRes.data.data || []
    const wfhItems = (wfhRes.data.data || []).map(row => ({
      ...row,
      id: `wfh-${row.id}`,
      source_id: row.id,
      module: 'WFH',
      subject: `Pengajuan WFH ${row.work_date}`,
      current_step_order: 1,
      decided_step_order: 1,
      step_name: 'Persetujuan Atasan',
      my_decision: row.status,
      decision_note: row.rejection_note || null,
      decision_at: row.approved_at || row.updated_at,
    }))
    items.value = [...approvalItems, ...wfhItems]
      .sort((a, b) => String(b.decision_at || b.created_at || '').localeCompare(String(a.decision_at || a.created_at || '')))

    const requestedId = route.query.request_id
      ? Number(route.query.request_id)
      : route.query.wfh_request_id
        ? `wfh-${route.query.wfh_request_id}`
        : null
    const requestedItem = requestedId !== null
      ? items.value.find(item => String(item.id) === String(requestedId))
      : null

    if (requestedItem && String(selected.value) !== String(requestedItem.id)) {
      selectItem(requestedItem)
    } else if (! selected.value && items.value.length > 0) {
      selectItem(items.value[0])
    } else if (selected.value && ! items.value.find(i => i.id === selected.value)) {
      // Selected hilang dari list (mis. baru saja diapprove); buka yang pertama.
      selected.value = null
      detail.value = null
      if (items.value.length > 0) selectItem(items.value[0])
    }
  } catch (e) {
    toast.error(e.response?.data?.message || t('approval_inbox.load_failed'))
  } finally {
    loading.value = false
  }
}

async function selectItem(item) {
  selected.value = item.id
  detail.value = null
  note.value = ''
  detailLoading.value = true
  try {
    if (item.module === 'WFH') {
      const res = await api.get(`/wfh-requests/${item.source_id}`)
      const row = res.data.data
      detail.value = {
        ...row,
        id: item.id,
        my_decision: item.my_decision,
        decision_note: item.decision_note,
        decision_at: item.decision_at,
        source_id: item.source_id,
        module: 'WFH',
        subject: `Pengajuan WFH ${row.work_date}`,
        request_detail: { work_date: row.work_date, status: row.status },
        submitter_note: row.reason,
        attachment: null,
        timeline: [{
          step_order: 1,
          step_name: 'Persetujuan Atasan',
          approver_type: 'SUPERVISOR',
          status: row.status === 'PENDING' ? 'WAITING_APPROVAL' : row.status,
          approver_user_name: row.approver_user_name || null,
          decided_at: row.approved_at || null,
          note: row.rejection_note || null,
          is_current: row.status === 'PENDING',
        }],
      }
    } else {
      const res = await api.get(`/approval-requests/${item.id}`)
      detail.value = {
        ...res.data.data,
        my_decision: item.my_decision,
        decision_note: item.decision_note,
        decision_at: item.decision_at,
      }
    }
  } catch (e) {
    toast.error(e.response?.data?.message || t('approval_inbox.load_failed'))
  } finally {
    detailLoading.value = false
  }
}

async function decide(action) {
  if (! selected.value) return
  if (action === 'reject' && ! note.value.trim()) {
    toast.warning(t('approval_inbox.note_required_reject'))
    return
  }
  submitting.value = true
  try {
    if (detail.value?.module === 'WFH') {
      await api.post(`/wfh-requests/${detail.value.source_id}/${action}`, { note: note.value })
    } else {
      await api.post(`/approval-requests/${selected.value}/${action}`, { note: note.value })
    }
    toast.success(action === 'approve' ? t('approval_inbox.approved') : t('approval_inbox.rejected'))
    note.value = ''
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('approval_inbox.process_failed'))
  } finally {
    submitting.value = false
  }
}

/**
 * Bulk approve/reject (Sprint 7 / H1.1).
 *
 * Pakai endpoint server `/approval-requests/bulk-approve|bulk-reject` yang
 * memproses semua ID dalam 1 request. Server menjamin per-item gagal tidak
 * menggagalkan yang lain dan mengembalikan summary { success, failed }.
 *
 * Batas server: max 50 ID per request. UI batasi sama supaya tidak ditolak.
 */
const BULK_MAX = 50

function openBulkReject() {
  if (bulkSelected.value.size === 0) return
  bulkRejectNote.value = ''
  bulkRejectError.value = ''
  bulkRejectOpen.value = true
}

function closeBulkReject() {
  if (bulkProcessing.value) return
  bulkRejectOpen.value = false
  bulkRejectNote.value = ''
  bulkRejectError.value = ''
}

async function confirmBulkReject() {
  const reason = bulkRejectNote.value.trim()
  if (reason.length < 5) {
    bulkRejectError.value = 'Alasan penolakan minimal 5 karakter.'
    return
  }
  bulkRejectError.value = ''
  await bulkDecide('reject', reason)
}

async function bulkDecide(action, confirmedNote = '') {
  if (bulkSelected.value.size === 0) return
  if (bulkSelected.value.size > BULK_MAX) {
    toast.warning(t('approval_inbox.bulk_max_warning', { max: BULK_MAX, count: bulkSelected.value.size }))
    return
  }
  const bulkNote = confirmedNote.trim()
  if (action === 'reject' && bulkNote === '') {
    openBulkReject()
    return
  }
  if (action !== 'reject'
    && ! window.confirm(t('approval_inbox.bulk_approve_confirm', { count: bulkSelected.value.size }))) return
  bulkProcessing.value = true
  const ids = Array.from(bulkSelected.value)
  try {
    const endpoint = action === 'approve' ? '/approval-requests/bulk-approve' : '/approval-requests/bulk-reject'
    const res = await api.post(endpoint, { approval_ids: ids, note: bulkNote })
    const summary = res.data?.data?.summary || { success: 0, failed: 0 }
    const failed = res.data?.data?.failed || []
    if (summary.failed === 0) {
      toast.success(t('approval_inbox.bulk_success', {
        count: summary.success,
        action: action === 'approve' ? t('approval_inbox.action_approved') : t('approval_inbox.action_rejected')
      }))
    } else if (summary.success > 0) {
      // Tampilkan reason gagal pertama supaya user dapat petunjuk konkrit.
      const firstReason = failed[0]?.reason ? ` (contoh: ${failed[0].reason})` : ''
      toast.warning(t('approval_inbox.bulk_partial', {
        success: summary.success,
        failed: summary.failed,
        reason: firstReason
      }))
    } else {
      const firstReason = failed[0]?.reason ? `: ${failed[0].reason}` : ''
      toast.error(t('approval_inbox.bulk_all_failed', {
        failed: summary.failed,
        reason: firstReason
      }))
    }
  } catch (e) {
    toast.error(e.response?.data?.message || t('approval_inbox.bulk_failed_process'))
  } finally {
    bulkProcessing.value = false
    if (action === 'reject') closeBulkReject()
    bulkSelected.value = new Set()
    fetchData()
  }
}

onMounted(() => fetchData())
watch([filterModule, activeTab], () => {
  selected.value = null
  detail.value = null
  bulkSelected.value = new Set()
  fetchData()
})

function fmtDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
}

function humanizeKey(key) {
  return String(key).replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase())
}

const attachmentIsImage = computed(() =>
  /\.(jpe?g|png|webp|gif)$/i.test(detail.value?.attachment?.name || '')
)

const attachmentIsPdf = computed(() =>
  /\.pdf$/i.test(detail.value?.attachment?.name || '')
)

function formatMetaValue(value) {
  if (value === null || value === undefined || value === '') return '-'
  if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

function initials(name) {
  if (! name) return '?'
  return name.split(' ').filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase()
}

function statusLabel(status) {
  return t(`approval_inbox.status_${String(status || '').toLowerCase()}`)
}

const decisionBadge = {
  PENDING:          { class: 'bg-amber-50 text-amber-700', label: 'Menunggu', icon: ClockIcon },
  WAITING_APPROVAL: { class: 'bg-amber-50 text-amber-700', label: 'Menunggu', icon: ClockIcon },
  APPROVED:         { class: 'bg-green-50 text-green-700', label: 'Disetujui', icon: CheckCircleIcon },
  REJECTED:         { class: 'bg-red-50 text-red-700', label: 'Ditolak', icon: XCircleIcon },
  SKIPPED:          { class: 'bg-gray-100 text-gray-600', label: 'Skip', icon: ClockIcon },
}

/**
 * Approver_role bisa berisi `DELEGATE_OF_<userId>` saat keputusan dibuat
 * mewakili orang lain (lihat ApprovalEngine::decide). UI menampilkannya
 * sebagai badge "Delegasi" supaya audit trail terbaca jelas.
 */
function isDelegateRole(role) {
  return typeof role === 'string' && role.startsWith('DELEGATE_OF_')
}
</script>

<template>
  <div class="approval-inbox space-y-5">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-gray-900">{{ t('approval_inbox.title') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ t('approval_inbox.subtitle') }}</p>
      </div>
      <select v-model="filterModule" class="input w-full bg-white sm:w-64" :aria-label="t('approval_inbox.filter_module')">
        <option v-for="m in moduleOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
      </select>
    </div>

    <div class="border-b border-gray-200">
      <nav class="-mb-px flex gap-6" aria-label="Tab approval">
        <button type="button"
          class="inline-flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-semibold transition"
          :class="activeTab === 'inbox'
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
          :aria-current="activeTab === 'inbox' ? 'page' : undefined"
          @click="activeTab = 'inbox'">
          <InboxArrowDownIcon class="h-5 w-5" />
          Menunggu Persetujuan
        </button>
        <button type="button"
          class="inline-flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-semibold transition"
          :class="activeTab === 'history'
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
          :aria-current="activeTab === 'history' ? 'page' : undefined"
          @click="activeTab = 'history'">
          <ClockIcon class="h-5 w-5" />
          Riwayat Diproses
        </button>
      </nav>
    </div>

    <div class="grid min-h-[640px] grid-cols-1 gap-5 xl:grid-cols-[380px_minmax(0,1fr)]">
      <!-- LIST -->
      <div class="approval-list card overflow-hidden border-gray-200 shadow-sm xl:col-span-1">
        <!-- Header dengan bulk action bar (Sprint 5-6 item O) -->
        <div class="card-header block bg-white">
          <div v-if="bulkSelected.size === 0" class="flex items-center gap-2 text-sm text-gray-500">
            <input v-if="activeTab === 'inbox'" type="checkbox" :checked="allSelected" :indeterminate.prop="someSelected"
                   @change="toggleAll" class="rounded border-gray-300"
                   :disabled="items.length === 0" :title="t('common.select_all')">
            <div>
              <p class="font-semibold text-gray-900">
                {{ activeTab === 'inbox' ? t('approval_inbox.request_list') : 'Riwayat Approval' }}
              </p>
              <p class="text-xs text-gray-500">
                {{ activeTab === 'inbox'
                  ? t('approval_inbox.pending_requests', { count: items.length })
                  : `${items.length} pengajuan telah Anda proses` }}
              </p>
            </div>
          </div>
          <div v-else class="-mx-4 -my-3 w-[calc(100%+2rem)] space-y-3 border-b border-blue-100 bg-blue-50/80 px-4 py-3.5">
            <div class="flex min-w-0 items-center gap-3">
              <input type="checkbox" :checked="allSelected" :indeterminate.prop="someSelected"
                @change="toggleAll"
                class="h-4 w-4 shrink-0 rounded border-blue-300 text-blue-600 focus:ring-blue-500">
              <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-semibold text-slate-800">
                  {{ bulkSelected.size }} pengajuan dipilih
                </div>
                <div class="text-xs text-slate-500">Pilih tindakan untuk pengajuan terpilih</div>
              </div>
              <button type="button"
                class="shrink-0 rounded-md px-2 py-1 text-xs font-semibold text-blue-600 transition hover:bg-blue-100 hover:text-blue-800"
                @click="bulkSelected = new Set()">
                Batal
              </button>
            </div>

            <div class="grid w-full grid-cols-2 gap-2">
              <button type="button"
                class="inline-flex min-h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-emerald-600 bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:border-emerald-700 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                :disabled="bulkProcessing" @click="bulkDecide('approve')">
                <ClockIcon v-if="bulkProcessing" class="h-4 w-4 animate-spin" />
                <CheckCircleIcon v-else class="h-4 w-4" />
                <span>Setujui</span>
              </button>
              <button type="button"
                class="inline-flex min-h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-600 shadow-sm transition hover:border-red-300 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                :disabled="bulkProcessing" @click="openBulkReject">
                <ClockIcon v-if="bulkProcessing" class="h-4 w-4 animate-spin" />
                <XCircleIcon v-else class="h-4 w-4" />
                <span>Tolak</span>
              </button>
            </div>
          </div>
        </div>
        <div class="approval-list-body max-h-[70vh] overflow-y-auto bg-gray-50/60 p-2">
          <div v-if="loading" class="px-4 py-8 text-center text-sm text-gray-400">{{ t('common.loading') }}</div>
          <div v-else-if="items.length === 0" class="flex min-h-72 flex-col items-center justify-center px-6 text-center text-sm text-gray-400">
            <span class="mb-3 rounded-full bg-green-50 p-4"><CheckCircleIcon class="h-8 w-8 text-green-600" /></span>
            <strong class="text-gray-800">
              {{ activeTab === 'inbox' ? t('approval_inbox.no_pending') : 'Belum ada riwayat approval' }}
            </strong>
            <span class="mt-1">
              {{ activeTab === 'inbox' ? t('approval_inbox.no_pending_hint') : 'Approval yang Anda setujui atau tolak akan tampil di sini.' }}
            </span>
          </div>
          <div v-for="item in items" :key="item.id"
            class="approval-item mb-2 flex w-full cursor-pointer items-start gap-3 rounded-xl border border-gray-100 bg-white p-3.5 transition-all hover:border-primary-200 hover:shadow-sm"
            :class="{ 'is-active border-primary-300 ring-1 ring-primary-100 shadow-sm': selected === item.id }"
            @click="selectItem(item)">
            <input
              v-if="activeTab === 'inbox'"
              type="checkbox"
              :checked="bulkSelected.has(item.id)"
              :disabled="item.module === 'WFH'"
              @click.stop="toggleOne(item.id)"
              class="mt-1 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-40"
              :title="t('approval_inbox.checkbox_bulk_title')"
            >
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-50 text-xs font-bold text-primary-700">
              {{ initials(item.employee_name) }}
            </div>
            <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between mb-1">
              <span class="font-medium text-sm truncate">{{ item.subject }}</span>
              <span class="ml-2 shrink-0 rounded-md bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600">{{ moduleLabel[item.module] || item.module }}</span>
            </div>
            <div class="text-xs text-gray-500">
              {{ item.employee_name }} · Step {{ item.decided_step_order || item.current_step_order }} ({{ item.step_name }})
            </div>
            <div v-if="activeTab === 'history'" class="mt-2 flex flex-wrap items-center gap-2">
              <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                :class="item.my_decision === 'APPROVED'
                  ? 'bg-green-50 text-green-700'
                  : 'bg-red-50 text-red-700'">
                <component :is="item.my_decision === 'APPROVED' ? CheckCircleIcon : XCircleIcon" class="h-3.5 w-3.5" />
                {{ item.my_decision === 'APPROVED' ? 'Disetujui' : 'Ditolak' }}
              </span>
              <span class="text-xs text-gray-400">{{ fmtDate(item.decision_at) }}</span>
            </div>
            <div v-else class="text-xs text-gray-400 mt-1">{{ fmtDate(item.created_at) }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- DETAIL -->
      <div class="approval-detail card overflow-hidden border-gray-200 shadow-sm xl:col-span-1">
        <div v-if="detailLoading" class="space-y-4 p-6">
          <div class="h-20 animate-pulse rounded-xl bg-gray-100"></div>
          <div class="h-36 animate-pulse rounded-xl bg-gray-100"></div>
          <div class="h-28 animate-pulse rounded-xl bg-gray-100"></div>
        </div>

        <div v-else-if="! detail" class="flex min-h-[540px] flex-col items-center justify-center px-6 text-center text-sm text-gray-400">
          <span class="mb-4 rounded-full bg-primary-50 p-5"><InboxArrowDownIcon class="h-9 w-9 text-primary-600" /></span>
          <strong class="text-base text-gray-800">{{ t('approval_inbox.select_request') }}</strong>
          <span class="mt-1">{{ t('approval_inbox.select_left') }}</span>
        </div>

        <div v-else>
          <div class="card-header bg-gradient-to-r from-primary-50/80 to-white">
            <div class="flex flex-wrap gap-3 items-center justify-between">
              <div>
                <div class="mb-1 text-xs font-medium text-primary-700">{{ moduleLabel[detail.module] || detail.module }} · #{{ detail.id }}</div>
                <div class="text-xl font-bold text-gray-900">{{ detail.subject }}</div>
                <div class="text-sm text-gray-500">
                  {{ t('common.from') }}: <span class="font-medium">{{ detail.employee_name }}</span> ·
                  {{ t('common.created_at') }} {{ fmtDate(detail.created_at) }}
                </div>
              </div>
              <div>
                <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold"
                   :class="detail.status === 'PENDING' ? 'bg-amber-50 text-amber-700' : detail.status === 'APPROVED' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'">
                  <span class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ statusLabel(detail.status) }}
                </span>
              </div>
            </div>
          </div>

          <div class="card-body space-y-6">
            <!-- Detail pengajuan dari row domain, bukan hanya metadata workflow. -->
            <div v-if="detail.request_detail && Object.keys(detail.request_detail).length">
              <div class="mb-3 text-sm font-semibold text-gray-900">Detail Pengajuan</div>
              <div class="grid gap-px overflow-hidden rounded-xl border border-gray-200 bg-gray-200 sm:grid-cols-2">
                <div v-for="(value, key) in detail.request_detail" :key="key" class="bg-gray-50 px-4 py-3">
                  <div class="text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ humanizeKey(key) }}</div>
                  <div class="mt-1 break-words text-sm font-medium text-gray-800">{{ formatMetaValue(value) }}</div>
                </div>
              </div>
            </div>

            <!-- Fallback untuk modul yang belum punya source-detail mapping. -->
            <div v-else-if="detail.meta && Object.keys(detail.meta).length">
              <div class="mb-3 text-sm font-semibold text-gray-900">Detail Pengajuan</div>
              <div class="grid gap-px overflow-hidden rounded-xl border border-gray-200 bg-gray-200 sm:grid-cols-2">
                <div v-for="(value, key) in detail.meta" :key="key" class="bg-gray-50 px-4 py-3">
                  <div class="text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ humanizeKey(key) }}</div>
                  <div class="mt-1 break-words text-sm font-medium text-gray-800">{{ formatMetaValue(value) }}</div>
                </div>
              </div>
            </div>

            <!-- Notes/alasan asli dari submitter. -->
            <div v-if="detail.submitter_note" class="rounded-xl border border-blue-100 bg-blue-50 p-4">
              <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-blue-700">Catatan Submitter</div>
              <div class="whitespace-pre-wrap text-sm text-gray-800">{{ detail.submitter_note }}</div>
            </div>

            <!-- Lampiran dengan signed URL. -->
            <div v-if="detail.attachment" class="overflow-hidden rounded-xl border border-gray-200">
              <div class="flex items-center justify-between border-b bg-gray-50 px-4 py-3">
                <div>
                  <div class="text-sm font-semibold text-gray-900">Lampiran</div>
                  <div class="text-xs text-gray-500">{{ detail.attachment.name }}</div>
                </div>
                <a :href="detail.attachment.url" target="_blank" rel="noopener"
                  class="btn-secondary text-xs">Buka / Unduh</a>
              </div>
              <div class="bg-slate-100 p-3">
                <img v-if="attachmentIsImage" :src="detail.attachment.url"
                  class="mx-auto max-h-[480px] max-w-full object-contain" alt="Preview lampiran" />
                <iframe v-else-if="attachmentIsPdf" :src="detail.attachment.url"
                  class="h-[480px] w-full bg-white" title="Preview lampiran PDF"></iframe>
                <div v-else class="py-8 text-center text-sm text-gray-500">
                  Preview tidak tersedia. Gunakan tombol Buka / Unduh.
                </div>
              </div>
            </div>

            <!-- Seluruh tahap: selesai, aktif, dan tahap berikutnya. -->
            <div>
              <div class="mb-3 text-sm font-semibold text-gray-900">Tahapan Approval</div>
              <div class="space-y-2">
                <div v-for="d in detail.timeline || []" :key="`${d.step_order}-${d.step_name}`"
                  class="flex items-start gap-3 rounded-xl border p-4"
                  :class="d.is_current ? 'border-amber-200 bg-amber-50/50' : 'border-gray-200 bg-white'">
                  <component :is="decisionBadge[d.status]?.icon || ClockIcon" class="mt-0.5 h-5 w-5"
                    :class="d.status === 'APPROVED' ? 'text-green-500' : d.status === 'REJECTED' ? 'text-red-500' : 'text-amber-500'" />
                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                      <div class="text-sm font-medium">
                        Step {{ d.step_order }} — {{ d.step_name }}
                        <span v-if="d.approver_type" class="ml-1 text-xs text-gray-400">({{ d.approver_type }})</span>
                        <span v-if="d.is_current" class="ml-1 text-xs text-amber-700">(tahap aktif)</span>
                      </div>
                      <span :class="['rounded-full px-2 py-0.5 text-xs', decisionBadge[d.status]?.class]">
                        {{ decisionBadge[d.status]?.label || d.status }}
                      </span>
                    </div>
                    <div v-if="d.approver_user_name" class="mt-1 text-xs text-gray-500">
                      Oleh {{ d.approver_user_name }} · {{ fmtDate(d.decided_at) }}
                    </div>
                    <div v-if="d.note" class="mt-1 text-xs italic text-gray-600">"{{ d.note }}"</div>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="activeTab === 'history' && detail.my_decision"
              class="rounded-xl border p-4"
              :class="detail.my_decision === 'APPROVED'
                ? 'border-green-200 bg-green-50'
                : 'border-red-200 bg-red-50'">
              <div class="flex items-center gap-2 text-sm font-semibold"
                :class="detail.my_decision === 'APPROVED' ? 'text-green-800' : 'text-red-800'">
                <component :is="detail.my_decision === 'APPROVED' ? CheckCircleIcon : XCircleIcon" class="h-5 w-5" />
                Anda {{ detail.my_decision === 'APPROVED' ? 'menyetujui' : 'menolak' }} pengajuan ini
              </div>
              <div class="mt-1 text-xs text-gray-500">{{ fmtDate(detail.decision_at) }}</div>
              <div v-if="detail.decision_note" class="mt-3 whitespace-pre-wrap text-sm text-gray-700">
                Catatan: {{ detail.decision_note }}
              </div>
            </div>

            <!-- Form keputusan -->
            <div v-if="activeTab === 'inbox' && detail.status === 'PENDING'" class="rounded-xl border border-gray-200 bg-gray-50 p-5">
              <label class="label">{{ t('approval_inbox.label_note') }}</label>
              <p class="mb-3 text-xs text-gray-500">{{ t('approval_inbox.note_hint') }}</p>
              <textarea v-model="note" class="input bg-white" rows="3"
                :placeholder="t('approval_inbox.note_ph')"></textarea>
              <div class="mt-4 grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                <button type="button"
                  class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-red-200 bg-white px-4 py-2.5 text-sm font-semibold text-red-600 shadow-sm transition hover:border-red-300 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                  :disabled="submitting" @click="decide('reject')">
                  <ClockIcon v-if="submitting" class="h-4 w-4 animate-spin" />
                  <XCircleIcon v-else class="h-5 w-5" />
                  Tolak Pengajuan
                </button>
                <button type="button"
                  class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-emerald-600 bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:border-emerald-700 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                  :disabled="submitting" @click="decide('approve')">
                  <ClockIcon v-if="submitting" class="h-4 w-4 animate-spin" />
                  <CheckCircleIcon v-else class="h-5 w-5" />
                  Setujui Pengajuan
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal alasan bulk reject; menggantikan browser window.prompt(). -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0">
        <div v-if="bulkRejectOpen"
          class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-[2px]"
          @click.self="closeBulkReject">
          <div role="dialog" aria-modal="true" aria-labelledby="bulk-reject-title"
            class="w-full max-w-lg overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">

            <div class="flex items-start gap-3 border-b border-slate-100 px-6 py-5">
              <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-50">
                <XCircleIcon class="h-6 w-6 text-red-600" />
              </span>
              <div class="min-w-0">
                <h2 id="bulk-reject-title" class="text-lg font-bold text-slate-900">
                  Tolak {{ bulkSelected.size }} Pengajuan
                </h2>
                <p class="mt-1 text-sm leading-5 text-slate-500">
                  Alasan berikut akan tercatat pada seluruh pengajuan yang dipilih.
                </p>
              </div>
            </div>

            <div class="space-y-2 px-6 py-5">
              <label for="bulk-reject-note" class="block text-sm font-semibold text-slate-700">
                Alasan penolakan <span class="text-red-500">*</span>
              </label>
              <textarea id="bulk-reject-note" v-model="bulkRejectNote" rows="4" autofocus
                class="block w-full resize-none rounded-xl border bg-white px-3.5 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:ring-2"
                :class="bulkRejectError
                  ? 'border-red-300 focus:border-red-500 focus:ring-red-100'
                  : 'border-slate-300 focus:border-red-500 focus:ring-red-100'"
                placeholder="Jelaskan alasan pengajuan ditolak..."
                @input="bulkRejectError = ''"
                @keydown.ctrl.enter="confirmBulkReject"></textarea>
              <div class="flex items-start justify-between gap-3">
                <p class="text-xs" :class="bulkRejectError ? 'font-medium text-red-600' : 'text-slate-400'">
                  {{ bulkRejectError || 'Minimal 5 karakter · Ctrl+Enter untuk mengirim' }}
                </p>
                <span class="shrink-0 text-xs text-slate-400">{{ bulkRejectNote.trim().length }} karakter</span>
              </div>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-slate-100 bg-slate-50 px-6 py-4 sm:flex-row sm:justify-end">
              <button type="button"
                class="inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2 disabled:opacity-50"
                :disabled="bulkProcessing" @click="closeBulkReject">
                Batal
              </button>
              <button type="button"
                class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-red-600 bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:border-red-700 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                :disabled="bulkProcessing || bulkRejectNote.trim().length < 5"
                @click="confirmBulkReject">
                <ClockIcon v-if="bulkProcessing" class="h-4 w-4 animate-spin" />
                <XCircleIcon v-else class="h-4 w-4" />
                {{ bulkProcessing ? 'Memproses...' : `Tolak ${bulkSelected.size} Pengajuan` }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
