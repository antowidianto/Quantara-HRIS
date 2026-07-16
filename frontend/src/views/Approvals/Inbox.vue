<script setup>
import { ref, onMounted, watch, computed } from 'vue'
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
const { t } = useI18n()

const moduleOptions = computed(() => [
  { value: '', label: t('approval_inbox.all_modules') },
  { value: 'LEAVE', label: t('delegation.modules.leave') },
  { value: 'OVERTIME', label: t('delegation.modules.overtime') },
  { value: 'ATTENDANCE_CHANGE', label: t('delegation.modules.attendance_change') },
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
  { value: 'GENERIC', label: 'Generic' },
])

const moduleLabel = computed(() => ({
  LEAVE: t('delegation.modules.leave'),
  OVERTIME: t('delegation.modules.overtime'),
  ATTENDANCE_CHANGE: t('delegation.modules.attendance_change'),
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
  GENERIC: 'Generic',
}))

const items = ref([])
const loading = ref(false)
const filterModule = ref('')

const selected = ref(null)
const detail = ref(null)
const detailLoading = ref(false)
const note = ref('')
const submitting = ref(false)

// Sprint 5-6 item O — bulk action di Approval Inbox.
// Set ID yang dicentang (Set untuk O(1) toggle, di-spread ke array saat dipakai).
const bulkSelected = ref(new Set())
const bulkProcessing = ref(false)

const allSelected = computed(() =>
  items.value.length > 0 && bulkSelected.value.size === items.value.length
)
const someSelected = computed(() =>
  bulkSelected.value.size > 0 && bulkSelected.value.size < items.value.length
)
function toggleAll() {
  if (allSelected.value) {
    bulkSelected.value = new Set()
  } else {
    bulkSelected.value = new Set(items.value.map(i => i.id))
  }
}
function toggleOne(id) {
  // Trigger reactivity dengan rebuild Set
  const next = new Set(bulkSelected.value)
  next.has(id) ? next.delete(id) : next.add(id)
  bulkSelected.value = next
}

async function fetchData() {
  loading.value = true
  try {
    const params = {}
    if (filterModule.value) params.module = filterModule.value
    const res = await api.get('/approval-requests/inbox', { params })
    items.value = res.data.data || []
    // Otomatis pilih item pertama jika sebelumnya tidak ada selection.
    if (! selected.value && items.value.length > 0) {
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
    const res = await api.get(`/approval-requests/${item.id}`)
    detail.value = res.data.data
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
    await api.post(`/approval-requests/${selected.value}/${action}`, { note: note.value })
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

async function bulkDecide(action) {
  if (bulkSelected.value.size === 0) return
  if (bulkSelected.value.size > BULK_MAX) {
    toast.warning(t('approval_inbox.bulk_max_warning', { max: BULK_MAX, count: bulkSelected.value.size }))
    return
  }
  let bulkNote = ''
  if (action === 'reject') {
    const promptResult = window.prompt(t('approval_inbox.bulk_reject_prompt'), '')
    if (! promptResult || ! promptResult.trim()) {
      toast.warning(t('approval_inbox.bulk_reject_required'))
      return
    }
    bulkNote = promptResult.trim()
  } else {
    if (! window.confirm(t('approval_inbox.bulk_approve_confirm', { count: bulkSelected.value.size }))) return
  }
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
    bulkSelected.value = new Set()
    fetchData()
  }
}

onMounted(() => fetchData())
watch(filterModule, () => fetchData())

function fmtDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
}

function humanizeKey(key) {
  return String(key).replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase())
}

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
  PENDING:  { class: 'bg-amber-50 text-amber-700',   label: 'Menunggu', icon: ClockIcon },
  APPROVED: { class: 'bg-green-50 text-green-700',   label: 'Disetujui', icon: CheckCircleIcon },
  REJECTED: { class: 'bg-red-50 text-red-700',       label: 'Ditolak', icon: XCircleIcon },
  SKIPPED:  { class: 'bg-gray-100 text-gray-600',    label: 'Skip', icon: ClockIcon },
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
        <div class="mb-1 flex items-center gap-2 text-sm font-medium text-primary-600">
          <ClipboardDocumentCheckIcon class="h-5 w-5" /> {{ t('approval_inbox.workspace_label') }}
        </div>
        <h1 class="text-2xl font-bold tracking-tight text-gray-900">{{ t('approval_inbox.title') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ t('approval_inbox.subtitle') }}</p>
      </div>
      <select v-model="filterModule" class="input w-full bg-white sm:w-64" :aria-label="t('approval_inbox.filter_module')">
        <option v-for="m in moduleOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
      </select>
    </div>

    <div class="grid min-h-[640px] grid-cols-1 gap-5 xl:grid-cols-[380px_minmax(0,1fr)]">
      <!-- LIST -->
      <div class="approval-list card overflow-hidden border-gray-200 shadow-sm xl:col-span-1">
        <!-- Header dengan bulk action bar (Sprint 5-6 item O) -->
        <div class="card-header bg-white">
          <div v-if="bulkSelected.size === 0" class="flex items-center gap-2 text-sm text-gray-500">
            <input type="checkbox" :checked="allSelected" :indeterminate.prop="someSelected"
                   @change="toggleAll" class="rounded border-gray-300"
                   :disabled="items.length === 0" :title="t('common.select_all')">
            <div>
              <p class="font-semibold text-gray-900">{{ t('approval_inbox.request_list') }}</p>
              <p class="text-xs text-gray-500">{{ t('approval_inbox.pending_requests', { count: items.length }) }}</p>
            </div>
          </div>
          <div v-else class="flex items-center justify-between text-sm bg-primary-50 -mx-4 -my-3 px-4 py-3 border-b border-primary-200">
            <div class="flex items-center gap-2">
              <input type="checkbox" :checked="allSelected" :indeterminate.prop="someSelected"
                     @change="toggleAll" class="rounded border-gray-300">
              <span class="font-medium text-primary-900">{{ t('approval_inbox.selected_count', { count: bulkSelected.size }) }}</span>
            </div>
            <div class="flex gap-2">
              <button class="btn btn-sm btn-success" :disabled="bulkProcessing" @click="bulkDecide('approve')">
                <CheckCircleIcon class="w-4 h-4" /> {{ t('approval_inbox.btn_approve_all') }}
              </button>
              <button class="btn btn-sm btn-danger" :disabled="bulkProcessing" @click="bulkDecide('reject')">
                <XCircleIcon class="w-4 h-4" /> {{ t('approval_inbox.btn_reject_all') }}
              </button>
            </div>
          </div>
        </div>
        <div class="approval-list-body max-h-[70vh] overflow-y-auto bg-gray-50/60 p-2">
          <div v-if="loading" class="px-4 py-8 text-center text-sm text-gray-400">{{ t('common.loading') }}</div>
          <div v-else-if="items.length === 0" class="flex min-h-72 flex-col items-center justify-center px-6 text-center text-sm text-gray-400">
            <span class="mb-3 rounded-full bg-green-50 p-4"><CheckCircleIcon class="h-8 w-8 text-green-600" /></span>
            <strong class="text-gray-800">{{ t('approval_inbox.no_pending') }}</strong>
            <span class="mt-1">{{ t('approval_inbox.no_pending_hint') }}</span>
          </div>
          <div v-for="item in items" :key="item.id"
            class="approval-item mb-2 flex w-full cursor-pointer items-start gap-3 rounded-xl border border-gray-100 bg-white p-3.5 transition-all hover:border-primary-200 hover:shadow-sm"
            :class="{ 'is-active border-primary-300 ring-1 ring-primary-100 shadow-sm': selected === item.id }"
            @click="selectItem(item)">
            <input
              type="checkbox"
              :checked="bulkSelected.has(item.id)"
              @click.stop="toggleOne(item.id)"
              class="mt-1 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
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
              {{ item.employee_name }} · Step {{ item.current_step_order }} ({{ item.step_name }})
            </div>
            <div class="text-xs text-gray-400 mt-1">{{ fmtDate(item.created_at) }}</div>
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
            <!-- Detail entitas asal (meta dari source row) -->
            <div v-if="detail.meta">
              <div class="mb-3 text-sm font-semibold text-gray-900">{{ t('approval_inbox.detail_title') }}</div>
              <div class="grid gap-px overflow-hidden rounded-xl border border-gray-200 bg-gray-200 sm:grid-cols-2">
                <div v-for="(value, key) in detail.meta" :key="key" class="bg-gray-50 px-4 py-3">
                  <div class="text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ humanizeKey(key) }}</div>
                  <div class="mt-1 break-words text-sm font-medium text-gray-800">{{ formatMetaValue(value) }}</div>
                </div>
              </div>
            </div>

            <!-- Timeline keputusan -->
            <div>
              <div class="mb-3 text-sm font-semibold text-gray-900">{{ t('approval_inbox.step_history') }}</div>
              <div class="space-y-2">
                <div v-for="d in detail.decisions || []" :key="d.id"
                  class="flex items-start gap-3 rounded-xl border border-gray-200 bg-white p-4">
                  <component :is="decisionBadge[d.decision]?.icon || ClockIcon" class="w-5 h-5 mt-0.5"
                    :class="d.decision === 'APPROVED' ? 'text-green-500' : d.decision === 'REJECTED' ? 'text-red-500' : 'text-amber-500'" />
                  <div class="flex-1">
                    <div class="flex items-center justify-between">
                      <div class="font-medium text-sm">
                        Step {{ d.step_order }} — {{ d.step_name }}
                        <span class="text-xs text-gray-400 ml-2">({{ d.approver_type }})</span>
                      </div>
                      <div class="flex items-center gap-1.5">
                        <span v-if="isDelegateRole(d.approver_role)"
                          class="px-2 py-0.5 rounded-full text-xs bg-indigo-50 text-indigo-700 border border-indigo-200"
                          :title="`Diputuskan sebagai pengganti (${d.approver_role})`">
                          {{ t('approval_inbox.delegate_badge') }}
                        </span>
                        <span :class="['px-2 py-0.5 rounded-full text-xs', decisionBadge[d.decision]?.class]">
                          {{ decisionBadge[d.decision]?.label || d.decision }}
                        </span>
                      </div>
                    </div>
                    <div v-if="d.approver_user_name" class="text-xs text-gray-500 mt-1">
                      {{ t('approval_inbox.by_prefix') }}{{ d.approver_user_name }} · {{ fmtDate(d.decided_at || d.created_at) }}
                      <span v-if="isDelegateRole(d.approver_role)" class="text-indigo-600">
                        {{ t('approval_inbox.acting_as_delegate') }}
                      </span>
                    </div>
                    <div v-if="d.note" class="text-xs text-gray-600 mt-1 italic">"{{ d.note }}"</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Form keputusan -->
            <div v-if="detail.status === 'PENDING'" class="rounded-xl border border-gray-200 bg-gray-50 p-5">
              <label class="label">{{ t('approval_inbox.label_note') }}</label>
              <p class="mb-3 text-xs text-gray-500">{{ t('approval_inbox.note_hint') }}</p>
              <textarea v-model="note" class="input bg-white" rows="3"
                :placeholder="t('approval_inbox.note_ph')"></textarea>
              <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button class="btn-secondary justify-center sm:min-w-28" :disabled="submitting" @click="decide('reject')">
                  <XCircleIcon class="w-4 h-4" /> {{ t('common.reject') }}
                </button>
                <button class="btn-primary justify-center sm:min-w-28" :disabled="submitting" @click="decide('approve')">
                  <CheckCircleIcon class="w-4 h-4" /> {{ t('common.approve') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
