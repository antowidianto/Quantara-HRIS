<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtDate, fmtCurrency } from '@/composables/useResource'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import {
  NoSymbolIcon, PlusIcon, ArrowPathIcon, CheckCircleIcon,
  XCircleIcon, DocumentTextIcon, UserIcon, ExclamationTriangleIcon,
  EyeIcon,
} from '@heroicons/vue/24/outline'

/**
 * TerminationBoard — PHK formal workflow (Sprint 4 / OFF-5).
 *
 * Workflow:
 *   DRAFT → PENDING_BIPARTITE → PENDING_HR_DIRECTOR → PENDING_BOD
 *   → APPROVED → EXECUTED (atau REJECTED / CANCELLED)
 *
 * Beda dari resignation: inisiator perusahaan, multi-step approval,
 * wajib bipartite untuk UNILATERAL non-pelanggaran-berat, severance
 * auto-create dengan multiplier sesuai PP 35/2021.
 */

const auth = useAuthStore()
const toast = useToast()

const tab = ref('all')
const list = ref([])
const loading = ref(false)
const options = ref({ types: [], statuses: [], reasons: [] })

const showCreateModal = ref(false)
const createForm = reactive({
  employee_id: null,
  termination_type: 'UNILATERAL',
  reason_code: '',
  reason_detail: '',
  planned_termination_date: '',
  last_disciplinary_id: null,
})

const showDetailModal = ref(null)
const detailData = ref(null)
const bipartiteForm = reactive({ outcome: 'AGREED', minutes: '' })
const showBipartiteForm = ref(false)
const approveNote = ref('')

const STATUS_BADGE = {
  DRAFT:               { label: 'Draft',                color: 'bg-gray-100 text-gray-700' },
  PENDING_BIPARTITE:   { label: 'Bipartite',            color: 'bg-amber-100 text-amber-700' },
  PENDING_HR_DIRECTOR: { label: 'HR Director Review',   color: 'bg-blue-100 text-blue-700' },
  PENDING_BOD:         { label: 'BOD Review',           color: 'bg-purple-100 text-purple-700' },
  APPROVED:            { label: 'Approved',             color: 'bg-emerald-100 text-emerald-700' },
  EXECUTED:            { label: 'Executed',             color: 'bg-emerald-200 text-emerald-800' },
  REJECTED:            { label: 'Rejected',             color: 'bg-rose-100 text-rose-700' },
  CANCELLED:           { label: 'Cancelled',            color: 'bg-gray-100 text-gray-500' },
}

const canManage  = computed(() => auth.isSuperAdmin || auth.can('termination.manage'))
const canApprove = computed(() => auth.isSuperAdmin || auth.can('termination.approve'))
const canExecute = computed(() => auth.isSuperAdmin || auth.can('termination.execute'))

const filteredList = computed(() => {
  if (tab.value === 'all') return list.value
  return list.value.filter(t => t.status === tab.value)
})

const stats = computed(() => ({
  draft: list.value.filter(t => t.status === 'DRAFT').length,
  pending: list.value.filter(t => ['PENDING_BIPARTITE','PENDING_HR_DIRECTOR','PENDING_BOD'].includes(t.status)).length,
  approved: list.value.filter(t => t.status === 'APPROVED').length,
  executed: list.value.filter(t => t.status === 'EXECUTED').length,
}))

async function fetchAll() {
  loading.value = true
  try {
    const [l, o] = await Promise.all([
      api.get('/terminations', { params: { per_page: 100 } }),
      api.get('/terminations/options'),
    ])
    list.value = l.data.data || []
    options.value = o.data.data || { types: [], statuses: [], reasons: [] }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat')
  } finally {
    loading.value = false
  }
}

onMounted(fetchAll)

function openCreateModal() {
  Object.assign(createForm, {
    employee_id: null,
    termination_type: 'UNILATERAL',
    reason_code: '',
    reason_detail: '',
    planned_termination_date: '',
    last_disciplinary_id: null,
  })
  showCreateModal.value = true
}

async function submitCreate() {
  if (! createForm.employee_id) { toast.error('Karyawan wajib'); return }
  if (! createForm.reason_code) { toast.error('Alasan PHK wajib'); return }
  if (! createForm.planned_termination_date) { toast.error('Tanggal target wajib'); return }
  try {
    const res = await api.post('/terminations', createForm)
    toast.success(`PHK draft #${res.data.data.reference_number} dibuat`)
    showCreateModal.value = false
    fetchAll()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal create')
  }
}

async function openDetail(t) {
  showDetailModal.value = t
  showBipartiteForm.value = false
  approveNote.value = ''
  try {
    const res = await api.get(`/terminations/${t.id}`)
    detailData.value = res.data.data
  } catch (e) {
    toast.error('Gagal memuat detail')
    showDetailModal.value = null
  }
}

async function submit(id) {
  const ok = await confirmDialog({
    title: 'Submit PHK request?',
    message: 'Status DRAFT akan berubah ke PENDING_BIPARTITE atau PENDING_HR_DIRECTOR '
      + 'tergantung kategori PHK. Setelah submit, edit field utama tidak diperbolehkan.',
    okText: 'Submit',
  })
  if (! ok) return
  try {
    await api.post(`/terminations/${id}/submit`)
    toast.success('Submitted')
    fetchAll()
    if (showDetailModal.value?.id === id) openDetail(showDetailModal.value)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal submit')
  }
}

async function submitBipartite() {
  if (bipartiteForm.minutes.trim().length < 20) {
    toast.error('Notulen min 20 karakter')
    return
  }
  try {
    await api.post(`/terminations/${detailData.value.id}/bipartite`, {
      outcome: bipartiteForm.outcome,
      minutes: bipartiteForm.minutes.trim(),
    })
    toast.success(`Bipartite outcome: ${bipartiteForm.outcome}`)
    showBipartiteForm.value = false
    fetchAll()
    openDetail(showDetailModal.value)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal record bipartite')
  }
}

async function approve(stage) {
  try {
    await api.post(`/terminations/${detailData.value.id}/approve`, {
      stage,
      note: approveNote.value || null,
    })
    toast.success(`Approved at ${stage}`)
    fetchAll()
    openDetail(showDetailModal.value)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal approve')
  }
}

async function reject(stage) {
  const reason = window.prompt('Alasan reject (min 10 karakter):')
  if (! reason || reason.trim().length < 10) {
    toast.error('Alasan min 10 karakter')
    return
  }
  try {
    await api.post(`/terminations/${detailData.value.id}/reject`, {
      stage,
      reason: reason.trim(),
    })
    toast.success('Rejected')
    fetchAll()
    openDetail(showDetailModal.value)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal reject')
  }
}

async function execute() {
  const ok = await confirmDialog({
    title: 'Execute PHK?',
    message: 'Sistem akan otomatis:\n'
      + '• Auto-create severance DRAFT sesuai PP 35/2021\n'
      + '• Generate PHK letter\n'
      + '• Set employees.resign_date\n'
      + '• Trigger offboarding tasks\n'
      + '• Auto-create MPP DRAFT pengganti (kecuali untuk PHK efisiensi)\n\n'
      + 'Action ini PERMANENT — pastikan semua dokumen siap.',
    okText: 'EXECUTE',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.post(`/terminations/${detailData.value.id}/execute`)
    toast.success('PHK EXECUTED — lihat severance & PHK letter')
    fetchAll()
    openDetail(showDetailModal.value)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal execute')
  }
}

async function cancelTermination() {
  const reason = window.prompt('Alasan cancel (min 10 karakter):')
  if (! reason || reason.trim().length < 10) {
    toast.error('Alasan min 10 karakter')
    return
  }
  try {
    await api.post(`/terminations/${detailData.value.id}/cancel`, { reason: reason.trim() })
    toast.success('PHK dibatalkan')
    fetchAll()
    showDetailModal.value = null
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal cancel')
  }
}

function downloadLetter() {
  const id = detailData.value.id
  window.open(`/api/v1/terminations/${id}/letter?format=pdf`, '_blank')
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <NoSymbolIcon class="w-6 h-6 text-rose-600" />
          Termination (PHK) Workflow
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">
          Pemutusan hubungan kerja formal — PP 35/2021. Multi-step approval
          dengan bipartite negotiation untuk UNILATERAL non-pelanggaran-berat.
        </p>
      </div>
      <div class="flex gap-2">
        <button class="btn-secondary text-xs" @click="fetchAll">
          <ArrowPathIcon class="w-3.5 h-3.5" /> Refresh
        </button>
        <button v-if="canManage" class="btn-primary text-xs" @click="openCreateModal">
          <PlusIcon class="w-3.5 h-3.5" /> Inisiasi PHK
        </button>
      </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
      <div class="bg-white rounded-lg border p-4">
        <div class="text-xs text-gray-500">DRAFT</div>
        <div class="text-2xl font-bold text-gray-700">{{ stats.draft }}</div>
      </div>
      <div class="bg-white rounded-lg border p-4">
        <div class="text-xs text-gray-500">PENDING APPROVAL</div>
        <div class="text-2xl font-bold text-amber-700">{{ stats.pending }}</div>
      </div>
      <div class="bg-white rounded-lg border p-4">
        <div class="text-xs text-gray-500">APPROVED (siap execute)</div>
        <div class="text-2xl font-bold text-blue-700">{{ stats.approved }}</div>
      </div>
      <div class="bg-white rounded-lg border p-4">
        <div class="text-xs text-gray-500">EXECUTED</div>
        <div class="text-2xl font-bold text-emerald-700">{{ stats.executed }}</div>
      </div>
    </div>

    <!-- Filter tabs -->
    <div class="flex gap-1 mb-3 border-b border-gray-200 overflow-x-auto">
      <button
        :class="['px-3 py-2 text-xs font-medium border-b-2 -mb-px whitespace-nowrap',
          tab === 'all' ? 'border-rose-500 text-rose-700' : 'border-transparent text-gray-500 hover:text-gray-700']"
        @click="tab = 'all'">
        Semua ({{ list.length }})
      </button>
      <button v-for="s in (options.statuses || [])" :key="s"
        :class="['px-3 py-2 text-xs font-medium border-b-2 -mb-px whitespace-nowrap',
          tab === s ? 'border-rose-500 text-rose-700' : 'border-transparent text-gray-500 hover:text-gray-700']"
        @click="tab = s">
        {{ STATUS_BADGE[s]?.label || s }}
      </button>
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-400">Memuat…</div>
    <div v-else-if="filteredList.length === 0" class="text-center py-12 text-gray-400 italic">
      Tidak ada termination request.
    </div>

    <div v-else class="space-y-2">
      <div v-for="t in filteredList" :key="t.id"
        class="bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md transition cursor-pointer"
        @click="openDetail(t)">
        <div class="flex items-start justify-between gap-3 flex-wrap">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="font-mono text-sm font-semibold">{{ t.reference_number }}</span>
              <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', STATUS_BADGE[t.status]?.color]">
                {{ STATUS_BADGE[t.status]?.label || t.status }}
              </span>
              <span class="text-xs px-2 py-0.5 rounded-full bg-blue-50 text-blue-700">
                {{ t.termination_type }}
              </span>
            </div>
            <div class="mt-1 flex items-center gap-2 flex-wrap text-sm">
              <UserIcon class="w-4 h-4 text-gray-400" />
              <strong>{{ t.employee_name }}</strong>
              <span class="text-xs text-gray-500">{{ t.employee_number }}</span>
              <span v-if="t.position_name" class="text-xs text-gray-500">· {{ t.position_name }}</span>
            </div>
            <div class="text-xs text-gray-500 mt-1">
              Reason: {{ t.reason_code }}
              · Target: {{ fmtDate(t.planned_termination_date) }}
              <span v-if="t.effective_date"> · Efektif: {{ fmtDate(t.effective_date) }}</span>
            </div>
          </div>
          <div class="text-xs text-gray-400">#{{ t.id }}</div>
        </div>
      </div>
    </div>

    <!-- Create modal -->
    <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">Inisiasi PHK Baru</h2>
          <button class="text-gray-500" @click="showCreateModal = false">&times;</button>
        </div>
        <div class="p-6 space-y-4">
          <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-900">
            <strong>Konteks:</strong> PHK formal sangat sensitif & melibatkan kewajiban hukum.
            Pastikan dokumen pendukung lengkap (SP3 untuk pelanggaran, laporan finansial untuk
            efisiensi, dll). Backend akan validate sesuai PP 35/2021.
          </div>

          <div>
            <label class="label">Karyawan *</label>
            <EmployeeSearchSelect v-model="createForm.employee_id"
              placeholder="Cari karyawan…" :only-active="true" />
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="label">Tipe PHK *</label>
              <select v-model="createForm.termination_type" class="input">
                <option v-for="t in (options.types || [])" :key="t" :value="t">{{ t }}</option>
              </select>
              <p class="text-xs text-gray-500 mt-1">
                UNILATERAL = sepihak (butuh bipartite); MUTUAL = kesepakatan;
                CONTRACT_END = PKWT habis.
              </p>
            </div>
            <div>
              <label class="label">Tanggal Target Efektif *</label>
              <AppDatePicker  v-model="createForm.planned_termination_date" class="input" />
            </div>
          </div>

          <div>
            <label class="label">Alasan PHK (sesuai PP 35/2021) *</label>
            <select v-model="createForm.reason_code" class="input">
              <option value="">— Pilih alasan —</option>
              <option v-for="r in (options.reasons || [])" :key="r.code" :value="r.code">
                {{ r.label }} (pesangon ×{{ r.pesangon_multiplier }}, UPMK ×{{ r.upmk_multiplier }})
              </option>
            </select>
          </div>

          <div>
            <label class="label">Detail Alasan</label>
            <textarea v-model="createForm.reason_detail" class="input" rows="3"
              placeholder="Detail kontekstual: penurunan revenue, jumlah orang yang di-PHK, hasil rapat manajemen, dll"></textarea>
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showCreateModal = false">Batal</button>
          <button class="btn-primary" @click="submitCreate">Simpan Draft</button>
        </div>
      </div>
    </div>

    <!-- Detail modal -->
    <div v-if="showDetailModal && detailData" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between sticky top-0 bg-white z-10">
          <div>
            <h2 class="font-semibold flex items-center gap-2">
              <span class="font-mono">{{ detailData.reference_number }}</span>
              <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', STATUS_BADGE[detailData.status]?.color]">
                {{ STATUS_BADGE[detailData.status]?.label }}
              </span>
            </h2>
            <p class="text-xs text-gray-500">{{ detailData.employee_name }} · {{ detailData.position_name }}</p>
          </div>
          <button class="text-gray-500" @click="showDetailModal = null">&times;</button>
        </div>

        <div class="p-6 space-y-4">
          <!-- Summary -->
          <div class="grid grid-cols-2 gap-3 text-sm">
            <div>
              <div class="text-xs text-gray-500">Tipe</div>
              <div class="font-medium">{{ detailData.termination_type }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">Reason</div>
              <div class="font-medium">{{ detailData.reason_code }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">Target Efektif</div>
              <div>{{ fmtDate(detailData.planned_termination_date) }}</div>
            </div>
            <div v-if="detailData.effective_date">
              <div class="text-xs text-gray-500">Aktual Efektif</div>
              <div>{{ fmtDate(detailData.effective_date) }}</div>
            </div>
          </div>

          <div v-if="detailData.reason_detail"
            class="bg-gray-50 border border-gray-200 rounded p-3 text-sm">
            <div class="text-xs font-semibold text-gray-700 mb-1">Detail Alasan</div>
            <p class="whitespace-pre-line">{{ detailData.reason_detail }}</p>
          </div>

          <!-- Severance preview -->
          <div v-if="detailData.severance_preview" class="bg-amber-50 border border-amber-200 rounded p-3 text-sm">
            <div class="text-xs font-semibold text-amber-700 mb-1">Preview Severance (PP 35/2021)</div>
            <div class="grid grid-cols-2 gap-2 text-xs">
              <div>Pesangon: <strong>{{ fmtCurrency(detailData.severance_preview.pesangon_amount) }}</strong></div>
              <div>UPMK: <strong>{{ fmtCurrency(detailData.severance_preview.upmk_amount) }}</strong></div>
              <div>UPH: <strong>{{ fmtCurrency(detailData.severance_preview.uph_total) }}</strong></div>
              <div class="font-bold text-amber-900">
                Total: {{ fmtCurrency(detailData.severance_preview.total_amount) }}
              </div>
            </div>
            <p v-if="detailData.requires_bod" class="text-[11px] text-amber-700 mt-2">
              ⚠ Pesangon > 100jt — wajib lewat BOD approval.
            </p>
          </div>

          <!-- Bipartite info -->
          <div v-if="detailData.bipartite_held_at" class="bg-blue-50 border border-blue-200 rounded p-3 text-sm">
            <div class="text-xs font-semibold text-blue-700 mb-1">
              Bipartite — {{ detailData.bipartite_outcome }}
            </div>
            <p class="whitespace-pre-line">{{ detailData.bipartite_minutes }}</p>
            <p v-if="detailData.tripartite_required" class="text-[11px] text-rose-700 mt-2">
              🔺 Tripartite (mediasi disnaker) diperlukan.
            </p>
          </div>

          <!-- Approver tracking -->
          <div v-if="detailData.hr_director_action_at || detailData.bod_action_at"
            class="border border-gray-200 rounded p-3 text-sm space-y-2">
            <div v-if="detailData.hr_director_action_at" class="flex items-start gap-2">
              <CheckCircleIcon v-if="detailData.hr_director_decision === 'APPROVED'"
                class="w-4 h-4 text-emerald-600 mt-0.5" />
              <XCircleIcon v-else class="w-4 h-4 text-rose-600 mt-0.5" />
              <div>
                <div class="font-medium">HR Director: {{ detailData.hr_director_decision }}</div>
                <div class="text-xs text-gray-500">{{ fmtDate(detailData.hr_director_action_at) }}</div>
                <div v-if="detailData.hr_director_note" class="text-xs italic mt-1">
                  "{{ detailData.hr_director_note }}"
                </div>
              </div>
            </div>
            <div v-if="detailData.bod_action_at" class="flex items-start gap-2">
              <CheckCircleIcon v-if="detailData.bod_decision === 'APPROVED'"
                class="w-4 h-4 text-emerald-600 mt-0.5" />
              <XCircleIcon v-else class="w-4 h-4 text-rose-600 mt-0.5" />
              <div>
                <div class="font-medium">BOD: {{ detailData.bod_decision }}</div>
                <div class="text-xs text-gray-500">{{ fmtDate(detailData.bod_action_at) }}</div>
                <div v-if="detailData.bod_note" class="text-xs italic mt-1">
                  "{{ detailData.bod_note }}"
                </div>
              </div>
            </div>
          </div>

          <!-- Bipartite form -->
          <div v-if="detailData.status === 'PENDING_BIPARTITE' && canManage" class="border-2 border-amber-300 rounded p-3 bg-amber-50/30">
            <h3 class="font-semibold text-sm mb-2">Record Bipartite Outcome</h3>
            <button v-if="!showBipartiteForm" class="btn-secondary text-sm" @click="showBipartiteForm = true">
              Buka Form Bipartite
            </button>
            <div v-else class="space-y-2">
              <div>
                <label class="label">Outcome</label>
                <select v-model="bipartiteForm.outcome" class="input">
                  <option value="AGREED">AGREED — kesepakatan tercapai</option>
                  <option value="PENDING_TRIPARTITE">PENDING_TRIPARTITE — lanjut ke disnaker, paralel</option>
                  <option value="DISAGREED">DISAGREED — gagal, perlu disnaker</option>
                </select>
              </div>
              <div>
                <label class="label">Notulen *</label>
                <textarea v-model="bipartiteForm.minutes" class="input" rows="4"
                  placeholder="Hadir: ..., topik dibahas: ..., kesepakatan: ..."></textarea>
                <p class="text-xs text-gray-500 mt-1">Min 20 karakter</p>
              </div>
              <button class="btn-primary text-sm" @click="submitBipartite">Submit Bipartite</button>
            </div>
          </div>

          <!-- Approve/Reject form -->
          <div v-if="['PENDING_HR_DIRECTOR','PENDING_BOD'].includes(detailData.status) && canApprove"
            class="border-2 border-blue-300 rounded p-3 bg-blue-50/30">
            <h3 class="font-semibold text-sm mb-2">
              Decision —
              {{ detailData.status === 'PENDING_HR_DIRECTOR' ? 'HR Director' : 'BOD' }}
            </h3>
            <textarea v-model="approveNote" class="input mb-2" rows="2"
              placeholder="Catatan (opsional)"></textarea>
            <div class="flex gap-2">
              <button class="btn-primary text-sm bg-emerald-600 hover:bg-emerald-700"
                @click="approve(detailData.status === 'PENDING_HR_DIRECTOR' ? 'HR_DIRECTOR' : 'BOD')">
                <CheckCircleIcon class="w-4 h-4" /> Approve
              </button>
              <button class="btn-secondary text-sm text-rose-600 border-rose-300"
                @click="reject(detailData.status === 'PENDING_HR_DIRECTOR' ? 'HR_DIRECTOR' : 'BOD')">
                Reject
              </button>
            </div>
          </div>
        </div>

        <div class="px-6 py-3 border-t flex justify-end gap-2 sticky bottom-0 bg-white">
          <button v-if="detailData.phk_letter_id"
            class="btn-secondary text-sm" @click="downloadLetter">
            <DocumentTextIcon class="w-4 h-4" /> Surat PHK PDF
          </button>
          <button v-if="canManage && detailData.status === 'DRAFT'"
            class="btn-primary text-sm" @click="submit(detailData.id)">Submit</button>
          <button v-if="canExecute && detailData.status === 'APPROVED'"
            class="btn-primary text-sm bg-rose-600 hover:bg-rose-700"
            @click="execute">
            EXECUTE
          </button>
          <button v-if="canManage && !['EXECUTED','REJECTED','CANCELLED'].includes(detailData.status)"
            class="text-sm px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"
            @click="cancelTermination">
            Cancel
          </button>
          <button class="btn-secondary" @click="showDetailModal = null">Tutup</button>
        </div>
      </div>
    </div>
  </div>
</template>