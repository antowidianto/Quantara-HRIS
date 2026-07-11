# Candidate Portal And Online Tests

Generated from DB `hris_db` on 2026-07-11 11:34:15 +07.

Candidate login route pattern: `/careers/{career_slug}/account/login`. Login uses `email` only.

## Candidate Accounts

| ID | Name | Email | Password | Active | Failed | Lock | Career slug |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Anto Widianto | anto@anto.ganteng | UNKNOWN - reset needed | yes | 0 | - | pt-quantara-nusantara-sejahtera |
| 2 | Athaya Maulida | budi.testkandidat@gmail.com | UNKNOWN - reset needed | yes | 0 | - | pt-quantara-nusantara-sejahtera |
| 3 | toto wiyahya | totowiyahya@gmail.com | UNKNOWN - reset needed | yes | 0 | - | pt-quantara-nusantara-sejahtera |

## Applications

| ID | Candidate | Email | Stage | Job | Reference | Applied at | Account ID |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Anto Widianto | anto@anto.ganteng | HIRED | Backend Developer | JOB-2026-00003 | 2026-05-27 16:20:05 | 1 |
| 2 | Athaya Maulida | budi.testkandidat@gmail.com | REJECTED | Backend Developer | JOB-2026-00003 | 2026-05-27 18:25:48 | 2 |
| 3 | toto wiyahya | totowiyahya@gmail.com | HIRED | Sales Executive | JOB-2026-00002 | 2026-06-15 15:54:47 | 3 |

## Online Test Assignments

| ID | Candidate | Account email | Job | Test | Status | Attempts | Best | Completed at | Due at |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Anto Widianto | anto@anto.ganteng | Backend Developer | TPA-DUMMY-001 TPA Dasar — Screening Kandidat | COMPLETED | 1/1 | 54.55 FAIL | 2026-05-27 16:52:04 | 2026-05-29 16:48:00 |
| 2 | Anto Widianto | anto@anto.ganteng | Backend Developer | ENG-DUMMY-001 English Basic Test | COMPLETED | 1/1 | 75.00 PASS | 2026-05-27 16:50:31 | 2026-05-29 16:49:00 |
| 3 | toto wiyahya | totowiyahya@gmail.com | Sales Executive | ACC-KNOW-001 Accounting Knowledge Test | COMPLETED | 2/2 | 80.00 PASS | 2026-06-15 15:59:02 | 2026-06-19 15:56:00 |

## Job Postings

| ID | Reference | Title | Status | Published | Public slug | Career slug | City |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | JOB-2026-00001 | Recruiter | DRAFT | no | - | pt-quantara-nusantara-sejahtera | - |
| 2 | JOB-2026-00002 | Sales Executive | OPEN | yes | sales-executive-job202600002 | pt-quantara-nusantara-sejahtera | - |
| 3 | JOB-2026-00003 | Backend Developer | CLOSED | no | backend-developer-job202600003 | pt-quantara-nusantara-sejahtera | - |
