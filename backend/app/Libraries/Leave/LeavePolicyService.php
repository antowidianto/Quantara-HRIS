<?php

declare(strict_types=1);

namespace App\Libraries\Leave;

use App\Repositories\Leave\LeaveRepository;
use CodeIgniter\Database\BaseConnection;

final class LeavePolicyService
{
    private readonly LeaveRepository $repository;

    public function __construct(?BaseConnection $connection = null)
    {
        $this->repository = new LeaveRepository($connection);
    }

    /** @param array<string,mixed> $employee @return array{ok:bool,reason:string,default_days:int|null} */
    public function categoryEligibility(string $category, array $employee): array
    {
        $gender = strtoupper((string) ($employee['gender'] ?? ''));
        $marital = strtoupper((string) ($employee['marital_status'] ?? ''));
        return match (strtoupper($category)) {
            'MATERNITY' => $gender === 'F'
                ? ['ok' => true, 'reason' => '', 'default_days' => 90]
                : ['ok' => false, 'reason' => 'Cuti melahirkan hanya untuk karyawan perempuan (UU 13/2003 Pasal 82).', 'default_days' => null],
            'PATERNITY' => $gender !== 'M'
                ? ['ok' => false, 'reason' => 'Cuti pendamping melahirkan hanya untuk karyawan laki-laki.', 'default_days' => null]
                : ($marital !== 'MARRIED'
                    ? ['ok' => false, 'reason' => 'Cuti pendamping melahirkan hanya untuk karyawan yang berstatus menikah.', 'default_days' => null]
                    : ['ok' => true, 'reason' => '', 'default_days' => 2]),
            'MENSTRUAL' => $gender === 'F'
                ? ['ok' => true, 'reason' => '', 'default_days' => 2]
                : ['ok' => false, 'reason' => 'Cuti haid hanya untuk karyawan perempuan (UU 13/2003 Pasal 81).', 'default_days' => null],
            'MARRIAGE' => $marital === 'MARRIED'
                ? ['ok' => false, 'reason' => 'Cuti menikah tidak dapat diajukan karena status Anda sudah menikah.', 'default_days' => null]
                : ['ok' => true, 'reason' => '', 'default_days' => 3],
            'BEREAVEMENT' => ['ok' => true, 'reason' => '', 'default_days' => 2],
            'HAJJ' => ['ok' => true, 'reason' => '', 'default_days' => 40],
            'ANNUAL' => $this->annualEligibility($employee),
            default => ['ok' => true, 'reason' => '', 'default_days' => null],
        };
    }

    /** @return array<string,mixed> */
    public function policy(int $companyId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $current = $this->repository->payrollPeriodForDate(
            $companyId, $today, ['DRAFT', 'OPEN', 'CALCULATED'],
        );
        $next = $this->repository->nextPayrollPeriod($companyId, $today, ['DRAFT', 'OPEN']);
        $maxStart = $next['pay_date'] ?? date('Y-m-d', strtotime($today . ' +180 days'));
        $sickMin = $current
            ? max((string) $current['start_date'], date('Y-m-d', strtotime($today . ' -14 days')))
            : date('Y-m-d', strtotime($today . ' -14 days'));
        $period = static fn (?array $row): ?array => $row ? [
            'id' => (int) $row['id'], 'code' => $row['code'], 'name' => $row['name'],
            'start_date' => $row['start_date'], 'end_date' => $row['end_date'],
            'pay_date' => $row['pay_date'], 'status' => $row['status'],
        ] : null;
        return [
            'today' => $today, 'current_period' => $period($current), 'next_period' => $period($next),
            'default_min_start_date' => $today, 'default_max_start_date' => $maxStart,
            'per_category' => [
                'SICK' => ['min_start_date' => $sickMin, 'max_start_date' => $maxStart],
                'BEREAVEMENT' => ['min_start_date' => date('Y-m-d', strtotime($today . ' -7 days')), 'max_start_date' => $maxStart],
                'MATERNITY' => ['min_start_date' => $today, 'max_start_date' => date('Y-m-d', strtotime($today . ' +365 days'))],
                'PATERNITY' => ['min_start_date' => date('Y-m-d', strtotime($today . ' -3 days')), 'max_start_date' => date('Y-m-d', strtotime($today . ' +365 days'))],
                'HAJJ' => ['min_start_date' => $today, 'max_start_date' => date('Y-m-d', strtotime($today . ' +365 days'))],
            ],
            'help_text' => 'Pengajuan cuti hanya bisa di periode payroll yang masih aktif (belum dikunci). Cuti sakit boleh diajukan mundur untuk hari-hari saat Anda sakit di periode berjalan.',
        ];
    }

    public function countWorkingDays(int $companyId, string $start, string $end): float
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if ($startTs === false || $endTs === false || $endTs < $startTs) return 0;
        $holidays = $this->repository->holidayDates($companyId, $start, $end);
        $days = 0;
        for ($timestamp = $startTs; $timestamp <= $endTs; $timestamp += 86400) {
            if ((int) date('N', $timestamp) >= 6) continue;
            if (in_array(date('Y-m-d', $timestamp), $holidays, true)) continue;
            $days++;
        }
        return (float) $days;
    }

    /** @param array<string,mixed> $employee @return array{ok:bool,reason:string,default_days:int|null} */
    private function annualEligibility(array $employee): array
    {
        $tenure = (int) ((time() - strtotime((string) ($employee['join_date'] ?? 'now'))) / 86400 / 30);
        return $tenure < 12
            ? ['ok' => false, 'reason' => "Cuti tahunan baru bisa diajukan setelah masa kerja 12 bulan (saat ini {$tenure} bulan).", 'default_days' => null]
            : ['ok' => true, 'reason' => '', 'default_days' => 12];
    }
}
