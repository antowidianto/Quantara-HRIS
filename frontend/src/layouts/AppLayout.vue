<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute, RouterLink, RouterView } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/api/client'
import {
  HomeIcon, UsersIcon, ClockIcon, CalendarDaysIcon, BanknotesIcon,
  Cog6ToothIcon, BellIcon, ArrowLeftStartOnRectangleIcon, ChartBarIcon,
  BuildingOffice2Icon, ShieldCheckIcon, ChevronDownIcon, Bars3Icon, XMarkIcon,
  CurrencyDollarIcon, ClipboardDocumentListIcon, UserCircleIcon
} from '@heroicons/vue/24/outline'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const sidebarOpen = ref(false)
const userMenuOpen = ref(false)
const notifMenuOpen = ref(false)
const unreadCount = ref(0)
const notifications = ref([])

const groups = computed(() => {
  const can = auth.can
  return [
    {
      title: 'Beranda',
      items: [{ name: 'Dashboard', to: '/dashboard', icon: HomeIcon }],
    },
    {
      title: 'Saya',
      items: [
        { name: 'Absensi Saya', to: '/attendance/my', icon: ClockIcon },
        { name: 'Clock In/Out', to: '/attendance/clock', icon: ClockIcon },
        { name: 'Pengajuan Absensi', to: '/attendance/my-changes', icon: ClipboardDocumentListIcon },
        { name: 'Cuti Saya', to: '/leaves', icon: CalendarDaysIcon },
        { name: 'Saldo Cuti', to: '/leaves/balance', icon: CalendarDaysIcon },
        { name: 'Lembur Saya', to: '/overtimes', icon: ClockIcon },
        { name: 'Slip Gaji Saya', to: '/payslips/my', icon: CurrencyDollarIcon },
        { name: 'Inbox Approval', to: '/approvals/inbox', icon: ClipboardDocumentListIcon },
        { name: 'Delegasi Approval', to: '/approvals/delegations', icon: ShieldCheckIcon },
        { name: 'Goal & Review Saya', to: '/performance/my', icon: ChartBarIcon },
      ],
    },
    {
      title: 'HR',
      items: [
        { name: 'Karyawan', to: '/employees', icon: UsersIcon, perm: 'employee.view' },
        { name: 'Kontrak Karyawan', to: '/employees/contracts', icon: ClipboardDocumentListIcon, perm: 'employee.view' },
        { name: 'Absensi', to: '/attendance', icon: ClockIcon, perm: 'attendance.view_all' },
        { name: 'Roster Shift', to: '/roster', icon: CalendarDaysIcon, perm: 'roster.view' },
        { name: 'Pengajuan Perubahan', to: '/attendance/change-requests/approvals', icon: ClipboardDocumentListIcon, perm: 'attendance.approve_change' },
        { name: 'Anomali Absensi', to: '/attendance/anomalies', icon: ShieldCheckIcon, perm: 'attendance.review_anomaly' },
        { name: 'Cuti', to: '/leaves?status=PENDING', icon: CalendarDaysIcon, perm: 'leave.view' },
        { name: 'Kalender Cuti Tim', to: '/leaves/team-calendar', icon: CalendarDaysIcon, perm: 'leave.team_calendar' },
        { name: 'Lembur', to: '/overtimes?status=PENDING', icon: ClockIcon, perm: 'overtime.view' },
        { name: 'Reimbursement', to: '/reimbursements', icon: BanknotesIcon, perm: 'reimbursement.view' },
        { name: 'Kasbon/Loan', to: '/loans', icon: BanknotesIcon, perm: 'loan.view' },
        { name: 'Surat Peringatan', to: '/hr/disciplinary', icon: ClipboardDocumentListIcon, perm: 'disciplinary.view' },
        { name: 'Pesangon / Severance', to: '/hr/severance', icon: CurrencyDollarIcon, perm: 'severance.view' },
        { name: 'Onboarding / Offboarding', to: '/hr/onboarding', icon: ClipboardDocumentListIcon, perm: 'onboarding.view' },
        { name: 'Aset Perusahaan', to: '/hr/assets', icon: BuildingOffice2Icon, perm: 'asset.view' },
        { name: 'Perjalanan Dinas', to: '/travel', icon: ClipboardDocumentListIcon, perm: 'business_trip.view' },
        { name: 'Recruitment', to: '/recruitment/jobs', icon: UsersIcon, perm: 'recruitment.view' },
        { name: 'HR Helpdesk', to: '/helpdesk/tickets', icon: ClipboardDocumentListIcon, perm: 'helpdesk.view' },
        { name: 'Training', to: '/training', icon: ClipboardDocumentListIcon, perm: 'training.view' },
        { name: 'Sertifikat', to: '/certificates', icon: ClipboardDocumentListIcon, perm: 'certificate.view' },
        { name: 'Performance / OKR', to: '/performance', icon: ChartBarIcon, perm: 'performance.view' },
      ].filter(i => ! i.perm || can(i.perm)),
    },
    {
      title: 'Payroll',
      items: [
        { name: 'Periode Payroll', to: '/payroll/periods', icon: CurrencyDollarIcon, perm: 'payroll.view' },
        { name: 'Slip Gaji', to: '/payslips', icon: ClipboardDocumentListIcon, perm: 'payslip.view_all' },
      ].filter(i => ! i.perm || can(i.perm)),
    },
    {
      title: 'Master Data',
      items: [
        { name: 'Cabang', to: '/master/branches', icon: BuildingOffice2Icon, perm: 'branch.view' },
        { name: 'Lokasi Kerja', to: '/master/work-locations', icon: BuildingOffice2Icon, perm: 'branch.view' },
        { name: 'Departemen', to: '/master/departments', icon: BuildingOffice2Icon, perm: 'department.view' },
        { name: 'Jabatan', to: '/master/positions', icon: BuildingOffice2Icon, perm: 'position.view' },
        { name: 'Job Grade', to: '/master/job-grades', icon: BuildingOffice2Icon, perm: 'position.view' },
        { name: 'Shift Kerja', to: '/master/shifts', icon: ClockIcon, perm: 'shift.view' },
        { name: 'Aturan Shift', to: '/master/shift-assignments', icon: ClockIcon, perm: 'shift.view' },
        { name: 'Pola Shift', to: '/master/shift-patterns', icon: CalendarDaysIcon, perm: 'shift_pattern.view' },
        { name: 'Tipe Cuti', to: '/master/leave-types', icon: CalendarDaysIcon, perm: 'leave.manage_types' },
        { name: 'Komponen Gaji', to: '/master/payroll-components', icon: CurrencyDollarIcon, perm: 'payroll.manage' },
        { name: 'Tunjangan / Mapping', to: '/master/allowances', icon: BanknotesIcon, perm: 'payroll.manage' },
        { name: 'Peraturan Perusahaan', to: '/master/regulations', icon: ClipboardDocumentListIcon, perm: 'settings.manage' },
        { name: 'Template Kontrak', to: '/master/contract-templates', icon: ClipboardDocumentListIcon, perm: 'settings.manage' },
        { name: 'Konfigurasi Approval', to: '/master/approval-chains', icon: ShieldCheckIcon, perm: 'settings.manage' },
        { name: 'Template Onboarding', to: '/master/task-templates', icon: ClipboardDocumentListIcon, perm: 'onboarding.manage' },
      ].filter(i => ! i.perm || can(i.perm)),
    },
    {
      title: 'Laporan',
      items: [
        { name: 'Laporan', to: '/reports', icon: ChartBarIcon, perm: 'report.view' },
      ].filter(i => ! i.perm || can(i.perm)),
    },
    {
      title: 'Admin',
      items: [
        { name: 'Pengguna', to: '/admin/users', icon: UsersIcon, perm: 'user.manage' },
        { name: 'Role', to: '/admin/roles', icon: ShieldCheckIcon, perm: 'role.manage' },
        { name: 'Audit Log', to: '/admin/audit-logs', icon: ClipboardDocumentListIcon, perm: 'audit.view' },
        { name: 'Perusahaan', to: '/admin/companies', icon: BuildingOffice2Icon, superAdmin: true },
        { name: 'Pengaturan', to: '/admin/settings', icon: Cog6ToothIcon, perm: 'settings.manage' },
      ].filter(i => (! i.perm || can(i.perm)) && (! i.superAdmin || auth.isSuperAdmin)),
    },
  ].filter(g => g.items.length > 0)
})

async function loadNotifications() {
  try {
    const [count, list] = await Promise.all([
      api.get('/notifications/unread-count'),
      api.get('/notifications', { params: { per_page: 8 } }),
    ])
    unreadCount.value = count.data.data.count
    notifications.value = list.data.data
  } catch (_) {}
}

async function markAllRead() {
  await api.post('/notifications/read-all')
  loadNotifications()
}

async function logout() {
  await auth.logout()
  router.push('/login')
}

function onSwitchCompany(e) {
  const id = e.target.value
  auth.setActiveCompany(id || null)
  // Refresh halaman supaya semua list mengikuti context baru
  if (typeof window !== 'undefined') window.location.reload()
}

let pollTimer = null
onMounted(() => {
  loadNotifications()
  pollTimer = setInterval(loadNotifications, 60000)
})
onUnmounted(() => { if (pollTimer) clearInterval(pollTimer) })
</script>

<template>
  <div class="min-h-screen flex bg-gray-50">
    <!-- Sidebar -->
    <aside :class="[
      'fixed inset-y-0 left-0 z-30 w-64 bg-white border-r border-gray-200 transform transition-transform lg:translate-x-0 lg:static lg:inset-0',
      sidebarOpen ? 'translate-x-0' : '-translate-x-full'
    ]">
      <div class="h-16 flex items-center justify-between px-4 border-b border-gray-200">
        <div class="flex items-center gap-2">
          <div class="w-8 h-8 rounded bg-primary-600 flex items-center justify-center text-white font-bold">H</div>
          <span class="font-semibold text-gray-900">HRIS</span>
        </div>
        <button class="lg:hidden text-gray-500" @click="sidebarOpen = false"><XMarkIcon class="w-5 h-5" /></button>
      </div>

      <nav class="px-3 py-4 overflow-y-auto h-[calc(100vh-4rem)]">
        <div v-for="group in groups" :key="group.title" class="mb-6">
          <h3 class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">{{ group.title }}</h3>
          <ul class="space-y-1">
            <li v-for="item in group.items" :key="item.to">
              <RouterLink :to="item.to"
                class="flex items-center gap-3 px-3 py-2 text-sm rounded-md text-gray-700 hover:bg-primary-50 hover:text-primary-700"
                :class="{ 'bg-primary-50 text-primary-700 font-medium': route.path.startsWith(item.to.split('?')[0]) }">
                <component :is="item.icon" class="w-5 h-5 flex-shrink-0" />
                <span>{{ item.name }}</span>
              </RouterLink>
            </li>
          </ul>
        </div>
      </nav>
    </aside>

    <!-- Main content -->
    <div class="flex-1 flex flex-col min-h-screen">
      <!-- Topbar -->
      <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-4 sticky top-0 z-20">
        <div class="flex items-center gap-3">
          <button class="lg:hidden text-gray-600" @click="sidebarOpen = true"><Bars3Icon class="w-6 h-6" /></button>
          <div class="hidden md:block">
            <span v-if="! auth.isSuperAdmin" class="text-sm text-gray-500">{{ auth.company?.name || 'HRIS' }}</span>
            <select
              v-else
              :value="auth.activeCompanyId || ''"
              @change="onSwitchCompany($event)"
              class="text-sm border-gray-200 rounded-md py-1 pl-2 pr-7 focus:ring-1 focus:ring-primary-500"
              :class="auth.needsCompanyContext ? 'border-amber-400 bg-amber-50 text-amber-900' : ''"
            >
              <option value="">— Pilih Perusahaan —</option>
              <option v-for="c in auth.availableCompanies" :key="c.id" :value="c.id">
                {{ c.name }}
              </option>
            </select>
          </div>
        </div>

        <div class="flex items-center gap-3">
          <!-- Notifications -->
          <div class="relative">
            <button @click="notifMenuOpen = !notifMenuOpen; userMenuOpen = false"
              class="relative p-2 text-gray-600 hover:text-primary-600 rounded-md hover:bg-gray-100">
              <BellIcon class="w-6 h-6" />
              <span v-if="unreadCount > 0" class="absolute -top-0.5 -right-0.5 w-5 h-5 rounded-full bg-red-500 text-white text-xs flex items-center justify-center">{{ unreadCount > 9 ? '9+' : unreadCount }}</span>
            </button>
            <div v-if="notifMenuOpen" class="absolute right-0 mt-2 w-80 bg-white rounded-md shadow-lg border border-gray-200 overflow-hidden">
              <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                <span class="font-semibold text-sm">Notifikasi</span>
                <button class="text-xs text-primary-600 hover:underline" @click="markAllRead">Tandai semua dibaca</button>
              </div>
              <div class="max-h-80 overflow-y-auto">
                <div v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-gray-500">Tidak ada notifikasi</div>
                <RouterLink v-for="n in notifications" :key="n.id" :to="n.link_url || '/notifications'"
                  class="block px-4 py-3 border-b border-gray-100 hover:bg-gray-50 last:border-0"
                  :class="{ 'bg-blue-50/40': ! n.is_read }">
                  <div class="text-sm font-medium text-gray-800">{{ n.title }}</div>
                  <div class="text-xs text-gray-500 line-clamp-2">{{ n.message }}</div>
                  <div class="text-xs text-gray-400 mt-1">{{ new Date(n.created_at).toLocaleString('id-ID') }}</div>
                </RouterLink>
              </div>
              <RouterLink to="/notifications" class="block px-4 py-2 text-center text-sm text-primary-600 hover:bg-gray-50">Lihat semua</RouterLink>
            </div>
          </div>

          <!-- User menu -->
          <div class="relative">
            <button @click="userMenuOpen = !userMenuOpen; notifMenuOpen = false"
              class="flex items-center gap-2 p-1.5 rounded-md hover:bg-gray-100">
              <div class="w-8 h-8 rounded-full bg-primary-100 flex items-center justify-center text-primary-700 font-semibold">
                {{ auth.user?.name?.[0]?.toUpperCase() || 'U' }}
              </div>
              <div class="hidden md:block text-left">
                <div class="text-sm font-medium text-gray-800">{{ auth.user?.name }}</div>
                <div class="text-xs text-gray-500">{{ auth.role?.name }}</div>
              </div>
              <ChevronDownIcon class="w-4 h-4 text-gray-500" />
            </button>
            <div v-if="userMenuOpen" class="absolute right-0 mt-2 w-56 bg-white rounded-md shadow-lg border border-gray-200 overflow-hidden">
              <RouterLink to="/profile" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                <UserCircleIcon class="w-5 h-5" /> Profil Saya
              </RouterLink>
              <RouterLink to="/change-password" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                <ShieldCheckIcon class="w-5 h-5" /> Ganti Password
              </RouterLink>
              <button @click="logout" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                <ArrowLeftStartOnRectangleIcon class="w-5 h-5" /> Keluar
              </button>
            </div>
          </div>
        </div>
      </header>

      <main class="flex-1 p-6">
        <RouterView />
      </main>
    </div>

    <!-- Mobile overlay -->
    <div v-if="sidebarOpen" class="fixed inset-0 z-20 bg-black/30 lg:hidden" @click="sidebarOpen = false"></div>
  </div>
</template>