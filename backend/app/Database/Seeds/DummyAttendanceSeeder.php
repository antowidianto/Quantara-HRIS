<?php

namespace App\Database\Seeds;

use App\Libraries\Attendance\ShiftResolver;
use CodeIgniter\Database\Seeder;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Membuat data kehadiran dummy untuk seluruh karyawan aktif berdasarkan
 * jadwal efektif dari ShiftResolver.
 *
 * Rentang: 120 hari sampai kemarin.
 * Idempotent: attendance yang sudah ada tidak diubah/ditimpa.
 *
 * Jalankan:
 *   php spark db:seed DummyAttendanceSeeder
 */
class DummyAttendanceSeeder extends Seeder
{
    private const DAYS = 120;
    private const BATCH_SIZE = 500;

    public function run(): void
    {
        if (! $this->db->tableExists('employees')
            || ! $this->db->tableExists('attendances')
            || ! $this->db->tableExists('shifts')) {
            throw new RuntimeException('Tabel employees/attendances/shifts belum tersedia.');
        }

        $employees = $this->db->table('employees')
            ->select('id, company_id, employee_number, full_name, join_date, resign_date')
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->orderBy('company_id', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        if ($employees === []) {
            echo "Tidak ada karyawan aktif; tidak ada attendance yang dibuat.\n";

            return;
        }

        $end = new DateTimeImmutable('yesterday');
        $start = $end->sub(new DateInterval('P' . (self::DAYS - 1) . 'D'));
        $dates = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));

        $existing = $this->loadExistingAttendance($start, $end);
        $attendanceFields = array_map(
            static fn ($field): string => strtolower((string) $field->name),
            $this->db->getFieldData('attendances')
        );
        $hasWorkType = in_array('work_type', $attendanceFields, true);

        $resolver = new ShiftResolver();
        $batch = [];
        $inserted = 0;
        $skippedExisting = 0;
        $skippedOff = 0;
        $skippedNoShift = 0;
        $failed = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($employees as $employee) {
            $employeeId = (int) $employee['id'];
            $joinDate = ! empty($employee['join_date'])
                ? new DateTimeImmutable((string) $employee['join_date'])
                : null;
            $resignDate = ! empty($employee['resign_date'])
                ? new DateTimeImmutable((string) $employee['resign_date'])
                : null;

            foreach ($dates as $date) {
                $workDate = $date->format('Y-m-d');

                if (($joinDate && $date < $joinDate) || ($resignDate && $date > $resignDate)) {
                    continue;
                }

                $key = $employeeId . '|' . $workDate;
                if (isset($existing[$key])) {
                    $skippedExisting++;

                    continue;
                }

                try {
                    $schedule = $resolver->resolve($employeeId, $workDate);
                } catch (Throwable $e) {
                    $failed++;
                    log_message(
                        'error',
                        "DummyAttendanceSeeder gagal resolve employee {$employeeId} {$workDate}: {$e->getMessage()}"
                    );

                    continue;
                }

                if (! empty($schedule['is_off']) || ! empty($schedule['is_holiday'])) {
                    $skippedOff++;

                    continue;
                }

                $shift = $schedule['shift'] ?? null;
                if (! is_array($shift) || empty($shift['id'])) {
                    $skippedNoShift++;

                    continue;
                }

                $row = $this->makeAttendance(
                    (int) $employee['company_id'],
                    $employeeId,
                    $workDate,
                    $shift,
                    $now
                );
                if ($hasWorkType) {
                    $row['work_type'] = 'ONSITE';
                }

                $batch[] = $row;
                $existing[$key] = true;

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->db->table('attendances')->insertBatch($batch);
                    $inserted += count($batch);
                    $batch = [];
                }
            }
        }

        if ($batch !== []) {
            $this->db->table('attendances')->insertBatch($batch);
            $inserted += count($batch);
        }

        echo "DummyAttendanceSeeder selesai.\n";
        echo "Rentang            : {$start->format('Y-m-d')} s.d. {$end->format('Y-m-d')}\n";
        echo "Karyawan aktif     : " . count($employees) . "\n";
        echo "Attendance dibuat  : {$inserted}\n";
        echo "Sudah ada (skip)   : {$skippedExisting}\n";
        echo "Libur/off (skip)   : {$skippedOff}\n";
        echo "Tanpa shift (skip) : {$skippedNoShift}\n";
        echo "Gagal (skip)       : {$failed}\n";
    }

    /**
     * @return array<string, true>
     */
    private function loadExistingAttendance(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rows = $this->db->table('attendances')
            ->select('employee_id, work_date')
            ->where('work_date >=', $start->format('Y-m-d'))
            ->where('work_date <=', $end->format('Y-m-d'))
            ->get()
            ->getResultArray();

        $existing = [];
        foreach ($rows as $row) {
            $existing[(int) $row['employee_id'] . '|' . $row['work_date']] = true;
        }

        return $existing;
    }

    /**
     * Variasi deterministik agar rerun menghasilkan pola yang sama:
     * 82% PRESENT, 10% LATE, 3% ABSENT, 3% SICK, 2% HALF_DAY.
     *
     * @param array<string, mixed> $shift
     * @return array<string, mixed>
     */
    private function makeAttendance(
        int $companyId,
        int $employeeId,
        string $workDate,
        array $shift,
        string $now
    ): array {
        $seed = (int) sprintf('%u', crc32($employeeId . '|' . $workDate));
        $bucket = $seed % 100;

        $base = [
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'shift_id' => (int) $shift['id'],
            'work_date' => $workDate,
            'clock_in_at' => null,
            'clock_in_notes' => 'Data dummy sesuai jadwal',
            'clock_out_at' => null,
            'clock_out_notes' => 'Data dummy sesuai jadwal',
            'work_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'overtime_minutes' => 0,
            'status' => 'PRESENT',
            'is_corrected' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($bucket >= 97) {
            $base['status'] = 'ABSENT';
            $base['clock_in_notes'] = 'Data dummy: tidak hadir';
            $base['clock_out_notes'] = null;

            return $base;
        }

        if ($bucket >= 94) {
            $base['status'] = 'SICK';
            $base['clock_in_notes'] = 'Data dummy: sakit';
            $base['clock_out_notes'] = null;

            return $base;
        }

        $start = new DateTimeImmutable($workDate . ' ' . $shift['start_time']);
        $end = new DateTimeImmutable($workDate . ' ' . $shift['end_time']);
        if (! empty($shift['crosses_midnight']) || $end <= $start) {
            $end = $end->modify('+1 day');
        }

        $breakMinutes = max(0, (int) ($shift['break_minutes'] ?? 0));
        $clockInOffset = (($seed >> 4) % 16) - 10;
        $clockOutOffset = (($seed >> 9) % 21) - 5;

        if ($bucket >= 84) {
            $lateMinutes = 16 + (($seed >> 14) % 30);
            $clockInOffset = $lateMinutes;
            $base['status'] = 'LATE';
            $base['late_minutes'] = $lateMinutes;
        }

        $clockIn = $start->modify(($clockInOffset >= 0 ? '+' : '') . $clockInOffset . ' minutes');
        $clockOut = $end->modify(($clockOutOffset >= 0 ? '+' : '') . $clockOutOffset . ' minutes');

        if ($bucket >= 82 && $bucket < 84) {
            $scheduledMinutes = max(1, (int) (($end->getTimestamp() - $start->getTimestamp()) / 60));
            $halfMinutes = max(1, (int) floor(($scheduledMinutes - $breakMinutes) / 2));
            $clockOut = $clockIn->modify("+{$halfMinutes} minutes");
            $base['status'] = 'HALF_DAY';
            $base['early_leave_minutes'] = max(
                0,
                (int) (($end->getTimestamp() - $clockOut->getTimestamp()) / 60)
            );
        }

        $grossMinutes = max(
            0,
            (int) (($clockOut->getTimestamp() - $clockIn->getTimestamp()) / 60)
        );

        $base['clock_in_at'] = $clockIn->format('Y-m-d H:i:s');
        $base['clock_out_at'] = $clockOut->format('Y-m-d H:i:s');
        $base['work_minutes'] = max(0, $grossMinutes - $breakMinutes);

        if ($base['status'] !== 'HALF_DAY' && $clockOut < $end) {
            $base['early_leave_minutes'] = (int) ceil(
                ($end->getTimestamp() - $clockOut->getTimestamp()) / 60
            );
        }
        if ($clockOut > $end) {
            $base['overtime_minutes'] = (int) floor(
                ($clockOut->getTimestamp() - $end->getTimestamp()) / 60
            );
        }

        return $base;
    }
}