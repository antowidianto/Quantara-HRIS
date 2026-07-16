<script setup>
/**
 * S2.3 SaaS — Data Export Self-Service (UU PDP Pasal 16 + GDPR Art. 20).
 *
 * Tenant super admin bisa request export semua data perusahaannya
 * dalam ZIP CSV (employees, payslips, leaves, attendance, dll).
 *
 * Workflow:
 *   1. Pilih scope (semua tabel atau subset)
 *   2. Submit → backend queue job
 *   3. Polling status (QUEUED → RUNNING → READY)
 *   4. Download via signed URL (expire 7 hari)
 *
 * Audit log severity HIGH karena ekspor PII massal.
 */
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  ArrowDownTrayIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  CheckCircleIcon,
  ArrowPathIcon,
} from '@heroicons/vue/24/outline'

const toast = useToast()

const requests = ref([])
const loading = ref(true)
const submitting = ref(false)
let pollTimer = null

// Daftar tabel yang bisa di-export. Sinkron dengan ALLOWED_TABLES di backend
const AVAILABLE_TABLES = [
  { key: 'employees',         label: 'Data Karyawan', recommended: true },
  { key: 'payslips',          label: 'Slip Gaji', recommended: true },
  { key: 'payroll_periods',   label: 'Periode Payroll' },
  { key: 'attendance_logs',   label: 'Log Absensi' },
  { key: 'leave_requests',    label: 'Pengajuan Cuti' },
  { key: 'reimbursements',    label: 'Reimbursement' },
  { key: 'loans',             label: 'Kasbon / Loan' },
  { key: 'companies',         label: 'Profil Perusahaan' },
  { key: 'departments',       label: 'Departemen' },
  { key: 'positions',         label: 'Jabatan' },
]

const scope = reactive({
  selected: AVAILABLE_TABLES.map(t => t.key),
})

async function loadRequests() {
  loading.value = true
  try {
    const res = await api.get('/companies/me/data-exports')
    requests.value = res.data?.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat list export')
  } finally {
    loading.value = false
  }
}

async function createExport() {
  if (! scope.selected.length) {
    toast.error('Pilih minimal 1 tabel untuk di-export')
    return
  }
  const ok = await confirmDialog({
    title: 'Konfirmasi Data Export',
    message: `Anda akan mengekspor ${scope.selected.length} tabel ke ZIP. Action tercatat di audit log severity HIGH karena melibatkan PII.`,
    okText: 'Ya, Export',
    variant: 'warning',
  })
  if (! ok) return

  submitting.value = true
  try {
    const res = await api.post('/companies/me/data-exports', { include: scope.selected })
    if (res.status === 202 && res.data.data?.approval_state === 'PENDING') {
      toast.warning('Export PII menunggu approval. Ulangi request yang sama setelah disetujui.')
      return
    }
    toast.success('Export request dibuat. Tunggu hingga status READY.')
    await loadRequests()
    startPolling()
  } catch (e) {
    if (e.response?.status === 409) {
      toast.warning('Ada export yang masih berjalan. Tunggu selesai dulu.')
    } else {
      toast.error(e.response?.data?.message || 'Gagal create export')
    }
  } finally {
    submitting.value = false
  }
}

async function fetchSingle(id) {
  try {
    const res = await api.get(`/companies/me/data-exports/${id}`)
    return res.data?.data || null
  } catch {
    return null
  }
}

async function downloadFile(req) {
  const fresh = await fetchSingle(req.id)
  if (! fresh?.download_url) {
    toast.error('Download URL tidak tersedia atau sudah expired')
    return
  }
  window.open(fresh.download_url, '_blank')
}

function statusBadge(status) {
  const map = {
    QUEUED:  'bg-gray-100 text-gray-700',
    RUNNING: 'bg-blue-100 text-blue-700',
    READY:   'bg-emerald-100 text-emerald-700',
    FAILED:  'bg-rose-100 text-rose-700',
    EXPIRED: 'bg-amber-100 text-amber-700',
  }
  return map[status] || 'bg-gray-100'
}

function formatDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

function formatBytes(b) {
  if (! b) return '-'
  const n = Number(b)
  if (n < 1024) return n + ' B'
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB'
  if (n < 1024 * 1024 * 1024) return (n / 1024 / 1024).toFixed(1) + ' MB'
  return (n / 1024 / 1024 / 1024).toFixed(1) + ' GB'
}

function selectAll() {
  scope.selected = AVAILABLE_TABLES.map(t => t.key)
}

function selectRecommended() {
  scope.selected = AVAILABLE_TABLES.filter(t => t.recommended).map(t => t.key)
}

function startPolling() {
  stopPolling()
  pollTimer = setInterval(async () => {
    const hasActive = requests.value.some(r => ['QUEUED', 'RUNNING'].includes(r.status))
    if (hasActive) {
      await loadRequests()
    } else {
      stopPolling()
    }
  }, 5000)
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

onMounted(async () => {
  await loadRequests()
  if (requests.value.some(r => ['QUEUED', 'RUNNING'].includes(r.status))) {
    startPolling()
  }
})

onBeforeUnmount(() => {
  stopPolling()
})
</script>

<template>
  <div class="p-6 max-w-5xl mx-auto">
    <div class="mb-6">
      <h1 class="text-2xl font-bold dark:text-white">Data Export (UU PDP Pasal 16)</h1>
      <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
        Ekspor seluruh data perusahaan Anda dalam format CSV (ZIP). Berlaku untuk hak portabilitas data
        UU Perlindungan Data Pribadi RI dan GDPR Article 20.
      </p>
    </div>

    <!-- Compliance notice -->
    <div class="mb-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg p-4 flex gap-3">
      <ExclamationTriangleIcon class="h-5 w-5 text-amber-600 shrink-0 mt-0.5" />
      <div class="text-sm text-amber-800 dark:text-amber-200">
        <p class="font-semibold mb-1">Perhatian Keamanan</p>
        <ul class="list-disc list-inside space-y-0.5 text-xs">
          <li>File ZIP berisi <strong>data PII</strong> (NIK, NPWP, gaji, kontak). Simpan dengan aman.</li>
          <li>Download link berlaku <strong>7 hari</strong>, setelah itu otomatis expired.</li>
          <li>Setiap request tercatat di audit log dengan severity <strong>HIGH</strong>.</li>
          <li>Hanya 1 export aktif per tenant. Tunggu selesai sebelum request baru.</li>
        </ul>
      </div>
    </div>

    <!-- Scope selector -->
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5 mb-4">
      <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <h3 class="text-lg font-semibold dark:text-white">Pilih Data yang Akan Di-Export</h3>
        <div class="flex gap-2">
          <button
            type="button"
            class="px-3 py-1 text-xs border rounded hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
            @click="selectAll"
          >
            Pilih Semua
          </button>
          <button
            type="button"
            class="px-3 py-1 text-xs border rounded hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
            @click="selectRecommended"
          >
            Recommended Only
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
        <label
          v-for="t in AVAILABLE_TABLES"
          :key="t.key"
          class="flex items-center gap-2 p-2 border rounded cursor-pointer hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700"
        >
          <input
            v-model="scope.selected"
            type="checkbox"
            :value="t.key"
            class="rounded border-gray-300"
          />
          <span class="text-sm dark:text-gray-200">{{ t.label }}</span>
          <span v-if="t.recommended" class="ml-auto text-xs px-1.5 py-0.5 bg-emerald-100 text-emerald-700 rounded">Recommended</span>
        </label>
      </div>

      <div class="mt-4 flex items-center justify-between flex-wrap gap-3">
        <div class="text-xs text-gray-500">
          {{ scope.selected.length }} dari {{ AVAILABLE_TABLES.length }} tabel dipilih
        </div>
        <button
          type="button"
          class="px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded hover:bg-blue-500 inline-flex items-center gap-1 disabled:opacity-50"
          :disabled="submitting || ! scope.selected.length"
          @click="createExport"
        >
          <ArrowDownTrayIcon class="h-4 w-4" />
          {{ submitting ? 'Memproses…' : 'Request Export' }}
        </button>
      </div>
    </div>

    <!-- Requests history -->
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-lg font-semibold dark:text-white">Riwayat Export</h3>
        <button
          type="button"
          class="text-xs text-blue-600 hover:underline inline-flex items-center gap-1"
          @click="loadRequests"
        >
          <ArrowPathIcon class="h-3 w-3" /> Refresh
        </button>
      </div>

      <div v-if="loading" class="text-gray-500 py-8 text-center">Memuat…</div>

      <div v-else-if="!requests.length" class="text-gray-500 py-8 text-center">
        Belum ada export request
      </div>

      <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
        <thead class="bg-gray-50 dark:bg-gray-900">
          <tr>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Size</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Expires</th>
            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
          <tr v-for="r in requests" :key="r.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
            <td class="px-3 py-3 text-sm dark:text-gray-200">#{{ r.id }}</td>
            <td class="px-3 py-3">
              <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded" :class="statusBadge(r.status)">
                <CheckCircleIcon v-if="r.status === 'READY'" class="h-3 w-3" />
                <ClockIcon v-else-if="['QUEUED', 'RUNNING'].includes(r.status)" class="h-3 w-3 animate-spin" />
                <ExclamationTriangleIcon v-else-if="r.status === 'FAILED'" class="h-3 w-3" />
                {{ r.status }}
              </span>
              <div v-if="r.error_message" class="text-xs text-rose-600 mt-1">{{ r.error_message }}</div>
            </td>
            <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-400">{{ formatDate(r.created_at) }}</td>
            <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-400">{{ formatBytes(r.file_size) }}</td>
            <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-400">{{ formatDate(r.expires_at) }}</td>
            <td class="px-3 py-3 text-right">
              <button
                v-if="r.status === 'READY'"
                type="button"
                class="px-3 py-1 text-xs font-medium bg-emerald-600 text-white rounded hover:bg-emerald-500 inline-flex items-center gap-1"
                @click="downloadFile(r)"
              >
                <ArrowDownTrayIcon class="h-3 w-3" /> Download
              </button>
              <span v-else class="text-xs text-gray-400">-</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
