import core from './en/core.js'
import time from './en/time.js'
import employees from './en/employees.js'
import payroll from './en/payroll.js'
import workflow from './en/workflow.js'
import admin from './en/admin.js'
import master from './en/master.js'
import messaging from './en/messaging.js'
import quality from './en/quality.js'
import compensation from './en/compensation.js'
import feCleanup from './en/fe_cleanup.js'
import training from './en/training.js'
import roster from './en/roster.js'
import employeeForm from './en/employee_form.js'
import employee360 from './en/employee_360.js'
import employeeList from './en/employee_list.js'
import contractSign from './en/contract_sign.js'
import employeeModal from './en/employee_modal.js'
import costCenters from './en/cost_centers.js'
import orgStructure from './en/org_structure.js'
import organizationRoles from './en/organization_roles.js'
import recruitmentPipelines from './en/recruitment_pipelines.js'
import benefitPolicies from './en/benefit_policies.js'
import allowanceNatura from './en/allowance_natura.js'
import loginMarketing from './en/login_marketing.js'

export default {
  ...core,
  ...time,
  ...employees,
  ...payroll,
  ...workflow,
  ...admin,
  users: {
    ...admin.users,
    search_placeholder: 'Search NIK, name, or email...',
    all_roles: 'All roles',
    all_statuses: 'All statuses',
    all_link_statuses: 'All link statuses',
    linked: 'Linked to employee',
    unlinked: 'Not linked',
    inactive: 'Inactive',
    filter: 'Filter',
    reset: 'Reset',
    loading: 'Loading...',
    no_results: 'No users match the filters.',
    load_failed: 'Failed to load user data',
    total: 'Total: {count} users',
    previous: 'Previous',
    next: 'Next',
    page_of: 'Page {current} / {total}',
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
