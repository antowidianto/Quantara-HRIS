<script setup>
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import {
  UserCircleIcon, IdentificationIcon, BuildingOfficeIcon, BanknotesIcon,
  PhoneIcon, ShieldCheckIcon, CalendarDaysIcon, AcademicCapIcon,
  MapPinIcon, ClockIcon, ChartBarIcon, ArrowTopRightOnSquareIcon,
  KeyIcon, EnvelopeIcon, BoltIcon, TrophyIcon, CheckBadgeIcon,
  DocumentTextIcon, BriefcaseIcon, HomeIcon, LockClosedIcon,
  CameraIcon, TrashIcon,
} from '@heroicons/vue/24/outline'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { useI18n } from '@/locales'

/**
 * Halaman Profil Saya — agregasi semua data karyawan yang relevan untuk
 * user. Sumber data:
 *   - /auth/me              → user, role, permissions, branch, company
 *   - /employees/{id}       → data lengkap karyawan
 *   - /attendance/my        → ringkasan absensi bulan berjalan
 *   - /leave-balances/{id}  → saldo cuti per jenis
 *   - /payslips/my          → 3 slip terakhir
 *   - /performance/my       → goal aktif + review terbaru
 */

const auth = useAuthStore()
const toast = useToast()
const { t, locale } = useI18n()

// ─── Reactive state ────────────────────────────────────────────────
const employee = ref(null)
const attendance = ref(null)
const leaveBalance = ref([])
const payslips = ref([])
const performance = ref({ goals: [], reviews: [] })
const loadingDetail = ref(false)
const showSensitive = ref(false)
const activeTab = ref('personal')

// ─── Avatar upload state ───────────────────────────────────────────
const fileInput = ref(null)
const uploadingAvatar = ref(false)
const avatarUrl = computed(() =>
  auth.employee?.avatar_url || auth.user?.avatar_url || null,
)

// Tabs reactive ke locale supaya label ikut translate.
const tabs = computed(() => {
  void locale.value
  return [
    { id: 'personal',  label: t('profile.tab_personal'),  icon: IdentificationIcon },
    { id: 'job',       label: t('profile.tab_job'),       icon: BriefcaseIcon },
    { id: 'finance',   label: t('profile.tab_finance'),   icon: BanknotesIcon },
    { id: 'emergency', label: t('profile.tab_emergency'), icon: PhoneIcon },
    { id: 'stats',     label: t('profile.tab_stats'),     icon: ChartBarIcon },
  ]
})

// ─── Fetch ─────────────────────────────────────────────────────────
async function fetchData() {
  loadingDetail.value = true
  const empId = auth.employee?.id
  const tasks = []

  if (empId) {
    tasks.push(
      api.get(`/employees/${empId}`).then(r => { employee.value = r.data?.data || null }).catch(() => {}),
      api.get('/attendance/my').then(r => { attendance.value = r.data?.data || null }).catch(() => {}),
      api.get(`/leave-balances/${empId}`).then(r => { leaveBalance.value = r.data?.data || [] }).catch(() => {}),
      api.get('/payslips/my', { params: { per_page: 3 } }).then(r => { payslips.value = r.data?.data || [] }).catch(() => {}),
      api.get('/performance/my').then(r => { performance.value = r.data?.data || { goals: [], reviews: [] } }).catch(() => {}),
    )
  }
  await Promise.all(tasks)
  loadingDetail.value = false
}

onMounted(fetchData)

// ─── Computed shortcuts ────────────────────────────────────────────
const u = computed(() => auth.user)
const e = computed(() => employee.value || auth.employee)
const c = computed(() => auth.company)
const role = computed(() => auth.role)
const branch = computed(() => auth.branch)

const fullAddress = computed(() => {
  if (! e.value) return null
  const parts = [e.value.address, e.value.city, e.value.province, e.value.postal_code].filter(Boolean)
  return parts.join(', ')
})

const ktpAddress = computed(() => {
  if (! e.value) return null
  const parts = [e.value.ktp_address, e.value.ktp_city, e.value.ktp_province, e.value.ktp_postal_code].filter(Boolean)
  return parts.join(', ')
})

// Length of service — locale-aware: "2 years 3 months" vs "2 tahun 3 bulan".
const lengthOfService = computed(() => {
  if (! e.value?.join_date) return null
  const start = new Date(e.value.join_date)
  const end = e.value.resign_date ? new Date(e.value.resign_date) : new Date()
  const months = (end.getFullYear() - start.getFullYear()) * 12 + (end.getMonth() - start.getMonth())
  const isEn = locale.value === 'en'
  if (months < 12) return isEn ? `${months} months` : `${months} bulan`
  const years = Math.floor(months / 12)
  const remMonths = months % 12
  if (isEn) {
    return remMonths > 0 ? `${years} years ${remMonths} months` : `${years} years`
  }
  return remMonths > 0 ? `${years} tahun ${remMonths} bulan` : `${years} tahun`
})

const employmentBadge = computed(() => {
  const s = e.value?.employment_status
  return ({
    PKWTT: 'bg-emerald-100 text-emerald-700',
    PKWT: 'bg-blue-100 text-blue-700',
    PROBATION: 'bg-amber-100 text-amber-700',
    HARIAN: 'bg-gray-100 text-gray-700',
    MAGANG: 'bg-purple-100 text-purple-700',
    OUTSOURCING: 'bg-pink-100 text-pink-700',
  })[s] || 'bg-gray-100 text-gray-700'
})

const totalLeaveQuota = computed(() => leaveBalance.value.reduce((s, b) => s + Number(b.entitled || 0), 0))
const totalLeaveUsed = computed(() => leaveBalance.value.reduce((s, b) => s + Number(b.taken || 0), 0))
const totalLeaveRemaining = computed(() => totalLeaveQuota.value - totalLeaveUsed.value)

const latestPayslip = computed(() => payslips.value[0] || null)
const activeGoals = computed(() => (performance.value.goals || []).filter(g => g.status !== 'CANCELLED' && g.status !== 'COMPLETED'))
const latestReview = computed(() => (performance.value.reviews || [])[0] || null)

// ─── Mask helpers untuk data sensitif ──────────────────────────────
function mask(value, keepLast = 4) {
  if (! value) return '—'
  const s = String(value)
  if (s.length <= keepLast) return '•'.repeat(s.length)
  return '•'.repeat(s.length - keepLast) + s.slice(-keepLast)
}

function display(value, sensitive = false) {
  if (! value) return '—'
  if (sensitive && ! showSensitive.value) return mask(value)
  return value
}

// Label PTKP/marital/gender — punya konteks pajak ID, jadi tetap pakai
// terminologi Indonesia walau locale EN (analog dengan istilah "K-1 visa"
// yang tidak diterjemahkan di sistem imigrasi US).
const ptkpLabel = (s) => ({
  TK0: 'TK/0 (Belum kawin, tanpa tanggungan)',
  TK1: 'TK/1 (Belum kawin, 1 tanggungan)',
  TK2: 'TK/2 (Belum kawin, 2 tanggungan)',
  TK3: 'TK/3 (Belum kawin, 3 tanggungan)',
  K0:  'K/0 (Kawin, tanpa tanggungan)',
  K1:  'K/1 (Kawin, 1 tanggungan)',
  K2:  'K/2 (Kawin, 2 tanggungan)',
  K3:  'K/3 (Kawin, 3 tanggungan)',
}[s] || s || '—')

const educationLabel = (s) => ({
  SD: 'SD', SMP: 'SMP', SMA: 'SMA', SMK: 'SMK',
  D1: 'D1', D2: 'D2', D3: 'D3', D4: 'D4',
  S1: 'S1 (Sarjana)', S2: 'S2 (Magister)', S3: 'S3 (Doktor)',
}[s] || s || '—')

const maritalLabel = (s) => {
  if (locale.value === 'en') {
    return ({
      SINGLE: 'Single',
      MARRIED: 'Married',
      DIVORCED: 'Divorced',
      WIDOWED: 'Widowed',
    })[s] || s || '—'
  }
  return ({
    SINGLE: 'Belum menikah',
    MARRIED: 'Menikah',
    DIVORCED: 'Cerai hidup',
    WIDOWED: 'Cerai mati',
  })[s] || s || '—'
}

const genderLabel = (s) => {
  if (locale.value === 'en') return ({ M: 'Male', F: 'Female' })[s] || '—'
  return ({ M: 'Laki-laki', F: 'Perempuan' })[s] || '—'
}

function copy(text, label) {
  if (! text) return
  navigator.clipboard?.writeText(String(text))
    .then(() => toast.success(`${label} ✓`))
    .catch(() => toast.error(t('common.error')))
}

// ─── Avatar handlers ───────────────────────────────────────────────
function pickPhoto() {
  if (! fileInput.value) return
  fileInput.value.value = ''
  fileInput.value.click()
}

async function handleFileChange(ev) {
  const file = ev.target.files?.[0]
  if (! file) return

  const allowed = ['image/jpeg', 'image/png', 'image/webp']
  if (! allowed.includes(file.type)) {
    toast.error(t('profile.avatar_bad_type'))
    return
  }
  if (file.size > 2 * 1024 * 1024) {
    toast.error(t('profile.avatar_too_large'))
    return
  }

  uploadingAvatar.value = true
  try {
    const form = new FormData()
    form.append('file', file)
    const res = await api.post('/auth/me/avatar', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    const d = res.data?.data || {}
    auth.setAvatar(d.avatar_path, d.avatar_url)
    if (employee.value) {
      employee.value = { ...employee.value, avatar_path: d.avatar_path }
    }
    toast.success(t('profile.avatar_uploaded'))
  } catch (err) {
    toast.error(err.response?.data?.message || t('common.error'))
  } finally {
    uploadingAvatar.value = false
  }
}

async function deletePhoto() {
  const ok = await confirmDialog({
    title:   t('profile.delete_avatar_confirm_title'),
    message: t('profile.delete_avatar_confirm_message'),
    okText:  t('common.delete'),
    variant: 'danger',
  })
  if (! ok) return

  uploadingAvatar.value = true
  try {
    await api.delete('/auth/me/avatar')
    auth.setAvatar(null, null)
    if (employee.value) {
      employee.value = { ...employee.value, avatar_path: null }
    }
    toast.success(t('profile.avatar_deleted'))
  } catch (err) {
    toast.error(err.response?.data?.message || t('common.error'))
  } finally {
    uploadingAvatar.value = false
  }
}
</script>

<template>
  <div class="max-w-8xl space-y-4">
    <!-- ─── Header card ────────────────────────────────────────────── -->
    <div class="card overflow-hidden">
      <div class="bg-gradient-to-br from-primary-600 to-primary-800 text-white px-6 py-6">
        <div class="flex items-start gap-5 flex-wrap">
          <!-- Avatar dengan upload overlay -->
          <div class="relative group">
            <button type="button" class="block focus:outline-none focus:ring-4 focus:ring-white/50 rounded-full"
              :title="avatarUrl ? t('profile.avatar_change') : t('profile.avatar_upload')"
              :disabled="uploadingAvatar" @click="pickPhoto">
              <img v-if="avatarUrl" :src="avatarUrl" :alt="u?.name || e?.full_name"
                class="w-24 h-24 rounded-full object-cover border-4 border-white/30 bg-white"
                @error="(ev) => { ev.target.style.display = 'none' }" />
              <div v-else
                class="w-24 h-24 rounded-full bg-white/20 backdrop-blur flex items-center justify-center text-4xl font-bold border-4 border-white/30">
                {{ (u?.name || e?.full_name || '?').charAt(0).toUpperCase() }}
              </div>
              <div class="absolute inset-0 rounded-full bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center pointer-events-none">
                <CameraIcon class="w-8 h-8 text-white" />
              </div>
              <div v-if="uploadingAvatar"
                class="absolute inset-0 rounded-full bg-black/50 flex items-center justify-center">
                <div class="w-6 h-6 border-2 border-white/30 border-t-white rounded-full animate-spin"></div>
              </div>
            </button>
            <button v-if="avatarUrl && ! uploadingAvatar" type="button"
              class="absolute -bottom-1 -right-1 w-7 h-7 rounded-full bg-red-500 hover:bg-red-600 text-white flex items-center justify-center shadow-md border-2 border-white/30"
              :title="t('profile.avatar_delete')" @click.stop="deletePhoto">
              <TrashIcon class="w-3.5 h-3.5" />
            </button>
            <input ref="fileInput" type="file" class="hidden"
              accept="image/jpeg,image/png,image/webp"
              @change="handleFileChange" />
          </div>
          <div class="flex-1 min-w-0">
            <h1 class="text-3xl font-bold">{{ e?.full_name || u?.name }}</h1>
            <div class="text-white/90 mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm">
              <span v-if="e?.employee_number" class="font-mono">{{ e.employee_number }}</span>
              <span v-if="e?.position_name">· {{ e.position_name }}</span>
              <span v-if="e?.department_name">· {{ e.department_name }}</span>
            </div>
            <div class="flex flex-wrap gap-2 mt-3">
              <span v-if="e?.employment_status"
                class="px-2 py-0.5 rounded text-xs font-medium" :class="employmentBadge">
                {{ e.employment_status }}
              </span>
              <span v-if="e?.is_active"
                class="px-2 py-0.5 rounded text-xs bg-emerald-500/20 text-emerald-50 border border-emerald-300/30">
                {{ t('common.yes_active') }}
              </span>
              <span v-else
                class="px-2 py-0.5 rounded text-xs bg-red-500/20 text-red-50 border border-red-300/30">
                {{ t('common.no_inactive') }}
              </span>
              <span v-if="role"
                class="px-2 py-0.5 rounded text-xs bg-white/15 text-white border border-white/30 inline-flex items-center gap-1">
                <ShieldCheckIcon class="w-3.5 h-3.5" /> {{ role.name }}
              </span>
              <span v-if="lengthOfService"
                class="px-2 py-0.5 rounded text-xs bg-white/15 text-white border border-white/30 inline-flex items-center gap-1">
                <ClockIcon class="w-3.5 h-3.5" /> {{ lengthOfService }} {{ t('profile.length_of_service') }}
              </span>
            </div>
          </div>
          <div class="flex flex-col items-end gap-2">
            <RouterLink to="/change-password"
              class="text-xs bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded inline-flex items-center gap-1">
              <KeyIcon class="w-3.5 h-3.5" /> {{ t('profile.head_quick_change_password') }}
            </RouterLink>
            <span class="text-xs bg-white/15 px-3 py-1 rounded inline-flex items-center gap-1">
              <ShieldCheckIcon class="w-3.5 h-3.5" />
              2FA: {{ u?.twofa_enabled ? t('common.yes_active') : t('common.no_inactive') }}
            </span>
          </div>
        </div>
      </div>

      <!-- Quick contact bar -->
      <div class="card-body grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
        <div class="flex items-start gap-2">
          <EnvelopeIcon class="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" />
          <div class="min-w-0">
            <div class="text-xs text-gray-500">{{ t('auth.email') }}</div>
            <div class="truncate">{{ e?.email || u?.email || '—' }}</div>
          </div>
        </div>
        <div class="flex items-start gap-2">
          <PhoneIcon class="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" />
          <div class="min-w-0">
            <div class="text-xs text-gray-500">{{ t('profile.contact_phone') }}</div>
            <div>{{ e?.phone || '—' }}</div>
          </div>
        </div>
        <div class="flex items-start gap-2">
          <BuildingOfficeIcon class="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" />
          <div class="min-w-0">
            <div class="text-xs text-gray-500">{{ t('profile.branch_company') }}</div>
            <div class="truncate">{{ branch?.name || '—' }} · {{ c?.name || '—' }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── Tabs ─────────────────────────────────────────────────── -->
    <div class="card">
      <div class="border-b border-gray-200 overflow-x-auto">
        <nav class="flex gap-1 px-2 min-w-max">
          <button v-for="tab in tabs" :key="tab.id"
            class="px-4 py-3 text-sm font-medium border-b-2 -mb-px inline-flex items-center gap-1.5 transition-colors"
            :class="activeTab === tab.id
              ? 'text-primary-600 border-primary-600'
              : 'text-gray-600 border-transparent hover:text-primary-600 hover:border-primary-300'"
            @click="activeTab = tab.id">
            <component :is="tab.icon" class="w-4 h-4" />
            {{ tab.label }}
          </button>
        </nav>
      </div>

      <div v-if="loadingDetail" class="p-12 text-center text-gray-400">{{ t('common.loading') }}</div>

      <!-- ─── Tab: Data Pribadi ──────────────────────────────────── -->
      <div v-else-if="activeTab === 'personal'" class="card-body space-y-5">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold text-gray-700 inline-flex items-center gap-2">
            <IdentificationIcon class="w-5 h-5 text-primary-600" />
            {{ t('profile.tab_personal') }}
          </h2>
          <button class="text-xs text-primary-600 hover:underline inline-flex items-center gap-1"
            @click="showSensitive = ! showSensitive">
            <LockClosedIcon class="w-3.5 h-3.5" />
            {{ showSensitive ? t('profile.hide_sensitive') : t('profile.show_sensitive') }}
          </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 text-sm">
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.full_name') }}</div>
            <div class="font-medium">{{ e?.full_name || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.gender') }}</div>
            <div>{{ genderLabel(e?.gender) }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.pob_dob') }}</div>
            <div>{{ e?.place_of_birth || '—' }}, {{ e?.date_of_birth ? fmtDate(e.date_of_birth) : '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.marital_status') }}</div>
            <div>{{ maritalLabel(e?.marital_status) }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.religion') }}</div>
            <div>{{ e?.religion || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.blood_type') }}</div>
            <div>{{ e?.blood_type || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.education') }}</div>
            <div class="inline-flex items-center gap-1">
              <AcademicCapIcon v-if="e?.education_level" class="w-4 h-4 text-gray-400" />
              {{ educationLabel(e?.education_level) }}
            </div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.citizenship') }}</div>
            <div>{{ e?.citizenship || '—' }}<span v-if="e?.ethnicity" class="text-gray-500 ml-1">({{ e.ethnicity }})</span></div>
          </div>
          <div class="md:col-span-2">
            <div class="text-xs text-gray-500">{{ t('profile.nik') }}</div>
            <div class="font-mono inline-flex items-center gap-2">
              {{ display(e?.nik, true) }}
              <button v-if="e?.nik && showSensitive" class="text-xs text-primary-600 hover:underline"
                @click="copy(e.nik, 'NIK')">{{ t('common.show') }}</button>
            </div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.kk_number') }}</div>
            <div class="font-mono">{{ display(e?.kk_number, true) }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.marital_certificate_no') }}</div>
            <div>{{ e?.marital_certificate_no || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.father_name') }}</div>
            <div>{{ e?.father_name || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.mother_name') }}</div>
            <div>{{ e?.mother_name || '—' }}</div>
          </div>
        </div>

        <div class="border-t border-gray-100 pt-4">
          <h3 class="font-medium text-sm text-gray-700 mb-3 inline-flex items-center gap-2">
            <HomeIcon class="w-4 h-4 text-gray-400" />
            {{ t('profile.address_domicile') }}
          </h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div>
              <div class="text-xs text-gray-500">{{ t('profile.address_domicile') }}</div>
              <div>{{ fullAddress || '—' }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('profile.address_ktp') }}</div>
              <div>{{ ktpAddress || '—' }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ─── Tab: Pekerjaan ─────────────────────────────────────── -->
      <div v-else-if="activeTab === 'job'" class="card-body space-y-5">
        <h2 class="font-semibold text-gray-700 inline-flex items-center gap-2">
          <BriefcaseIcon class="w-5 h-5 text-primary-600" />
          {{ t('profile.tab_job') }}
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 text-sm">
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.employee_number') }}</div>
            <div class="font-mono font-medium">{{ e?.employee_number || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.employment_status') }}</div>
            <div>
              <span class="px-2 py-0.5 rounded text-xs font-medium" :class="employmentBadge">
                {{ e?.employment_status || '—' }}
              </span>
              <span v-if="e?.employment_type" class="ml-2 text-gray-500">{{ e.employment_type }}</span>
            </div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.join_date') }}</div>
            <div>{{ e?.join_date ? fmtDate(e.join_date) : '—' }}<span v-if="lengthOfService" class="text-gray-500 text-xs ml-1">({{ lengthOfService }})</span></div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.probation_or_contract') }}</div>
            <div>
              <span v-if="e?.probation_end_date">Probation: {{ fmtDate(e.probation_end_date) }}</span>
              <span v-else-if="e?.contract_end_date">{{ t('profile.probation_or_contract').split('/')[1].trim() }}: {{ fmtDate(e.contract_end_date) }}</span>
              <span v-else>—</span>
            </div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.position') }}</div>
            <div>{{ e?.position_name || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.department') }}</div>
            <div>{{ e?.department_name || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.branch') }}</div>
            <div>{{ e?.branch_name || branch?.name || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.company') }}</div>
            <div>{{ c?.name || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.role') }}</div>
            <div class="inline-flex items-center gap-1">
              <ShieldCheckIcon class="w-4 h-4 text-gray-400" />
              {{ role?.name }}
            </div>
          </div>
          <div v-if="e?.basic_salary !== undefined">
            <div class="text-xs text-gray-500">{{ t('profile.basic_salary') }}</div>
            <div class="font-medium">{{ display(e.basic_salary ? fmtCurrency(e.basic_salary) : '—', true) }}</div>
          </div>
        </div>
      </div>

      <!-- ─── Tab: Bank & Pajak ──────────────────────────────────── -->
      <div v-else-if="activeTab === 'finance'" class="card-body space-y-5">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold text-gray-700 inline-flex items-center gap-2">
            <BanknotesIcon class="w-5 h-5 text-primary-600" />
            {{ t('profile.tab_finance') }}
          </h2>
          <button class="text-xs text-primary-600 hover:underline inline-flex items-center gap-1"
            @click="showSensitive = ! showSensitive">
            <LockClosedIcon class="w-3.5 h-3.5" />
            {{ showSensitive ? t('profile.hide_sensitive') : t('profile.show_sensitive') }}
          </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 text-sm">
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.bank') }}</div>
            <div>{{ e?.bank_name || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.account_number') }}</div>
            <div class="font-mono inline-flex items-center gap-2">
              {{ display(e?.bank_account, true) }}
              <button v-if="e?.bank_account && showSensitive" class="text-xs text-primary-600 hover:underline"
                @click="copy(e.bank_account, t('profile.account_number'))">{{ t('common.show') }}</button>
            </div>
          </div>
          <div class="md:col-span-2">
            <div class="text-xs text-gray-500">{{ t('profile.account_holder') }}</div>
            <div>{{ e?.bank_account_holder || '—' }}</div>
          </div>

          <div>
            <div class="text-xs text-gray-500">{{ t('profile.npwp') }}</div>
            <div class="font-mono inline-flex items-center gap-2">
              {{ display(e?.npwp, true) }}
              <button v-if="e?.npwp && showSensitive" class="text-xs text-primary-600 hover:underline"
                @click="copy(e.npwp, 'NPWP')">{{ t('common.show') }}</button>
            </div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.ptkp_status') }}</div>
            <div>{{ ptkpLabel(e?.ptkp_status) }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.pph21_method') }}</div>
            <div>{{ e?.pph21_method || 'TER' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.tax_method') }}</div>
            <div>{{ e?.tax_method || 'GROSS' }}</div>
          </div>

          <div class="md:col-span-2 border-t border-gray-100 pt-3">
            <h3 class="font-medium text-sm text-gray-700 mb-2">BPJS</h3>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.bpjs_kes') }}</div>
            <div class="font-mono">{{ display(e?.bpjs_kesehatan_no, true) }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.bpjs_tk') }}</div>
            <div class="font-mono">{{ display(e?.bpjs_tk_no, true) }}</div>
          </div>
        </div>
      </div>

      <!-- ─── Tab: Kontak Darurat ────────────────────────────────── -->
      <div v-else-if="activeTab === 'emergency'" class="card-body space-y-5">
        <h2 class="font-semibold text-gray-700 inline-flex items-center gap-2">
          <PhoneIcon class="w-5 h-5 text-primary-600" />
          {{ t('profile.tab_emergency') }}
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 text-sm">
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.emergency_name') }}</div>
            <div class="font-medium">{{ e?.emergency_contact_name || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.emergency_phone') }}</div>
            <div>{{ e?.emergency_contact_phone || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('profile.emergency_rel') }}</div>
            <div>{{ e?.emergency_contact_rel || '—' }}</div>
          </div>
        </div>
        <div class="text-xs text-gray-500 italic">
          {{ t('profile.emergency_note') }}
        </div>
      </div>

      <!-- ─── Tab: Aktivitas / Statistik ─────────────────────────── -->
      <div v-else-if="activeTab === 'stats'" class="card-body space-y-5">
        <h2 class="font-semibold text-gray-700 inline-flex items-center gap-2">
          <ChartBarIcon class="w-5 h-5 text-primary-600" />
          {{ t('profile.activity_summary') }}
        </h2>

        <!-- Attendance summary bulan ini -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <div class="rounded border border-emerald-200 bg-emerald-50 p-3">
            <div class="text-xs text-emerald-700 inline-flex items-center gap-1">
              <CheckBadgeIcon class="w-3.5 h-3.5" /> {{ t('attendance.present') }}
            </div>
            <div class="text-2xl font-bold text-emerald-900 mt-1">
              {{ attendance?.summary?.present ?? '—' }}
            </div>
            <div class="text-xs text-emerald-700">{{ t('attendance.days_unit') }} ({{ attendance?.start_date?.slice(0,7) || '—' }})</div>
          </div>
          <div class="rounded border border-amber-200 bg-amber-50 p-3">
            <div class="text-xs text-amber-700 inline-flex items-center gap-1">
              <ClockIcon class="w-3.5 h-3.5" /> {{ t('attendance.late') }}
            </div>
            <div class="text-2xl font-bold text-amber-900 mt-1">
              {{ attendance?.summary?.late ?? '—' }}
            </div>
            <div class="text-xs text-amber-700">{{ t('attendance.late_total', { minutes: attendance?.summary?.late_minutes || 0 }) }}</div>
          </div>
          <div class="rounded border border-blue-200 bg-blue-50 p-3">
            <div class="text-xs text-blue-700 inline-flex items-center gap-1">
              <CalendarDaysIcon class="w-3.5 h-3.5" /> {{ t('attendance.leave') }}
            </div>
            <div class="text-2xl font-bold text-blue-900 mt-1">
              {{ attendance?.summary?.leave ?? '—' }}
            </div>
            <div class="text-xs text-blue-700">{{ t('attendance.days_this_month') }}</div>
          </div>
          <div class="rounded border border-red-200 bg-red-50 p-3">
            <div class="text-xs text-red-700 inline-flex items-center gap-1">
              <BoltIcon class="w-3.5 h-3.5" /> {{ t('attendance.absent') }}
            </div>
            <div class="text-2xl font-bold text-red-900 mt-1">
              {{ attendance?.summary?.absent ?? '—' }}
            </div>
            <div class="text-xs text-red-700">{{ t('attendance.days_this_month') }}</div>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <!-- Saldo cuti -->
          <div class="rounded border border-gray-200">
            <div class="px-4 py-2 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
              <h3 class="font-medium text-sm inline-flex items-center gap-2">
                <CalendarDaysIcon class="w-4 h-4 text-blue-600" /> {{ t('leave.balance') }}
              </h3>
              <RouterLink to="/leaves/balance" class="text-xs text-primary-600 hover:underline inline-flex items-center gap-1">
                {{ t('common.detail') }} <ArrowTopRightOnSquareIcon class="w-3 h-3" />
              </RouterLink>
            </div>
            <div v-if="leaveBalance.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">
              {{ t('profile.no_balance') }}
            </div>
            <div v-else class="divide-y divide-gray-100">
              <div v-for="b in leaveBalance" :key="b.leave_type_id" class="px-4 py-2 flex items-center justify-between text-sm">
                <span>{{ b.leave_name || b.leave_code }}</span>
                <span class="text-xs">
                  <span class="font-bold text-emerald-700">{{ Number(b.entitled || 0) - Number(b.taken || 0) }}</span>
                  <span class="text-gray-500"> / {{ b.entitled || 0 }} {{ t('leave.days') }}</span>
                </span>
              </div>
              <div class="px-4 py-2 bg-gray-50 text-sm flex items-center justify-between font-medium">
                <span>{{ t('profile.leave_remaining') }}</span>
                <span class="text-emerald-700">{{ totalLeaveRemaining }} / {{ totalLeaveQuota }} {{ t('leave.days') }}</span>
              </div>
            </div>
          </div>

          <!-- Slip gaji terakhir -->
          <div class="rounded border border-gray-200">
            <div class="px-4 py-2 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
              <h3 class="font-medium text-sm inline-flex items-center gap-2">
                <DocumentTextIcon class="w-4 h-4 text-emerald-600" /> {{ t('profile.latest_payslip') }}
              </h3>
              <RouterLink to="/payslips/my" class="text-xs text-primary-600 hover:underline inline-flex items-center gap-1">
                {{ t('profile.all_payslips') }} <ArrowTopRightOnSquareIcon class="w-3 h-3" />
              </RouterLink>
            </div>
            <div v-if="payslips.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">
              {{ t('profile.no_payslip') }}
            </div>
            <div v-else>
              <RouterLink v-if="latestPayslip" :to="`/payslips/${latestPayslip.id}`"
                class="block px-4 py-3 hover:bg-gray-50 border-b border-gray-100">
                <div class="flex items-center justify-between">
                  <div>
                    <div class="font-mono text-xs text-gray-500">{{ latestPayslip.slip_number }}</div>
                    <div class="font-medium text-sm">{{ t('profile.take_home_pay') }}</div>
                  </div>
                  <div class="text-emerald-700 font-bold">
                    {{ display(fmtCurrency(latestPayslip.take_home_pay), true) }}
                  </div>
                </div>
              </RouterLink>
              <div class="divide-y divide-gray-100">
                <RouterLink v-for="p in payslips.slice(1)" :key="p.id" :to="`/payslips/${p.id}`"
                  class="block px-4 py-2 hover:bg-gray-50 text-sm flex items-center justify-between">
                  <span class="font-mono text-xs text-gray-500">{{ p.slip_number }}</span>
                  <span class="text-gray-700">{{ display(fmtCurrency(p.take_home_pay), true) }}</span>
                </RouterLink>
              </div>
            </div>
          </div>

          <!-- Performance -->
          <div class="rounded border border-gray-200 lg:col-span-2">
            <div class="px-4 py-2 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
              <h3 class="font-medium text-sm inline-flex items-center gap-2">
                <TrophyIcon class="w-4 h-4 text-amber-600" /> {{ t('profile.performance_section') }}
              </h3>
              <RouterLink to="/performance/my" class="text-xs text-primary-600 hover:underline inline-flex items-center gap-1">
                {{ t('common.detail') }} <ArrowTopRightOnSquareIcon class="w-3 h-3" />
              </RouterLink>
            </div>
            <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
              <div>
                <div class="text-xs text-gray-500 mb-1">{{ t('profile.active_goals') }}</div>
                <div v-if="activeGoals.length === 0" class="text-gray-400">{{ t('profile.no_active_goals') }}</div>
                <ul v-else class="space-y-1">
                  <li v-for="g in activeGoals.slice(0, 3)" :key="g.id" class="flex items-center justify-between gap-2">
                    <span class="truncate">{{ g.title }}</span>
                    <span class="text-xs text-gray-500 flex-shrink-0">{{ g.progress_percent }}%</span>
                  </li>
                </ul>
                <div v-if="activeGoals.length > 3" class="text-xs text-gray-500 mt-1">
                  {{ t('profile.other_goals', { n: activeGoals.length - 3 }) }}
                </div>
              </div>
              <div>
                <div class="text-xs text-gray-500 mb-1">{{ t('profile.latest_review') }}</div>
                <div v-if="!latestReview" class="text-gray-400">{{ t('profile.no_review') }}</div>
                <div v-else>
                  <div class="font-medium">{{ latestReview.cycle_name }}</div>
                  <div class="flex items-center gap-2 mt-1">
                    <span v-if="latestReview.overall_score !== null"
                      class="text-2xl font-bold text-primary-700">{{ latestReview.overall_score }}</span>
                    <span v-if="latestReview.rating"
                      class="px-2 py-0.5 rounded text-xs bg-emerald-100 text-emerald-700">{{ latestReview.rating }}</span>
                  </div>
                  <div class="text-xs text-gray-500 mt-0.5">{{ t('common.status') }}: {{ latestReview.status }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Pintasan -->
        <div class="border-t border-gray-100 pt-4">
          <h3 class="font-medium text-sm text-gray-700 mb-2">{{ t('profile.quick_links') }}</h3>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
            <RouterLink to="/attendance/clock" class="px-3 py-2 rounded border border-gray-200 hover:border-primary-400 hover:bg-primary-50 inline-flex items-center gap-2">
              <ClockIcon class="w-4 h-4 text-primary-600" /> {{ t('profile.quick_clock') }}
            </RouterLink>
            <RouterLink to="/attendance/my" class="px-3 py-2 rounded border border-gray-200 hover:border-primary-400 hover:bg-primary-50 inline-flex items-center gap-2">
              <CalendarDaysIcon class="w-4 h-4 text-primary-600" /> {{ t('profile.quick_my_attendance') }}
            </RouterLink>
            <RouterLink to="/leaves/new" class="px-3 py-2 rounded border border-gray-200 hover:border-primary-400 hover:bg-primary-50 inline-flex items-center gap-2">
              <CalendarDaysIcon class="w-4 h-4 text-primary-600" /> {{ t('profile.quick_apply_leave') }}
            </RouterLink>
            <RouterLink to="/payslips/my" class="px-3 py-2 rounded border border-gray-200 hover:border-primary-400 hover:bg-primary-50 inline-flex items-center gap-2">
              <DocumentTextIcon class="w-4 h-4 text-primary-600" /> {{ t('profile.quick_payslips') }}
            </RouterLink>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>