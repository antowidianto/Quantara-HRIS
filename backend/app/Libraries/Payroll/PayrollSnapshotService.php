<?php

namespace App\Libraries\Payroll;

use CodeIgniter\Database\BaseConnection;

/** Creates and verifies immutable payroll evidence used after approval. */
final class PayrollSnapshotService
{
    public const LOCKED_STATUSES = ['APPROVED', 'FINALIZED', 'PAID', 'CLOSED'];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    /** @return array{0:string,1:string} */
    public function build(array $period): array
    {
        $payslips = $this->db->table('payslips')
            ->where('payroll_period_id', (int) $period['id'])
            ->where('company_id', (int) $period['company_id'])
            ->orderBy('id', 'ASC')->get()->getResultArray();

        foreach ($payslips as &$payslip) {
            $payslip['details'] = $this->db->table('payslip_details')
                ->where('payslip_id', (int) $payslip['id'])
                ->orderBy('id', 'ASC')->get()->getResultArray();
            $payslip['employee_master'] = $this->db->table('employees')
                ->select('id, employee_number, basic_salary, bank_name, bank_account_encrypted, bank_account_holder, cost_center_id, ptkp_status')
                ->where('id', (int) $payslip['employee_id'])
                ->where('company_id', (int) $period['company_id'])
                ->get()->getRowArray();
        }
        unset($payslip);

        $evidence = [
            'schema_version' => 1,
            'period' => array_diff_key($period, array_flip([
                'snapshot_json', 'snapshot_hash', 'snapshot_created_at',
            ])),
            'payslips' => $payslips,
        ];
        $json = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [$json, hash('sha256', $json)];
    }

    /** @return array<string,mixed> */
    public function assertValid(array $period): array
    {
        $json = (string) ($period['snapshot_json'] ?? '');
        $storedHash = (string) ($period['snapshot_hash'] ?? '');
        if ($json === '' || $storedHash === '') {
            throw new \RuntimeException('Payroll snapshot is missing');
        }
        if (! hash_equals($storedHash, hash('sha256', $json))) {
            throw new \RuntimeException('Payroll snapshot integrity check failed');
        }

        try {
            $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Payroll snapshot JSON is invalid', 0, $e);
        }
        if (! is_array($snapshot)
            || (int) ($snapshot['period']['id'] ?? 0) !== (int) ($period['id'] ?? 0)
            || (int) ($snapshot['period']['company_id'] ?? 0) !== (int) ($period['company_id'] ?? 0)
            || ! is_array($snapshot['payslips'] ?? null)
        ) {
            throw new \RuntimeException('Payroll snapshot evidence is invalid');
        }

        return $snapshot;
    }

    /** @return array<string,mixed> */
    public function assertPeriod(int $companyId, int $periodId): array
    {
        $period = $this->db->table('payroll_periods')
            ->where('id', $periodId)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $period) {
            throw new \InvalidArgumentException('Payroll period not found');
        }
        $this->assertValid($period);

        return $period;
    }

    /** @return array<string,mixed> */
    public function payslip(array $snapshot, int $payslipId): array
    {
        foreach ($snapshot['payslips'] as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $payslipId) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Payslip is missing from finalized snapshot');
    }

    /**
     * Baseline legacy locked periods created before immutable snapshots existed.
     * Partial or mismatched snapshots are deliberately never overwritten.
     *
     * @return array{scanned:int,backfilled:int,failed:int,errors:array<int,string>}
     */
    public function backfillMissing(?int $companyId = null): array
    {
        $builder = $this->db->table('payroll_periods')
            ->whereIn('status', self::LOCKED_STATUSES)
            ->orderBy('id', 'ASC');
        if ($companyId !== null) {
            $builder->where('company_id', $companyId);
        }

        $result = ['scanned' => 0, 'backfilled' => 0, 'failed' => 0, 'errors' => []];
        foreach ($builder->get()->getResultArray() as $period) {
            $json = (string) ($period['snapshot_json'] ?? '');
            $hash = (string) ($period['snapshot_hash'] ?? '');
            if ($json !== '' || $hash !== '') {
                continue;
            }
            $result['scanned']++;
            try {
                [$snapshotJson, $snapshotHash] = $this->build($period);
                $updated = $this->db->table('payroll_periods')
                    ->where('id', (int) $period['id'])
                    ->where('company_id', (int) $period['company_id'])
                    ->groupStart()->where('snapshot_json IS NULL')->orWhere('snapshot_json', '')->groupEnd()
                    ->groupStart()->where('snapshot_hash IS NULL')->orWhere('snapshot_hash', '')->groupEnd()
                    ->update([
                        'snapshot_json' => $snapshotJson,
                        'snapshot_hash' => $snapshotHash,
                        'snapshot_created_at' => date('Y-m-d H:i:s'),
                    ]);
                if ($updated && $this->db->affectedRows() === 1) {
                    $result['backfilled']++;
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][(int) $period['id']] = $e->getMessage();
            }
        }

        return $result;
    }

    /** @return array{checked:int,valid:int,invalid:int,errors:array<int,string>} */
    public function verifyLocked(?int $companyId = null): array
    {
        $builder = $this->db->table('payroll_periods')
            ->whereIn('status', self::LOCKED_STATUSES)
            ->orderBy('id', 'ASC');
        if ($companyId !== null) {
            $builder->where('company_id', $companyId);
        }

        $result = ['checked' => 0, 'valid' => 0, 'invalid' => 0, 'errors' => []];
        foreach ($builder->get()->getResultArray() as $period) {
            $result['checked']++;
            try {
                $this->assertValid($period);
                $result['valid']++;
            } catch (\Throwable $e) {
                $result['invalid']++;
                $result['errors'][(int) $period['id']] = $e->getMessage();
            }
        }

        return $result;
    }
}
