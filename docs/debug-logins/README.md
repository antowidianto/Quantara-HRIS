# Debug Login Accounts

Generated from DB `hris_db` on 2026-07-11 11:34:16 +07.

This folder is for local trial/debug only. It contains verified seeded passwords where the DB hash matched a known seed password. If a password is marked `UNKNOWN - reset needed`, the plaintext cannot be recovered from the hash.

Internal HRIS login accepts either `email` or `employee_number` as the `identifier`. Candidate portal login uses email and company `career_slug`.

## DB Counts

| Table | Rows |
| --- | --- |
| companies | 1 |
| roles | 20 |
| users | 71 |
| employees | 70 |
| candidate_accounts | 3 |
| candidates | 3 |
| job_postings | 3 |
| online_tests | 9 |
| online_test_assignments | 3 |

## Password Hash Matches

| Password status | Users |
| --- | --- |
| Quantara@2026 | 68 |
| UNKNOWN - reset needed | 3 |

## Role Files

| Role slug | Role name | Users | Active | File |
| --- | --- | --- | --- | --- |
| super_admin | Super Admin | 2 | 2 | [role-super_admin.md](role-super_admin.md) |
| hr_admin | HR Admin | 0 | 0 | [role-hr_admin.md](role-hr_admin.md) |
| manager | Manager | 5 | 5 | [role-manager.md](role-manager.md) |
| employee | Employee | 17 | 17 | [role-employee.md](role-employee.md) |
| finance | Finance | 9 | 9 | [role-finance.md](role-finance.md) |
| ceo | CEO / Direktur Utama | 1 | 1 | [role-ceo.md](role-ceo.md) |
| cfo | CFO / Direktur Keuangan | 1 | 1 | [role-cfo.md](role-cfo.md) |
| hr_director | HR Director / CHRO | 1 | 1 | [role-hr_director.md](role-hr_director.md) |
| hr_business_partner | HR Business Partner (HRBP) | 2 | 2 | [role-hr_business_partner.md](role-hr_business_partner.md) |
| recruiter | Recruiter / Talent Acquisition | 3 | 3 | [role-recruiter.md](role-recruiter.md) |
| learning_development | Learning & Development | 2 | 2 | [role-learning_development.md](role-learning_development.md) |
| compensation_benefits | Compensation & Benefits | 2 | 2 | [role-compensation_benefits.md](role-compensation_benefits.md) |
| payroll_admin | Payroll Administrator | 5 | 5 | [role-payroll_admin.md](role-payroll_admin.md) |
| branch_manager | Branch Manager / Kepala Cabang | 4 | 4 | [role-branch_manager.md](role-branch_manager.md) |
| department_head | Department Head / Kepala Bagian | 4 | 4 | [role-department_head.md](role-department_head.md) |
| supervisor | Supervisor / Team Lead | 4 | 4 | [role-supervisor.md](role-supervisor.md) |
| it_admin | IT System Administrator | 5 | 5 | [role-it_admin.md](role-it_admin.md) |
| internal_auditor | Internal Auditor | 1 | 1 | [role-internal_auditor.md](role-internal_auditor.md) |
| legal_compliance | Legal & Compliance Officer | 3 | 3 | [role-legal_compliance.md](role-legal_compliance.md) |
| data_privacy_officer | Data Protection Officer (DPO) | 0 | 0 | [role-data_privacy_officer.md](role-data_privacy_officer.md) |

## Recruitment / Online Test

- Candidate and online-test data: [candidate-portal-and-online-tests.md](candidate-portal-and-online-tests.md)
