import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  { path: '/login', name: 'login', component: () => import('@/views/Login.vue'), meta: { guest: true } },
  { path: '/forgot-password', name: 'forgot-password', component: () => import('@/views/ForgotPassword.vue'), meta: { guest: true } },
  { path: '/reset-password', name: 'reset-password', component: () => import('@/views/ResetPassword.vue'), meta: { guest: true } },

  {
    path: '/',
    component: () => import('@/layouts/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', redirect: '/dashboard' },
      { path: 'dashboard', name: 'dashboard', component: () => import('@/views/Dashboard.vue') },

      // Employees — semua aksi (lihat/edit/tambah) sekarang lewat modal
      // di EmployeeList. Kita tetap dukung URL legacy supaya bookmark
      // /employees/123 tidak rusak; route legacy itu render list yang
      // akan otomatis membuka modal sesuai path (lihat syncFromRoute).
      { path: 'employees', name: 'employees', component: () => import('@/views/Employees/EmployeeList.vue') },
      { path: 'employees/new', name: 'employee-new', component: () => import('@/views/Employees/EmployeeList.vue') },
      { path: 'employees/:id', name: 'employee-detail', component: () => import('@/views/Employees/EmployeeList.vue') },
      { path: 'employees/:id/edit', name: 'employee-edit', component: () => import('@/views/Employees/EmployeeList.vue') },

      // Allowance mapping (per cabang & grade)
      { path: 'master/allowances', name: 'allowances', component: () => import('@/views/Master/Allowances.vue') },

      // Master Data
      { path: 'master/branches', name: 'branches', component: () => import('@/views/Master/Branches.vue') },
      { path: 'master/work-locations', name: 'work-locations', component: () => import('@/views/Master/WorkLocations.vue') },
      { path: 'master/departments', name: 'departments', component: () => import('@/views/Master/Departments.vue') },
      { path: 'master/positions', name: 'positions', component: () => import('@/views/Master/Positions.vue') },
      { path: 'master/job-grades', name: 'job-grades', component: () => import('@/views/Master/JobGrades.vue') },
      { path: 'master/shifts', name: 'shifts', component: () => import('@/views/Master/Shifts.vue') },
      { path: 'master/shift-assignments', name: 'shift-assignments', component: () => import('@/views/Master/ShiftAssignments.vue') },
      { path: 'master/shift-patterns', name: 'shift-patterns', component: () => import('@/views/Master/ShiftPatterns.vue') },

      // Roster
      { path: 'roster', name: 'roster', component: () => import('@/views/Roster/RosterMatrix.vue') },
      { path: 'master/leave-types', name: 'leave-types', component: () => import('@/views/Master/LeaveTypes.vue') },
      { path: 'master/payroll-components', name: 'payroll-components', component: () => import('@/views/Master/PayrollComponents.vue') },
      { path: 'master/regulations', name: 'regulations', component: () => import('@/views/Master/Regulations.vue') },
      { path: 'master/contract-templates', name: 'contract-templates', component: () => import('@/views/Master/ContractTemplates.vue') },
      { path: 'master/approval-chains', name: 'approval-chains', component: () => import('@/views/Master/ApprovalChains.vue') },
      { path: 'master/task-templates', name: 'task-templates', component: () => import('@/views/Master/TaskTemplates.vue') },

      // Approval inbox
      { path: 'approvals/inbox', name: 'approval-inbox', component: () => import('@/views/Approvals/Inbox.vue') },
      { path: 'approvals/delegations', name: 'approval-delegations', component: () => import('@/views/Approvals/MyDelegations.vue') },

      // Disciplinary (Surat Peringatan)
      { path: 'hr/disciplinary', name: 'disciplinary', component: () => import('@/views/HR/Disciplinary.vue') },
      // Severance (Pesangon / PHK)
      { path: 'hr/severance', name: 'severance', component: () => import('@/views/HR/Severance.vue') },
      // Onboarding & Offboarding
      { path: 'hr/onboarding', name: 'onboarding', component: () => import('@/views/HR/OnboardingBoard.vue') },
      // Asset / Inventory
      { path: 'hr/assets', name: 'assets', component: () => import('@/views/HR/Assets.vue') },
      // Business Trip / SPPD
      { path: 'travel', name: 'business-trips', component: () => import('@/views/Travel/TripList.vue') },
      // Recruitment / ATS
      { path: 'recruitment/jobs', name: 'recruitment-jobs', component: () => import('@/views/Recruitment/JobList.vue') },
      { path: 'recruitment/jobs/:id', name: 'recruitment-pipeline', component: () => import('@/views/Recruitment/PipelineBoard.vue') },
      // HR Helpdesk / Tickets
      { path: 'helpdesk/tickets', name: 'helpdesk-tickets', component: () => import('@/views/Helpdesk/TicketList.vue') },
      { path: 'helpdesk/tickets/:id', name: 'helpdesk-ticket-detail', component: () => import('@/views/Helpdesk/TicketDetail.vue') },
      // Training & Sertifikasi
      { path: 'training', name: 'training', component: () => import('@/views/Training/TrainingList.vue') },
      { path: 'training/:id', name: 'training-detail', component: () => import('@/views/Training/TrainingDetail.vue') },
      { path: 'certificates', name: 'certificates', component: () => import('@/views/Training/CertificateList.vue') },
      // Performance Management (OKR / KPI / Review)
      { path: 'performance', name: 'performance', component: () => import('@/views/Performance/PerformanceList.vue') },
      { path: 'performance/cycles/:id', name: 'performance-cycle', component: () => import('@/views/Performance/CycleDetail.vue') },
      { path: 'performance/reviews/:id', name: 'performance-review', component: () => import('@/views/Performance/ReviewDetail.vue') },
      { path: 'performance/my', name: 'my-performance', component: () => import('@/views/Performance/MyPerformance.vue') },

      // Generated contracts (kontrak yang sudah digenerate dari template)
      { path: 'employees/contracts', name: 'employee-contracts', component: () => import('@/views/Employees/GeneratedContracts.vue') },

      // Attendance
      { path: 'attendance', name: 'attendance', component: () => import('@/views/Attendance/AttendanceList.vue') },
      { path: 'attendance/clock', name: 'clock', component: () => import('@/views/Attendance/Clock.vue') },
      { path: 'attendance/my', name: 'my-attendance', component: () => import('@/views/Attendance/MyAttendance.vue') },
      { path: 'attendance/my-changes', name: 'my-attendance-changes', component: () => import('@/views/Attendance/MyChangeRequests.vue') },
      { path: 'attendance/change-requests/approvals', name: 'attendance-change-approvals', component: () => import('@/views/Attendance/ChangeRequestApprovals.vue') },
      { path: 'attendance/anomalies', name: 'attendance-anomalies', component: () => import('@/views/Attendance/Anomalies.vue') },

      // Leave
      { path: 'leaves', name: 'leaves', component: () => import('@/views/Leaves/LeaveList.vue') },
      { path: 'leaves/new', name: 'leave-new', component: () => import('@/views/Leaves/LeaveForm.vue') },
      { path: 'leaves/balance', name: 'leave-balance', component: () => import('@/views/Leaves/LeaveBalance.vue') },
      { path: 'leaves/team-calendar', name: 'leave-team-calendar', component: () => import('@/views/Leaves/TeamCalendar.vue') },

      // Overtime
      { path: 'overtimes', name: 'overtimes', component: () => import('@/views/Overtimes/OvertimeList.vue') },
      { path: 'overtimes/new', name: 'overtime-new', component: () => import('@/views/Overtimes/OvertimeForm.vue') },

      // Payroll
      { path: 'payroll/periods', name: 'payroll-periods', component: () => import('@/views/Payroll/PeriodList.vue') },
      { path: 'payroll/periods/new', name: 'payroll-period-new', component: () => import('@/views/Payroll/PeriodForm.vue') },
      { path: 'payroll/periods/:id', name: 'payroll-period-detail', component: () => import('@/views/Payroll/PeriodDetail.vue') },
      { path: 'payslips', name: 'payslips', component: () => import('@/views/Payroll/PayslipList.vue') },
      { path: 'payslips/my', name: 'my-payslips', component: () => import('@/views/Payroll/MyPayslips.vue') },
      { path: 'payslips/:id', name: 'payslip-detail', component: () => import('@/views/Payroll/PayslipDetail.vue') },

      // Reimbursement / Loan
      { path: 'reimbursements', name: 'reimbursements', component: () => import('@/views/Finance/ReimbursementList.vue') },
      { path: 'loans', name: 'loans', component: () => import('@/views/Finance/LoanList.vue') },

      // Notifications
      { path: 'notifications', name: 'notifications', component: () => import('@/views/Notifications.vue') },

      // Reports
      { path: 'reports', name: 'reports', component: () => import('@/views/Reports.vue') },

      // Admin
      { path: 'admin/users', name: 'users', component: () => import('@/views/Admin/Users.vue') },
      { path: 'admin/roles', name: 'roles', component: () => import('@/views/Admin/Roles.vue') },
      { path: 'admin/audit-logs', name: 'audit-logs', component: () => import('@/views/Admin/AuditLogs.vue') },
      { path: 'admin/companies', name: 'companies', component: () => import('@/views/Admin/Companies.vue') },
      { path: 'admin/settings', name: 'settings', component: () => import('@/views/Admin/Settings.vue') },

      // Profile
      { path: 'profile', name: 'profile', component: () => import('@/views/Profile.vue') },
      { path: 'change-password', name: 'change-password', component: () => import('@/views/ChangePassword.vue') },
    ],
  },

  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFound.vue') },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to, from, next) => {
  const auth = useAuthStore()

  if (to.meta.requiresAuth && ! auth.isAuthenticated) {
    return next({ name: 'login', query: { redirect: to.fullPath } })
  }
  if (to.meta.guest && auth.isAuthenticated) {
    return next({ name: 'dashboard' })
  }
  next()
})

export default router