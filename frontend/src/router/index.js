import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { ensureFeaturesLoaded } from '@/composables/useFeature'
import { ensureSubscriptionLoaded } from '@/composables/useSubscription'
import { resolveNavigation } from './guard'

const routes = [
  { path: '/login', name: 'login', component: () => import('@/views/Login.vue'), meta: { guest: true } },
  { path: '/forgot-password', name: 'forgot-password', component: () => import('@/views/ForgotPassword.vue'), meta: { guest: true } },
  { path: '/reset-password', name: 'reset-password', component: () => import('@/views/ResetPassword.vue'), meta: { guest: true } },

  // S1.3 SaaS — Self-Service Tenant Signup (publik, no auth)
  // Signup form + email verification handler. Verify route TIDAK pakai
  // `meta.guest: true` karena setelah verify sukses kita auto-login user
  // dan butuh redirect ke /onboarding (route auth required). Guest guard
  // akan kick user yg sudah login ke dashboard sebelum verify selesai.
  { path: '/signup',         name: 'signup',         component: () => import('@/views/Signup.vue'),       meta: { guest: true } },
  { path: '/verify-email',   name: 'verify-email',   component: () => import('@/views/VerifyEmail.vue'),  meta: { public: true } },

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
      { path: 'my-team', name: 'my-team', component: () => import('@/views/Employees/MyTeam.vue') },
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
      { path: 'master/job-titles', name: 'job-titles', component: () => import('@/views/Master/JobTitles.vue') },
      { path: 'master/job-grades', name: 'job-grades', component: () => import('@/views/Master/JobGrades.vue') },
      // Recruitment master — pipeline + jenis test online (Migration #065)
      { path: 'master/recruitment-pipelines', name: 'recruitment-pipelines',
        component: () => import('@/views/Master/RecruitmentPipelines.vue') },
      { path: 'master/online-test-categories', name: 'online-test-categories',
        component: () => import('@/views/Master/OnlineTestCategories.vue') },
      { path: 'master/shifts', name: 'shifts', component: () => import('@/views/Master/Shifts.vue') },
      { path: 'master/shift-assignments', name: 'shift-assignments', component: () => import('@/views/Master/ShiftAssignments.vue') },
      { path: 'master/shift-patterns', name: 'shift-patterns', component: () => import('@/views/Master/ShiftPatterns.vue') },

      // Roster
      { path: 'roster', name: 'roster', component: () => import('@/views/Roster/RosterMatrix.vue') },
      { path: 'master/leave-types', name: 'leave-types', component: () => import('@/views/Master/LeaveTypes.vue') },
      { path: 'master/payroll-components', name: 'payroll-components', component: () => import('@/views/Master/PayrollComponents.vue') },
      { path: 'master/benefit-policies', name: 'benefit-policies', component: () => import('@/views/Master/BenefitPolicies.vue') },
      // Cost Center master + budget vs actual report. Lihat docs/COST_CENTER.md.
      { path: 'master/cost-centers', name: 'cost-centers', component: () => import('@/views/Master/CostCenters.vue') },
      { path: 'master/regulations', name: 'regulations', component: () => import('@/views/Master/Regulations.vue') },
      { path: 'master/contract-templates', name: 'contract-templates', component: () => import('@/views/Master/ContractTemplates.vue') },
      { path: 'master/approval-chains', name: 'approval-chains', component: () => import('@/views/Master/ApprovalChains.vue'), meta: { permission: 'approval_chain.view' } },
      { path: 'master/task-templates', name: 'task-templates', component: () => import('@/views/Master/TaskTemplates.vue') },

      // Approval inbox
      { path: 'approvals/inbox', name: 'approval-inbox', component: () => import('@/views/Approvals/Inbox.vue') },
      { path: 'approvals/delegations', name: 'approval-delegations', component: () => import('@/views/Approvals/MyDelegations.vue') },
      { path: 'approvals/loans', name: 'approval-loans', component: () => import('@/views/Approvals/Loans.vue') },

      // ─── Horizon 1 (Sprint 7-9 Quick Wins) ───────────────────
      // H1.5 WFH Mode — pengajuan & approval inbox WFH
      { path: 'attendance/wfh', name: 'wfh-requests', component: () => import('@/views/Attendance/WfhRequests.vue') },
      // Backward compatibility untuk link notifikasi WFH lama.
      { path: 'wfh-requests/:id', redirect: to => ({
        name: 'wfh-requests',
        query: { request_id: to.params.id },
      }) },
      // ADMS / Physical Devices (Fingerprint)
      { path: 'attendance/physical-devices', name: 'physical-devices', component: () => import('@/views/Attendance/PhysicalDevices.vue'), meta: { permission: 'attendance.manage_devices' } },
      // H1.4 Recruitment Funnel Metrics — bottleneck pipeline + stale candidates
      { path: 'recruitment/funnel', name: 'recruitment-funnel', component: () => import('@/views/Recruitment/FunnelMetrics.vue') },
      // H1.8 THR Planning Dashboard — proyeksi total THR per tahun
      { path: 'payroll/thr-planning', name: 'thr-planning', component: () => import('@/views/Payroll/ThrPlanning.vue') },

      // ─── Horizon 2 (Sprint 10-13 Core Deepening) ─────────────
      // H2.6 Turnover Analytics — turnover rate, early attrition, regrettable loss
      { path: 'hr/turnover-analytics', name: 'turnover-analytics', component: () => import('@/views/HR/TurnoverAnalytics.vue') },
      { path: 'hr/data-quality', name: 'employee-data-quality', component: () => import('@/views/HR/EmployeeDataQuality.vue'), meta: { permissionAny: ['hr_analytics.view', 'compliance.report.view'] } },
      { path: 'hr/compensation-review', name: 'compensation-review', component: () => import('@/views/HR/CompensationReview.vue'), meta: { permissionAny: ['employee.view', 'payroll.view'] } },
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

      // ─── Fase 4: Talent Management ───
      { path: 'talent/review-360', name: 'talent-review-360', component: () => import('@/views/Talent/Review360.vue'), meta: { permissionAny: ['review.view_team', 'review.conduct'] } },
      { path: 'talent/nine-box', name: 'talent-nine-box', component: () => import('@/views/Talent/NineBox.vue') },
      { path: 'talent/succession', name: 'talent-succession', component: () => import('@/views/Talent/SuccessionPlan.vue') },

      // ─── Horizon 4 (Sprint 19+ Platform Evolution) ───────────
      // H4.1 AI HR Copilot — chat assistant dengan PII redactor
      { path: 'hr/ai-copilot', name: 'ai-copilot', component: () => import('@/views/HR/AiCopilot.vue') },
      // H4.4 Pulse Survey & Wellbeing — anonim eNPS + burnout
      { path: 'hr/pulse-survey', name: 'pulse-survey', component: () => import('@/views/HR/PulseSurvey.vue') },
      // H4.3 Workforce Scenarios — Monte Carlo modeling
      { path: 'hr/workforce-scenarios', name: 'workforce-scenarios', component: () => import('@/views/HR/WorkforceScenarios.vue') },
      // H4.5 Compensation Intelligence — compa-ratio, equity, bands, benchmarks
      { path: 'hr/compensation-intel', name: 'compensation-intel', component: () => import('@/views/HR/CompensationIntel.vue') },

      // ─── Sprint 20 — Enterprise Readiness Modules ──────────────
      // H3.4 ERP Journal Integration (Accurate / Zahir / Odoo / SAP / CSV)
      { path: 'erp/journal', name: 'erp-journal', component: () => import('@/views/Erp/ErpJournalDashboard.vue'), meta: { permission: 'erp.journal.view', feature: 'integration.erp' } },
      // H3.7 Digital Signing PSrE (PrivyID / Vida / Peruri)
      { path: 'signing',         name: 'signing-envelopes', component: () => import('@/views/Signing/SigningEnvelopes.vue') },
      // H3.8 Open API & Developer Portal
      { path: 'developer/api-keys', name: 'api-keys',     component: () => import('@/views/Developer/ApiKeys.vue') },
      // Project Timesheet (jasa profesional / konsultan / agency)
      { path: 'timesheet',       name: 'timesheet',       component: () => import('@/views/Timesheet/TimesheetEntry.vue') },
      // License & Certification Expiry Tracker (healthcare / EHS)
      { path: 'licenses',        name: 'licenses',        component: () => import('@/views/License/LicenseDashboard.vue') },

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
      { path: 'performance/reviews/:id', name: 'performance-review', component: () => import('@/views/Performance/ReviewDetail.vue'), meta: { permissionAny: ['review.view_team', 'review.conduct', 'review.conduct_self'] } },
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
      { path: 'payroll/periods', name: 'payroll-periods', component: () => import('@/views/Payroll/PeriodList.vue'), meta: { permission: 'payroll.view' } },
      { path: 'payroll/periods/new', name: 'payroll-period-new', component: () => import('@/views/Payroll/PeriodForm.vue'), meta: { permission: 'payroll.manage', requiresWritableSubscription: true } },
      { path: 'payroll/periods/:id', name: 'payroll-period-detail', component: () => import('@/views/Payroll/PeriodDetail.vue'), meta: { permission: 'payroll.view' } },
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
      { path: 'loans/my', name: 'my-loans', component: () => import('@/views/Payroll/MyLoans.vue') },

      // Notifications
      { path: 'notifications', name: 'notifications', component: () => import('@/views/Notifications.vue') },
      { path: 'profile/notification-settings', name: 'notification-settings', component: () => import('@/views/NotificationPreferences.vue') },
      { path: 'admin/notification-broadcast', name: 'notification-broadcast', component: () => import('@/views/Admin/NotificationBroadcast.vue'), meta: { permission: 'notifications.broadcast' } },

      // Reports
      { path: 'reports', name: 'reports', component: () => import('@/views/Reports.vue') },

      // Admin
      { path: 'admin/users', name: 'users', component: () => import('@/views/Admin/Users.vue'), meta: { permission: 'user.manage' } },
      { path: 'admin/roles', name: 'roles', component: () => import('@/views/Admin/Roles.vue'), meta: { permission: 'role.manage' } },
      { path: 'admin/audit-logs', name: 'audit-logs', component: () => import('@/views/Admin/AuditLogs.vue') },
      { path: 'admin/companies', name: 'companies', component: () => import('@/views/Admin/Companies.vue') },
      { path: 'admin/settings', name: 'settings', component: () => import('@/views/Admin/Settings.vue') },
      { path: 'admin/email-settings', name: 'email-settings', component: () => import('@/views/Admin/EmailSettings.vue') },

      // ─── Roadmap SaaS Phase 1-3 (S1.x..S3.5) ──────────────────────────────
      // Tenant-facing: billing, branding, data export, onboarding
      { path: 'billing/plan',          name: 'billing-plan',     component: () => import('@/views/Billing/BillingPlan.vue') },
      { path: 'billing/usage',         name: 'billing-usage',    component: () => import('@/views/Billing/BillingUsage.vue') },
      { path: 'settings/branding',     name: 'settings-branding', component: () => import('@/views/Settings/BrandingSettings.vue') },
      { path: 'settings/data-export',  name: 'settings-data-export', component: () => import('@/views/Settings/DataExport.vue') },
      { path: 'onboarding',            name: 'onboarding-wizard', component: () => import('@/views/Onboarding/OnboardingWizard.vue') },

      // Quantara internal (super_admin) — SaaS console
      { path: 'admin/tenants',         name: 'admin-tenants',    component: () => import('@/views/Admin/TenantList.vue') },
      { path: 'admin/tenants/:id',     name: 'admin-tenant-detail', component: () => import('@/views/Admin/TenantDetail.vue'), props: true },
      { path: 'admin/saas-metrics',    name: 'admin-saas-metrics', component: () => import('@/views/Admin/SaasMetrics.vue') },
      { path: 'admin/customer-health', name: 'admin-customer-health', component: () => import('@/views/Admin/CustomerHealth.vue') },
      { path: 'admin/operations', name: 'admin-operations', component: () => import('@/views/Admin/SuperAdminDashboard.vue'), meta: { superAdminOnly: true } },
      { path: 'admin/dashboard-builder', name: 'admin-dashboard-builder', component: () => import('@/views/Admin/DashboardBuilder.vue'), meta: { superAdminOnly: true } },

      // Migration #076 — Menu Manager (sidebar dinamis)
      { path: 'admin/menus', name: 'menus', component: () => import('@/views/Admin/Menus.vue') },

      // Profile
      { path: 'profile', name: 'profile', component: () => import('@/views/Profile.vue') },
      { path: 'change-password', name: 'change-password', component: () => import('@/views/ChangePassword.vue') },
    ],
  },

  // Contract digital signing — accessed via notification link, NOT in sidebar
  { path: '/employee/contracts/sign/:id', name: 'contract-sign', component: () => import('@/views/Employees/ContractSign.vue'), meta: { requiresAuth: true } },

  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFound.vue') },
]

// Route-level RBAC is defense-in-depth for direct URLs. The sidebar already
// filters menu entries, but hiding a menu alone must never make the page
// reachable. Detail routes inherit the same read permission as their list.
const routePermissionByName = Object.freeze({
  employees: 'employee.view', 'employee-new': 'employee.create', 'employee-detail': 'employee.view',
  'employee-edit': 'employee.update', 'employee-360': 'employee.view', 'employee-contracts': 'employee.view',
  'employee-bonus-history': 'bonus.view', attendance: 'attendance.view_all', roster: 'roster.view',
  'attendance-change-approvals': 'attendance.approve_change', 'attendance-anomalies': 'attendance.review_anomaly',
  allowances: 'payroll.view', 'org-structure': 'org_structure.view', 'organization-roles': 'org_structure.view',
  branches: 'branch.view', 'work-locations': 'work_location.view', departments: 'department.view', positions: 'position.view',
  'job-titles': 'job_title.view', 'job-grades': 'hr.manage', 'recruitment-pipelines': 'recruitment_pipeline.view',
  'online-test-categories': 'online_test_category.manage', shifts: 'shift.view', 'shift-assignments': 'shift_schedules.view',
  'shift-patterns': 'shift_pattern.view', 'leave-types': 'leave.manage_types', 'payroll-components': 'payroll.manage',
  'benefit-policies': 'payroll.manage', 'cost-centers': 'cost_center.view', regulations: 'regulation.view',
  'contract-templates': 'contract_template.view', 'task-templates': 'onboarding.view',
  'approval-loans': 'loan.view', 'physical-devices': 'attendance.manage_devices',
  'recruitment-funnel': 'recruitment.view', 'thr-planning': 'payroll.view', 'turnover-analytics': 'hr_analytics.view',
  'payroll-simulate': 'payroll.simulate', 'goals-tree': 'performance.view', competencies: 'competency.view',
  succession: 'succession.view', 'nine-box': 'performance.view',
  'talent-succession': 'succession.view', 'ai-copilot': 'ai_assistant.use', 'workforce-scenarios': 'workforce_planning.view',
  'compensation-intel': 'compensation_intelligence.view', 'erp-journal': 'erp.journal.view',
  'signing-envelopes': 'signing.envelope.view', 'api-keys': 'api_key.view', licenses: 'employee_license.view',
  'whatsapp-settings': 'settings.manage', 'candidate-scoring': 'recruitment.manage', disciplinary: 'disciplinary.view',
  jic: 'jic.view', 'employee-transfers': 'employee_transfer.view', severance: 'severance.view',
  onboarding: 'onboarding.view', probation: 'probation.manage', idp: 'idp.view', terminations: 'termination.view',
  'exit-interviews': 'exit_interview.view', 'compliance-reports': 'compliance.report.view', resignations: 'resignation.view',
  assets: 'asset.view', 'business-trips': 'business_trip.view', 'recruitment-mpp': 'mpp.view',
  'recruitment-jobs': 'recruitment.view', 'recruitment-pipeline': 'recruitment.view', 'career-settings': 'careers.manage',
  'online-tests': 'online_tests.view', 'online-test-detail': 'online_tests.view', 'online-test-bank': 'online_tests.manage',
  'online-test-assignments': 'online_tests.view', 'online-test-attempt-review': 'online_tests.view',
  'helpdesk-tickets': 'helpdesk.view', 'helpdesk-ticket-detail': 'helpdesk.view', training: 'training.view',
  'training-detail': 'training.view', certificates: 'certificate.view', performance: 'performance.view',
  'performance-cycle': 'performance.view', 'performance-review': 'performance.view', payslips: 'payslip.view_all',
  'bonus-schemes': 'bonus.manage', 'bonus-runs': 'bonus.view', 'bonus-run-detail': 'bonus.view',
  reimbursements: 'reimbursement.view', loans: 'loan.view', reports: 'report.view', 'audit-logs': 'audit.view',
  settings: 'settings.manage', 'email-settings': 'settings.manage', 'billing-plan': 'billing.manage',
  'billing-usage': 'billing.manage', 'settings-branding': 'branding.manage', 'settings-data-export': 'data_export.manage',
  menus: 'menu.manage', 'notification-broadcast': 'notifications.broadcast',
  'my-team': 'employee.view', 'wfh-requests': 'attendance.view', 'talent-nine-box': 'talent_pool.view',
  'referral-program': 'referral.create_self', 'peer-review': 'performance.view', 'overtime-plan': 'overtime.create',
  clock: 'attendance.clock', 'my-attendance': 'attendance.view_self', 'my-attendance-changes': 'attendance.view',
  leaves: 'leave.view', 'leave-new': 'leave.create', 'leave-balance': 'leave.view',
  'leave-team-calendar': 'leave.team_calendar', overtimes: 'overtime.view', 'overtime-new': 'overtime.create',
  'my-payslips': 'payslip.view_self', 'my-bonuses': 'bonus.view_self', 'my-loans': 'loan.view',
})

const routePermissionAnyByName = Object.freeze({
  'talent-marketplace': ['internal_job.apply', 'talent_pool.view', 'talent_pool.manage'],
  timesheet: ['timesheet.submit', 'timesheet.approve', 'timesheet.report'],
  'my-transfers': ['employee_transfer.view_self', 'employee_transfer.view'],
  'my-resignation': ['resignation.view_self', 'resignation.view'],
  'payslip-detail': ['payslip.view_self', 'payslip.view_all'],
})

const employeeOnlyRouteNames = new Set([
  'talent-marketplace', 'referral-program', 'my-transfers', 'my-resignation', 'my-performance',
  'clock', 'my-attendance', 'my-attendance-changes', 'leave-new', 'leave-balance', 'overtime-plan',
  'overtime-new', 'my-payslips', 'my-bonuses', 'my-loans', 'pulse-survey',
])

const superAdminRouteNames = new Set([
  'companies', 'admin-tenants', 'admin-tenant-detail', 'admin-saas-metrics', 'admin-customer-health', 'admin-operations',
  'admin-dashboard-builder',
])

function applyRouteAccessDefaults(routeList) {
  routeList.forEach((route) => {
    const permission = routePermissionByName[route.name]
    if (permission && !route.meta?.permission && !route.meta?.permissionAny) {
      route.meta = { ...(route.meta || {}), permission }
    }
    const permissionAny = routePermissionAnyByName[route.name]
    if (permissionAny && !route.meta?.permission && !route.meta?.permissionAny) {
      route.meta = { ...(route.meta || {}), permissionAny }
    }
    if (employeeOnlyRouteNames.has(route.name)) {
      route.meta = { ...(route.meta || {}), requiresEmployee: true }
    }
    if (superAdminRouteNames.has(route.name)) {
      route.meta = { ...(route.meta || {}), superAdminOnly: true }
    }
    if (route.children) applyRouteAccessDefaults(route.children)
  })
}

applyRouteAccessDefaults(routes)

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to, from, next) => {
  const auth = useAuthStore()
  const featureMap = to.meta.feature ? await ensureFeaturesLoaded() : null
  const subscription = to.meta.requiresWritableSubscription
    ? await ensureSubscriptionLoaded()
    : null
  const resolution = resolveNavigation(to, auth, featureMap, subscription?.status || null)
  next(resolution === true ? undefined : resolution)
})

export default router
