<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm } from '@/composables/useConfirm'
import { useI18n } from '@/locales'
import {
  ClockIcon, MapPinIcon, ArrowPathIcon, CheckCircleIcon,
  ExclamationTriangleIcon, BoltIcon, CalendarDaysIcon,
  CameraIcon, FingerPrintIcon, ChevronRightIcon,
} from '@heroicons/vue/24/outline'

/**
 * Halaman Clock In / Clock Out (kios mode untuk self-service).
 *
 * Fitur utama:
 *  - Big state-aware button (Clock In / Clock Out) berdasarkan kondisi hari ini
 *  - Live elapsed timer saat sudah clock-in tapi belum clock-out
 *  - Info shift, deteksi telat real-time, perkiraan jam kerja sampai pulang
 *  - GPS status + jarak ke kantor (haversine di client)
 *  - Geofence pre-check supaya user tahu kemungkinan reject sebelum tap
 *  - Quick notes preset dan catatan bebas
 *  - Riwayat 7 hari terakhir
 */

const toast = useToast()
const router = useRouter()
const { t } = useI18n()

// ─── State umum ────────────────────────────────────────────────────
const now = ref(new Date())
const loading = ref(false)
const today = ref(null)         // entry kalender hari ini
const recentDays = ref([])      // 7 hari terakhir
const branch = ref(null)        // info branch user (untuk geofence)
const employee = ref(null)

// Geolocation
const lat = ref(null)
const lng = ref(null)
const accuracy = ref(null)
const geoError = ref('')
const geoLoading = ref(false)

// Form
const notes = ref('')
const presetNotes = computed(() => [
  t('attendance.clock.preset_on_time'),
  t('attendance.clock.preset_traffic'),
  t('attendance.clock.preset_client_meeting'),
  t('attendance.clock.preset_wfh'),
  t('attendance.clock.preset_heavy_rain'),
  t('attendance.clock.preset_overtime'),
])

// ─── Anti-cheat client signals ─────────────────────────────────────
// Sebagian deteksi mock-GPS hanya bisa dilakukan di native (Capacitor),
// di web kita pakai heuristik akurasi terlalu rendah / koordinat bulat
// sebagai sinyal awal saja. Server tetap punya skor anomali sendiri.
const selfieDataUrl = ref(null)         // base64 data: URL hasil capture
const showCamera = ref(false)
const videoEl = ref(null)
const stream = ref(null)
const fingerprint = ref('')             // device fingerprint sederhana
const requireSelfie = ref(false)        // bisa di-set dari setting company

function isLikelyMockGps() {
  // Heuristik: koordinat bulat presisi (kelipatan 0.0001) sering dari emulator,
  // accuracy tepat 0 atau >= 10000m juga mencurigakan.
  if (! lat.value || ! lng.value) return false
  if (accuracy.value === 0 || (accuracy.value && accuracy.value > 10000)) return true
  const round = Math.round(lat.value * 10000) / 10000
  if (Math.abs(round - lat.value) < 1e-9) return true
  return false
}

function generateFingerprint() {
  // Fingerprint stabil dari user-agent + viewport + timezone + language.
  // Bukan anti-fraud kelas berat — cukup untuk korelasi device per user.
  try {
    const parts = [
      navigator.userAgent,
      `${screen.width}x${screen.height}`,
      Intl.DateTimeFormat().resolvedOptions().timeZone || '',
      navigator.language,
    ]
    let h = 0
    const s = parts.join('|')
    for (let i = 0; i < s.length; i++) {
      h = ((h << 5) - h) + s.charCodeAt(i)
      h |= 0
    }
    return `web-${(h >>> 0).toString(16)}-${parts[0].length}`
  } catch (_) {
    return 'web-unknown'
  }
}

async function openCamera() {
  showCamera.value = true
  try {
    stream.value = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: 640, height: 480 },
      audio: false,
    })
    setTimeout(() => {
      if (videoEl.value && stream.value) {
        videoEl.value.srcObject = stream.value
      }
    }, 100)
  } catch (e) {
    showCamera.value = false
    toast.error(t('attendance.clock.camera_failed', { msg: e.message || 'unknown' }))
  }
}

function captureSelfie() {
  if (! videoEl.value) return
  const canvas = document.createElement('canvas')
  canvas.width = videoEl.value.videoWidth || 640
  canvas.height = videoEl.value.videoHeight || 480
  canvas.getContext('2d').drawImage(videoEl.value, 0, 0, canvas.width, canvas.height)
  selfieDataUrl.value = canvas.toDataURL('image/jpeg', 0.7)
  closeCamera()
  toast.success(t('attendance.clock.selfie_saved'))
}

function closeCamera() {
  if (stream.value) {
    stream.value.getTracks().forEach(t => t.stop())
    stream.value = null
  }
  showCamera.value = false
}

function clearSelfie() {
  selfieDataUrl.value = null
}

// ─── Time / live updates ───────────────────────────────────────────
let tick = null
onMounted(async () => {
  tick = setInterval(() => { now.value = new Date() }, 1000)
  fingerprint.value = generateFingerprint()
  await Promise.all([fetchToday(), fetchProfile()])
  fetchRecent()
  refreshGeo()
})
onUnmounted(() => {
  if (tick) clearInterval(tick)
  closeCamera()
})

// Tanggal hari ini dalam format YYYY-MM-DD (timezone lokal).
function todayStr() {
  const d = new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${dd}`
}

async function fetchProfile() {
  try {
    const res = await api.get('/auth/me')
    employee.value = res.data.data?.employee || null
    requireSelfie.value = !! res.data.data?.attendance_settings?.require_selfie
    const branchId = employee.value?.branch_id
    if (branchId) {
      try {
        const br = await api.get(`/branches/${branchId}`)
        branch.value = br.data.data || null
      } catch (_) { /* silent */ }
    }
  } catch (_) { /* silent */ }
}

async function fetchToday() {
  try {
    const t = todayStr()
    const res = await api.get('/attendance/my', { params: { start_date: t, end_date: t } })
    const days = res.data.data?.days || []
    today.value = days.find(d => d.date === t) || null
  } catch (e) {
    // Don't toast — halaman tetap usable
    console.warn('Gagal memuat data hari ini', e)
  }
}

async function fetchRecent() {
  try {
    const end = new Date()
    const start = new Date()
    start.setDate(start.getDate() - 6)
    const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    const res = await api.get('/attendance/my', { params: { start_date: fmt(start), end_date: fmt(end) } })
    recentDays.value = (res.data.data?.days || []).slice().reverse()
  } catch (_) { /* silent */ }
}

// ─── Geolocation ───────────────────────────────────────────────────
function refreshGeo() {
  if (! navigator.geolocation) {
    geoError.value = t('attendance.clock.gps_unsupported')
    return
  }
  geoLoading.value = true
  geoError.value = ''
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      lat.value = pos.coords.latitude
      lng.value = pos.coords.longitude
      accuracy.value = Math.round(pos.coords.accuracy)
      geoLoading.value = false
    },
    (err) => {
      geoLoading.value = false
      const msg = err.code === 1 ? t('attendance.clock.geo_denied')
        : err.code === 2 ? t('attendance.clock.geo_unavailable')
        : err.code === 3 ? t('attendance.clock.geo_timeout')
        : t('attendance.clock.geo_failed')
      geoError.value = msg
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 },
  )
}

// Haversine (meter) untuk geofence pre-check di sisi client.
function haversine(la1, lo1, la2, lo2) {
  const R = 6371000
  const dLat = (la2 - la1) * Math.PI / 180
  const dLng = (lo2 - lo1) * Math.PI / 180
  const a = Math.sin(dLat / 2) ** 2
    + Math.cos(la1 * Math.PI / 180) * Math.cos(la2 * Math.PI / 180)
    * Math.sin(dLng / 2) ** 2
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))
}

const distanceToBranch = computed(() => {
  if (! lat.value || ! lng.value || ! branch.value?.lat || ! branch.value?.lng) return null
  return haversine(lat.value, lng.value, Number(branch.value.lat), Number(branch.value.lng))
})

const geofenceStatus = computed(() => {
  if (! branch.value?.lat) return { state: 'no_geofence', label: t('attendance.clock.no_geofence'), color: 'gray' }
  if (distanceToBranch.value === null) return { state: 'unknown', label: t('attendance.clock.location_unavailable'), color: 'gray' }
  const radius = Number(branch.value.geofence_radius_m || 0)
  if (! radius) return { state: 'no_geofence', label: t('attendance.clock.geofence_unset'), color: 'gray' }
  if (distanceToBranch.value <= radius) {
    return {
      state: 'inside',
      label: t('attendance.clock.inside_radius', { n: Math.round(distanceToBranch.value) }),
      color: 'green',
    }
  }
  return {
    state: 'outside',
    label: t('attendance.clock.outside_radius', { n: Math.round(distanceToBranch.value), max: radius }),
    color: 'red',
  }
})

// ─── Derived state hari ini ────────────────────────────────────────
const att = computed(() => today.value?.attendance || null)
const shift = computed(() => today.value?.shift || null)

const hasClockedIn  = computed(() => !! att.value?.clock_in_at)
const hasClockedOut = computed(() => !! att.value?.clock_out_at)

// Live elapsed time (sejak clock-in).
const elapsedMinutes = computed(() => {
  if (! att.value?.clock_in_at) return 0
  const inAt = new Date(att.value.clock_in_at.replace(' ', 'T'))
  return Math.max(0, Math.floor((now.value - inAt) / 60000))
})
const elapsedLabel = computed(() => {
  const m = elapsedMinutes.value
  const h = Math.floor(m / 60)
  const mm = m % 60
  return t('attendance.work_hours', { hours: h, minutes: mm.toString().padStart(2, '0') })
})

// Real-time deteksi keterlambatan jika belum clock-in.
const lateInfo = computed(() => {
  if (hasClockedIn.value) return null
  if (! shift.value?.start_time) return null
  const ds = todayStr()
  const start = new Date(`${ds}T${shift.value.start_time}`)
  const tol = 0 // tolerance ditangani backend; di sini just info
  if (now.value > start) {
    const min = Math.ceil((now.value - start) / 60000)
    return { minutes: min, message: t('attendance.clock.past_schedule', { n: min }) }
  }
  const min = Math.ceil((start - now.value) / 60000)
  return { minutes: -min, message: t('attendance.clock.until_schedule', { n: min }) }
})

// Hari libur / cuti tampilkan info terpisah.
const isOffDay = computed(() => {
  const s = today.value?.status
  return s === 'OFF' || s === 'HOLIDAY' || s === 'LEAVE' || s === 'LEAVE_PENDING'
})

const primaryAction = computed(() => {
  if (! hasClockedIn.value) return 'CLOCK_IN'
  if (! hasClockedOut.value) return 'CLOCK_OUT'
  return 'DONE'
})

// ─── Aksi ──────────────────────────────────────────────────────────
function buildAttendancePayload() {
  return {
    lat: lat.value,
    lng: lng.value,
    accuracy: accuracy.value,
    notes: notes.value || null,
    is_mock_gps: isLikelyMockGps() ? 1 : 0,
    device_name: navigator.userAgent.slice(0, 100),
    device_fingerprint: fingerprint.value,
    photo_data_url: selfieDataUrl.value,  // base64 ke server (server simpan)
  }
}

async function clockIn() {
  if (requireSelfie.value && ! selfieDataUrl.value) {
    toast.warning(t('attendance.clock.selfie_required'))
    return
  }
  if (geofenceStatus.value.state === 'outside') {
    const ok = await confirm({
      title: t('attendance.clock.outside_office_title'),
      message: t('attendance.clock.outside_office_msg'),
      okText: t('attendance.clock.stay_clock'),
      cancelText: t('common.cancel'),
      variant: 'warning',
    })
    if (! ok) return
  }
  loading.value = true
  try {
    await api.post('/attendance/clock-in', buildAttendancePayload())
    toast.success(t('attendance.clock.clock_in_success'))
    notes.value = ''
    selfieDataUrl.value = null
    await fetchToday()
    fetchRecent()
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.clock.clock_in_failed'))
  } finally {
    loading.value = false
  }
}

async function clockOut() {
  loading.value = true
  try {
    await api.post('/attendance/clock-out', buildAttendancePayload())
    toast.success(t('attendance.clock.clock_out_success'))
    notes.value = ''
    selfieDataUrl.value = null
    await fetchToday()
    fetchRecent()
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.clock.clock_out_failed'))
  } finally {
    loading.value = false
  }
}

function applyPreset(p) {
  notes.value = (notes.value ? notes.value + ' · ' : '') + p
}

// ─── Tampilan helpers ──────────────────────────────────────────────
function fmtTime(dt) {
  if (! dt) return '--:--'
  return dt.slice(11, 16)
}
function fmtDateShort(d) {
  if (! d) return ''
  return new Date(d).toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short' })
}
function statusBadgeClass(c) {
  return ({
    green: 'bg-green-100 text-green-700',
    amber: 'bg-amber-100 text-amber-700',
    red:   'bg-red-100 text-red-700',
    blue:  'bg-blue-100 text-blue-700',
    gray:  'bg-gray-100 text-gray-600',
  })[c] || 'bg-gray-100 text-gray-600'
}
</script>

<template>
  <div class="max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold mb-1">{{ t('attendance.clock_in_out') }}</h1>
    <p class="text-sm text-gray-500 mb-4">{{ t('attendance.clock.subtitle') }}</p>

    <!-- ─── Kartu utama ─── -->
    <div class="card overflow-hidden">
      <!-- Header dengan jam besar -->
      <div class="bg-gradient-to-br from-primary-600 to-primary-700 text-white px-6 py-6 text-center">
        <div class="text-5xl md:text-6xl font-bold tabular-nums tracking-tight">
          {{ now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) }}
        </div>
        <div class="opacity-90 mt-1">
          {{ now.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) }}
        </div>
        <div v-if="employee" class="text-xs opacity-80 mt-1">
          {{ employee.full_name }} · {{ employee.employee_number }}
          <span v-if="branch?.name">· {{ branch.name }}</span>
        </div>
      </div>

      <div class="card-body space-y-4">
        <!-- Banner libur / cuti -->
        <div v-if="isOffDay"
          class="rounded-md p-3 flex items-start gap-2 bg-blue-50 text-blue-800 text-sm">
          <CalendarDaysIcon class="w-5 h-5 mt-0.5 flex-shrink-0" />
          <div>
            <div class="font-medium">{{ today?.status_label || t('attendance.clock.holiday') }}</div>
            <div class="text-xs">
              {{ today?.holiday_name
                || (today?.leave?.leave_name ? t('attendance.clock.leave_prefix', { name: today.leave.leave_name }) : t('attendance.clock.not_workday')) }}
            </div>
          </div>
        </div>

        <!-- Info shift + indikator terlambat -->
        <div v-if="shift" class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div class="border border-gray-200 rounded-md p-3">
            <div class="text-xs text-gray-500 flex items-center gap-1">
              <CalendarDaysIcon class="w-3.5 h-3.5" /> {{ t('attendance.clock.today_shift') }}
            </div>
            <div class="font-semibold mt-1">{{ shift.name }}</div>
            <div class="text-xs text-gray-600">
              {{ shift.start_time?.slice(0, 5) }} – {{ shift.end_time?.slice(0, 5) }}
            </div>
          </div>

          <div class="border border-gray-200 rounded-md p-3">
            <div class="text-xs text-gray-500 flex items-center gap-1">
              <ClockIcon class="w-3.5 h-3.5" /> {{ t('attendance.clock.today_status') }}
            </div>
            <div v-if="hasClockedOut"
              class="mt-1 inline-flex items-center gap-1 text-green-700 font-semibold">
              <CheckCircleIcon class="w-4 h-4" /> {{ t('attendance.clock.done_working') }}
            </div>
            <div v-else-if="hasClockedIn" class="mt-1">
              <div class="font-semibold text-amber-700 flex items-center gap-1">
                <BoltIcon class="w-4 h-4" /> {{ t('attendance.clock.working_now') }}
              </div>
              <div class="text-xs text-gray-600">{{ t('attendance.clock.elapsed_running', { time: elapsedLabel }) }}</div>
            </div>
            <div v-else class="mt-1 text-gray-700 font-semibold">{{ t('attendance.clock.not_clocked_in') }}</div>
          </div>

          <div class="border border-gray-200 rounded-md p-3">
            <div class="text-xs text-gray-500 flex items-center gap-1">
              <ExclamationTriangleIcon class="w-3.5 h-3.5" /> {{ t('attendance.clock.lateness') }}
            </div>
            <div v-if="hasClockedIn" class="mt-1">
              <span v-if="att?.late_minutes" class="text-amber-700 font-semibold">
                {{ t('attendance.clock.minutes', { n: att.late_minutes }) }}
              </span>
              <span v-else class="text-green-700 font-semibold">{{ t('attendance.clock.on_time') }}</span>
            </div>
            <div v-else-if="lateInfo" class="mt-1">
              <span :class="lateInfo.minutes > 0 ? 'text-amber-700 font-semibold' : 'text-gray-600'">
                {{ lateInfo.message }}
              </span>
            </div>
            <div v-else class="mt-1 text-gray-400 text-sm">—</div>
          </div>
        </div>

        <!-- Bila tidak ada shift sama sekali -->
        <div v-else-if="!isOffDay"
          class="rounded-md p-3 flex items-start gap-2 bg-amber-50 text-amber-800 text-sm">
          <ExclamationTriangleIcon class="w-5 h-5 mt-0.5 flex-shrink-0" />
          <div>
            <div class="font-medium">{{ t('attendance.clock.no_shift_title') }}</div>
            <div class="text-xs">{{ t('attendance.clock.no_shift_note') }}</div>
          </div>
        </div>

        <!-- Detail clock-in & clock-out tile -->
        <div class="grid grid-cols-2 gap-3">
          <div class="rounded-md border p-3"
            :class="hasClockedIn ? 'border-green-200 bg-green-50' : 'border-gray-200 bg-gray-50'">
            <div class="text-xs text-gray-500">{{ t('attendance.clock.clock_in_label') }}</div>
            <div class="text-2xl font-bold tabular-nums mt-1">
              {{ fmtTime(att?.clock_in_at) }}
            </div>
            <div v-if="att?.clock_in_address" class="text-[11px] text-gray-500 mt-1 line-clamp-2">
              <MapPinIcon class="w-3 h-3 inline -mt-0.5" /> {{ att.clock_in_address }}
            </div>
          </div>
          <div class="rounded-md border p-3"
            :class="hasClockedOut ? 'border-green-200 bg-green-50' : 'border-gray-200 bg-gray-50'">
            <div class="text-xs text-gray-500">{{ t('attendance.clock.clock_out_label') }}</div>
            <div class="text-2xl font-bold tabular-nums mt-1">
              {{ fmtTime(att?.clock_out_at) }}
            </div>
            <div v-if="att?.clock_out_address" class="text-[11px] text-gray-500 mt-1 line-clamp-2">
              <MapPinIcon class="w-3 h-3 inline -mt-0.5" /> {{ att.clock_out_address }}
            </div>
          </div>
        </div>

        <!-- Lokasi & geofence -->
        <div class="border border-gray-200 rounded-md p-3">
          <div class="flex items-center justify-between">
            <div class="text-xs text-gray-500 flex items-center gap-1">
              <MapPinIcon class="w-3.5 h-3.5" /> {{ t('attendance.clock.my_location') }}
            </div>
            <button class="text-xs text-primary-600 inline-flex items-center gap-1 hover:underline"
              @click="refreshGeo" :disabled="geoLoading">
              <ArrowPathIcon class="w-3.5 h-3.5" :class="geoLoading ? 'animate-spin' : ''" />
              {{ t('common.refresh') }}
            </button>
          </div>
          <div v-if="geoError" class="text-sm text-red-700 mt-1">{{ geoError }}</div>
          <div v-else-if="lat" class="mt-1">
            <div class="text-sm font-mono">
              {{ lat.toFixed(6) }}, {{ lng.toFixed(6) }}
              <span class="text-xs text-gray-500 ml-2">{{ t('attendance.clock.accuracy', { n: accuracy }) }}</span>
            </div>
            <div class="mt-1.5 inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full"
              :class="statusBadgeClass(geofenceStatus.color)">
              <FingerPrintIcon class="w-3.5 h-3.5" />
              {{ geofenceStatus.label }}
            </div>
            <a v-if="branch?.lat" class="block text-xs text-primary-600 hover:underline mt-1"
              :href="`https://www.google.com/maps/dir/${lat},${lng}/${branch.lat},${branch.lng}`"
              target="_blank" rel="noopener">
              {{ t('attendance.clock.open_in_maps') }}
            </a>
          </div>
          <div v-else class="text-sm text-gray-500 mt-1">
            {{ t('attendance.clock.getting_location') }}
          </div>
        </div>

        <!-- Selfie capture (anti-cheat) -->
        <div v-if="primaryAction !== 'DONE' && !isOffDay" class="border border-gray-200 rounded-md p-3">
          <div class="flex items-center justify-between mb-2">
            <div class="text-xs text-gray-500 flex items-center gap-1">
              <CameraIcon class="w-3.5 h-3.5" />
              {{ t('attendance.clock.selfie') }}
              <span v-if="requireSelfie" class="text-red-600">*</span>
              <span v-else class="text-gray-400">{{ t('attendance.clock.optional') }}</span>
            </div>
            <button v-if="!selfieDataUrl" class="text-xs text-primary-600 inline-flex items-center gap-1 hover:underline"
              @click="openCamera">
              <CameraIcon class="w-3.5 h-3.5" /> {{ t('attendance.clock.take_photo') }}
            </button>
            <button v-else class="text-xs text-red-600 hover:underline" @click="clearSelfie">
              {{ t('attendance.clock.retake') }}
            </button>
          </div>
          <div v-if="selfieDataUrl" class="flex items-center gap-3">
            <img :src="selfieDataUrl" alt="Selfie" class="w-24 h-24 object-cover rounded border" />
            <div class="text-xs text-emerald-700">
              <CheckCircleIcon class="w-4 h-4 inline" />
              {{ t('attendance.clock.selfie_ready', { action: primaryAction === 'CLOCK_IN' ? 'in' : 'out' }) }}
            </div>
          </div>
          <div v-else class="text-xs text-gray-500">
            {{ t('attendance.clock.selfie_help') }}
          </div>
        </div>

        <!-- Catatan + preset -->
        <div v-if="primaryAction !== 'DONE' && !isOffDay">
          <label class="label">{{ t('attendance.clock.notes_label') }}</label>
          <textarea v-model="notes" rows="2" class="input w-full"
            :placeholder="t('attendance.clock.notes_placeholder')"></textarea>
          <div class="flex flex-wrap gap-1 mt-2">
            <button v-for="p in presetNotes" :key="p"
              class="px-2 py-0.5 text-xs rounded-full bg-gray-100 hover:bg-primary-50 hover:text-primary-700 text-gray-700"
              @click="applyPreset(p)">
              + {{ p }}
            </button>
          </div>
        </div>

        <!-- Tombol aksi besar -->
        <div v-if="primaryAction === 'CLOCK_IN'">
          <button class="btn-primary w-full py-4 text-lg justify-center"
            :disabled="loading || !lat" @click="clockIn">
            <ClockIcon class="w-6 h-6" />
            {{ loading ? t('common.processing') : t('attendance.clock.clock_in_now') }}
          </button>
          <div v-if="!lat" class="text-xs text-amber-700 text-center mt-2">
            {{ t('attendance.clock.wait_gps') }}
          </div>
        </div>

        <div v-else-if="primaryAction === 'CLOCK_OUT'">
          <button class="btn-success w-full py-4 text-lg justify-center"
            :disabled="loading" @click="clockOut">
            <CheckCircleIcon class="w-6 h-6" />
            {{ loading ? t('common.processing') : t('attendance.clock.clock_out_now') }}
          </button>
          <div class="text-xs text-gray-600 text-center mt-2">
            {{ t('attendance.clock.worked_for', { time: elapsedLabel }) }}
          </div>
        </div>

        <div v-else-if="primaryAction === 'DONE'"
          class="rounded-md p-3 bg-green-50 border border-green-200 text-center">
          <CheckCircleIcon class="w-8 h-8 text-green-600 mx-auto" />
          <div class="font-semibold text-green-800 mt-1">{{ t('attendance.clock.done_today') }}</div>
          <div class="text-xs text-green-700 mt-1">
            {{ t('attendance.clock.total_work', { hours: Math.round((att.work_minutes || 0) / 6) / 10 }) }}
          </div>
        </div>

        <!-- Aksi cepat tambahan -->
        <div class="flex flex-wrap gap-2 pt-2 border-t border-gray-100">
          <RouterLink to="/attendance/my" class="text-sm text-primary-600 hover:underline inline-flex items-center gap-1">
            <CalendarDaysIcon class="w-4 h-4" /> {{ t('attendance.clock.view_my_attendance') }}
          </RouterLink>
          <RouterLink to="/attendance/my-changes" class="text-sm text-primary-600 hover:underline inline-flex items-center gap-1">
            <ClockIcon class="w-4 h-4" /> {{ t('attendance.clock.my_requests_history') }}
          </RouterLink>
        </div>
      </div>
    </div>

    <!-- Modal Kamera -->
    <Teleport to="body">
      <div v-if="showCamera" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg w-full max-w-md p-4">
          <div class="text-lg font-semibold mb-2">{{ t('attendance.clock.take_selfie') }}</div>
          <video ref="videoEl" autoplay playsinline class="w-full rounded bg-black"
            style="max-height: 50vh; object-fit: cover;"></video>
          <div class="flex justify-end gap-2 mt-3">
            <button class="btn-secondary" @click="closeCamera">{{ t('common.cancel') }}</button>
            <button class="btn-primary" @click="captureSelfie">
              <CameraIcon class="w-4 h-4" /> {{ t('attendance.clock.capture') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Riwayat 7 hari terakhir -->
    <div v-if="recentDays.length" class="mt-6">
      <h2 class="text-lg font-semibold mb-2">{{ t('attendance.clock.last_7_days') }}</h2>
      <div class="card overflow-hidden">
        <div class="divide-y divide-gray-100">
          <div v-for="d in recentDays" :key="d.date"
            class="px-4 py-3 flex items-center gap-3 hover:bg-gray-50 cursor-pointer"
            @click="router.push('/attendance/my')">
            <div class="w-16 text-xs">
              <div class="font-semibold">{{ Number(d.date.slice(8, 10)) }}</div>
              <div class="text-gray-500">{{ fmtDateShort(d.date) }}</div>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded-full text-xs"
                  :class="statusBadgeClass(d.status_color)">
                  {{ d.status_label }}
                </span>
                <span v-if="d.attendance" class="text-xs text-gray-500 tabular-nums">
                  {{ fmtTime(d.attendance.clock_in_at) }} – {{ fmtTime(d.attendance.clock_out_at) }}
                </span>
              </div>
              <div v-if="d.attendance?.late_minutes" class="text-[11px] text-amber-700">
                {{ t('attendance.clock.late_minutes', { n: d.attendance.late_minutes }) }}
              </div>
              <div v-if="d.leave" class="text-[11px] text-blue-700">
                ✦ {{ d.leave.leave_name || d.leave.leave_code }}
              </div>
            </div>
            <ChevronRightIcon class="w-4 h-4 text-gray-400" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>