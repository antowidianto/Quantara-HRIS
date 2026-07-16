<?php

declare(strict_types=1);

namespace App\Libraries\Payroll;

use App\Repositories\Payroll\BonusRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class BonusWorkflowService
{
    private readonly BaseConnection $db;
    private readonly BonusRepository $repository;

    public function __construct(?BaseConnection $connection = null)
    {
        $this->db = $connection ?? Database::connect();
        $this->repository = new BonusRepository($this->db);
    }

    /** @param array<string,mixed> $data */
    public function createScheme(int $companyId, array $data): int
    {
        $this->requireCompany($companyId);
        $exists = $this->db->table('bonus_schemes')->where('company_id', $companyId)
            ->where('code', $data['code'])->where('deleted_at IS NULL')->countAllResults();
        if ($exists > 0) throw new \DomainException("Code '{$data['code']}' sudah dipakai di company ini");
        $this->db->table('bonus_schemes')->insert($data + ['company_id' => $companyId]);
        return (int) $this->db->insertID();
    }

    /** @param array<string,mixed> $data */
    public function updateScheme(int $id, int $companyId, array $data): array
    {
        $scheme = $this->requireScheme($id, $companyId);
        $this->db->table('bonus_schemes')->where('id', $id)->where('company_id', $companyId)->update($data);
        return $scheme;
    }

    public function deleteScheme(int $id, int $companyId): array
    {
        $scheme = $this->requireScheme($id, $companyId);
        $active = $this->db->table('bonus_runs')->where('bonus_scheme_id', $id)
            ->where('company_id', $companyId)->whereIn('status', ['DRAFT', 'CALCULATED', 'PENDING_APPROVAL', 'APPROVED'])
            ->where('deleted_at IS NULL')->countAllResults();
        if ($active > 0) throw new \DomainException('Skema masih dipakai di bonus run aktif. Cancel run dulu.');
        $now = date('Y-m-d H:i:s');
        $this->db->table('bonus_schemes')->where('id', $id)->where('company_id', $companyId)
            ->update(['deleted_at' => $now, 'is_active' => 0, 'updated_at' => $now]);
        return $scheme;
    }

    /** @param array<string,mixed> $data */
    public function createRun(int $companyId, int $schemeId, array $data): int
    {
        $scheme = $this->requireScheme($schemeId, $companyId);
        if ((int) $scheme['is_active'] !== 1) throw new \DomainException('Skema tidak aktif');
        $this->db->table('bonus_runs')->insert($data + [
            'company_id' => $companyId,
            'bonus_scheme_id' => $schemeId,
        ]);
        return (int) $this->db->insertID();
    }

    /** @param array<string,mixed> $data */
    public function updateRun(int $id, int $companyId, array $data): array
    {
        $run = $this->requireRun($id, $companyId);
        $this->assertStatus($run, ['DRAFT'], 'Hanya DRAFT yang bisa diedit');
        $this->db->table('bonus_runs')->where('id', $id)->where('company_id', $companyId)->update($data);
        return $run;
    }

    public function deleteRun(int $id, int $companyId): array
    {
        $run = $this->requireRun($id, $companyId);
        $this->assertStatus($run, ['DRAFT'], 'Hanya DRAFT yang bisa dihapus permanen');
        $this->transaction(function () use ($id, $companyId): void {
            $this->db->table('bonus_recipients')->where('bonus_run_id', $id)
                ->where('company_id', $companyId)->delete();
            $this->db->table('bonus_runs')->where('id', $id)->where('company_id', $companyId)->delete();
        });
        return $run;
    }

    public function markCalculator(int $id, int $companyId, int $userId): array
    {
        $run = $this->requireRun($id, $companyId);
        $this->assertStatus($run, ['DRAFT', 'CALCULATED', 'REJECTED'], 'Run tidak dapat dihitung ulang');
        $this->db->table('bonus_runs')->where('id', $id)->where('company_id', $companyId)
            ->update(['calculated_by' => $userId, 'updated_at' => date('Y-m-d H:i:s')]);
        return $run;
    }

    public function markPaid(int $id, int $companyId, int $userId): array
    {
        $run = $this->requireRun($id, $companyId);
        $this->assertStatus($run, ['APPROVED'], 'Hanya APPROVED yang bisa di-mark PAID');
        if ($userId === (int) ($run['submitted_by'] ?? 0) || $userId === (int) ($run['approved_by'] ?? 0)) {
            throw new \DomainException('Disburser harus berbeda dari submitter dan final approver');
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('bonus_runs')->where('id', $id)->where('company_id', $companyId)->update([
            'status' => 'PAID', 'paid_by' => $userId, 'paid_at' => $now, 'updated_at' => $now,
        ]);
        return $run + ['transitioned_at' => $now];
    }

    public function cancelRun(int $id, int $companyId, int $userId, string $reason): array
    {
        $run = $this->requireRun($id, $companyId);
        if (in_array($run['status'], ['PAID', 'CANCELLED'], true)) {
            throw new \DomainException($run['status'] === 'PAID' ? 'Tidak bisa cancel run yang sudah PAID' : 'Sudah dibatalkan');
        }
        if (mb_strlen(trim($reason)) < 5) throw new \DomainException('Alasan minimal 5 karakter');
        $now = date('Y-m-d H:i:s');
        $this->transaction(function () use ($id, $companyId, $userId, $reason, $run, $now): void {
            $this->db->table('bonus_runs')->where('id', $id)->where('company_id', $companyId)
                ->where('status', (string) $run['status'])->update([
                    'status' => 'CANCELLED', 'cancelled_by' => $userId, 'cancelled_at' => $now,
                    'cancellation_reason' => trim($reason), 'updated_at' => $now,
                ]);
            if ($this->db->affectedRows() !== 1) throw new \DomainException('Bonus run berubah; muat ulang data');
            if ($run['status'] === 'PENDING_APPROVAL') {
                Services::approval()->cancelEntity('BONUS', 'bonus_runs', $id, $companyId);
            }
        });
        return $run;
    }

    /** @return array<string,int|float> */
    public function includeAllRecipients(int $runId, int $companyId): array
    {
        $run = $this->requireRun($runId, $companyId);
        $this->assertStatus($run, ['DRAFT', 'CALCULATED'], 'Run sudah locked, recipient tidak boleh diubah');
        $scheme = $this->requireScheme((int) $run['bonus_scheme_id'], $companyId);
        $rows = $this->db->table('bonus_recipients')->where('bonus_run_id', $runId)
            ->where('company_id', $companyId)->get()->getResultArray();
        if ($rows === []) throw new \DomainException('Run ini belum punya recipient sama sekali. Klik Calculate dulu.');
        $beforeExcluded = count(array_filter($rows, fn (array $row): bool => (int) $row['is_excluded'] === 1));
        $beforeOverridden = count(array_filter($rows, fn (array $row): bool => (int) $row['amount_overridden'] === 1));
        $affected = 0;
        $this->transaction(function () use ($rows, $run, $scheme, $companyId, &$affected): void {
            $now = date('Y-m-d H:i:s');
            foreach ($rows as $recipient) {
                $employee = $this->repository->findEmployee((int) $recipient['employee_id'], $companyId);
                if (! $employee) continue;
                $employee['fixed_allowance'] ??= 0;
                $computed = BonusCalculator::compute($scheme, $employee, (string) $run['reference_date']);
                $this->db->table('bonus_recipients')->where('id', (int) $recipient['id'])
                    ->where('company_id', $companyId)->update([
                        'is_excluded' => 0, 'exclude_reason' => null, 'amount_overridden' => 0,
                        'override_reason' => null, 'amount' => (float) $computed['amount'],
                        'amount_capped' => $computed['capped'] ? 1 : 0,
                        'breakdown_json' => json_encode($computed['breakdown'], JSON_UNESCAPED_UNICODE),
                        'updated_at' => $now,
                    ]);
                $affected++;
            }
            $this->refreshAggregate((int) $run['id'], $companyId, $now);
        });
        $aggregate = $this->aggregate($runId, $companyId);
        return [
            'affected' => $affected, 'before_excluded' => $beforeExcluded,
            'before_overridden' => $beforeOverridden,
            'total_included' => (int) $aggregate['cnt'], 'total_amount' => (float) $aggregate['total'],
        ];
    }

    /** @param array<string,mixed> $input @return array{recipient:array<string,mixed>,update:array<string,mixed>} */
    public function overrideRecipient(int $id, int $companyId, array $input): array
    {
        $recipient = $this->repository->findRecipient($id, $companyId);
        if (! $recipient) throw new \InvalidArgumentException('Recipient tidak ditemukan');
        $run = $this->requireRun((int) $recipient['bonus_run_id'], $companyId);
        $this->assertStatus($run, ['DRAFT', 'CALCULATED'], 'Run sudah locked, recipient tidak boleh diubah');
        $update = ['updated_at' => date('Y-m-d H:i:s')];
        if (array_key_exists('amount', $input)) {
            $amount = (float) $input['amount'];
            if ($amount < 0) throw new \DomainException('Amount tidak boleh negatif');
            $scheme = $this->requireScheme((int) $run['bonus_scheme_id'], $companyId);
            if ($scheme['cap_amount'] !== null && $amount > (float) $scheme['cap_amount']) {
                throw new \DomainException('Amount melebihi cap_amount skema');
            }
            $update += ['amount' => $amount, 'amount_overridden' => 1, 'override_reason' => trim((string) ($input['reason'] ?? ''))];
        }
        if (array_key_exists('is_excluded', $input)) {
            $excluded = (int) (bool) $input['is_excluded'];
            $update['is_excluded'] = $excluded;
            if ($excluded === 1) $update += ['exclude_reason' => trim((string) ($input['exclude_reason'] ?? '')), 'amount' => 0];
        }
        $this->transaction(function () use ($id, $companyId, $run, $update): void {
            $this->db->table('bonus_recipients')->where('id', $id)->where('company_id', $companyId)->update($update);
            $this->refreshAggregate((int) $run['id'], $companyId, date('Y-m-d H:i:s'));
        });
        return ['recipient' => $recipient, 'update' => $update];
    }

    /** @return array<string,mixed> */
    private function requireScheme(int $id, int $companyId): array
    {
        $scheme = $this->repository->findScheme($id, $companyId);
        if (! $scheme) throw new \InvalidArgumentException('Skema tidak ditemukan');
        return $scheme;
    }

    /** @return array<string,mixed> */
    private function requireRun(int $id, int $companyId): array
    {
        $run = $this->repository->findRun($id, $companyId);
        if (! $run) throw new \InvalidArgumentException('Bonus run tidak ditemukan');
        return $run;
    }

    /** @param array<string,mixed> $run @param list<string> $statuses */
    private function assertStatus(array $run, array $statuses, string $message): void
    {
        if (! in_array((string) $run['status'], $statuses, true)) throw new \DomainException($message);
    }

    private function requireCompany(int $companyId): void
    {
        if ($companyId <= 0) throw new \DomainException('Active company context is required');
    }

    /** @return array<string,mixed> */
    private function aggregate(int $runId, int $companyId): array
    {
        return $this->db->table('bonus_recipients')->selectSum('amount', 'total')->selectCount('id', 'cnt')
            ->where('bonus_run_id', $runId)->where('company_id', $companyId)->where('is_excluded', 0)
            ->get()->getRowArray() ?? ['total' => 0, 'cnt' => 0];
    }

    private function refreshAggregate(int $runId, int $companyId, string $now): void
    {
        $aggregate = $this->aggregate($runId, $companyId);
        $this->db->table('bonus_runs')->where('id', $runId)->where('company_id', $companyId)->update([
            'total_amount' => (float) ($aggregate['total'] ?? 0),
            'recipient_count' => (int) ($aggregate['cnt'] ?? 0), 'updated_at' => $now,
        ]);
    }

    private function transaction(callable $callback): void
    {
        $this->db->transBegin();
        try {
            $callback();
            if ($this->db->transStatus() === false) throw new \RuntimeException('Bonus transaction failed');
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
