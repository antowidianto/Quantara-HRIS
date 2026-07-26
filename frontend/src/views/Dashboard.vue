<script setup>
import { ref, onMounted, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import api from '@/api/client'
import { useI18n } from '@/locales'
import { ClockIcon, CalendarDaysIcon, CurrencyDollarIcon, GiftIcon, CakeIcon } from '@heroicons/vue/24/outline'
import AttendanceHeatmap from '@/components/AttendanceHeatmap.vue'
import SuperAdminDashboard from '@/views/Admin/SuperAdminDashboard.vue'

const auth = useAuthStore()
const { t } = useI18n()
const headcount = ref(null)
const myAttendance = ref([])
const myLeaves = ref([])
const myPayslips = ref([])
const upcomingEvents = ref([])     // birthday + anniversary 30 hari ke depan
const loading = ref(true)

onMounted(async () => {
  if (auth.isSuperAdmin) {
    loading.value = false
    return
  }
  loading.value = true
  try {
    const tasks = [
      api.get('/attendance/my').then(r => myAttendance.value = r.data.data || []),
      api.get('/leaves', { params: { mine: 1, per_page: 5 } }).then(r => myLeaves.value = r.data.data || []),
      api.get('/payslips/my').then(r => myPayslips.value = r.data.data || []),
      api.get('/anniversary/upcoming', { params: { days: 30 } })
        .then(r => upcomingEvents.value = r.data?.data?.events || r.data?.data || [])
        .catch(() => {}),
    ]
    if (auth.can('report.view')) {
      tasks.push(api.get('/reports/headcount').then(r => headcount.value = r.data.data))
    }
    await Promise.allSettled(tasks)
  } finally {
    loading.value = false
  }
})

function fmtEventDate(d) {
  if (! d) return ''
  return new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })
}
function eventLabel(e) {
  // Backend event item bentuk: {type: 'birthday'|'anniversary', employee_name,
  // next_occurrence (YYYY-MM-DD), days_until, years (anniversary), is_milestone}
  if (e.type === 'birthday') return t('dashboard.birthday')
  if (e.type === 'anniversary') {
    return e.is_milestone
      ? t('dashboard.milestone', { n: e.years || '' })
      : t('dashboard.anniversary', { n: e.years || '' })
  }
  return e.label || '-'
}

const stats = computed(() => {
  const att = myAttendance.value
  const present = att.filter(a => a.status === 'PRESENT' || a.status === 'LATE').length
  const absent = att.filter(a => a.status === 'ABSENT').length
  const onLeave = myLeaves.value.filter(l => l.status === 'APPROVED').length
  const lastSlip = myPayslips.value[0]
  return { present, absent, onLeave, lastSlip }
})

function fmtCurrency(v) {
  if (!v) return 'Rp 0'
  return 'Rp ' + Number(v).toLocaleString('id-ID')
}
</script>

<template>
  <SuperAdminDashboard v-if="auth.isSuperAdmin" />
  <div v-else>
    <h1 class="text-2xl font-bold text-gray-900">{{ t('dashboard.greeting', { name: auth.user?.name?.split(' ')[0] }) }}</h1>
    <p class="text-gray-500 mb-6">{{ t('dashboard.subtitle') }}</p>

    <!-- Personal stats -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      <div class="card card-body flex items-start gap-3">
        <div class="p-2 bg-green-100 rounded text-green-600"><ClockIcon class="w-5 h-5" /></div>
        <div>
          <div class="text-xs text-gray-500">{{ t('dashboard.stat_present') }}</div>
          <div class="text-2xl font-bold">{{ stats.present }}</div>
        </div>
      </div>
      <div class="card card-body flex items-start gap-3">
        <div class="p-2 bg-red-100 rounded text-red-600"><ClockIcon class="w-5 h-5" /></div>
        <div>
          <div class="text-xs text-gray-500">{{ t('dashboard.stat_absent') }}</div>
          <div class="text-2xl font-bold">{{ stats.absent }}</div>
        </div>
      </div>
      <div class="card card-body flex items-start gap-3">
        <div class="p-2 bg-blue-100 rounded text-blue-600"><CalendarDaysIcon class="w-5 h-5" /></div>
        <div>
          <div class="text-xs text-gray-500">{{ t('dashboard.stat_approved_leave') }}</div>
          <div class="text-2xl font-bold">{{ stats.onLeave }}</div>
        </div>
      </div>
      <div class="card card-body flex items-start gap-3">
        <div class="p-2 bg-yellow-100 rounded text-yellow-600"><CurrencyDollarIcon class="w-5 h-5" /></div>
        <div>
          <div class="text-xs text-gray-500">{{ t('dashboard.stat_last_payslip') }}</div>
          <div class="text-lg font-bold">{{ stats.lastSlip ? fmtCurrency(stats.lastSlip.take_home_pay) : '-' }}</div>
        </div>
      </div>
    </div>

    <!-- Company stats -->
    <div v-if="headcount" class="card mb-6">
      <div class="card-header">
        <span class="font-semibold">{{ t('dashboard.headcount_title') }}</span>
      </div>
      <div class="card-body grid grid-cols-1 md:grid-cols-3 gap-6">
        <div>
          <div class="text-sm text-gray-500 mb-2">{{ t('dashboard.total_employees') }}</div>
          <div class="text-3xl font-bold text-primary-600">{{ headcount.totals?.active || 0 }}</div>
          <div class="text-xs text-gray-400">{{ headcount.totals?.inactive || 0 }} {{ t('dashboard.inactive') }}</div>
        </div>
        <div>
          <div class="text-sm text-gray-500 mb-2">{{ t('dashboard.by_department') }}</div>
          <ul class="text-sm space-y-1">
            <li v-for="d in (headcount.by_department || []).slice(0, 5)" :key="d.id" class="flex justify-between">
              <span>{{ d.name || '-' }}</span>
              <span class="font-semibold">{{ d.total }}</span>
            </li>
          </ul>
        </div>
        <div>
          <div class="text-sm text-gray-500 mb-2">{{ t('dashboard.by_status') }}</div>
          <ul class="text-sm space-y-1">
            <li v-for="s in (headcount.by_status || [])" :key="s.employment_status" class="flex justify-between">
              <span>{{ s.employment_status }}</span>
              <span class="font-semibold">{{ s.total }}</span>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Upcoming birthdays / anniversaries (30 hari) -->
    <div v-if="upcomingEvents.length" class="card mb-6">
      <div class="card-header flex items-center gap-2">
        <GiftIcon class="w-5 h-5 text-pink-500" />
        <span class="font-semibold">{{ t('dashboard.events_title') }}</span>
        <span class="text-xs text-gray-400">({{ upcomingEvents.length }})</span>
      </div>
      <div class="card-body">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2 max-h-64 overflow-y-auto">
          <div v-for="(e, i) in upcomingEvents.slice(0, 12)" :key="i"
            class="flex items-start gap-2 px-3 py-2 rounded border"
            :class="e.is_milestone ? 'bg-amber-50 border-amber-200' : 'bg-gray-50 border-gray-200'">
            <component :is="e.type === 'birthday' ? CakeIcon : GiftIcon"
              class="w-5 h-5 flex-shrink-0 mt-0.5"
              :class="e.type === 'birthday' ? 'text-pink-500' : 'text-blue-500'" />
            <div class="flex-1 min-w-0">
              <div class="text-sm font-medium truncate">{{ e.employee_name || e.full_name }}</div>
              <div class="text-xs text-gray-600">{{ eventLabel(e) }}</div>
              <div class="text-xs text-gray-500">
                {{ fmtEventDate(e.next_occurrence || e.date) }}
                <span v-if="e.days_until === 0" class="text-pink-600 font-semibold">· {{ t('dashboard.today') }}</span>
                <span v-else-if="e.days_until === 1" class="text-amber-700">· {{ t('dashboard.tomorrow') }}</span>
                <span v-else-if="e.days_until !== undefined" class="text-gray-400">· {{ t('dashboard.days_left', { n: e.days_until }) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent activities -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="card">
        <div class="card-header"><span class="font-semibold">{{ t('dashboard.recent_leaves') }}</span></div>
        <div class="card-body">
          <div v-if="myLeaves.length === 0" class="text-sm text-gray-400 text-center py-4">{{ t('dashboard.no_leaves') }}</div>
          <ul v-else class="divide-y divide-gray-100">
            <li v-for="l in myLeaves.slice(0, 5)" :key="l.id" class="py-3">
              <div class="flex items-center justify-between">
                <div>
                  <div class="font-medium text-sm">{{ l.leave_name }}</div>
                  <div class="text-xs text-gray-500">{{ l.start_date }} → {{ l.end_date }} ({{ l.days }} hari)</div>
                </div>
                <span :class="{
                  'badge-yellow': l.status === 'PENDING',
                  'badge-green': l.status === 'APPROVED',
                  'badge-red': l.status === 'REJECTED',
                  'badge-gray': l.status === 'CANCELLED',
                }">{{ l.status }}</span>
              </div>
            </li>
          </ul>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><span class="font-semibold">{{ t('dashboard.recent_attendance') }}</span></div>
        <div class="card-body">
          <div v-if="myAttendance.length === 0" class="text-sm text-gray-400 text-center py-4">{{ t('dashboard.no_attendance') }}</div>
          <ul v-else class="divide-y divide-gray-100">
            <li v-for="a in myAttendance.slice(0, 5)" :key="a.id" class="py-3 flex items-center justify-between">
              <div>
                <div class="font-medium text-sm">{{ a.work_date }}</div>
                <div class="text-xs text-gray-500">
                  {{ a.clock_in_at?.slice(11, 16) || '--:--' }} → {{ a.clock_out_at?.slice(11, 16) || '--:--' }}
                </div>
              </div>
              <span :class="{
                'badge-green': a.status === 'PRESENT',
                'badge-yellow': a.status === 'LATE',
                'badge-red': a.status === 'ABSENT',
                'badge-blue': a.status === 'LEAVE' || a.status === 'HOLIDAY',
              }">{{ a.status }}</span>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Heatmap absensi (HR only). Gating ke permission attendance.view_all
         supaya non-HR tidak lihat agregat antar departemen. Sprint 5-6 item T. -->
    <div v-if="auth.can('attendance.view_all') || auth.can('report.view')" class="mt-6">
      <AttendanceHeatmap />
    </div>
  </div>
</template>
