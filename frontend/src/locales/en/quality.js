export default {
  data_quality: {
    nav_label: 'Employee Data Quality',
    title: 'Employee Data Quality',
    subtitle: 'Tenant-scoped validation of identity, payroll readiness, organization mapping, and expiring records.',
    refresh: 'Refresh', loading: 'Checking employee data...', load_failed: 'Failed to load data-quality results.',
    active_employees: 'Active employees', total_issues: 'Issue records', generated_at: 'Generated',
    no_issues: 'No issues found in this category.', samples_note: 'Showing a safe sample without identity, tax, or bank-account values.',
    employee_number: 'Employee No.', employee_name: 'Employee', findings: 'Findings', details: 'Details',
    categories: {
      identity_tax: 'NIK & NPWP', bank_account: 'Bank Account', bpjs: 'BPJS Registration',
      expired_records: 'Expired Records', organization_completeness: 'Organization Completeness',
      minimum_wage: 'Minimum Wage', orphaned_references: 'Orphaned References',
    },
    codes: {
      NIK_MISSING: 'NIK is missing', NIK_INVALID: 'NIK format is invalid', NPWP_MISSING: 'NPWP is missing', NPWP_INVALID: 'NPWP format is invalid',
      BANK_ACCOUNT_INCOMPLETE: 'Bank-account information is incomplete', BANK_ACCOUNT_DUPLICATE: 'Bank account is shared by multiple employees',
      BPJS_HEALTH_MISSING: 'BPJS Health number is missing', BPJS_HEALTH_INVALID: 'BPJS Health number is invalid',
      BPJS_EMPLOYMENT_MISSING: 'BPJS Employment number is missing', BPJS_EMPLOYMENT_INVALID: 'BPJS Employment number is invalid',
      CONTRACT_EXPIRED: 'Employee contract has expired', DOCUMENT_EXPIRED: 'Employee document has expired', CERTIFICATION_EXPIRED: 'Certification has expired',
      MANAGER_MISSING: 'Manager is not assigned', POSITION_MISSING: 'Position is not assigned', COST_CENTER_MISSING: 'Cost center is not assigned', WORK_LOCATION_MISSING: 'Work location is not assigned',
      SALARY_BELOW_MINIMUM: 'Base salary is below the applicable minimum wage', USER_ORPHANED: 'User reference does not exist in this tenant',
      MANAGER_ORPHANED: 'Manager reference does not exist in this tenant', BRANCH_ORPHANED: 'Branch reference does not exist in this tenant',
      DEPARTMENT_ORPHANED: 'Department reference does not exist in this tenant', POSITION_ORPHANED: 'Position reference does not exist in this tenant',
      COST_CENTER_ORPHANED: 'Cost-center reference does not exist in this tenant', WORK_LOCATION_ORPHANED: 'Work-location reference does not exist in this tenant',
    },
  },
}
