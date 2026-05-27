<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  HomeModernIcon, PlusIcon, CheckIcon, XMarkIcon, ArrowPathIcon,
  ClockIcon, MapPinIcon, ExclamationTriangleIcon, NoSymbolIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 8 / H1.5 — WFH Requests UI.
 *
 * Tiga skenario pengguna:
 *   - Karyawan: ajukan WFH (tab "Pengajuan Saya")
 *   - Supervisor: setujui/tolak (tab "Inbox Approval")
 *   - HR: lihat semua (tab "Semua Pengajuan")
 *
 * Backend menerima cap bulanan (default 12 hari) + aturan H-1 sebelum
 * jam mulai shift. UI hanya tampilkan error message dari backend kalau
 * validasi gagal.
 */

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const canApprove = computed(() =>
  auth.can('attendance.approve_change') || auth.can('attendance.view_all') || auth.isSuperAdmin
)

const tab = ref(route.query.scope === 'inbox' ? 'inbox' : route.query.scope === 'all' ? 'all' : 'mine')
const items = ref([])
const loading = ref(false)
const stats = ref({ pending: 0, approved: 0, rejected: 0 })

const filters = reactive({
  status: '',
  from: '',
  to: '',
})

const showCreate = ref(false)
const form = reactive({
  work_date: '',
  reason: '',
})
const submitting = ref(false)

const showReject = ref(null)
const rejectNote = ref('')

async function fetchList() {
  loading.value = true
  try {
    const params = { scope: tab.value, ...filters }
    Object.keys(params).forEach(k => params[k] === '' && delete params[k])
    const res = await api.get('/wfh-requests', { params })
    items.value = res.data.data || []
    // Hitung stats lokal supaya badge di tab tetap akurat
    stats.value = items.value.reduce((acc, r) => {
      const k = (r.status || 'PENDING').toLowerCase()
      if (k === 'pending') acc.pending++
      else if (k === 'approved') acc.approved++
      else if (k === 'rejected') acc.rejected++
      return acc
    }, { pending: 0, approved: 0, rejected: 0 })
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat pengajuan WFH')
  } finally {
    loading.value = false
  }
}

function switchTab(t) {
  tab.value = t
  router.replace({ query: { ...route.query, scope: t } })
  fetchList()
}

async function submitForm() {
  if (! form.work_date) {
    toast.warning('Tanggal wajib diisi')
    return
  }
  if (! form.reason || form.reason.trim().length < 5) {
    toast.warning('Alasan minimal 5 karakter')
    return
  }
  submitting.value = true
  try {
    await api.post('/wfh-requests', form)
    toast.success('Pengajuan WFH terkirim')
    showCreate.value = false
    form.work_date = ''
    form.reason = ''
    fetchList()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal mengirim pengajuan')
  } finally {
    submitting.value = false
  }
}

async function approve(item) {
  const ok = await confirmDialog({
    title: 'Setujui WFH',
    message: `Setujui pengajuan WFH ${item.employee_name || ''} pada ${item.work_date}?`,
    confirmText: 'Setujui',
  })
  if (! ok) return
  try {
    await api.post(`/wfh-requests/${item.id}/approve`)
    toast.success('Pengajuan disetujui')
    fetchList()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyetujui')
  }
}

async function rejectSubmit() {
  if (! rejectNote.value || rejectNote.value.trim().length < 5) {
    toast.warning('Catatan penolakan minimal 5 karakter')
    return
  }
  try {
    await api.post(`/wfh-requests/${showReject.value.id}/reject`, { note: rejectNote.value })
    toast.success('Pengajuan ditolak')
    showReject.value = null
    rejectNote.value = ''
    fetchList()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menolak')
  }
}

async function cancel(item) {
  const ok = await confirmDialog({
    title: 'Batalkan Pengajuan',
    message: `Batalkan pengajuan WFH ${item.work_date}?`,
    confirmText: 'Batalkan',
    danger: true,
  })
  if (! ok) return
  try {
    await api.post(`/wfh-requests/${item.id}/cancel`)
    toast.success('Pengajuan dibatalkan')
    fetchList()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal membatalkan')
  }
}

function statusBadge(s) {
  const map = {
    PENDING:  'bg-amber-50 text-amber-700 border-amber-200',
    APPROVED: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    REJECTED: 'bg-red-50 text-red-700 border-red-200',
    CANCELLED: 'bg-gray-50 text-gray-600 border-gray-200',
  }
  return map[s] || 'bg-gray-50 text-gray-600 border-gray-200'
}

const today = new Date().toISOString().slice(0, 10)
const maxDate = (() => {
  const d = new Date()
  d.setDate(d.getDate() + 30)
  return d.toISOString().slice(0, 10)
})()

onMounted(fetchList)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <HomeModernIcon class="w-6 h-6" /> Pengajuan WFH (Work From Home)
      </h1>
      <div class="flex items-center gap-2">
        <button class="btn-secondary" @click="fetchList" :disabled="loading">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" /> Refresh
        </button>
        <button class="btn-primary" @click="showCreate = true">
          <PlusIcon class="w-4 h-4" /> Ajukan WFH
        </button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 mb-4 flex gap-1">
      <button
        v-for="t in [
          { id: 'mine', label: 'Pengajuan Saya' },
          ...(canApprove ? [{ id: 'inbox', label: 'Inbox Approval' }] : []),
          ...(canApprove ? [{ id: 'all', label: 'Semua Pengajuan' }] : []),
        ]"
        :key="t.id"
        @click="switchTab(t.id)"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
          tab === t.id
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-gray-500 hover:text-gray-700'
        ]"
      >
        {{ t.label }}
      </button>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-3 gap-3 mb-4">
      <div class="card-body bg-amber-50 rounded-lg border border-amber-100">
        <div class="text-xs text-amber-700 font-medium">Pending</div>
        <div class="text-2xl font-bold text-amber-900">{{ stats.pending }}</div>
      </div>
      <div class="card-body bg-emerald-50 rounded-lg border border-emerald-100">
        <div class="text-xs text-emerald-700 font-medium">Disetujui</div>
        <div class="text-2xl font-bold text-emerald-900">{{ stats.approved }}</div>
      </div>
      <div class="card-body bg-red-50 rounded-lg border border-red-100">
        <div class="text-xs text-red-700 font-medium">Ditolak</div>
        <div class="text-2xl font-bold text-red-900">{{ stats.rejected }}</div>
      </div>
    </div>

    <!-- Filter -->
    <div class="card mb-4">
      <div class="card-body grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
        <div>
          <label class="label">Status</label>
          <select v-model="filters.status" class="input" @change="fetchList">
            <option value="">Semua</option>
            <option value="PENDING">Pending</option>
            <option value="APPROVED">Disetujui</option>
            <option value="REJECTED">Ditolak</option>
            <option value="CANCELLED">Dibatalkan</option>
          </select>
        </div>
        <div>
          <label class="label">Dari</label>
          <input type="date" v-model="filters.from" class="input" @change="fetchList" />
        </div>
        <div>
          <label class="label">Sampai</label>
          <input type="date" v-model="filters.to" class="input" @change="fetchList" />
        </div>
      </div>
    </div>

    <!-- List -->
    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th v-if="tab !== 'mine'">Karyawan</th>
              <th>Tanggal WFH</th>
              <th>Alasan</th>
              <th>Status</th>
              <th>Approver</th>
              <th>Diajukan</th>
              <th class="text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td :colspan="tab === 'mine' ? 6 : 7" class="text-center py-8 text-gray-400">Memuat…</td></tr>
            <tr v-else-if="items.length === 0">
              <td :colspan="tab === 'mine' ? 6 : 7" class="text-center py-8 text-gray-400">Belum ada pengajuan</td>
            </tr>
            <tr v-for="r in items" :key="r.id">
              <td v-if="tab !== 'mine'">
                <div class="font-medium text-sm">{{ r.employee_name || '—' }}</div>
                <div class="text-xs text-gray-500 font-mono">{{ r.employee_number || '' }}</div>
              </td>
              <td>
                <div class="font-medium">{{ r.work_date }}</div>
              </td>
              <td class="max-w-xs">
                <div class="text-sm text-gray-700 line-clamp-2">{{ r.reason }}</div>
                <div v-if="r.rejection_note" class="text-xs text-red-600 mt-1">
                  <ExclamationTriangleIcon class="w-3 h-3 inline" /> {{ r.rejection_note }}
                </div>
              </td>
              <td>
                <span class="inline-block px-2 py-0.5 rounded-full text-xs border" :class="statusBadge(r.status)">
                  {{ r.status }}
                </span>
              </td>
              <td class="text-xs text-gray-600">{{ r.approver_name || '—' }}</td>
              <td class="text-xs text-gray-500">{{ r.created_at?.slice(0, 16) }}</td>
              <td class="text-right">
                <div class="inline-flex gap-1">
                  <button v-if="r.status === 'PENDING' && canApprove && tab !== 'mine'"
                    class="btn-icon text-emerald-600" @click="approve(r)" title="Setujui">
                    <CheckIcon class="w-4 h-4" />
                  </button>
                  <button v-if="r.status === 'PENDING' && canApprove && tab !== 'mine'"
                    class="btn-icon text-red-600" @click="showReject = r" title="Tolak">
                    <XMarkIcon class="w-4 h-4" />
                  </button>
                  <button v-if="r.status === 'PENDING' && tab === 'mine'"
                    class="btn-icon text-gray-600" @click="cancel(r)" title="Batalkan">
                    <NoSymbolIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal: Pengajuan -->
    <div v-if="showCreate" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showCreate = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h3 class="font-semibold">Pengajuan WFH</h3>
          <button class="text-gray-400 hover:text-gray-700" @click="showCreate = false">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        <div class="p-5 space-y-3">
          <div>
            <label class="label">Tanggal WFH <span class="text-red-500">*</span></label>
            <input type="date" v-model="form.work_date" class="input" :min="today" :max="maxDate" />
            <p class="text-xs text-gray-500 mt-1">
              <ClockIcon class="w-3 h-3 inline" />
              Pengajuan paling lambat H-1 atau sebelum jam shift dimulai. Maks 30 hari ke depan.
            </p>
          </div>
          <div>
            <label class="label">Alasan <span class="text-red-500">*</span></label>
            <textarea v-model="form.reason" class="input" rows="4"
              placeholder="Contoh: Anak sakit, perlu mendampingi sambil tetap kerja"></textarea>
          </div>
          <div class="bg-blue-50 border border-blue-200 rounded p-3 text-xs text-blue-800">
            <MapPinIcon class="w-4 h-4 inline mr-1" />
            Saat WFH disetujui, geofence saat clock-in di-skip otomatis.
            Cap default 12 hari WFH per bulan (bisa berbeda sesuai konfigurasi perusahaan).
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showCreate = false">Batal</button>
          <button class="btn-primary" @click="submitForm" :disabled="submitting">
            <span v-if="submitting">Mengirim…</span>
            <span v-else>Kirim Pengajuan</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Modal: Reject -->
    <div v-if="showReject" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showReject = null">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h3 class="font-semibold">Tolak Pengajuan WFH</h3>
          <button class="text-gray-400 hover:text-gray-700" @click="showReject = null">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        <div class="p-5 space-y-3">
          <div class="text-sm text-gray-700">
            Tolak pengajuan WFH <strong>{{ showReject.employee_name }}</strong>
            untuk tanggal <strong>{{ showReject.work_date }}</strong>?
          </div>
          <div>
            <label class="label">Catatan Penolakan <span class="text-red-500">*</span></label>
            <textarea v-model="rejectNote" class="input" rows="3"
              placeholder="Jelaskan alasan penolakan, akan dikirim ke karyawan"></textarea>
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showReject = null">Batal</button>
          <button class="btn-danger" @click="rejectSubmit">Tolak Pengajuan</button>
        </div>
      </div>
    </div>
  </div>
</template>