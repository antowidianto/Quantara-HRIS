<?php

namespace App\Controllers\Api\V1;

/**
 * AnniversaryController
 *
 * Endpoint untuk dashboard widget: upcoming birthdays & work anniversaries.
 *
 * Endpoint:
 *   GET /anniversary/upcoming?days=30 — list event 30 hari ke depan
 *   GET /anniversary/today           — list event hari ini
 *
 * Authorization: butuh `employee.view` atau pegawai bisa lihat tim sendiri
 * (filter ke department/branch ybs).
 */
class AnniversaryController extends ApiController
{
    /**
     * GET /anniversary/upcoming?days=30
     *
     * Mengembalikan list karyawan yang akan ulang tahun atau anniversary
     * dalam X hari ke depan, sorted by tanggal.
     */
    public function upcoming()
    {
        $db = \Config\Database::connect();
        $tenant = $this->tenant();
        $companyId = $tenant->getCompanyId();
        if (! $companyId && ! $tenant->isSuperAdmin()) {
            return $this->fail('Company context required', 400);
        }

        $days = max(1, min(90, (int) ($this->request->getGet('days') ?? 30)));
        $today = new \DateTime();
        $end = (clone $today)->modify("+{$days} days");

        $builder = $db->table('employees e')
            ->select('e.id, e.full_name, e.first_name, e.last_name, e.date_of_birth, e.join_date,'
                . ' e.department_id, e.branch_id, e.user_id, d.name AS department_name, b.name AS branch_name')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('branches b', 'b.id = e.branch_id', 'left')
            ->where('e.is_active', 1)
            ->where('e.deleted_at IS NULL');
        $tenant->applyScope($builder, 'e.company_id');
        $employees = $builder->get()->getResultArray();

        $events = [];
        foreach ($employees as $emp) {
            $name = $emp['full_name'] ?? trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));

            // Birthday upcoming
            if (! empty($emp['date_of_birth'])) {
                $next = $this->nextOccurrence((string) $emp['date_of_birth'], $today);
                if ($next && $next <= $end) {
                    $age = $this->calcYears((string) $emp['date_of_birth'], $next);
                    $events[] = [
                        'type' => 'BIRTHDAY',
                        'date' => $next->format('Y-m-d'),
                        'days_until' => (int) $today->diff($next)->days * ($next < $today ? -1 : 1),
                        'employee_id' => (int) $emp['id'],
                        'employee_name' => $name,
                        'department_name' => $emp['department_name'],
                        'branch_name' => $emp['branch_name'],
                        'age' => $age,
                        'milestone' => false,
                    ];
                }
            }

            // Anniversary upcoming
            if (! empty($emp['join_date'])) {
                $next = $this->nextOccurrence((string) $emp['join_date'], $today);
                if ($next && $next <= $end) {
                    $years = $this->calcYears((string) $emp['join_date'], $next);
                    if ($years >= 1) {
                        $isMilestone = in_array($years, [1, 3, 5, 10, 15, 20, 25, 30], true);
                        $events[] = [
                            'type' => 'ANNIVERSARY',
                            'date' => $next->format('Y-m-d'),
                            'days_until' => (int) $today->diff($next)->days * ($next < $today ? -1 : 1),
                            'employee_id' => (int) $emp['id'],
                            'employee_name' => $name,
                            'department_name' => $emp['department_name'],
                            'branch_name' => $emp['branch_name'],
                            'years' => $years,
                            'milestone' => $isMilestone,
                        ];
                    }
                }
            }
        }

        // Sort by date asc
        usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));

        return $this->ok($events);
    }

    /**
     * GET /anniversary/today
     *
     * Shortcut untuk event hari ini saja.
     */
    public function today()
    {
        // Kembalikan hanya yg days_until = 0
        $resp = $this->upcoming();
        $body = json_decode((string) $resp->getBody(), true);
        if (! is_array($body) || ! isset($body['data'])) return $resp;
        $body['data'] = array_values(array_filter($body['data'], fn($e) => (int) ($e['days_until'] ?? -1) === 0));
        return $this->response->setJSON($body);
    }

    /**
     * Cari occurrence berikutnya dari tanggal (ulang tahun / anniversary).
     * Misal date_of_birth 1990-06-15, today = 2026-05-21 → return 2026-06-15.
     * Kalau sudah lewat tahun ini, return tahun depan.
     */
    private function nextOccurrence(string $originalDate, \DateTime $today): ?\DateTime
    {
        try {
            $orig = new \DateTime($originalDate);
        } catch (\Throwable $e) { return null; }
        $thisYear = (int) $today->format('Y');
        $candidate = clone $orig;
        $candidate->setDate($thisYear, (int) $orig->format('m'), (int) $orig->format('d'));
        if ($candidate < $today) {
            $candidate->modify('+1 year');
        }
        return $candidate;
    }

    private function calcYears(string $from, \DateTime $to): int
    {
        try {
            $f = new \DateTime($from);
        } catch (\Throwable $e) { return 0; }
        $diff = $to->diff($f);
        return (int) $diff->y;
    }
}