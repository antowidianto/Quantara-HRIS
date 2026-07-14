# Business Job Monitoring and Recovery Runbook

> Scope: payroll calculation, tenant-isolation scan, encrypted backup, approval SLA, email delivery, and webhook delivery. This runbook supplements `CRON_JOBS.md`, `BACKUP_DR.md`, and `DEPLOYMENT.md`.

## Evidence and access

- Prometheus evidence is exposed by `GET /api/v1/metrics` and must remain restricted to the metrics policy/network boundary.
- Each instrumented execution persists `job_name`, status, tenant, request/job correlation, duration, processed/failed counts, a stable error code, and scrubbed diagnostic context in `business_job_runs`.
- Never place passwords, tokens, secrets, bank accounts, NIK, or NPWP in labels, logs, or job context.

## Alert policy

| Signal | Warning | Critical | First response |
|---|---:|---:|---|
| `hris_business_job_runs_24h{status="failed"}` | any failure | repeated failure for the same job/tenant | Find the latest run by `job_name`, then correlate `job_id` and `request_id`. |
| `hris_business_job_duration_ms{job="payroll.calculate"}` | > 2x 7-day p95 | exceeds payroll cut-off window | Stop duplicate retries, inspect period/job ID, DB locks, and worker capacity. |
| `hris_approvals_stuck_sla` | > 0 | growing for two checks | Identify tenant and approval chain; validate delegation/approver availability. |
| `hris_email_delivery_failed` | > 0 | growing for 15 minutes | Check provider health and stable error code; retry only transient failures. |
| `hris_webhook_dead_letter_count` | > 0 | payroll/identity event dead-lettered | Validate destination, signature/secret rotation, and replay safety before retry. |
| `hris_backup_age_seconds` | > 90,000 (25h) | `-1` or > 172,800 (48h) | Run backup diagnostics; do not claim recoverability until an isolated restore drill passes. |
| tenant scan job failure | one failed strict scan | release/deploy attempted with failed scan | Block release, inspect the JSON artifact, and remediate new fingerprints; never refresh the baseline merely to pass CI. |

Thresholds are starting values. Tune them from 30 days of observed production history and record changes in the monitoring configuration review.

## Recovery procedure

1. Acknowledge the alert and record incident ID, `job_name`, tenant ID, job ID, request ID, first-failure time, and safe error code.
2. Confirm whether the operation is idempotent. Use the original idempotency key/job ID when the command supports replay; never create a second payroll calculation, payment, export, or webhook effect blindly.
3. Resolve the dependency or data error. Do not edit finalized payroll snapshots or acknowledged audit evidence in place.
4. Retry once in the affected tenant and verify both business state and the new `SUCCESS` run. Escalate after a repeated failure.
5. For payment, payroll finalization, or backup incidents, reconcile persisted totals/hashes before closing the incident.

## Job-specific notes

### Payroll calculation

- Check the payroll period is still mutable and that another run is not active.
- Use the period ID as the job correlation ID. A failed attempt must release its application lock before retry.
- After success, validate snapshot hash, employee count, variances, unresolved reconciliation exceptions, and maker-checker state.

### Tenant-isolation scan

- Open `backend/writable/security/tenant-leak-audit.json` and distinguish new fingerprints from acknowledged legacy debt.
- Any new finding blocks merge/release. Fix or prove correct scoping with a focused policy test.

### Email and webhook delivery

- Retry timeouts, rate limits, and provider 5xx with bounded backoff.
- Do not retry permanent validation/auth failures until configuration is corrected.
- Webhook replay must reuse the persisted event identity so the receiver can deduplicate it.

### Backup

- Follow `BACKUP_DR.md` for cadence, hash verification, off-site copy, isolated restore, and RPO/RTO evidence.
- A fresh encrypted file is backup evidence, not restore evidence. Keep OPS-02 open until database, uploads, authentication, hashes, and record counts are verified in isolation.

## Escalation and closure

- Payroll/payment or cross-tenant risk: page engineering and the relevant payroll/security owner immediately.
- Backup critical threshold: page operations and the data owner; freeze destructive releases until recoverability is understood.
- Close only after the business outcome is verified, the metric returns to normal, and the incident links to run IDs and reconciliation/restore evidence.
