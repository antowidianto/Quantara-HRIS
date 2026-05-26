<script setup>
import { ref, reactive, onMounted, watch, computed } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import {
  InboxArrowDownIcon, CheckCircleIcon, XCircleIcon, ClockIcon,
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

const moduleOptions = [
  { value: '', label: 'Semua Modul' },
  { value: 'LEAVE', label: 'Cuti' },
  { value: 'OVERTIME', label: 'Lembur' },
  { value: 'ATTENDANCE_CHANGE', label: 'Perubahan Absensi' },
  { value: 'REIMBURSEMENT', label: 'Reimbursement' },
  { value: 'LOAN', label: 'Pinjaman' },
  { value: 'GENERIC', label: 'Generic' },
]

const moduleLabel = {
  LEAVE: 'Cuti',
  OVERTIME: 'Lembur',
  ATTENDANCE_CHANGE: 'Perubahan Absensi',
  REIMBURSEMENT: 'Reimbursement',
  LOAN: 'Pinjaman',
  GENERIC: 'Generic',
}

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
    toast.error(e.response?.data?.message || 'Gagal memuat inbox')
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
    toast.error(e.response?.data?.message || 'Gagal memuat detail')
  } finally {
    detailLoading.value = false
  }
}

async function decide(action) {
  if (! selected.value) return
  if (action === 'reject' && ! note.value.trim()) {
    toast.warning('Alasan penolakan wajib diisi')
    return
  }
  submitting.value = true
  try {
    await api.post(`/approval-requests/${selected.value}/${action}`, { note: note.value })
    toast.success(action === 'approve' ? 'Permintaan disetujui' : 'Permintaan ditolak')
    note.value = ''
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memproses keputusan')
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
    toast.warning(`Maksimal ${BULK_MAX} approval per bulk action. Saat ini dipilih ${bulkSelected.value.size}.`)
    return
  }
  let bulkNote = ''
  if (action === 'reject') {
    const promptResult = window.prompt('Alasan penolakan (akan dipakai untuk semua):', '')
    if (! promptResult || ! promptResult.trim()) {
      toast.warning('Alasan penolakan wajib diisi untuk bulk reject')
      return
    }
    bulkNote = promptResult.trim()
  } else {
    if (! window.confirm(`Setujui ${bulkSelected.value.size} permintaan sekaligus?`)) return
  }
  bulkProcessing.value = true
  const ids = Array.from(bulkSelected.value)
  try {
    const endpoint = action === 'approve' ? '/approval-requests/bulk-approve' : '/approval-requests/bulk-reject'
    const res = await api.post(endpoint, { approval_ids: ids, note: bulkNote })
    const summary = res.data?.data?.summary || { success: 0, failed: 0 }
    const failed = res.data?.data?.failed || []
    if (summary.failed === 0) {
      toast.success(`${summary.success} permintaan ${action === 'approve' ? 'disetujui' : 'ditolak'}`)
    } else if (summary.success > 0) {
      // Tampilkan reason gagal pertama supaya user dapat petunjuk konkrit.
      const firstReason = failed[0]?.reason ? ` (contoh: ${failed[0].reason})` : ''
      toast.warning(`${summary.success} berhasil, ${summary.failed} gagal${firstReason}`)
    } else {
      const firstReason = failed[0]?.reason ? `: ${failed[0].reason}` : ''
      toast.error(`Semua ${summary.failed} permintaan gagal diproses${firstReason}`)
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memproses bulk action')
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

function metaPretty(meta) {
  if (! meta || typeof meta !== 'object') return ''
  const lines = []
  for (const [k, v] of Object.entries(meta)) {
    lines.push(`${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`)
  }
  return lines.join('\n')
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
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <InboxArrowDownIcon class="w-6 h-6" /> Inbox Approval
      </h1>
      <select v-model="filterModule" class="input w-64">
        <option v-for="m in moduleOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
      </select>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- LIST -->
      <div class="lg:col-span-1 card overflow-hidden">
        <!-- Header dengan bulk action bar (Sprint 5-6 item O) -->
        <div class="card-header">
          <div v-if="bulkSelected.size === 0" class="flex items-center gap-2 text-sm text-gray-500">
            <input type="checkbox" :checked="allSelected" :indeterminate.prop="someSelected"
                   @change="toggleAll" class="rounded border-gray-300"
                   :disabled="items.length === 0" :title="`Pilih ${items.length}`">
            <span>{{ items.length }} permintaan menunggu</span>
          </div>
          <div v-else class="flex items-center justify-between text-sm bg-primary-50 -mx-4 -my-3 px-4 py-3 border-b border-primary-200">
            <div class="flex items-center gap-2">
              <input type="checkbox" :checked="allSelected" :indeterminate.prop="someSelected"
                     @change="toggleAll" class="rounded border-gray-300">
              <span class="font-medium text-primary-900">{{ bulkSelected.size }} dipilih</span>
            </div>
            <div class="flex gap-2">
              <button class="btn btn-sm btn-success" :disabled="bulkProcessing" @click="bulkDecide('approve')">
                <CheckCircleIcon class="w-4 h-4" /> Setujui Semua
              </button>
              <button class="btn btn-sm btn-danger" :disabled="bulkProcessing" @click="bulkDecide('reject')">
                <XCircleIcon class="w-4 h-4" /> Tolak Semua
              </button>
            </div>
          </div>
        </div>
        <div class="divide-y divide-gray-100 max-h-[70vh] overflow-y-auto">
          <div v-if="loading" class="px-4 py-8 text-center text-sm text-gray-400">Memuat...</div>
          <div v-else-if="items.length === 0" class="px-4 py-12 text-center text-sm text-gray-400">
            <CheckCircleIcon class="w-10 h-10 mx-auto mb-2 text-gray-300" />
            Tidak ada yang perlu disetujui
          </div>
          <div v-for="item in items" :key="item.id"
            class="flex w-full items-start gap-2 px-4 py-3 hover:bg-gray-50 transition cursor-pointer"
            :class="{ 'bg-primary-50/40 border-l-4 border-primary-500': selected === item.id }"
            @click="selectItem(item)">
            <input
              type="checkbox"
              :checked="bulkSelected.has(item.id)"
              @click.stop="toggleOne(item.id)"
              class="mt-1 rounded border-gray-300"
              :title="`Centang untuk bulk action`"
            >
            <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between mb-1">
              <span class="font-medium text-sm truncate">{{ item.subject }}</span>
              <span class="text-xs text-gray-400 ml-2 shrink-0">{{ moduleLabel[item.module] || item.module }}</span>
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
      <div class="lg:col-span-2 card">
        <div v-if="detailLoading" class="px-6 py-12 text-center text-sm text-gray-400">Memuat detail...</div>

        <div v-else-if="! detail" class="px-6 py-16 text-center text-sm text-gray-400">
          Pilih permintaan di sebelah kiri untuk melihat detail.
        </div>

        <div v-else>
          <div class="card-header">
            <div class="flex flex-wrap gap-3 items-center justify-between">
              <div>
                <div class="text-xs text-gray-500">{{ moduleLabel[detail.module] || detail.module }} · #{{ detail.id }}</div>
                <div class="text-lg font-semibold">{{ detail.subject }}</div>
                <div class="text-sm text-gray-500">
                  Dari: <span class="font-medium">{{ detail.employee_name }}</span> ·
                  Diajukan {{ fmtDate(detail.created_at) }}
                </div>
              </div>
              <div>
                <span class="px-2 py-1 rounded-full text-xs"
                  :class="detail.status === 'PENDING' ? 'bg-amber-50 text-amber-700' : detail.status === 'APPROVED' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'">
                  {{ detail.status }}
                </span>
              </div>
            </div>
          </div>

          <div class="card-body space-y-4">
            <!-- Detail entitas asal (meta dari source row) -->
            <div v-if="detail.meta" class="bg-gray-50 border border-gray-200 rounded-md p-4">
              <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Detail Permintaan</div>
              <pre class="text-xs whitespace-pre-wrap font-mono text-gray-700">{{ metaPretty(detail.meta) }}</pre>
            </div>

            <!-- Timeline keputusan -->
            <div>
              <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Riwayat Step</div>
              <div class="space-y-2">
                <div v-for="d in detail.decisions || []" :key="d.id"
                  class="flex items-start gap-3 border border-gray-200 rounded-md p-3 bg-white">
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
                          🤝 Delegasi
                        </span>
                        <span :class="['px-2 py-0.5 rounded-full text-xs', decisionBadge[d.decision]?.class]">
                          {{ decisionBadge[d.decision]?.label || d.decision }}
                        </span>
                      </div>
                    </div>
                    <div v-if="d.approver_user_name" class="text-xs text-gray-500 mt-1">
                      Oleh: {{ d.approver_user_name }} · {{ fmtDate(d.decided_at || d.created_at) }}
                      <span v-if="isDelegateRole(d.approver_role)" class="text-indigo-600">
                        — bertindak sebagai delegasi
                      </span>
                    </div>
                    <div v-if="d.note" class="text-xs text-gray-600 mt-1 italic">"{{ d.note }}"</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Form keputusan -->
            <div v-if="detail.status === 'PENDING'" class="border-t border-gray-200 pt-4">
              <label class="label">Catatan / Alasan</label>
              <textarea v-model="note" class="input" rows="3"
                placeholder="Catatan untuk pemohon. Wajib jika menolak."></textarea>
              <div class="flex justify-end gap-2 mt-3">
                <button class="btn-secondary" :disabled="submitting" @click="decide('reject')">
                  <XCircleIcon class="w-4 h-4" /> Tolak
                </button>
                <button class="btn-primary" :disabled="submitting" @click="decide('approve')">
                  <CheckCircleIcon class="w-4 h-4" /> Setujui
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>