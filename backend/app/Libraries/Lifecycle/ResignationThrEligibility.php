<?php

declare(strict_types=1);

namespace App\Libraries\Lifecycle;

use CodeIgniter\Database\BaseConnection;

/** Permenaker 6/2016 Article 7: PKWTT termination within 30 days before the religious holiday retains THR. */
final class ResignationThrEligibility
{
    /** @return array{eligible: bool, amount: float, holiday_date: ?string, holiday_name: ?string, reason: string} */
    public static function evaluate(array $employee, string $resignDate, array $holiday): array
    {
        $holidayDate = (string) ($holiday['date'] ?? '');
        $holidayName = (string) ($holiday['name'] ?? '');
        $result = ['eligible' => false, 'amount' => 0.0, 'holiday_date' => $holidayDate ?: null, 'holiday_name' => $holidayName ?: null];
        if (strtoupper((string) ($employee['employment_status'] ?? '')) !== 'PKWTT') {
            return $result + ['reason' => 'Article 7 protection applies only to PKWTT employees'];
        }
        if ($holidayDate === '' || ! self::holidayMatchesReligion($holidayName, (string) ($employee['religion'] ?? ''))) {
            return $result + ['reason' => 'No matching religious holiday'];
        }

        $resign = new \DateTimeImmutable($resignDate);
        $holidayAt = new \DateTimeImmutable($holidayDate);
        $daysBefore = (int) $resign->diff($holidayAt)->format('%r%a');
        if ($daysBefore < 0 || $daysBefore > 30) {
            return $result + ['reason' => 'Resignation is outside the 30-day protected window'];
        }

        $joinDate = (string) ($employee['join_date'] ?? '');
        if ($joinDate === '') return $result + ['reason' => 'Join date is unavailable'];
        $tenureDays = (int) (new \DateTimeImmutable($joinDate))->diff($resign)->format('%r%a');
        if ($tenureDays < 30) return $result + ['reason' => 'Tenure is less than one month'];

        $monthlyWage = (float) ($employee['basic_salary'] ?? 0) + (float) ($employee['fixed_allowance'] ?? 0);
        $amount = $tenureDays >= 365 ? $monthlyWage : $monthlyWage * $tenureDays / 365;
        return [
            'eligible' => true,
            'amount' => round(max(0.0, $amount), 2),
            'holiday_date' => $holidayDate,
            'holiday_name' => $holidayName,
            'reason' => 'PKWTT resignation falls within 30 days before the matching religious holiday',
        ];
    }

    /** @return array{eligible: bool, amount: float, holiday_date: ?string, holiday_name: ?string, reason: string} */
    public function resolve(BaseConnection $db, int $companyId, array $employee, string $resignDate): array
    {
        $until = (new \DateTimeImmutable($resignDate))->modify('+30 days')->format('Y-m-d');
        $holidays = $db->table('holidays')->where('date >=', $resignDate)->where('date <=', $until)
            ->where('is_religious', 1)->groupStart()->where('company_id', $companyId)
            ->orWhere('company_id', null)->groupEnd()->orderBy('date', 'ASC')->get()->getResultArray();
        foreach ($holidays as $holiday) {
            $result = self::evaluate($employee, $resignDate, $holiday);
            if ($result['eligible']) return $result;
        }
        return ['eligible' => false, 'amount' => 0.0, 'holiday_date' => null, 'holiday_name' => null, 'reason' => 'No eligible holiday in the protected window'];
    }

    private static function holidayMatchesReligion(string $holidayName, string $religion): bool
    {
        $name = strtoupper($holidayName);
        return match (strtoupper($religion)) {
            'ISLAM' => str_contains($name, 'IDUL FITRI') || str_contains($name, 'LEBARAN'),
            'KRISTEN', 'KATOLIK' => str_contains($name, 'NATAL'),
            'HINDU' => str_contains($name, 'NYEPI'),
            'BUDDHA' => str_contains($name, 'WAISAK'),
            'KONGHUCU' => str_contains($name, 'IMLEK'),
            default => false,
        };
    }
}
