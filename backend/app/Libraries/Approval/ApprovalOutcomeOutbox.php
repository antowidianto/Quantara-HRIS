<?php

namespace App\Libraries\Approval;

use App\Constants\WebhookEvent;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class ApprovalOutcomeOutbox
{
    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $domainResult */
    public function record(array $request, string $decision, int $actorUserId, string $note, array $domainResult): int
    {
        $db = $this->db();
        $module = strtoupper((string) $request['module']);
        $companyId = (int) $request['company_id'];
        $employee = $db->table('employees')->where('id', (int) $request['employee_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        $eventType = $this->eventType($module, $decision);
        $entity = $db->table((string) $request['entity_table'])
            ->where('id', (int) $request['entity_id'])->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $entity) throw new \RuntimeException('Entitas outcome approval tidak ditemukan');
        $recipientUserId = $employee && ! empty($employee['user_id']) ? (int) $employee['user_id'] : null;
        if ($module === 'CANDIDATE_OFFER' && $decision === 'APPROVED' && ! empty($entity['candidate_id'])) {
            $candidate = $db->table('candidates')->where('id', (int) $entity['candidate_id'])
                ->where('company_id', $companyId)->get()->getRowArray();
            $recipientUserId = $candidate && ! empty($candidate['user_id'])
                ? (int) $candidate['user_id'] : null;
        }
        $requestMeta = $request['meta'] ?? [];
        if (is_string($requestMeta)) $requestMeta = json_decode($requestMeta, true) ?: [];
        if (! is_array($requestMeta)) $requestMeta = [];
        if (in_array($module, ['GENERATED_CONTRACT', 'ASSET_TRANSACTION', 'HIRE_CONVERSION', 'EMPLOYEE_SENSITIVE_CHANGE', 'MASTER_RATE_CHANGE', 'PRIVILEGED_ACTION'], true) && $decision === 'REJECTED') {
            $recipientUserId = ! empty($requestMeta['submitted_by'])
                ? (int) $requestMeta['submitted_by'] : null;
        }
        if ($module === 'PRIVILEGED_ACTION') {
            $recipientUserId = ! empty($requestMeta['submitted_by'])
                ? (int) $requestMeta['submitted_by'] : null;
        }

        $requestNumber = (string) ($entity['request_number'] ?? $entity['loan_number'] ?? $entity['offer_number']
            ?? $entity['contract_number'] ?? $entity['asset_number'] ?? $entity['reference_number'] ?? $entity['full_name']
            ?? $entity['title'] ?? $entity['name']
            ?? ('#' . $request['entity_id']));
        $notification = $this->notification($module, $decision, $requestNumber, $note);
        if ($module === 'CANDIDATE_OFFER') {
            if ($decision === 'APPROVED') {
                $company = $db->table('companies')->where('id', $companyId)->get()->getRowArray();
                $slug = trim((string) ($company['career_slug'] ?? ''));
                $notification['link'] = $slug !== '' ? '/careers/' . rawurlencode($slug) . '/offers' : '/careers';
            } elseif (! empty($entity['job_posting_id'])) {
                $notification['link'] = '/recruitment/jobs/' . (int) $entity['job_posting_id'];
            }
        }
        if ($module === 'GENERATED_CONTRACT') $notification['link'] = '/employees/contracts';
        if ($module === 'ASSET_TRANSACTION') $notification['link'] = '/hr/assets';
        if ($module === 'EMPLOYEE_SENSITIVE_CHANGE') $notification['link'] = '/employees';
        if ($module === 'MASTER_RATE_CHANGE') $notification['link'] = '/master/payroll-components';
        if ($module === 'PRIVILEGED_ACTION') $notification['link'] = '/approvals';
        $payload = [
            'approval_request_id' => (int) $request['id'],
            'module' => $module,
            'decision' => $decision,
            'entity_table' => (string) $request['entity_table'],
            'entity_id' => (int) $request['entity_id'],
            'employee_id' => (int) $request['employee_id'],
            'recipient_user_id' => $recipientUserId,
            'request_number' => $requestNumber,
            'actor_user_id' => $actorUserId,
            'notification' => $notification,
            'webhook' => [
                'event' => $eventType,
                'company_id' => $companyId,
                'approval_request_id' => (int) $request['id'],
                'entity_id' => (int) $request['entity_id'],
                'employee_id' => (int) $request['employee_id'],
                'converted_employee_id' => $module === 'HIRE_CONVERSION'
                    ? (int) ($entity['converted_employee_id'] ?? 0) ?: null : null,
                'request_number' => $requestNumber,
                'decision' => $decision,
                'approval_action' => $requestMeta['approval_action'] ?? null,
                'decided_by' => $actorUserId,
                'decided_at' => date('c'),
            ],
        ];
        // Jangan serialisasi domainResult: outcome cukup memakai identifier dan
        // menghindari duplikasi data HR sensitif di outbox.
        unset($domainResult);
        $now = date('Y-m-d H:i:s');
        $db->table('approval_outbox_events')->insert([
            'company_id' => $companyId,
            'approval_request_id' => (int) $request['id'],
            'module' => $module,
            'entity_table' => (string) $request['entity_table'],
            'entity_id' => (int) $request['entity_id'],
            'decision' => $decision,
            'event_type' => $eventType,
            'event_id' => $this->eventId((int) $request['id'], $decision),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'PENDING',
            'attempts' => 0,
            'max_attempts' => 5,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $db->insertID();
    }

    /** @return array{published:int,failed:int,skipped:int} */
    public function processBatch(int $limit = 50): array
    {
        $db = $this->db();
        $now = date('Y-m-d H:i:s');
        $staleProcessing = date('Y-m-d H:i:s', time() - 900);
        $rows = $db->table('approval_outbox_events')
            ->groupStart()
                ->groupStart()->whereIn('status', ['PENDING', 'FAILED'])->where('available_at <=', $now)->groupEnd()
                ->orGroupStart()->where('status', 'PROCESSING')->where('processing_at <=', $staleProcessing)->groupEnd()
            ->groupEnd()
            ->where('attempts < max_attempts', null, false)->orderBy('id', 'ASC')
            ->limit(max(1, min(200, $limit)))->get()->getResultArray();
        $published = $failed = $skipped = 0;
        foreach ($rows as $row) {
            $attempt = (int) $row['attempts'] + 1;
            $db->table('approval_outbox_events')->where('id', (int) $row['id'])
                ->where('status', (string) $row['status'])->update([
                    'status' => 'PROCESSING', 'attempts' => $attempt,
                    'processing_at' => $now, 'updated_at' => $now,
                ]);
            if ($db->affectedRows() !== 1) {
                $skipped++;
                continue;
            }
            try {
                $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
                $notification = $payload['notification'];
                $recipient = (int) ($payload['recipient_user_id'] ?? 0);
                if ($recipient > 0) {
                    Services::notification()->send(
                        $recipient, (int) $row['company_id'], (string) $notification['type'],
                        (string) $notification['title'], (string) $notification['message'],
                        ['approval_request_id' => (int) $row['approval_request_id'], 'entity_id' => (int) $row['entity_id']],
                        (string) $notification['link'], true
                    );
                }
                Services::webhook()->dispatch(
                    (string) $row['event_type'], (int) $row['company_id'],
                    $payload['webhook'], (string) $row['event_id']
                );
                $db->table('approval_outbox_events')->where('id', (int) $row['id'])
                    ->where('status', 'PROCESSING')->update([
                        'status' => 'PUBLISHED', 'published_at' => date('Y-m-d H:i:s'),
                        'last_error' => null, 'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                $published++;
            } catch (\Throwable $exception) {
                $exhausted = $attempt >= (int) $row['max_attempts'];
                $delay = 30 * (2 ** max(0, $attempt - 1));
                $db->table('approval_outbox_events')->where('id', (int) $row['id'])->update([
                    'status' => 'FAILED',
                    'available_at' => $exhausted ? $now : date('Y-m-d H:i:s', time() + $delay),
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $failed++;
            }
        }
        return ['published' => $published, 'failed' => $failed, 'skipped' => $skipped];
    }

    private function eventType(string $module, string $decision): string
    {
        return match ($module . ':' . $decision) {
            'LEAVE:APPROVED' => WebhookEvent::LEAVE_APPROVED,
            'LEAVE:REJECTED' => WebhookEvent::LEAVE_REJECTED,
            'OVERTIME:APPROVED' => WebhookEvent::OVERTIME_APPROVED,
            'OVERTIME:REJECTED' => WebhookEvent::OVERTIME_REJECTED,
            'ATTENDANCE_CHANGE:APPROVED' => WebhookEvent::ATTENDANCE_CHANGE_APPROVED,
            'ATTENDANCE_CHANGE:REJECTED' => WebhookEvent::ATTENDANCE_CHANGE_REJECTED,
            'REIMBURSEMENT:APPROVED' => WebhookEvent::REIMBURSEMENT_APPROVED,
            'REIMBURSEMENT:REJECTED' => WebhookEvent::REIMBURSEMENT_REJECTED,
            'LOAN:APPROVED' => WebhookEvent::LOAN_APPROVED,
            'LOAN:REJECTED' => WebhookEvent::LOAN_REJECTED,
            'BUSINESS_TRIP:APPROVED' => WebhookEvent::BUSINESS_TRIP_APPROVED,
            'BUSINESS_TRIP:REJECTED' => WebhookEvent::BUSINESS_TRIP_REJECTED,
            'BONUS:APPROVED' => WebhookEvent::BONUS_RUN_APPROVED,
            'BONUS:REJECTED' => WebhookEvent::BONUS_RUN_REJECTED,
            'PAYROLL_ADJUSTMENT:APPROVED' => WebhookEvent::PAYROLL_ADJUSTMENT_APPROVED,
            'PAYROLL_ADJUSTMENT:REJECTED' => WebhookEvent::PAYROLL_ADJUSTMENT_REJECTED,
            'COMPENSATION_REVIEW:APPROVED' => WebhookEvent::COMPENSATION_REVIEW_APPROVED,
            'COMPENSATION_REVIEW:REJECTED' => WebhookEvent::COMPENSATION_REVIEW_REJECTED,
            'TRAINING_REGISTRATION:APPROVED' => WebhookEvent::TRAINING_REGISTRATION_APPROVED,
            'TRAINING_REGISTRATION:REJECTED' => WebhookEvent::TRAINING_REGISTRATION_REJECTED,
            'TIMESHEET:APPROVED' => WebhookEvent::TIMESHEET_APPROVED,
            'TIMESHEET:REJECTED' => WebhookEvent::TIMESHEET_REJECTED,
            'CANDIDATE_OFFER:APPROVED' => WebhookEvent::CANDIDATE_OFFER_APPROVED,
            'CANDIDATE_OFFER:REJECTED' => WebhookEvent::CANDIDATE_OFFER_REJECTED,
            'GENERATED_CONTRACT:APPROVED' => WebhookEvent::GENERATED_CONTRACT_APPROVED,
            'GENERATED_CONTRACT:REJECTED' => WebhookEvent::GENERATED_CONTRACT_REJECTED,
            'ASSET_TRANSACTION:APPROVED' => WebhookEvent::ASSET_TRANSACTION_APPROVED,
            'ASSET_TRANSACTION:REJECTED' => WebhookEvent::ASSET_TRANSACTION_REJECTED,
            'HIRE_CONVERSION:APPROVED' => WebhookEvent::CANDIDATE_HIRED,
            'HIRE_CONVERSION:REJECTED' => WebhookEvent::CANDIDATE_HIRE_REJECTED,
            'PERFORMANCE_GOAL:APPROVED' => WebhookEvent::PERFORMANCE_GOAL_APPROVED,
            'PERFORMANCE_GOAL:REJECTED' => WebhookEvent::PERFORMANCE_GOAL_REJECTED,
            'IDP_ACTIVATION:APPROVED' => WebhookEvent::IDP_ACTIVATION_APPROVED,
            'IDP_ACTIVATION:REJECTED' => WebhookEvent::IDP_ACTIVATION_REJECTED,
            'EMPLOYEE_SENSITIVE_CHANGE:APPROVED' => WebhookEvent::EMPLOYEE_SENSITIVE_CHANGE_APPROVED,
            'EMPLOYEE_SENSITIVE_CHANGE:REJECTED' => WebhookEvent::EMPLOYEE_SENSITIVE_CHANGE_REJECTED,
            'MASTER_RATE_CHANGE:APPROVED' => WebhookEvent::MASTER_RATE_CHANGE_APPROVED,
            'MASTER_RATE_CHANGE:REJECTED' => WebhookEvent::MASTER_RATE_CHANGE_REJECTED,
            'PRIVILEGED_ACTION:APPROVED' => WebhookEvent::PRIVILEGED_ACTION_APPROVED,
            'PRIVILEGED_ACTION:REJECTED' => WebhookEvent::PRIVILEGED_ACTION_REJECTED,
            default => throw new \RuntimeException("Outcome event tidak dikenal: {$module}:{$decision}"),
        };
    }

    /** @return array{type:string,title:string,message:string,link:string} */
    private function notification(string $module, string $decision, string $number, string $note): array
    {
        $label = match ($module) {
            'LEAVE' => 'Pengajuan cuti', 'OVERTIME' => 'Pengajuan lembur',
            'ATTENDANCE_CHANGE' => 'Koreksi absensi', 'REIMBURSEMENT' => 'Reimbursement',
            'BUSINESS_TRIP' => 'Perjalanan dinas',
            'BONUS' => 'Bonus run',
            'PAYROLL_ADJUSTMENT' => 'Koreksi payroll',
            'COMPENSATION_REVIEW' => 'Perubahan kompensasi',
            'TRAINING_REGISTRATION' => 'Registrasi training',
            'TIMESHEET' => 'Timesheet',
            'CANDIDATE_OFFER' => 'Penawaran kerja',
            'GENERATED_CONTRACT' => 'Kontrak kerja',
            'ASSET_TRANSACTION' => 'Transaksi asset',
            'HIRE_CONVERSION' => 'Konversi kandidat',
            'PERFORMANCE_GOAL' => 'Performance goal',
            'IDP_ACTIVATION' => 'Aktivasi IDP',
            'EMPLOYEE_SENSITIVE_CHANGE' => 'Perubahan data sensitif employee',
            'MASTER_RATE_CHANGE' => 'Perubahan master rate payroll',
            'PRIVILEGED_ACTION' => 'Aksi administratif berisiko',
            'LOAN' => 'Pinjaman/kasbon', default => 'Pengajuan',
        };
        $approved = $decision === 'APPROVED';
        $suffix = $approved ? 'telah disetujui' : 'ditolak' . ($note !== '' ? ': ' . mb_substr($note, 0, 300) : '');
        $type = match ($module) {
            'ATTENDANCE_CHANGE' => 'attendance.change.' . strtolower($decision),
            default => strtolower($module) . '.' . strtolower($decision),
        };
        $link = match ($module) {
            'LEAVE' => '/leaves', 'OVERTIME' => '/overtimes',
            'ATTENDANCE_CHANGE' => '/attendance/my-changes',
            'BUSINESS_TRIP' => '/travel',
            'BONUS' => '/payroll/bonus/runs',
            'PAYROLL_ADJUSTMENT' => '/payroll/adjustments',
            'COMPENSATION_REVIEW' => '/hr/compensation-review',
            'TRAINING_REGISTRATION' => '/training',
            'TIMESHEET' => '/timesheet',
            'CANDIDATE_OFFER' => '/careers',
            'GENERATED_CONTRACT' => '/employees/contracts',
            'ASSET_TRANSACTION' => '/hr/assets',
            'HIRE_CONVERSION' => '/recruitment/pipeline',
            'PERFORMANCE_GOAL' => '/performance',
            'IDP_ACTIVATION' => '/hr/idp',
            'EMPLOYEE_SENSITIVE_CHANGE' => '/employees',
            'MASTER_RATE_CHANGE' => '/master/payroll-components',
            'PRIVILEGED_ACTION' => '/approvals',
            'REIMBURSEMENT' => '/reimbursements', 'LOAN' => '/loans', default => '/',
        };
        return [
            'type' => $type,
            'title' => $label . ($approved ? ' disetujui' : ' ditolak'),
            'message' => "{$label} {$number} {$suffix}",
            'link' => $link,
        ];
    }

    private function eventId(int $requestId, string $decision): string
    {
        $hex = substr(hash('sha256', "approval:{$requestId}:{$decision}"), 0, 32);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
