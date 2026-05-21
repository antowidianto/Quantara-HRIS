<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { fmtDate } from '@/composables/useResource'
import ChangeRequestModal from './ChangeRequestModal.vue'
import QuickLeaveModal from './QuickLeaveModal.vue'
import {
  ClockIcon, MapPinIcon, DocumentTextIcon, CalendarDaysIcon,
  ExclamationTriangleIcon, CheckCircleIcon, XCircleIcon,
  PencilSquareIcon, PlusIcon, ArrowTopRightOnSquareIcon,
  HeartIcon, NoSymbolIcon,
} from '@heroicons/vue/24/outline'

const toast = useToast()
const router = useRouter()

const today = new Date().toISOString().slice(0, 10)
const cursorMonth = ref(new Date().toISOString().slice(0, 7)) // YYYY-MM

const data = ref({ days: [], summary: null })
const loading = ref(false)
const selectedDay = ref(null)

// Modal pengajuan perubahan absensi (clock-in/out, status, lupa absen).
const showChangeModal = ref(false)
const changeModalDay = ref(null)

// Modal pengajuan cuti / izin sakit cepat dari kalender.
const showLeaveModal = ref(false)
const leaveModalDay  = ref(null)
const leaveCategory  = ref('') // SICK | PAID | ''

const monthLabel = computed(() => {
  const [y, m] = cursorMonth.value.split('-')
  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('id-ID', {
    month: 'long', year: 'numeric',
  })
})

const calendarCells = computed(() => {
  // Build a 6x7 grid starting from Monday for the current cursor month.
  const [y, m] = cursorMonth.value.split('-').map(Number)
  const firstDay = new Date(y, m - 1, 1)
  const lastDay = new Date(y, m, 0)
  // Monday = 1, Sunday = 7 (ISO). JS Date.getDay(): Sun=0..Sat=6
  const firstIdx = ((firstDay.getDay() + 6) % 7) // 0=Mon
  const cells = []

  // Leading blanks
  for (let i = 0; i < firstIdx; i++) cells.push(null)
  // Actual days
  const byDate = {}
  for (const d of data.value.days || []) byDate[d.date] = d
  for (let day = 1; day <= lastDay.getDate(); day++) {
    const date = `${y}-${String(m).padStart(2, '0')}-${String(day).padStart(2, '0')}`
    cells.push(byDate[date] || { date, status: 'NO_DATA', status_label: '—', status_color: 'gray' })
  }
  // Trailing blanks to complete the grid (max 42 cells)
  while (cells.length % 7 !== 0) cells.push(null)
  return cells
})

const dayHeaders = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min']

async function fetchData() {
  loading.value = true
  try {
    const [y, m] = cursorMonth.value.split('-').map(Number)
    const start = `${y}-${String(m).padStart(2, '0')}-01`
    const lastDay = new Date(y, m, 0).getDate()
    const end = `${y}-${String(m).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`
    const res = await api.get('/attendance/my', { params: { start_date: start, end_date: end } })
    data.value = res.data.data
    // If selected day is in the new range, refresh its reference.
    if (selectedDay.value) {
      const found = (data.value.days || []).find(d => d.date === selectedDay.value.date)
      selectedDay.value = found || null
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat data absensi')
  } finally {
    loading.value = false
  }
}

function shiftMonth(delta) {
  const [y, m] = cursorMonth.value.split('-').map(Number)
  const dt = new Date(y, m - 1 + delta, 1)
  cursorMonth.value = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}`
  selectedDay.value = null
  fetchData()
}

function selectDay(day) {
  if (! day) return
  selectedDay.value = day
}

// ─── Aksi cepat dari panel kanan ────────────────────────────────────
function openChangeRequest(day) {
  changeModalDay.value = day
  showChangeModal.value = true
}

function openLeaveModal(day, category = '') {
  leaveModalDay.value = day
  leaveCategory.value = category
  showLeaveModal.value = true
}

function onChangeSubmitted() {
  showChangeModal.value = false
  fetchData()
  toast.success('Pengajuan perubahan absensi berhasil dikirim')
}

function onLeaveSubmitted() {
  showLeaveModal.value = false
  fetchData()
  toast.success('Pengajuan cuti / izin berhasil dikirim')
}

// ─── Helpers tampilan ───────────────────────────────────────────────
const colorMap = {
  green: 'bg-green-100 text-green-800 border-green-300',
  amber: 'bg-amber-100 text-amber-800 border-amber-300',
  red:   'bg-red-100 text-red-800 border-red-300',
  blue:  'bg-blue-100 text-blue-800 border-blue-300',
  gray:  'bg-gray-100 text-gray-700 border-gray-300',
}

function dotColor(c) {
  return ({
    green: 'bg-green-500', amber: 'bg-amber-500', red: 'bg-red-500',
    blue: 'bg-blue-500', gray: 'bg-gray-400',
  })[c] || 'bg-gray-400'
}

function formatTime(dt) {
  if (! dt) return '-'
  return dt.slice(11, 16)
}

function formatHours(minutes) {
  if (! minutes) return '0 jam'
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (h && m) return `${h} jam ${m} menit`
  if (h) return `${h} jam`
  return `${m} menit`
}

const crStatusClass = {
  PENDING:   'bg-amber-50 text-amber-700 border-amber-200',
  APPROVED:  'bg-green-50 text-green-700 border-green-200',
  REJECTED:  'bg-red-50 text-red-700 border-red-200',
  CANCELLED: 'bg-gray-100 text-gray-600 border-gray-200',
}

const leaveStatusClass = {
  PENDING:  'bg-amber-50 text-amber-700',
  APPROVED: 'bg-green-50 text-green-700',
  REJECTED: 'bg-red-50 text-red-700',
  CANCELLED: 'bg-gray-100 text-gray-600',
}

const changeTypeLabel = {
  CLOCK_IN:  'Perbaiki Clock-In',
  CLOCK_OUT: 'Perbaiki Clock-Out',
  BOTH:      'Perbaiki Clock-In & Out',
  STATUS:    'Ubah Status',
  MISSED:    'Lupa Absen',
}

// Ringkasan situasi hari yang dipilih untuk menentukan tombol-tombol apa
// yang masuk akal ditawarkan (mis. ABSENT → tombol Sakit / Cuti).
const dayContext = computed(() => {
  const d = selectedDay.value
  if (! d) return null
  const cr = d.change_request
  const hasPendingChange = cr && cr.status === 'PENDING'
  const hasPendingLeave  = d.leave && d.leave.status === 'PENDING'
  const hasApprovedLeave = d.leave && d.leave.status === 'APPROVED'

  // Boleh ajukan cuti/izin jika belum ada cuti aktif untuk hari ini dan
  // tanggal masih di hari ini atau ke depan, atau lampau-tapi-ABSENT.
  const allowLeave = ! hasApprovedLeave && ! hasPendingLeave && (
    d.is_today || d.is_future || d.status === 'ABSENT' || d.status === 'NOT_CLOCKED_IN'
  )

  // Boleh ajukan perubahan absensi jika hari ini/lampau dan belum ada
  // change-request PENDING.
  const allowChange = ! hasPendingChange && ! d.is_future && d.status !== 'OFF' && d.status !== 'HOLIDAY'

  return {
    hasPendingChange,
    hasPendingLeave,
    hasApprovedLeave,
    allowLeave,
    allowChange,
    isAbsent: d.status === 'ABSENT',
  }
})

onMounted(fetchData)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-bold">Absensi Saya</h1>
        <p class="text-sm text-gray-500">Lihat absensi tanggal lampau, hari ini, dan jadwal mendatang.</p>
      </div>
      <div class="flex items-center gap-2">
        <button class="btn-secondary" @click="shiftMonth(-1)" :disabled="loading">‹ Bulan Lalu</button>
        <span class="font-semibold w-44 text-center">{{ monthLabel }}</span>
        <button class="btn-secondary" @click="shiftMonth(1)" :disabled="loading">Bulan Berikutnya ›</button>
      </div>
    </div>

    <!-- Summary cards -->
    <div v-if="data.summary" class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4">
      <div class="card p-3"><div class="text-xs text-gray-500">Hadir</div><div class="text-xl font-bold text-green-600">{{ data.summary.present }}</div></div>
      <div class="card p-3"><div class="text-xs text-gray-500">Terlambat</div><div class="text-xl font-bold text-amber-600">{{ data.summary.late }}</div></div>
      <div class="card p-3"><div class="text-xs text-gray-500">Tanpa Kabar</div><div class="text-xl font-bold text-red-600">{{ data.summary.absent }}</div></div>
      <div class="card p-3"><div class="text-xs text-gray-500">Cuti/Sakit</div><div class="text-xl font-bold text-blue-600">{{ data.summary.leave }}</div></div>
      <div class="card p-3"><div class="text-xs text-gray-500">Libur</div><div class="text-xl font-bold text-blue-500">{{ data.summary.holiday + data.summary.off }}</div></div>
      <div class="card p-3"><div class="text-xs text-gray-500">Total Telat (menit)</div><div class="text-xl font-bold text-amber-700">{{ data.summary.late_minutes }}</div></div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Calendar -->
      <div class="lg:col-span-2">
        <div class="card p-4">
          <div class="grid grid-cols-7 gap-1 mb-1 text-xs font-semibold text-gray-500 text-center">
            <div v-for="h in dayHeaders" :key="h">{{ h }}</div>
          </div>
          <div class="grid grid-cols-7 gap-1">
            <button
              v-for="(cell, idx) in calendarCells" :key="idx"
              :class="[
                'min-h-[78px] p-2 rounded-md border text-left transition-colors',
                cell ? colorMap[cell.status_color] || colorMap.gray : 'bg-transparent border-transparent cursor-default',
                cell && selectedDay && selectedDay.date === cell.date ? 'ring-2 ring-primary-500' : '',
                cell && cell.is_today ? 'border-primary-500 border-2' : '',
                !cell ? 'pointer-events-none' : 'hover:opacity-90 cursor-pointer'
              ]"
              :disabled="!cell"
              @click="selectDay(cell)"
            >
              <template v-if="cell">
                <div class="flex items-start justify-between text-xs">
                  <span class="font-semibold">{{ Number(cell.date.slice(8, 10)) }}</span>
                  <span v-if="cell.is_today" class="px-1 rounded bg-primary-600 text-white text-[10px]">Hari ini</span>
                </div>
                <div class="mt-1 flex items-center gap-1 text-[11px] leading-tight">
                  <span :class="['inline-block w-2 h-2 rounded-full', dotColor(cell.status_color)]"></span>
                  <span class="truncate">{{ cell.status_label }}</span>
                </div>
                <div v-if="cell.attendance" class="mt-0.5 text-[10px] text-gray-600">
                  {{ formatTime(cell.attendance.clock_in_at) }} – {{ formatTime(cell.attendance.clock_out_at) }}
                </div>
                <div v-if="cell.change_request" class="mt-0.5 text-[10px]">
                  <span :class="{
                    'text-amber-700': cell.change_request.status === 'PENDING',
                    'text-green-700': cell.change_request.status === 'APPROVED',
                    'text-red-700':   cell.change_request.status === 'REJECTED',
                    'text-gray-500':  cell.change_request.status === 'CANCELLED',
                  }">
                    ⚐ {{ cell.change_request.status }}
                  </span>
                </div>
                <div v-if="cell.leave" class="mt-0.5 text-[10px] truncate"
                  :class="{
                    'text-blue-700':  cell.leave.status === 'APPROVED',
                    'text-amber-700': cell.leave.status === 'PENDING',
                    'text-red-700':   cell.leave.status === 'REJECTED',
                  }">
                  ✦ {{ cell.leave.leave_name || cell.leave.leave_code }}
                </div>
              </template>
            </button>
          </div>
        </div>
      </div>

      <!-- Day detail panel -->
      <div class="card p-4">
        <div v-if="!selectedDay" class="text-center text-gray-400 py-12">
          Pilih tanggal pada kalender untuk melihat detail absensi.
        </div>

        <div v-else>
          <!-- Header -->
          <div class="flex items-center justify-between mb-3">
            <div>
              <h2 class="text-lg font-semibold">{{ fmtDate(selectedDay.date) }}</h2>
              <p class="text-xs text-gray-500">{{ selectedDay.day_name }}</p>
            </div>
            <span :class="['badge', colorMap[selectedDay.status_color] || colorMap.gray]">
              {{ selectedDay.status_label }}
            </span>
          </div>

          <!-- Banner ABSENT (alfa tanpa keterangan) -->
          <div v-if="dayContext?.isAbsent"
            class="bg-red-50 border border-red-200 rounded-md p-3 mb-3 text-sm text-red-800 flex items-start gap-2">
            <ExclamationTriangleIcon class="w-5 h-5 flex-shrink-0 mt-0.5" />
            <div>
              <div class="font-medium">Alfa / Tanpa Keterangan</div>
              <div class="text-xs mt-0.5">
                Belum ada catatan absensi atau cuti untuk hari ini. Anda bisa mengajukan
                cuti, izin sakit, atau perubahan absensi (jika lupa absen) di bawah ini.
              </div>
            </div>
          </div>

          <!-- Hari libur -->
          <div v-if="selectedDay.is_holiday" class="text-sm bg-blue-50 text-blue-800 rounded p-2 mb-3">
            🎉 {{ selectedDay.holiday_name }}
          </div>

          <!-- Jadwal shift -->
          <div v-if="selectedDay.shift" class="mb-3 border-l-2 border-primary-300 pl-3">
            <div class="text-xs uppercase tracking-wide text-gray-500 flex items-center gap-1">
              <CalendarDaysIcon class="w-3.5 h-3.5" /> Jadwal Shift
            </div>
            <div class="text-sm font-medium">{{ selectedDay.shift.name }}</div>
            <div class="text-xs text-gray-600">
              {{ selectedDay.shift.start_time?.slice(0, 5) }} – {{ selectedDay.shift.end_time?.slice(0, 5) }}
              <span v-if="selectedDay.shift.is_off" class="ml-1 text-gray-400">(Off-day)</span>
            </div>
          </div>

          <!-- Detail absensi (transaksi clock-in/out) -->
          <div v-if="selectedDay.attendance" class="mb-3">
            <div class="text-xs uppercase tracking-wide text-gray-500 flex items-center gap-1 mb-2">
              <ClockIcon class="w-3.5 h-3.5" /> Catatan Absensi
            </div>
            <div class="grid grid-cols-2 gap-3 text-sm">
              <div>
                <div class="text-xs text-gray-500">Clock-In</div>
                <div class="font-medium">{{ formatTime(selectedDay.attendance.clock_in_at) }}</div>
                <div v-if="selectedDay.attendance.clock_in_address" class="text-[11px] text-gray-500 flex items-start gap-1 mt-0.5">
                  <MapPinIcon class="w-3 h-3 mt-0.5 flex-shrink-0" />
                  <span class="line-clamp-2">{{ selectedDay.attendance.clock_in_address }}</span>
                </div>
              </div>
              <div>
                <div class="text-xs text-gray-500">Clock-Out</div>
                <div class="font-medium">{{ formatTime(selectedDay.attendance.clock_out_at) }}</div>
                <div v-if="selectedDay.attendance.clock_out_address" class="text-[11px] text-gray-500 flex items-start gap-1 mt-0.5">
                  <MapPinIcon class="w-3 h-3 mt-0.5 flex-shrink-0" />
                  <span class="line-clamp-2">{{ selectedDay.attendance.clock_out_address }}</span>
                </div>
              </div>
              <div>
                <div class="text-xs text-gray-500">Jam kerja</div>
                <div>{{ formatHours(selectedDay.attendance.work_minutes || 0) }}</div>
              </div>
              <div>
                <div class="text-xs text-gray-500">Telat / Pulang Cepat</div>
                <div>
                  <span v-if="selectedDay.attendance.late_minutes" class="text-amber-700">
                    +{{ selectedDay.attendance.late_minutes }} menit
                  </span>
                  <span v-else class="text-gray-400">-</span>
                  <span v-if="selectedDay.attendance.early_leave_minutes" class="text-amber-700 ml-1">
                    / pulang cepat {{ selectedDay.attendance.early_leave_minutes }} m
                  </span>
                </div>
              </div>
            </div>
            <div v-if="selectedDay.attendance.clock_in_notes" class="text-xs text-gray-600 italic mt-2">
              "{{ selectedDay.attendance.clock_in_notes }}"
            </div>
          </div>

          <!-- Detail Cuti / Izin Sakit -->
          <div v-if="selectedDay.leave"
            class="mb-3 border border-blue-200 rounded-md p-3 bg-blue-50/60">
            <div class="flex items-center justify-between">
              <div class="text-xs uppercase tracking-wide text-blue-700 font-medium flex items-center gap-1">
                <DocumentTextIcon class="w-3.5 h-3.5" />
                {{ selectedDay.leave.leave_name || selectedDay.leave.leave_code }}
              </div>
              <span :class="['px-2 py-0.5 rounded-full text-[10px]', leaveStatusClass[selectedDay.leave.status] || 'bg-gray-100 text-gray-600']">
                {{ selectedDay.leave.status }}
              </span>
            </div>
            <div class="text-xs text-gray-700 mt-2">
              <span class="text-gray-500">Periode:</span>
              {{ selectedDay.leave.start_date }} s/d {{ selectedDay.leave.end_date }}
              <span v-if="selectedDay.leave.is_half_day" class="ml-1 text-gray-500">(½ hari)</span>
            </div>
            <div v-if="selectedDay.leave.reason" class="text-xs text-gray-700 mt-1 italic">
              "{{ selectedDay.leave.reason }}"
            </div>
            <div v-if="selectedDay.leave.review_note" class="text-xs text-gray-700 mt-1">
              <strong>Catatan reviewer:</strong> {{ selectedDay.leave.review_note }}
            </div>
            <div class="mt-2 text-right">
              <RouterLink :to="`/leaves`" class="text-xs text-primary-600 hover:underline inline-flex items-center gap-1">
                Lihat di Daftar Cuti <ArrowTopRightOnSquareIcon class="w-3 h-3" />
              </RouterLink>
            </div>
          </div>

          <!-- Note generik (kalau bukan cuti) -->
          <div v-if="selectedDay.note && !selectedDay.leave" class="text-xs text-gray-600 italic mb-3">
            {{ selectedDay.note }}
          </div>

          <!-- Pengajuan perubahan absensi -->
          <div v-if="selectedDay.change_request"
            :class="['mb-3 border rounded-md p-3 text-sm', crStatusClass[selectedDay.change_request.status] || 'bg-gray-50 border-gray-200']">
            <div class="flex items-center justify-between">
              <div class="text-xs uppercase tracking-wide font-medium flex items-center gap-1">
                <PencilSquareIcon class="w-3.5 h-3.5" /> Pengajuan Perubahan
              </div>
              <span class="px-2 py-0.5 rounded-full bg-white/70 text-[10px] font-medium">
                {{ selectedDay.change_request.status }}
              </span>
            </div>
            <div class="text-xs mt-2">
              <span class="text-gray-500">Tipe:</span>
              {{ changeTypeLabel[selectedDay.change_request.change_type] || selectedDay.change_request.change_type }}
            </div>
            <div v-if="selectedDay.change_request.requested_clock_in" class="text-xs">
              <span class="text-gray-500">Diajukan In:</span>
              {{ formatTime(selectedDay.change_request.requested_clock_in) }}
            </div>
            <div v-if="selectedDay.change_request.requested_clock_out" class="text-xs">
              <span class="text-gray-500">Diajukan Out:</span>
              {{ formatTime(selectedDay.change_request.requested_clock_out) }}
            </div>
            <div v-if="selectedDay.change_request.requested_status" class="text-xs">
              <span class="text-gray-500">Status baru:</span> {{ selectedDay.change_request.requested_status }}
            </div>
            <div class="text-xs mt-1 italic">"{{ selectedDay.change_request.reason }}"</div>
            <div v-if="selectedDay.change_request.review_note" class="text-xs mt-1">
              <strong>Catatan reviewer:</strong> {{ selectedDay.change_request.review_note }}
            </div>
            <div class="mt-2 text-right">
              <RouterLink to="/attendance/my-changes"
                class="text-xs hover:underline inline-flex items-center gap-1">
                Lihat semua pengajuan saya <ArrowTopRightOnSquareIcon class="w-3 h-3" />
              </RouterLink>
            </div>
          </div>

          <!-- ─── Aksi cepat ─── -->
          <div class="border-t border-gray-200 pt-3 space-y-2">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-1">Aksi</div>

            <button
              v-if="dayContext?.allowChange"
              class="btn-secondary w-full justify-center"
              @click="openChangeRequest(selectedDay)"
            >
              <PencilSquareIcon class="w-4 h-4" /> Ajukan Perubahan Absensi
            </button>
            <div v-else-if="dayContext?.hasPendingChange" class="text-xs text-amber-700 italic px-1">
              Pengajuan perubahan absensi sedang menunggu review.
            </div>

            <button
              v-if="dayContext?.allowLeave"
              class="btn-secondary w-full justify-center"
              @click="openLeaveModal(selectedDay, 'SICK')"
            >
              <HeartIcon class="w-4 h-4 text-red-500" /> Ajukan Izin Sakit
            </button>

            <button
              v-if="dayContext?.allowLeave"
              class="btn-secondary w-full justify-center"
              @click="openLeaveModal(selectedDay, 'PAID')"
            >
              <CalendarDaysIcon class="w-4 h-4 text-blue-500" /> Ajukan Cuti
            </button>

            <button
              v-if="dayContext?.allowLeave"
              class="btn-secondary w-full justify-center"
              @click="openLeaveModal(selectedDay, '')"
            >
              <PlusIcon class="w-4 h-4" /> Pengajuan Cuti / Izin Lainnya
            </button>

            <div v-if="dayContext?.hasPendingLeave"
              class="text-xs text-amber-700 italic px-1">
              Pengajuan cuti/izin untuk hari ini sedang menunggu review.
            </div>
            <div v-if="dayContext?.hasApprovedLeave"
              class="text-xs text-green-700 italic px-1">
              Cuti/izin sudah disetujui untuk hari ini.
            </div>

            <RouterLink to="/attendance/my-changes"
              class="btn-secondary w-full justify-center mt-2">
              <DocumentTextIcon class="w-4 h-4" /> Riwayat Pengajuan Saya
            </RouterLink>
          </div>
        </div>
      </div>
    </div>

    <!-- Modals -->
    <ChangeRequestModal
      v-if="showChangeModal"
      :day="changeModalDay"
      @close="showChangeModal = false"
      @submitted="onChangeSubmitted"
    />
    <QuickLeaveModal
      v-if="showLeaveModal"
      :day="leaveModalDay"
      :prefer-category="leaveCategory"
      @close="showLeaveModal = false"
      @submitted="onLeaveSubmitted"
    />
  </div>
</template>

<style scoped>
.badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 9999px;
  font-size: 11px;
  font-weight: 500;
  border: 1px solid transparent;
}
</style>