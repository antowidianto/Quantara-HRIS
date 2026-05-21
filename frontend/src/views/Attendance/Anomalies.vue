<script setup>
/**
 * Attendance/Anomalies.vue — HR review attendance anomaly + device management.
 *
 * Endpoint:
 *   GET    /attendance-anomaly?min_score=&reviewed=&from_date=&to_date=&page=&per_page=
 *   GET    /attendance-anomaly/stats?from_date=&to_date=
 *   POST   /attendance-anomaly/:id/review { note }
 *   GET    /attendance-devices?user_id=
 *   POST   /attendance-devices/:id/trust
 *   POST   /attendance-devices/:id/block { reason }
 *
 * Layout: tab "Anomali" (list + detail review modal) dan "Device" (list + trust/block).
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useConfirm, promptDialog } from '@/composables/useConfirm'
import {
  ShieldExclamationIcon, ExclamationTriangleIcon, CheckCircleIcon,
  DevicePhoneMobileIcon, NoSymbolIcon, HandThumbUpIcon, FlagIcon,
} from '@heroicons/vue/24/outline'

const toast = useToast()
const { confirm } = useConfirm()

const tab = ref('anomalies') // 'anomalies' | 'devices'

// ─── Anomalies ──────────────────────────────────────────────────────
const anomalies = ref([])
const aLoading = ref(false)
const aPage = ref(1)
const aTotal = ref(0)
const aFilters = ref({
  min_score: 30,
  reviewed: '',     // '' | 0 | 1
  from_date: '',
  to_date: '',
})

const stats = ref(null)

// Modal review
const reviewing = ref(null)
const reviewNote = ref('')
const reviewSubmitting = ref(false)

// ─── Devices ────────────────────────────────────────────────────────
const devices = ref([])
const dLoading = ref(false)

async function fetchAnomalies() {
  aLoading.value = true
  try {
    const res = await api.get('/attendance-anomaly', {
      params: {
        page: aPage.value,
        per_page: 20,
        min_score: aFilters.value.min_score || 0,
        reviewed: aFilters.value.reviewed === '' ? undefined : aFilters.value.reviewed,
        from_date: aFilters.value.from_date || undefined,
        to_date: aFilters.value.to_date || undefined,
      },
    })
    const data = res.data?.data || {}
    anomalies.value = data.items || data.rows || data || []
    aTotal.value = data.total || anomalies.value.length || 0
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat anomali')
  } finally {
    aLoading.value = false
  }
}

async function fetchStats() {
  try {
    const res = await api.get('/attendance-anomaly/stats', {
      params: {
        from_date: aFilters.value.from_date || undefined,
        to_date: aFilters.value.to_date || undefined,
      },
    })
    stats.value = res.data?.data || null
  } catch (e) {
    // Silent — stats card optional
  }
}

async function fetchDevices() {
  dLoading.value = true
  try {
    const res = await api.get('/attendance-devices')
    devices.value = res.data?.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat devices')
  } finally {
    dLoading.value = false
  }
}

function openReview(row) {
  reviewing.value = row
  reviewNote.value = ''
}
async function submitReview() {
  if (! reviewing.value) return
  if (reviewNote.value.trim().length < 5) {
    toast.warning('Catatan review min 5 karakter')
    return
  }
  reviewSubmitting.value = true
  try {
    await api.post(`/attendance-anomaly/${reviewing.value.id}/review`, {
      note: reviewNote.value.trim(),
    })
    toast.success('Anomali ditandai sudah direview')
    reviewing.value = null
    reviewNote.value = ''
    fetchAnomalies()
    fetchStats()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan review')
  } finally {
    reviewSubmitting.value = false
  }
}

async function trustDevice(d) {
  const ok = await confirm({
    title: 'Trust Device?',
    message: `Device "${d.device_name || d.device_fingerprint?.substring(0, 16)}" akan ditandai sebagai trusted dan tidak akan men-trigger flag NEW_DEVICE lagi.`,
    confirmText: 'Trust',
    type: 'info',
  })
  if (! ok) return
  try {
    await api.post(`/attendance-devices/${d.id}/trust`)
    toast.success('Device di-trust')
    fetchDevices()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal trust device')
  }
}

async function blockDevice(d) {
  const reason = await promptDialog({
    title: `Blokir device "${d.device_name || 'unknown'}"`,
    message: 'Alasan akan ditampilkan di audit log.',
    inputLabel: 'Alasan blok (min 5 karakter)',
    inputType: 'textarea',
    inputRequired: true,
    okText: 'Blokir',
    variant: 'danger',
  })
  if (! reason || reason.trim().length < 5) {
    if (reason !== null) toast.warning('Alasan blok min 5 karakter')
    return
  }
  try {
    await api.post(`/attendance-devices/${d.id}/block`, { reason: reason.trim() })
    toast.success('Device diblokir')
    fetchDevices()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal blokir device')
  }
}

function applyFilters() {
  aPage.value = 1
  fetchAnomalies()
  fetchStats()
}

function fmtDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
}

function fmtDateOnly(d) {
  if (! d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

const flagDescriptions = {
  MOCK_GPS:        { label: 'Mock GPS terdeteksi', color: 'bg-red-100 text-red-700' },
  LOW_ACCURACY:    { label: 'Akurasi GPS rendah',  color: 'bg-amber-100 text-amber-700' },
  NEW_DEVICE:      { label: 'Device baru',          color: 'bg-blue-100 text-blue-700' },
  BLOCKED_DEVICE:  { label: 'Device di-block',      color: 'bg-red-100 text-red-700' },
  IP_MISMATCH:     { label: 'IP tidak biasa',       color: 'bg-amber-100 text-amber-700' },
  GEOFENCE_FAR:    { label: 'Jauh dari geofence',   color: 'bg-amber-100 text-amber-700' },
  NO_SELFIE:       { label: 'Tidak ada selfie',     color: 'bg-gray-100 text-gray-700' },
}

function scoreColor(score) {
  const s = Number(score) || 0
  if (s >= 70) return 'bg-red-100 text-red-700 border-red-300'
  if (s >= 40) return 'bg-amber-100 text-amber-700 border-amber-300'
  return 'bg-yellow-50 text-yellow-700 border-yellow-200'
}

onMounted(() => {
  fetchAnomalies()
  fetchStats()
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ShieldExclamationIcon class="w-6 h-6 text-red-600" />
          Anomali Absensi & Device Management
        </h1>
        <p class="text-sm text-gray-500">
          Review absensi yang punya skor anomali tinggi (mock GPS, akurasi rendah, dll.)
          dan kelola device yang dipakai karyawan.
        </p>
      </div>
      <div class="flex gap-1 bg-gray-100 rounded-lg p-1">
        <button
          class="px-3 py-1.5 text-sm rounded-md transition"
          :class="tab === 'anomalies' ? 'bg-white text-primary-700 shadow' : 'text-gray-600'"
          @click="tab = 'anomalies'"
        >
          <FlagIcon class="w-4 h-4 inline -mt-0.5" /> Anomali
        </button>
        <button
          class="px-3 py-1.5 text-sm rounded-md transition"
          :class="tab === 'devices' ? 'bg-white text-primary-700 shadow' : 'text-gray-600'"
          @click="tab = 'devices'; fetchDevices()"
        >
          <DevicePhoneMobileIcon class="w-4 h-4 inline -mt-0.5" /> Device
        </button>
      </div>
    </div>

    <!-- ANOMALI TAB ───────────────────────────────────────────────── -->
    <div v-if="tab === 'anomalies'">
      <!-- Stats -->
      <div v-if="stats" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="card p-3">
          <div class="text-xs text-gray-500">Total Anomali</div>
          <div class="text-xl font-bold">{{ stats.total ?? 0 }}</div>
        </div>
        <div class="card p-3">
          <div class="text-xs text-gray-500">Sudah Direview</div>
          <div class="text-xl font-bold text-emerald-600">{{ stats.reviewed ?? 0 }}</div>
        </div>
        <div class="card p-3">
          <div class="text-xs text-gray-500">Belum Direview</div>
          <div class="text-xl font-bold text-amber-600">{{ stats.unreviewed ?? 0 }}</div>
        </div>
        <div class="card p-3">
          <div class="text-xs text-gray-500">Skor Tertinggi</div>
          <div class="text-xl font-bold text-red-600">{{ stats.max_score ?? 0 }}</div>
        </div>
      </div>

      <!-- Filters -->
      <div class="card mb-4">
        <div class="card-body grid grid-cols-1 md:grid-cols-5 gap-3">
          <div>
            <label class="label text-xs">Skor Minimal</label>
            <input v-model.number="aFilters.min_score" type="number" min="0" max="100" class="input" />
          </div>
          <div>
            <label class="label text-xs">Status Review</label>
            <select v-model="aFilters.reviewed" class="input">
              <option value="">Semua</option>
              <option :value="0">Belum direview</option>
              <option :value="1">Sudah direview</option>
            </select>
          </div>
          <div>
            <label class="label text-xs">Dari Tanggal</label>
            <input v-model="aFilters.from_date" type="date" class="input" />
          </div>
          <div>
            <label class="label text-xs">Sampai Tanggal</label>
            <input v-model="aFilters.to_date" type="date" class="input" />
          </div>
          <div class="flex items-end">
            <button class="btn-primary w-full" @click="applyFilters">Filter</button>
          </div>
        </div>
      </div>

      <!-- List -->
      <div class="card overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left">Karyawan</th>
              <th class="px-3 py-2 text-left">Tanggal</th>
              <th class="px-3 py-2 text-left">Clock In/Out</th>
              <th class="px-3 py-2 text-center">Skor</th>
              <th class="px-3 py-2 text-left">Flags</th>
              <th class="px-3 py-2 text-left">Status</th>
              <th class="px-3 py-2 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="aLoading">
              <td colspan="7" class="px-4 py-6 text-center text-gray-400">Memuat…</td>
            </tr>
            <tr v-else-if="!anomalies.length">
              <td colspan="7" class="px-4 py-12 text-center text-gray-400">
                <CheckCircleIcon class="w-10 h-10 mx-auto mb-2 text-emerald-300" />
                Tidak ada anomali sesuai filter
              </td>
            </tr>
            <tr v-for="a in anomalies" :key="a.id" class="hover:bg-gray-50">
              <td class="px-3 py-2">
                <div class="font-medium">{{ a.employee_name }}</div>
                <div class="text-xs text-gray-500">{{ a.employee_number }} · {{ a.department_name || '-' }}</div>
              </td>
              <td class="px-3 py-2">{{ fmtDateOnly(a.attendance_date) }}</td>
              <td class="px-3 py-2 text-xs">
                <div v-if="a.clock_in_time">In: {{ a.clock_in_time }}</div>
                <div v-if="a.clock_out_time">Out: {{ a.clock_out_time }}</div>
              </td>
              <td class="px-3 py-2 text-center">
                <span :class="['inline-block px-2 py-0.5 rounded border text-xs font-bold', scoreColor(a.anomaly_score)]">
                  {{ a.anomaly_score }}
                </span>
              </td>
              <td class="px-3 py-2">
                <div class="flex flex-wrap gap-1">
                  <span v-for="f in a.anomaly_flags || []" :key="f"
                    class="text-[10px] px-1.5 py-0.5 rounded font-mono"
                    :class="(flagDescriptions[f] || {}).color || 'bg-gray-100 text-gray-700'"
                    :title="(flagDescriptions[f] || {}).label || f">
                    {{ f }}
                  </span>
                </div>
              </td>
              <td class="px-3 py-2 text-xs">
                <span v-if="a.anomaly_reviewed_at" class="text-emerald-700 flex items-center gap-1">
                  <CheckCircleIcon class="w-3.5 h-3.5" /> {{ fmtDate(a.anomaly_reviewed_at) }}
                </span>
                <span v-else class="text-amber-700 flex items-center gap-1">
                  <ExclamationTriangleIcon class="w-3.5 h-3.5" /> Belum direview
                </span>
              </td>
              <td class="px-3 py-2 text-right">
                <button v-if="!a.anomaly_reviewed_at" class="btn-secondary text-xs" @click="openReview(a)">
                  Review
                </button>
                <button v-else class="btn-secondary text-xs" @click="openReview(a)" title="Lihat / tambahkan catatan">
                  Lihat
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- DEVICE TAB ───────────────────────────────────────────────── -->
    <div v-else>
      <div class="card overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left">User / Karyawan</th>
              <th class="px-3 py-2 text-left">Device</th>
              <th class="px-3 py-2 text-left">First/Last Seen</th>
              <th class="px-3 py-2 text-center">Usage</th>
              <th class="px-3 py-2 text-left">Status</th>
              <th class="px-3 py-2 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="dLoading">
              <td colspan="6" class="px-4 py-6 text-center text-gray-400">Memuat…</td>
            </tr>
            <tr v-else-if="!devices.length">
              <td colspan="6" class="px-4 py-12 text-center text-gray-400">Belum ada device tercatat</td>
            </tr>
            <tr v-for="d in devices" :key="d.id" class="hover:bg-gray-50"
              :class="{ 'bg-red-50/40': d.is_blocked }">
              <td class="px-3 py-2">
                <div class="font-medium">{{ d.user_name || d.employee_name || '-' }}</div>
                <div class="text-xs text-gray-500">{{ d.employee_number || '-' }}</div>
              </td>
              <td class="px-3 py-2">
                <div class="text-sm">{{ d.device_name || 'Unknown device' }}</div>
                <code class="text-[10px] text-gray-500 break-all">
                  {{ (d.device_fingerprint || '').substring(0, 32) }}…
                </code>
              </td>
              <td class="px-3 py-2 text-xs">
                <div>First: {{ fmtDate(d.first_seen_at) }}</div>
                <div>Last:  {{ fmtDate(d.last_seen_at) }}</div>
              </td>
              <td class="px-3 py-2 text-center">{{ d.usage_count || 0 }}×</td>
              <td class="px-3 py-2">
                <span v-if="d.is_blocked" class="text-xs px-1.5 py-0.5 rounded bg-red-100 text-red-700">Blocked</span>
                <span v-else-if="d.is_trusted" class="text-xs px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700">Trusted</span>
                <span v-else class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-700">Normal</span>
              </td>
              <td class="px-3 py-2 text-right">
                <button v-if="!d.is_trusted && !d.is_blocked"
                  class="btn-secondary text-xs text-emerald-600 mr-1" @click="trustDevice(d)">
                  <HandThumbUpIcon class="w-3.5 h-3.5" /> Trust
                </button>
                <button v-if="!d.is_blocked"
                  class="btn-secondary text-xs text-red-600" @click="blockDevice(d)">
                  <NoSymbolIcon class="w-3.5 h-3.5" /> Block
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- REVIEW MODAL ─────────────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="reviewing" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg w-full max-w-lg shadow-xl">
          <div class="px-5 py-3 border-b">
            <div class="text-lg font-semibold">Review Anomali</div>
            <div class="text-xs text-gray-500">
              {{ reviewing.employee_name }} · {{ fmtDateOnly(reviewing.attendance_date) }}
              · Skor: {{ reviewing.anomaly_score }}
            </div>
          </div>
          <div class="p-5 space-y-3">
            <div class="bg-gray-50 rounded p-3 text-xs">
              <div class="font-semibold mb-1">Flags terdeteksi:</div>
              <div class="flex flex-wrap gap-1">
                <span v-for="f in reviewing.anomaly_flags || []" :key="f"
                  class="text-[10px] px-1.5 py-0.5 rounded"
                  :class="(flagDescriptions[f] || {}).color || 'bg-gray-100 text-gray-700'">
                  {{ (flagDescriptions[f] || {}).label || f }}
                </span>
              </div>
              <div v-if="reviewing.gps_accuracy" class="mt-2 text-gray-600">
                GPS accuracy: {{ reviewing.gps_accuracy }} m
              </div>
              <div v-if="reviewing.ip_address" class="text-gray-600">
                IP: {{ reviewing.ip_address }}
              </div>
            </div>
            <div v-if="reviewing.anomaly_reviewed_at" class="text-xs text-emerald-700 italic">
              Sudah direview {{ fmtDate(reviewing.anomaly_reviewed_at) }}
              <span v-if="reviewing.anomaly_review_note">
                — "{{ reviewing.anomaly_review_note }}"
              </span>
            </div>
            <div v-if="!reviewing.anomaly_reviewed_at">
              <label class="label">Catatan Review *</label>
              <textarea v-model="reviewNote" rows="4" class="input"
                placeholder="Tulis hasil investigasi: alasan dimaafkan / tindak lanjut / dll. (min 5 karakter)"></textarea>
            </div>
          </div>
          <div class="px-5 py-3 border-t flex justify-end gap-2">
            <button class="btn-secondary" @click="reviewing = null">Tutup</button>
            <button v-if="!reviewing.anomaly_reviewed_at"
              class="btn-primary" :disabled="reviewSubmitting" @click="submitReview">
              {{ reviewSubmitting ? 'Menyimpan…' : 'Tandai Sudah Direview' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>