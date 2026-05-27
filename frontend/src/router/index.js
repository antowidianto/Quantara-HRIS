import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  { path: '/login', name: 'login', component: () => import('@/views/Login.vue'), meta: { guest: true } },
  { path: '/forgot-password', name: 'forgot-password', component: () => import('@/views/ForgotPassword.vue'), meta: { guest: true } },
  { path: '/reset-password', name: 'reset-password', component: () => import('@/views/ResetPassword.vue'), meta: { guest: true } },

  // ─── Public Career Portal (no auth) ─────────────────────────
  // Lihat docs/CAREER_PORTAL.md. Pelamar mengakses tanpa login.
  {
    path: '/careers/:slug',
    component: () => import('@/layouts/CareerPublicLayout.vue'),
    meta: { public: true },
    children: [
      { path: '',                            name: 'career-home',     component: () => import('@/views/Career/CareerHome.vue') },
      { path: 'jobs/:jobSlug',               name: 'career-job',      component: () => import('@/views/Career/CareerJobDetail.vue') },
      { path: 'jobs/:jobSlug/apply',         name: 'career-apply',    component: () => import('@/views/Career/CareerApply.vue') },
      { path: 'success',                     name: 'career-success',  component: () => import('@/views/Career/CareerSuccess.vue') },
      { path: 'track',                       name: 'career-track',    component: () => import('@/views/Career/CareerTrack.vue') },
      { path: 'track/:token',                name: 'career-track-token', component: () => import('@/views/Career/CareerTrack.vue') },

      // Sprint 21+ — Candidate Account & Online Test
      // Pelamar punya akun tersendiri (terpisah dari user HRIS internal).
      // Lihat docs/ONLINE_TEST.md.
      { path: 'login',                       name: 'candidate-login',     component: () => import('@/views/Career/CandidateLogin.vue') },
      { path: 'register',                    name: 'candidate-register',  component: () => import('@/views/Career/CandidateRegister.vue') },
      { path: 'dashboard',                   name: 'candidate-dashboard', component: () => import('@/views/Career/CandidateDashboard.vue') },
      // Sprint 23+ — "Profil Saya" untuk pelamar; edit data lamaran
      // selama belum di stage final (HIRED/REJECTED/WITHDRAWN).
      { path: 'profile/:id',                 name: 'candidate-profile',   component: () => import('@/views/Career/CandidateProfile.vue') },
      { path: 'tests/:id',                   name: 'candidate-test-detail', component: () => import('@/views/Career/CandidateTakeTest.vue') },
      { path: 'tests/result/:attemptId',    name: 'candidate-test-result', component: () => import('@/views/Career/CandidateTestResult.vue') },
      // Sprint 25 / Option C — kandidat self-service review + accept/reject offer
      { path: 'offers',                      name: 'candidate-offers',        component: () => import('@/views/Career/CandidateOffers.vue') },
      { path: 'offers/:id',                  name: 'candidate-offer-detail',  component: () => import('@/views/Career/CandidateOfferDetail.vue') },
    ],
  },

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
      // Sprint 23+ — HR bonus history per employee (review/merit context)
      { path: 'employees/:id/bonus-history', name: 'employee-bonus-history', component: () => import('@/views/Payroll/Bonus/EmployeeBonusHistory.vue') },

      // Allowance mapping (per cabang & grade)
      { path: 'master/allowances', name: 'allowances', component: () => import('@/views/Master/Allowances.vue') },

      // Org Structure (Organization Development) — drag & drop tree
      { path: 'master/org-structure', name: 'org-structure', component: () => import('@/views/Master/OrgStructure.vue') },
      // Master Jabatan Kunci — assign penjabat HR Manager, Direktur, dst
      { path: 'master/organization-roles', name: 'organization-roles', component: () => import('@/views/Master/OrganizationRoles.vue') },

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
      // Cost Center master + budget vs actual report. Lihat docs/COST_CENTER.md.
      { path: 'master/cost-centers', name: 'cost-centers', component: () => import('@/views/Master/CostCenters.vue') },
      { path: 'master/regulations', name: 'regulations', component: () => import('@/views/Master/Regulations.vue') },
      { path: 'master/contract-templates', name: 'contract-templates', component: () => import('@/views/Master/ContractTemplates.vue') },
      { path: 'master/approval-chains', name: 'approval-chains', component: () => import('@/views/Master/ApprovalChains.vue') },
      { path: 'master/task-templates', name: 'task-templates', component: () => import('@/views/Master/TaskTemplates.vue') },

      // Approval inbox
      { path: 'approvals/inbox', name: 'approval-inbox', component: () => import('@/views/Approvals/Inbox.vue') },
      { path: 'approvals/delegations', name: 'approval-delegations', component: () => import('@/views/Approvals/MyDelegations.vue') },

      // ─── Horizon 1 (Sprint 7-9 Quick Wins) ───────────────────
      // H1.5 WFH Mode — pengajuan & approval inbox WFH
      { path: 'attendance/wfh', name: 'wfh-requests', component: () => import('@/views/Attendance/WfhRequests.vue') },
      // H1.4 Recruitment Funnel Metrics — bottleneck pipeline + stale candidates
      { path: 'recruitment/funnel', name: 'recruitment-funnel', component: () => import('@/views/Recruitment/FunnelMetrics.vue') },
      // H1.8 THR Planning Dashboard — proyeksi total THR per tahun
      { path: 'payroll/thr-planning', name: 'thr-planning', component: () => import('@/views/Payroll/ThrPlanning.vue') },

      // ─── Horizon 2 (Sprint 10-13 Core Deepening) ─────────────
      // H2.6 Turnover Analytics — turnover rate, early attrition, regrettable loss
      { path: 'hr/turnover-analytics', name: 'turnover-analytics', component: () => import('@/views/HR/TurnoverAnalytics.vue') },
      // H2.7 Payroll Simulation — what-if + compare + reverse THP
      { path: 'payroll/simulate', name: 'payroll-simulate', component: () => import('@/views/Payroll/PayrollSimulation.vue') },
      // H2.2 Goals Cascading Tree — visualisasi hierarchy goals
      { path: 'performance/goals-tree', name: 'goals-tree', component: () => import('@/views/Performance/GoalsTree.vue') },

      // ─── Horizon 3 (Sprint 14-17 Talent Management) ──────────
      // H3.1 Competency Library — master + position profile + gap analysis
      { path: 'hr/competencies', name: 'competencies', component: () => import('@/views/HR/CompetencyLibrary.vue') },
      // H3.2 Succession Planning — critical positions + successor + coverage
      { path: 'hr/succession', name: 'succession', component: () => import('@/views/HR/SuccessionPlanning.vue') },
      // H3.6 Talent Marketplace — skills + internal job board + 9-box
      { path: 'hr/talent-marketplace', name: 'talent-marketplace', component: () => import('@/views/HR/TalentMarketplace.vue') },

      // ─── Horizon 4 (Sprint 19+ Platform Evolution) ───────────
      // H4.1 AI HR Copilot — chat assistant dengan PII redactor
      { path: 'hr/ai-copilot', name: 'ai-copilot', component: () => import('@/views/HR/AiCopilot.vue') },
      // H4.4 Pulse Survey & Wellbeing — anonim eNPS + burnout
      { path: 'hr/pulse-survey', name: 'pulse-survey', component: () => import('@/views/HR/PulseSurvey.vue') },
      // H4.3 Workforce Scenarios — Monte Carlo modeling
      { path: 'hr/workforce-scenarios', name: 'workforce-scenarios', component: () => import('@/views/HR/WorkforceScenarios.vue') },
      // H4.5 Compensation Intelligence — compa-ratio, equity, bands, benchmarks
      { path: 'hr/compensation-intel', name: 'compensation-intel', component: () => import('@/views/HR/CompensationIntel.vue') },

      // ─── Tambahan Sprint 7-12 — Quick Wins polish & ESS ────
      // H1.9 WhatsApp Provider config (admin)
      { path: 'admin/whatsapp-settings', name: 'whatsapp-settings', component: () => import('@/views/Admin/WhatsAppSettings.vue') },
      // H2.5 Employee Referral Program — share link + leaderboard
      { path: 'recruitment/referral', name: 'referral-program', component: () => import('@/views/Recruitment/ReferralProgram.vue') },
      // H2.3 360° Peer Review — review, nominate, hasil
      { path: 'performance/peer-review', name: 'peer-review', component: () => import('@/views/Performance/PeerReview.vue') },
      // H2.4 Candidate Scoring Config — bobot kriteria + bulk recompute
      { path: 'recruitment/candidate-scoring', name: 'candidate-scoring', component: () => import('@/views/Recruitment/CandidateScoring.vue') },
      // H1.6 Overtime Pre-Approval Plan — submit rencana + realize
      { path: 'overtimes/plan', name: 'overtime-plan', component: () => import('@/views/Overtimes/OvertimePlan.vue') },

      // Disciplinary (Surat Peringatan)
      { path: 'hr/disciplinary', name: 'disciplinary', component: () => import('@/views/HR/Disciplinary.vue') },
      // Job Information Change (Promosi/Demosi/Mutasi) — lihat docs/JIC.md
      { path: 'hr/jic', name: 'jic', component: () => import('@/views/HR/JobInformationChange.vue') },
      // Employee Transfer (Mutasi sederhana antar branch/dept/posisi) — lihat docs/EMPLOYEE_TRANSFER.md
      { path: 'hr/transfers', name: 'employee-transfers', component: () => import('@/views/HR/EmployeeTransfers.vue') },
      // Self-service: karyawan lihat & approve/reject mutasi atas nama mereka sendiri
      { path: 'hr/my-transfers', name: 'my-transfers', component: () => import('@/views/HR/MyTransfers.vue') },
      // Severance (Pesangon / PHK)
      { path: 'hr/severance', name: 'severance', component: () => import('@/views/HR/Severance.vue') },
      // Onboarding & Offboarding
      { path: 'hr/onboarding', name: 'onboarding', component: () => import('@/views/HR/OnboardingBoard.vue') },
      // Probation Tracker (Sprint 2 / HIRE-1) — monitoring masa percobaan
      // PKWTT, decision PASS/FAIL/EXTEND. Lihat docs/SPRINT_2_RECRUITMENT_POLISH.md.
      { path: 'hr/probation', name: 'probation', component: () => import('@/views/HR/ProbationDashboard.vue') },
      // Sprint 3 — Individual Development Plan (OD-2)
      { path: 'hr/idp', name: 'idp', component: () => import('@/views/HR/IdpDashboard.vue') },
      // Sprint 4 — Termination workflow (OFF-5), Exit Interview (OFF-1),
      // Compliance Reports (CC-5). Lihat docs/SPRINT_4_TERMINATION_POLISH.md.
      { path: 'hr/terminations', name: 'terminations', component: () => import('@/views/HR/TerminationBoard.vue') },
      { path: 'hr/exit-interviews', name: 'exit-interviews', component: () => import('@/views/HR/ExitInterviews.vue') },
      { path: 'hr/compliance-reports', name: 'compliance-reports', component: () => import('@/views/HR/ComplianceReports.vue') },
      // Sprint 4 — Employee 360 view (CC-4) — aggregator profile lengkap
      { path: 'employees/:id/360', name: 'employee-360', component: () => import('@/views/Employees/Employee360.vue') },
      // Resignation — self-service untuk karyawan + halaman review HR/atasan.
      // Lihat docs/RESIGNATION.md.
      { path: 'hr/my-resignation', name: 'my-resignation', component: () => import('@/views/HR/MyResignation.vue') },
      { path: 'hr/resignations',   name: 'resignations',   component: () => import('@/views/HR/ResignationReview.vue') },
      // Asset / Inventory
      { path: 'hr/assets', name: 'assets', component: () => import('@/views/HR/Assets.vue') },
      // Business Trip / SPPD
      { path: 'travel', name: 'business-trips', component: () => import('@/views/Travel/TripList.vue') },
      // Recruitment / ATS
      // Manpower Planning (MPP) — pengajuan kebutuhan SDM dari N+1
      { path: 'recruitment/mpp', name: 'recruitment-mpp', component: () => import('@/views/Recruitment/MppList.vue') },
      { path: 'recruitment/jobs', name: 'recruitment-jobs', component: () => import('@/views/Recruitment/JobList.vue') },
      { path: 'recruitment/jobs/:id', name: 'recruitment-pipeline', component: () => import('@/views/Recruitment/PipelineBoard.vue') },
      // Career Portal — admin settings
      { path: 'recruitment/career-settings', name: 'career-settings', component: () => import('@/views/Career/CareerSettings.vue') },
      // Sprint 21+ — Online Tests admin (TPA, English, DISC, Technical)
      { path: 'recruitment/online-tests',         name: 'online-tests',           component: () => import('@/views/Recruitment/OnlineTests/TestList.vue') },
      { path: 'recruitment/online-tests/:id',     name: 'online-test-detail',     component: () => import('@/views/Recruitment/OnlineTests/TestBuilder.vue') },
      { path: 'recruitment/online-test-bank',     name: 'online-test-bank',       component: () => import('@/views/Recruitment/OnlineTests/QuestionBank.vue') },
      { path: 'recruitment/online-test-assignments', name: 'online-test-assignments', component: () => import('@/views/Recruitment/OnlineTests/AssignmentList.vue') },
      { path: 'recruitment/online-test-attempts/:id', name: 'online-test-attempt-review', component: () => import('@/views/Recruitment/OnlineTests/AttemptReview.vue') },
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
      // Sprint 21+ — Self-service riwayat bonus per karyawan
      { path: 'bonuses/my', name: 'my-bonuses', component: () => import('@/views/Payroll/MyBonuses.vue') },

      // Bonus Engine — Sprint 19+ admin UI; backend Sprint 16+ (lihat docs/BONUS_ENGINE.md)
      { path: 'payroll/bonus/schemes', name: 'bonus-schemes', component: () => import('@/views/Payroll/Bonus/BonusSchemeList.vue') },
      { path: 'payroll/bonus/runs',    name: 'bonus-runs',    component: () => import('@/views/Payroll/Bonus/BonusRunList.vue') },
      { path: 'payroll/bonus/runs/:id', name: 'bonus-run-detail', component: () => import('@/views/Payroll/Bonus/BonusRunDetail.vue') },

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
      { path: 'admin/email-settings', name: 'email-settings', component: () => import('@/views/Admin/EmailSettings.vue') },

      // Profile
      { path: 'profile', name: 'profile', component: () => import('@/views/Profile.vue') },
      { path: 'change-password', name: 'change-password', component: () => import('@/views/ChangePassword.vue') },
    ],
  },

  // Contract digital signing — accessed via notification link, NOT in sidebar
  { path: '/employee/contracts/sign/:id', name: 'contract-sign', component: () => import('@/views/Employees/ContractSign.vue'), meta: { requiresAuth: true } },

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