<?php

declare(strict_types=1);

namespace App\Libraries\Employee;

use App\Repositories\Employee\JicWorkflowRepository;

final class JicWorkflowService
{
    public function __construct(
        private readonly JicWorkflowRepository $repository = new JicWorkflowRepository(),
        private readonly JicService $policy = new JicService(),
    ) {
    }

    /** @param array<string,mixed> $row @return array{status:string,jic_number:string} */
    public function submit(array $row): array
    {
        $errors = $this->policy->validateSupportingDocuments($row);
        if (($row['ump_check_status'] ?? null) === 'FAIL' && empty($row['ump_override_reason'])) {
            $errors[] = sprintf(
                'Upah baru (%s) di bawah UMP rujukan (%s). Isi `ump_override_reason` atau naikkan upah baru.',
                number_format((float) ($row['new_basic_salary'] ?? 0), 0, ',', '.'),
                number_format((float) ($row['ump_reference'] ?? 0), 0, ',', '.'),
            );
        }
        if ($errors !== []) throw new JicWorkflowException('Validasi gagal', 422, 'COMPLIANCE_FAILED', $errors);

        $companyId = $this->companyId($row);
        $now = date('Y-m-d H:i:s');
        $number = (string) ($row['jic_number'] ?: $this->policy->generateJicNumber($companyId));
        $update = ['jic_number' => $number, 'status' => 'PENDING_SUPERVISOR', 'submitted_at' => $now, 'updated_at' => $now];
        if (($row['ump_check_status'] ?? null) === 'FAIL' && ! empty($row['ump_override_reason'])) {
            $update['ump_check_status'] = 'OVERRIDE';
        }
        $this->repository->update((int) $row['id'], $companyId, $update);
        return ['status' => 'PENDING_SUPERVISOR', 'jic_number' => $number];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{status:string,sk_number:?string,notification:string}
     */
    public function approve(
        array $row,
        int $userId,
        ?string $note,
        bool $isSupervisor,
        bool $isDepartmentHead,
        bool $canActAsHr,
        bool $canActAsBod,
    ): array {
        $companyId = $this->companyId($row);
        $now = date('Y-m-d H:i:s');
        $status = (string) $row['status'];
        $notification = '';
        $update = ['updated_at' => $now];

        if ($status === 'PENDING_SUPERVISOR') {
            if (! $isSupervisor) throw new JicWorkflowException('Hanya atasan langsung yang dapat menyetujui di tahap ini', 403, 'FORBIDDEN');
            $update += ['status' => 'PENDING_DEPT_HEAD', 'supervisor_user_id' => $userId, 'supervisor_action_at' => $now, 'supervisor_decision' => 'APPROVED', 'supervisor_note' => $note];
            $notification = 'PENDING_DEPT_HEAD';
        } elseif ($status === 'PENDING_DEPT_HEAD') {
            if (! $isDepartmentHead) throw new JicWorkflowException('Hanya kepala departemen terkait yang dapat menyetujui di tahap ini', 403, 'FORBIDDEN');
            $update += ['status' => 'PENDING_HR', 'dept_head_user_id' => $userId, 'dept_head_action_at' => $now, 'dept_head_decision' => 'APPROVED', 'dept_head_note' => $note];
            $notification = 'PENDING_HR';
        } elseif ($status === 'PENDING_HR') {
            if (! $canActAsHr) throw new JicWorkflowException('Hanya HR yang dapat menyetujui di tahap ini', 403, 'FORBIDDEN');
            $tier = empty($row['new_position_id']) ? null : $this->repository->positionTier((int) $row['new_position_id'], $companyId);
            $next = $this->policy->requiresBodApproval((string) $row['change_type'], $tier, (float) ($row['salary_change_pct'] ?? 0))
                ? 'PENDING_BOD' : ($this->needsConsent($row) ? 'PENDING_EMPLOYEE_CONSENT' : 'APPROVED');
            $update += ['status' => $next, 'hr_user_id' => $userId, 'hr_action_at' => $now, 'hr_decision' => 'APPROVED', 'hr_note' => $note];
            $notification = $next;
        } elseif ($status === 'PENDING_BOD') {
            if (! $canActAsBod) throw new JicWorkflowException('Hanya direksi/super admin yang dapat menyetujui di tahap ini', 403, 'FORBIDDEN');
            $next = $this->needsConsent($row) ? 'PENDING_EMPLOYEE_CONSENT' : 'APPROVED';
            $update += ['status' => $next, 'bod_user_id' => $userId, 'bod_action_at' => $now, 'bod_decision' => 'APPROVED', 'bod_note' => $note];
            $notification = $next;
        } else {
            throw new JicWorkflowException('Status saat ini tidak bisa di-approve: ' . $status, 409, 'INVALID_STATE');
        }

        if (($update['status'] ?? '') === 'APPROVED' && empty($row['sk_number'])) {
            $update['sk_number'] = $this->repository->nextSkNumber($companyId);
            $update['sk_generated_at'] = $now;
        }
        $this->repository->update((int) $row['id'], $companyId, $update);
        return ['status' => (string) $update['status'], 'sk_number' => $update['sk_number'] ?? ($row['sk_number'] ?? null), 'notification' => $notification];
    }

    /** @param array<string,mixed> $row */
    public function reject(array $row, int $userId, string $note, bool $isSupervisor, bool $isDepartmentHead, bool $canActAsHr, bool $canActAsBod): void
    {
        $now = date('Y-m-d H:i:s');
        $stage = (string) $row['status'];
        $update = ['status' => 'REJECTED', 'updated_at' => $now];
        if ($stage === 'PENDING_SUPERVISOR' && $isSupervisor) $update += ['supervisor_user_id' => $userId, 'supervisor_action_at' => $now, 'supervisor_decision' => 'REJECTED', 'supervisor_note' => $note];
        elseif ($stage === 'PENDING_DEPT_HEAD' && $isDepartmentHead) $update += ['dept_head_user_id' => $userId, 'dept_head_action_at' => $now, 'dept_head_decision' => 'REJECTED', 'dept_head_note' => $note];
        elseif ($stage === 'PENDING_HR' && $canActAsHr) $update += ['hr_user_id' => $userId, 'hr_action_at' => $now, 'hr_decision' => 'REJECTED', 'hr_note' => $note];
        elseif ($stage === 'PENDING_BOD' && $canActAsBod) $update += ['bod_user_id' => $userId, 'bod_action_at' => $now, 'bod_decision' => 'REJECTED', 'bod_note' => $note];
        elseif (in_array($stage, ['PENDING_SUPERVISOR', 'PENDING_DEPT_HEAD', 'PENDING_HR', 'PENDING_BOD'], true)) throw new JicWorkflowException('Tidak memiliki kewenangan menolak pada tahap ini', 403, 'FORBIDDEN');
        else throw new JicWorkflowException('Status saat ini tidak bisa di-reject: ' . $stage, 409, 'INVALID_STATE');
        $this->repository->update((int) $row['id'], $this->companyId($row), $update);
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $input @return string */
    public function consent(array $row, int $userId, array $input): string
    {
        if (($row['status'] ?? '') !== 'PENDING_EMPLOYEE_CONSENT') throw new JicWorkflowException('Status saat ini tidak menunggu consent: ' . ($row['status'] ?? ''), 409, 'INVALID_STATE');
        $companyId = $this->companyId($row);
        $employee = $this->repository->employee((int) $row['employee_id'], $companyId);
        if (! $employee || (int) ($employee['user_id'] ?? 0) !== $userId) throw new JicWorkflowException('Hanya karyawan yang bersangkutan yang dapat memberi persetujuan', 403, 'FORBIDDEN');
        $now = date('Y-m-d H:i:s');
        $decision = (string) $input['decision'];
        $update = ['employee_consent_status' => $decision, 'employee_consent_at' => $now, 'employee_consent_note' => $input['note'] ?? null, 'employee_consent_signed_path' => $input['signed_path'] ?? null, 'updated_at' => $now];
        if ($decision === 'AGREED') {
            $update['status'] = 'APPROVED';
            if (empty($row['sk_number'])) { $update['sk_number'] = $this->repository->nextSkNumber($companyId); $update['sk_generated_at'] = $now; }
        } else {
            $update['status'] = 'REJECTED';
            $update['hr_decision'] = 'REJECTED';
            $update['hr_note'] = "[Auto-reject] Karyawan menolak demosi: " . ($input['note'] ?? '');
        }
        $this->repository->update((int) $row['id'], $companyId, $update);
        return (string) $update['status'];
    }

    /** @param array<string,mixed> $row */
    public function cancel(array $row, int $userId, bool $isHr, ?string $reason): void
    {
        $status = (string) $row['status'];
        if (in_array($status, ['IMPLEMENTED', 'CANCELLED', 'REJECTED'], true)) throw new JicWorkflowException('Tidak bisa cancel di status ' . $status, 409, 'INVALID_STATE');
        $initiator = (int) ($row['created_by'] ?? 0) === $userId;
        if (! $isHr && ! $initiator) throw new JicWorkflowException('Hanya pembuat atau HR yang dapat membatalkan', 403, 'FORBIDDEN');
        if ($status === 'APPROVED' && ! $isHr) throw new JicWorkflowException('Setelah APPROVED hanya HR yang dapat membatalkan', 403, 'FORBIDDEN');
        $now = date('Y-m-d H:i:s');
        $this->repository->update((int) $row['id'], $this->companyId($row), ['status' => 'CANCELLED', 'cancelled_at' => $now, 'cancelled_by' => $userId, 'cancellation_reason' => $reason, 'updated_at' => $now]);
    }

    /** @param array<string,mixed> $row */
    private function companyId(array $row): int
    {
        $companyId = (int) ($row['company_id'] ?? 0);
        if ($companyId <= 0) throw new \DomainException('Active company context is required for JIC operations');
        return $companyId;
    }

    /** @param array<string,mixed> $row */
    private function needsConsent(array $row): bool
    {
        return (int) ($row['employee_consent_required'] ?? 0) === 1 && ($row['employee_consent_status'] ?? null) !== 'AGREED';
    }
}
