import core from './id/core.js'
import time from './id/time.js'
import employees from './id/employees.js'
import payroll from './id/payroll.js'
import workflow from './id/workflow.js'
import admin from './id/admin.js'
import master from './id/master.js'
import messaging from './id/messaging.js'
import quality from './id/quality.js'
import compensation from './id/compensation.js'
import feCleanup from './id/fe_cleanup.js'
import training from './id/training.js'
import roster from './id/roster.js'
import employeeForm from './id/employee_form.js'
import employee360 from './id/employee_360.js'
import employeeList from './id/employee_list.js'
import contractSign from './id/contract_sign.js'
import employeeModal from './id/employee_modal.js'
import costCenters from './id/cost_centers.js'
import orgStructure from './id/org_structure.js'
import organizationRoles from './id/organization_roles.js'
import recruitmentPipelines from './id/recruitment_pipelines.js'
import benefitPolicies from './id/benefit_policies.js'
import allowanceNatura from './id/allowance_natura.js'
import loginMarketing from './id/login_marketing.js'

export default {
  ...core,
  ...time,
  ...employees,
  ...payroll,
  ...workflow,
  ...admin,
  users: {
    ...admin.users,
    search_placeholder: 'Cari NIK, nama, atau email...',
    all_roles: 'Semua role',
    all_statuses: 'Semua status',
    all_link_statuses: 'Semua keterhubungan',
    linked: 'Terhubung ke karyawan',
    unlinked: 'Belum terhubung',
    inactive: 'Non-aktif',
    filter: 'Filter',
    reset: 'Reset',
    loading: 'Memuat...',
    no_results: 'Tidak ada pengguna yang cocok dengan filter.',
    load_failed: 'Gagal memuat data pengguna',
    total: 'Total: {count} pengguna',
    previous: 'Sebelumnya',
    next: 'Berikutnya',
    page_of: 'Halaman {current} / {total}',
  },
  ...master,
  ...messaging,
  ...quality,
  ...compensation,
  ...feCleanup,
  ...training,
  ...roster,
  ...employeeForm,
  ...employee360,
  ...employeeList,
  ...contractSign,
  ...employeeModal,
  ...costCenters,
  ...orgStructure,
  ...organizationRoles,
  ...recruitmentPipelines,
  ...benefitPolicies,
  ...allowanceNatura,
  ...loginMarketing,
}
