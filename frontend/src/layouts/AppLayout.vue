<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute, RouterLink, RouterView } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/api/client'
import {
  HomeIcon, UsersIcon, ClockIcon, CalendarDaysIcon, BanknotesIcon,
  Cog6ToothIcon, BellIcon, ArrowLeftStartOnRectangleIcon, ChartBarIcon,
  BuildingOffice2Icon, ShieldCheckIcon, ChevronDownIcon, Bars3Icon, XMarkIcon,
  CurrencyDollarIcon, ClipboardDocumentListIcon, UserCircleIcon,
  SunIcon, MoonIcon, ComputerDesktopIcon, LanguageIcon, CheckIcon,
  ArrowsRightLeftIcon,
  // Sprint 2 / 4 polish — icons untuk menu baru
  ExclamationTriangleIcon, ChatBubbleLeftRightIcon, DocumentChartBarIcon,
  NoSymbolIcon,
} from '@heroicons/vue/24/outline'
import { useDarkMode } from '@/composables/useDarkMode'
import { useI18n } from '@/locales'

// Sprint 5-6 item S — toggle dark mode di topbar.
const { mode: darkMode, effective: effectiveDarkMode, toggle: toggleDarkMode } = useDarkMode()

// i18n — language switcher di topbar.
const { t, locale, setLocale, availableLocales } = useI18n()
const langs = availableLocales()

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const sidebarOpen = ref(false)
const userMenuOpen = ref(false)
const notifMenuOpen = ref(false)
const langMenuOpen = ref(false)
const companyMenuOpen = ref(false)
const unreadCount = ref(0)
const notifications = ref([])

// Cari profil company yg sedang aktif untuk ditampilkan di tombol dropdown.
// Dipakai super admin saja — user biasa tinggal lihat nama company-nya.
const activeCompany = computed(() => {
  if (! auth.activeCompanyId) return null
  return auth.availableCompanies.find(c => Number(c.id) === Number(auth.activeCompanyId)) || null
})

function pickCompany(id) {
  // Pilih perusahaan dari custom dropdown. Sama logic dgn select lama:
  // simpan ke store + reload supaya semua list di halaman aktif refetch
  // dengan header X-Company-Id baru.
  auth.setActiveCompany(id || null)
  companyMenuOpen.value = false
  if (typeof window !== 'undefined') window.location.reload()
}

const groups = computed(() => {
  const can = auth.can
  // `locale.value` di-touch supaya group ikut re-compute saat ganti bahasa.
  void locale.value
  return [
    {
      title: t('nav.home'),
      items: [{ name: t('nav.items.dashboard'), to: '/dashboard', icon: HomeIcon }],
    },
    {
      title: t('nav.my'),
      items: [
        { name: t('nav.items.my_attendance'),       to: '/attendance/my',           icon: ClockIcon },
        { name: t('nav.items.clock_in_out'),        to: '/attendance/clock',        icon: ClockIcon },
        { name: t('nav.items.attendance_request'),  to: '/attendance/my-changes',   icon: ClipboardDocumentListIcon },
        { name: t('nav.items.my_leaves'),           to: '/leaves',                  icon: CalendarDaysIcon },
        { name: t('nav.items.leave_balance'),       to: '/leaves/balance',          icon: CalendarDaysIcon },
        { name: t('nav.items.my_overtimes'),        to: '/overtimes',               icon: ClockIcon },
        { name: t('nav.items.my_payslips'),         to: '/payslips/my',             icon: CurrencyDollarIcon },
        // Sprint 21+ — Self-service riwayat bonus
        { name: 'Riwayat Bonus',                     to: '/bonuses/my',              icon: BanknotesIcon },
        { name: t('nav.items.approval_inbox'),      to: '/approvals/inbox',         icon: ClipboardDocumentListIcon },
        { name: t('nav.items.approval_delegations'),to: '/approvals/delegations',   icon: ShieldCheckIcon },
        { name: t('nav.items.my_performance'),      to: '/performance/my',          icon: ChartBarIcon },
        // Pengajuan resignation self-service. Selalu visible — engine
        // backend yang block kalau user tidak terhubung ke record
        // employee atau sudah punya pengajuan aktif.
        { name: t('nav.items.my_resignation'),      to: '/hr/my-resignation',       icon: ArrowLeftStartOnRectangleIcon },
        // Mutasi karyawan — self-service untuk lihat & approve/reject mutasi
        // yang menyangkut diri sendiri (penting saat HR submit mutasi dengan
        // requires_consent=1).
        { name: 'Mutasi Saya',                       to: '/hr/my-transfers',         icon: ArrowsRightLeftIcon },
      ],
    },
    {
      title: t('nav.hr'),
      items: [
        { name: t('nav.items.employees'),           to: '/employees',                                  icon: UsersIcon,                  perm: 'employee.view' },
        { name: t('nav.items.employee_contracts'),  to: '/employees/contracts',                        icon: ClipboardDocumentListIcon,  perm: 'employee.view' },
        { name: t('nav.items.attendance'),          to: '/attendance',                                 icon: ClockIcon,                  perm: 'attendance.view_all' },
        { name: t('nav.items.shift_roster'),        to: '/roster',                                     icon: CalendarDaysIcon,           perm: 'roster.view' },
        { name: t('nav.items.change_requests'),     to: '/attendance/change-requests/approvals',       icon: ClipboardDocumentListIcon,  perm: 'attendance.approve_change' },
        { name: t('nav.items.anomalies'),           to: '/attendance/anomalies',                       icon: ShieldCheckIcon,            perm: 'attendance.review_anomaly' },
        { name: t('nav.items.leaves'),              to: '/leaves?status=PENDING',                      icon: CalendarDaysIcon,           perm: 'leave.view' },
        { name: t('nav.items.team_calendar'),       to: '/leaves/team-calendar',                       icon: CalendarDaysIcon,           perm: 'leave.team_calendar' },
        { name: t('nav.items.overtimes'),           to: '/overtimes?status=PENDING',                   icon: ClockIcon,                  perm: 'overtime.view' },
        { name: t('nav.items.reimbursements'),      to: '/reimbursements',                             icon: BanknotesIcon,              perm: 'reimbursement.view' },
        { name: t('nav.items.loans'),               to: '/loans',                                      icon: BanknotesIcon,              perm: 'loan.view' },
        { name: t('nav.items.disciplinary'),        to: '/hr/disciplinary',                            icon: ClipboardDocumentListIcon,  perm: 'disciplinary.view' },
        { name: 'Promosi / Demosi (JIC)',           to: '/hr/jic',                                     icon: ClipboardDocumentListIcon,  perm: 'jic.view' },
        { name: 'Mutasi Karyawan',                  to: '/hr/transfers',                               icon: ArrowsRightLeftIcon,        perm: 'employee_transfer.view' },
        { name: t('nav.items.severance'),           to: '/hr/severance',                               icon: CurrencyDollarIcon,         perm: 'severance.view' },
        // Sprint 4 / OFF-5 — PHK formal workflow (multi-step approval)
        { name: 'Termination (PHK)',                to: '/hr/terminations',                            icon: NoSymbolIcon,               perm: 'termination.view' },
        { name: t('nav.items.resignations'),        to: '/hr/resignations',                            icon: ArrowLeftStartOnRectangleIcon, perm: 'resignation.view' },
        // Sprint 4 / OFF-1 — Exit Interview + analytics turnover
        { name: 'Exit Interviews',                  to: '/hr/exit-interviews',                         icon: ChatBubbleLeftRightIcon,    perm: 'exit_interview.view' },
        { name: t('nav.items.onboarding'),          to: '/hr/onboarding',                              icon: ClipboardDocumentListIcon,  perm: 'onboarding.view' },
        // Sprint 2 polish — Probation Tracker (HIRE-1)
        { name: 'Probation Tracker',                to: '/hr/probation',                               icon: ExclamationTriangleIcon,    perm: 'employee.update' },
        // Sprint 3 / OD-2 — Individual Development Plan
        { name: 'Development Plan (IDP)',           to: '/hr/idp',                                     icon: ChartBarIcon,               perm: 'idp.view' },
        { name: t('nav.items.assets'),              to: '/hr/assets',                                  icon: BuildingOffice2Icon,        perm: 'asset.view' },
        { name: t('nav.items.business_trips'),      to: '/travel',                                     icon: ClipboardDocumentListIcon,  perm: 'business_trip.view' },
        { name: 'Manpower Planning (MPP)',          to: '/recruitment/mpp',                            icon: ClipboardDocumentListIcon,  perm: 'mpp.view' },
        { name: t('nav.items.recruitment'),         to: '/recruitment/jobs',                           icon: UsersIcon,                  perm: 'recruitment.view' },
        { name: 'Career Portal',                    to: '/recruitment/career-settings',                icon: UsersIcon,                  perm: 'careers.manage' },
        { name: 'Online Tests',                     to: '/recruitment/online-tests',                   icon: ClipboardDocumentListIcon,  perm: 'online_tests.view' },
        { name: 'Question Bank',                    to: '/recruitment/online-test-bank',               icon: ClipboardDocumentListIcon,  perm: 'online_tests.manage' },
        { name: 'Test Assignments',                 to: '/recruitment/online-test-assignments',        icon: ClipboardDocumentListIcon,  perm: 'online_tests.view' },
        { name: t('nav.items.helpdesk'),            to: '/helpdesk/tickets',                           icon: ClipboardDocumentListIcon,  perm: 'helpdesk.view' },
        { name: t('nav.items.training'),            to: '/training',                                   icon: ClipboardDocumentListIcon,  perm: 'training.view' },
        { name: t('nav.items.certificates'),        to: '/certificates',                               icon: ClipboardDocumentListIcon,  perm: 'certificate.view' },
        { name: t('nav.items.performance'),         to: '/performance',                                icon: ChartBarIcon,               perm: 'performance.view' },
      ].filter(i => ! i.perm || can(i.perm)),
    },
    {
      title: t('nav.payroll'),
      items: [
        { name: t('nav.items.payroll_periods'), to: '/payroll/periods',        icon: CurrencyDollarIcon,        perm: 'payroll.view' },
        { name: t('nav.items.payslips'),        to: '/payslips',                icon: ClipboardDocumentListIcon, perm: 'payslip.view_all' },
        // Bonus Engine — Sprint 16+ backend, Sprint 19+ UI. Lihat docs/BONUS_ENGINE.md
        { name: 'Bonus Schemes',                to: '/payroll/bonus/schemes',  icon: BanknotesIcon,             perm: 'bonus.manage' },
        { name: 'Bonus Runs',                   to: '/payroll/bonus/runs',     icon: BanknotesIcon,             perm: 'bonus.view' },
      ].filter(i => ! i.perm || can(i.perm)),
    },
    {
      title: t('nav.master_data'),
      items: [
        { name: t('nav.items.org_structure'),      to: '/master/org-structure',      icon: BuildingOffice2Icon,        perm: 'department.view' },
        { name: 'Master Jabatan Kunci',            to: '/master/organization-roles', icon: ShieldCheckIcon,            perm: 'org_structure.view' },
        { name: t('nav.items.branches'),           to: '/master/branches',           icon: BuildingOffice2Icon,        perm: 'branch.view' },
        { name: t('nav.items.work_locations'),     to: '/master/work-locations',     icon: BuildingOffice2Icon,        perm: 'branch.view' },
        { name: t('nav.items.departments'),        to: '/master/departments',        icon: BuildingOffice2Icon,        perm: 'department.view' },
        { name: t('nav.items.positions'),          to: '/master/positions',          icon: BuildingOffice2Icon,        perm: 'position.view' },
        { name: t('nav.items.job_grades'),         to: '/master/job-grades',         icon: BuildingOffice2Icon,        perm: 'position.view' },
        { name: t('nav.items.shifts'),             to: '/master/shifts',             icon: ClockIcon,                  perm: 'shift.view' },
        { name: t('nav.items.shift_assignments'),  to: '/master/shift-assignments',  icon: ClockIcon,                  perm: 'shift.view' },
        { name: t('nav.items.shift_patterns'),     to: '/master/shift-patterns',     icon: CalendarDaysIcon,           perm: 'shift_pattern.view' },
        { name: t('nav.items.leave_types'),        to: '/master/leave-types',        icon: CalendarDaysIcon,           perm: 'leave.manage_types' },
        { name: t('nav.items.payroll_components'), to: '/master/payroll-components', icon: CurrencyDollarIcon,         perm: 'payroll.manage' },
        { name: t('nav.items.allowances'),         to: '/master/allowances',         icon: BanknotesIcon,              perm: 'payroll.manage' },
        // Cost Center master + budget vs actual report. Lihat docs/COST_CENTER.md.
        { name: 'Cost Center',                     to: '/master/cost-centers',       icon: CurrencyDollarIcon,         perm: 'cost_center.view' },
        { name: t('nav.items.regulations'),        to: '/master/regulations',        icon: ClipboardDocumentListIcon,  perm: 'settings.manage' },
        { name: t('nav.items.contract_templates'), to: '/master/contract-templates', icon: ClipboardDocumentListIcon,  perm: 'settings.manage' },
        { name: t('nav.items.approval_chains'),    to: '/master/approval-chains',    icon: ShieldCheckIcon,            perm: 'settings.manage' },
        { name: t('nav.items.task_templates'),     to: '/master/task-templates',     icon: ClipboardDocumentListIcon,  perm: 'onboarding.manage' },
      ].filter(i => ! i.perm || can(i.perm)),
    },
    {
      title: t('nav.reports'),
      items: [
        { name: t('nav.items.reports_main'), to: '/reports', icon: ChartBarIcon, perm: 'report.view' },
        // Sprint 4 / CC-5 — Compliance Reports (WLKP, BPJS, PKWT, PHK, audit, turnover)
        { name: 'Compliance Reports',        to: '/hr/compliance-reports', icon: DocumentChartBarIcon, perm: 'compliance.report.view' },
      ].filter(i => ! i.perm || can(i.perm)),
    },
    {
      title: t('nav.admin'),
      items: [
        { name: t('nav.items.users'),      to: '/admin/users',      icon: UsersIcon,                  perm: 'user.manage' },
        { name: t('nav.items.roles'),      to: '/admin/roles',      icon: ShieldCheckIcon,            perm: 'role.manage' },
        { name: t('nav.items.audit_logs'), to: '/admin/audit-logs', icon: ClipboardDocumentListIcon,  perm: 'audit.view' },
        { name: t('nav.items.companies'),  to: '/admin/companies',  icon: BuildingOffice2Icon,        superAdmin: true },
        { name: t('nav.items.settings'),   to: '/admin/settings',   icon: Cog6ToothIcon,              perm: 'settings.manage' },
        { name: 'Email Settings',          to: '/admin/email-settings', icon: Cog6ToothIcon,          perm: 'settings.manage' },
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
      'fixed inset-y-0 left-0 z-30 w-64 bg-gradient-to-b from-slate-900 via-slate-900 to-slate-800 border-r border-slate-800/60 shadow-xl transform transition-transform lg:translate-x-0 lg:static lg:inset-0',
      sidebarOpen ? 'translate-x-0' : '-translate-x-full'
    ]">
      <!-- Brand header — gelap senada dengan body sidebar untuk kesan korporat -->
      <div class="h-16 flex items-center justify-between px-4 border-b border-slate-800/70 bg-slate-900/80">
        <div class="flex items-center gap-2.5">
          <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-primary-500 to-primary-700 flex items-center justify-center text-white font-bold shadow-md ring-1 ring-white/10">
            H
          </div>
          <div class="flex flex-col leading-tight">
            <span class="font-semibold text-white text-sm tracking-wide">HRIS</span>
            <span class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Workforce Suite</span>
          </div>
        </div>
        <button class="lg:hidden text-slate-400 hover:text-white" @click="sidebarOpen = false">
          <XMarkIcon class="w-5 h-5" />
        </button>
      </div>

      <nav class="sidebar-scroll px-3 py-4 overflow-y-auto h-[calc(100vh-4rem)]">
        <div v-for="group in groups" :key="group.title" class="mb-5">
          <h3 class="px-3 mb-2 text-[10px] font-semibold text-slate-500 uppercase tracking-[0.18em]">
            {{ group.title }}
          </h3>
          <ul class="space-y-0.5">
            <li v-for="item in group.items" :key="item.to">
              <RouterLink :to="item.to"
                class="nav-item group relative flex items-center gap-3 pl-3 pr-3 py-2 text-sm rounded-md text-slate-300 hover:bg-slate-800/70 hover:text-white transition-colors"
                :class="{ 'is-active': route.path.startsWith(item.to.split('?')[0]) }">
                <span class="active-indicator absolute left-0 top-1.5 bottom-1.5 w-0.5 rounded-r bg-primary-400 opacity-0 transition-opacity"></span>
                <component :is="item.icon" class="w-5 h-5 flex-shrink-0 text-slate-400 group-hover:text-slate-200" />
                <span class="truncate">{{ item.name }}</span>
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

          <!-- Branding text untuk user non-super-admin -->
          <div v-if="! auth.isSuperAdmin" class="hidden md:flex items-center gap-2">
            <div class="w-8 h-8 rounded-md bg-primary-50 flex items-center justify-center text-primary-700">
              <BuildingOffice2Icon class="w-4 h-4" />
            </div>
            <div class="text-sm">
              <div class="font-medium text-gray-800 leading-tight">{{ auth.company?.name || 'HRIS' }}</div>
              <div v-if="auth.branch?.name" class="text-xs text-gray-500 leading-tight">{{ auth.branch.name }}</div>
            </div>
          </div>

          <!-- Company switcher khusus super admin — custom pretty dropdown -->
          <div v-else class="hidden md:block relative">
            <button
              @click="companyMenuOpen = !companyMenuOpen; userMenuOpen = false; notifMenuOpen = false; langMenuOpen = false"
              class="company-switch group inline-flex items-center gap-2 pl-2 pr-3 py-1.5 rounded-lg border bg-white hover:bg-gray-50 transition-colors min-w-[14rem]"
              :class="auth.needsCompanyContext
                ? 'border-amber-300 bg-amber-50 hover:bg-amber-50/80'
                : 'border-gray-200'"
              :title="t('nav.select_company')"
            >
              <span class="w-7 h-7 rounded-md flex items-center justify-center text-sm font-semibold flex-shrink-0"
                :class="activeCompany
                  ? 'bg-primary-100 text-primary-700'
                  : 'bg-amber-100 text-amber-700'">
                <span v-if="activeCompany">{{ (activeCompany.code || activeCompany.name)?.[0]?.toUpperCase() }}</span>
                <BuildingOffice2Icon v-else class="w-4 h-4" />
              </span>
              <span class="flex-1 text-left min-w-0">
                <span v-if="activeCompany" class="block text-xs text-gray-500 leading-tight">{{ t('nav.active_company') || 'Perusahaan aktif' }}</span>
                <span v-else class="block text-xs text-amber-700 leading-tight">{{ t('nav.select_company') }}</span>
                <span class="block text-sm font-medium truncate"
                  :class="activeCompany ? 'text-gray-800' : 'text-amber-900'">
                  {{ activeCompany?.name || '—' }}
                </span>
              </span>
              <ChevronDownIcon class="w-4 h-4 text-gray-400 group-hover:text-gray-600 flex-shrink-0 transition-transform"
                :class="companyMenuOpen ? 'rotate-180' : ''" />
            </button>

            <!-- Dropdown panel -->
            <div v-if="companyMenuOpen"
              class="absolute left-0 mt-2 w-80 bg-white rounded-lg shadow-xl border border-gray-200 overflow-hidden z-30">
              <div class="px-4 py-2.5 border-b border-gray-100 bg-gray-50">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                  {{ t('nav.select_company') }}
                </div>
                <div class="text-[11px] text-gray-400 mt-0.5">
                  Data master, karyawan, dan transaksi akan mengikuti perusahaan terpilih.
                </div>
              </div>
              <div class="max-h-80 overflow-y-auto">
                <button
                  type="button"
                  class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 text-left"
                  :class="!auth.activeCompanyId ? 'bg-primary-50/40' : ''"
                  @click="pickCompany(null)"
                >
                  <span class="w-8 h-8 rounded-md flex items-center justify-center bg-gray-100 text-gray-500 flex-shrink-0">
                    <BuildingOffice2Icon class="w-4 h-4" />
                  </span>
                  <span class="flex-1 min-w-0">
                    <span class="block text-sm font-medium text-gray-800">Semua perusahaan</span>
                    <span class="block text-xs text-gray-500">Mode super admin global</span>
                  </span>
                  <CheckIcon v-if="!auth.activeCompanyId" class="w-4 h-4 text-primary-600 flex-shrink-0" />
                </button>
                <button
                  v-for="c in auth.availableCompanies"
                  :key="c.id"
                  type="button"
                  class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 text-left border-t border-gray-50"
                  :class="Number(auth.activeCompanyId) === Number(c.id) ? 'bg-primary-50/60' : ''"
                  @click="pickCompany(c.id)"
                >
                  <span class="w-8 h-8 rounded-md flex items-center justify-center font-semibold text-sm flex-shrink-0"
                    :class="Number(auth.activeCompanyId) === Number(c.id)
                      ? 'bg-primary-600 text-white'
                      : 'bg-primary-50 text-primary-700'">
                    {{ (c.code || c.name)?.[0]?.toUpperCase() }}
                  </span>
                  <span class="flex-1 min-w-0">
                    <span class="block text-sm font-medium text-gray-800 truncate">{{ c.name }}</span>
                    <span class="block text-xs text-gray-500 truncate">
                      <span v-if="c.code" class="font-mono">{{ c.code }}</span>
                      <span v-if="c.code && c.city"> • </span>
                      <span v-if="c.city">{{ c.city }}</span>
                    </span>
                  </span>
                  <CheckIcon v-if="Number(auth.activeCompanyId) === Number(c.id)"
                    class="w-4 h-4 text-primary-600 flex-shrink-0" />
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="flex items-center gap-3">
          <!-- Notifications -->
          <div class="relative">
            <button @click="notifMenuOpen = !notifMenuOpen; userMenuOpen = false; langMenuOpen = false; companyMenuOpen = false"
              class="relative p-2 text-gray-600 hover:text-primary-600 rounded-md hover:bg-gray-100">
              <BellIcon class="w-6 h-6" />
              <span v-if="unreadCount > 0" class="absolute -top-0.5 -right-0.5 w-5 h-5 rounded-full bg-red-500 text-white text-xs flex items-center justify-center">{{ unreadCount > 9 ? '9+' : unreadCount }}</span>
            </button>
            <div v-if="notifMenuOpen" class="absolute right-0 mt-2 w-80 bg-white rounded-md shadow-lg border border-gray-200 overflow-hidden">
              <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                <span class="font-semibold text-sm">{{ t('nav.notifications') }}</span>
                <button class="text-xs text-primary-600 hover:underline" @click="markAllRead">{{ t('nav.mark_all_read') }}</button>
              </div>
              <div class="max-h-80 overflow-y-auto">
                <div v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-gray-500">{{ t('nav.no_notifications') }}</div>
                <RouterLink v-for="n in notifications" :key="n.id" :to="n.link_url || '/notifications'"
                  class="block px-4 py-3 border-b border-gray-100 hover:bg-gray-50 last:border-0"
                  :class="{ 'bg-blue-50/40': ! n.is_read }">
                  <div class="text-sm font-medium text-gray-800">{{ n.title }}</div>
                  <div class="text-xs text-gray-500 line-clamp-2">{{ n.message }}</div>
                  <div class="text-xs text-gray-400 mt-1">{{ new Date(n.created_at).toLocaleString(locale === 'en' ? 'en-US' : 'id-ID') }}</div>
                </RouterLink>
              </div>
              <RouterLink to="/notifications" class="block px-4 py-2 text-center text-sm text-primary-600 hover:bg-gray-50">{{ t('nav.view_all') }}</RouterLink>
            </div>
          </div>

          <!-- Language switcher (i18n) -->
          <div class="relative">
            <button
              @click="langMenuOpen = !langMenuOpen; userMenuOpen = false; notifMenuOpen = false; companyMenuOpen = false"
              class="p-2 rounded-md hover:bg-gray-100 text-gray-600 inline-flex items-center gap-1"
              :title="t('language.label')"
              aria-label="Switch language"
            >
              <LanguageIcon class="w-5 h-5" />
              <span class="text-xs font-medium uppercase">{{ locale }}</span>
            </button>
            <div v-if="langMenuOpen"
              class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg border border-gray-200 overflow-hidden z-30">
              <button v-for="l in langs" :key="l.code"
                class="w-full flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50"
                :class="locale === l.code ? 'bg-primary-50 text-primary-700 font-medium' : 'text-gray-700'"
                @click="setLocale(l.code); langMenuOpen = false">
                <span class="text-base">{{ l.flag }}</span>
                <span>{{ l.label }}</span>
                <span v-if="locale === l.code" class="ml-auto text-primary-600">✓</span>
              </button>
            </div>
          </div>

          <!-- Dark mode toggle (Sprint 5-6 item S) -->
          <button
            @click="toggleDarkMode"
            class="p-2 rounded-md hover:bg-gray-100 text-gray-600"
            :title="`${t('theme.title')}: ${darkMode === 'auto' ? t('theme.auto') : (darkMode === 'dark' ? t('theme.dark') : t('theme.light'))}. ${t('theme.click_to_change')}.`"
            aria-label="Toggle theme"
          >
            <SunIcon v-if="darkMode === 'light'" class="w-5 h-5" />
            <MoonIcon v-else-if="darkMode === 'dark'" class="w-5 h-5" />
            <ComputerDesktopIcon v-else class="w-5 h-5" />
          </button>

          <!-- User menu -->
          <div class="relative">
            <button @click="userMenuOpen = !userMenuOpen; notifMenuOpen = false; langMenuOpen = false; companyMenuOpen = false"
              class="flex items-center gap-2 p-1.5 rounded-md hover:bg-gray-100">
              <div class="w-8 h-8 rounded-full bg-primary-100 flex items-center justify-center text-primary-700 font-semibold overflow-hidden">
                <img v-if="auth.user?.avatar_url || auth.employee?.avatar_url"
                  :src="auth.employee?.avatar_url || auth.user?.avatar_url"
                  :alt="auth.user?.name"
                  class="w-full h-full object-cover"
                  @error="(ev) => { ev.target.style.display = 'none' }" />
                <span v-else>{{ auth.user?.name?.[0]?.toUpperCase() || 'U' }}</span>
              </div>
              <div class="hidden md:block text-left">
                <div class="text-sm font-medium text-gray-800">{{ auth.user?.name }}</div>
                <div class="text-xs text-gray-500">{{ auth.role?.name }}</div>
              </div>
              <ChevronDownIcon class="w-4 h-4 text-gray-500" />
            </button>
            <div v-if="userMenuOpen" class="absolute right-0 mt-2 w-56 bg-white rounded-md shadow-lg border border-gray-200 overflow-hidden">
              <RouterLink to="/profile" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                <UserCircleIcon class="w-5 h-5" /> {{ t('nav.profile') }}
              </RouterLink>
              <RouterLink to="/change-password" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                <ShieldCheckIcon class="w-5 h-5" /> {{ t('nav.change_password') }}
              </RouterLink>
              <button @click="logout" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                <ArrowLeftStartOnRectangleIcon class="w-5 h-5" /> {{ t('nav.logout') }}
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

<style scoped>
/*
 * Sidebar scrollbar — slim, tone-on-tone dengan background slate-900,
 * fade-in saat hover supaya nav terasa lebih rapi.
 */
.sidebar-scroll {
  scrollbar-width: thin;
  scrollbar-color: rgba(148, 163, 184, 0.25) transparent;
  scrollbar-gutter: stable;
}
.sidebar-scroll:hover {
  scrollbar-color: rgba(148, 163, 184, 0.55) transparent;
}
.sidebar-scroll::-webkit-scrollbar {
  width: 6px;
}
.sidebar-scroll::-webkit-scrollbar-track {
  background: transparent;
}
.sidebar-scroll::-webkit-scrollbar-thumb {
  background-color: rgba(148, 163, 184, 0.25);
  border-radius: 9999px;
  transition: background-color 200ms ease;
}
.sidebar-scroll:hover::-webkit-scrollbar-thumb {
  background-color: rgba(148, 163, 184, 0.55);
}
.sidebar-scroll::-webkit-scrollbar-thumb:hover {
  background-color: rgba(148, 163, 184, 0.75);
}

/*
 * Nav item active state — corporate look dengan accent kiri tipis +
 * subtle inset background. Lebih kalem dibanding warna solid penuh,
 * cocok untuk dashboard enterprise.
 */
.nav-item.is-active {
  background-color: rgba(30, 41, 59, 0.85); /* slate-800 + alpha */
  color: #fff;
  font-weight: 500;
  box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.08);
}
.nav-item.is-active .active-indicator {
  opacity: 1;
}
.nav-item.is-active :is(svg) {
  color: #93c5fd; /* primary-300 — accent tipis */
}
</style>