<?php

namespace App\Libraries;

use Config\Services;

/**
 * TrainingService — orkestrasi training, registrations, certificates, budget.
 *
 * Tanggung jawab:
 *  1. Generate code unik (TRN-YEAR-XXXXX, CERT-YEAR-XXXXX)
 *  2. State machine registration (REGISTERED → APPROVED → ATTENDING → COMPLETED → CERTIFIED)
 *  3. Auto-issue certificate setelah COMPLETED + lulus passing_score
 *  4. Update budget reserved/used saat status berubah
 *  5. Detect & alert sertifikat yang akan expire (30/14/7 hari)
 *  6. Cap kapasitas peserta saat approve
 */
class TrainingService
{
    public const REGISTRATION_STATUSES = [
        'REGISTERED','APPROVED','ATTENDING','COMPLETED','CERTIFIED','REJECTED','CANCELLED','NO_SHOW',
    ];

    public const CERT_ALERT_DAYS = [60, 30, 14, 7]; // hari sebelum expire

    private function db() { return \Config\Database::connect(); }

    public function nextTrainingCode(int $companyId): string
    {
        $year = date('Y');
        $prefix = "TRN-{$year}-";
        $count = $this->db()->table('trainings')
            ->where('company_id', $companyId)
            ->like('code', $prefix, 'after')->countAllResults();
        do {
            $count++;
            $candidate = $prefix . str_pad((string) $count, 5, '0', STR_PAD_LEFT);
            $exists = $this->db()->table('trainings')
                ->where('company_id', $companyId)->where('code', $candidate)->countAllResults();
        } while ($exists > 0 && $count < 99999);
        return $candidate;
    }

    public function nextCertNumber(int $companyId): string
    {
        $year = date('Y');
        $prefix = "CERT-{$year}-";
        $count = $this->db()->table('certificates')
            ->where('company_id', $companyId)
            ->like('certificate_number', $prefix, 'after')->countAllResults();
        do {
            $count++;
            $candidate = $prefix . str_pad((string) $count, 5, '0', STR_PAD_LEFT);
            $exists = $this->db()->table('certificates')
                ->where('company_id', $companyId)
                ->where('certificate_number', $candidate)->countAllResults();
        } while ($exists > 0 && $count < 99999);
        return $candidate;
    }

    /**
     * Approve registration + cek kapasitas + reserve budget.
     */
    public function approveRegistration(int $regId, int $byUserId): array
    {
        $reg = $this->db()->table('training_registrations')->where('id', $regId)->get()->getRowArray();
        if (! $reg) throw new \RuntimeException('Registration tidak ditemukan');
        if ($reg['status'] !== 'REGISTERED') {
            throw new \RuntimeException("Hanya status REGISTERED yang bisa di-approve, current: {$reg['status']}");
        }

        $training = $this->db()->table('trainings')->where('id', (int) $reg['training_id'])->get()->getRowArray();
        if (! $training) throw new \RuntimeException('Training tidak ditemukan');

        // Cek kapasitas
        if ((int) $training['max_participants'] > 0) {
            $approvedCount = $this->db()->table('training_registrations')
                ->where('training_id', $training['id'])
                ->whereIn('status', ['APPROVED','ATTENDING','COMPLETED','CERTIFIED'])
                ->countAllResults();
            if ($approvedCount >= (int) $training['max_participants']) {
                throw new \RuntimeException("Kuota peserta penuh ({$approvedCount}/{$training['max_participants']})");
            }
        }

        $now = date('Y-m-d H:i:s');
        $cost = (float) ($reg['cost'] ?: $training['cost_per_participant']);
        $this->db()->table('training_registrations')->where('id', $regId)->update([
            'status' => 'APPROVED',
            'approved_at' => $now,
            'approved_by' => $byUserId,
            'cost' => $cost,
            'updated_at' => $now,
        ]);

        // Update budget reserved
        $this->updateBudgetReserved((int) $training['company_id'], $training['department_id'] ?? null, $cost, +1);

        return ['status' => 'APPROVED', 'cost' => $cost];
    }

    /**
     * Mark sebagai COMPLETED. Optional auto-issue certificate.
     */
    public function completeRegistration(int $regId, array $data = []): array
    {
        $reg = $this->db()->table('training_registrations')->where('id', $regId)->get()->getRowArray();
        if (! $reg) throw new \RuntimeException('Registration tidak ditemukan');
        if (! in_array($reg['status'], ['APPROVED','ATTENDING'], true)) {
            throw new \RuntimeException("Hanya APPROVED/ATTENDING yang bisa di-complete, current: {$reg['status']}");
        }

        $training = $this->db()->table('trainings')->where('id', (int) $reg['training_id'])->get()->getRowArray();
        $now = date('Y-m-d H:i:s');

        $score = isset($data['final_score']) ? (float) $data['final_score'] : null;
        $attendance = isset($data['attendance_percent']) ? (float) $data['attendance_percent'] : null;
        $isPassed = null;
        if ($score !== null && $training['passing_score']) {
            $isPassed = $score >= (float) $training['passing_score'] ? 1 : 0;
        }

        $update = [
            'status' => 'COMPLETED',
            'completed_at' => $now,
            'final_score' => $score,
            'attendance_percent' => $attendance,
            'is_passed' => $isPassed,
            'updated_at' => $now,
        ];
        $this->db()->table('training_registrations')->where('id', $regId)->update($update);

        // Update budget: reserved → used
        $cost = (float) $reg['cost'];
        $this->updateBudgetReserved((int) $training['company_id'], $training['department_id'] ?? null, $cost, -1);
        $this->updateBudgetUsed((int) $training['company_id'], $training['department_id'] ?? null, $cost, +1);

        // Auto-issue certificate kalau training mengeluarkan dan lulus
        $certId = null;
        if ((int) $training['issues_certificate'] === 1
            && ($score === null || $isPassed === 1)) {
            // Merge score & attendance ke $reg supaya ter-snapshot di cert
            $reg = array_merge($reg, $update);
            $certId = $this->issueCertificate($reg, $training);
            $this->db()->table('training_registrations')->where('id', $regId)
                ->update(['status' => 'CERTIFIED', 'updated_at' => $now]);
        }

        return ['status' => $certId ? 'CERTIFIED' : 'COMPLETED', 'certificate_id' => $certId];
    }

    /**
     * Issue certificate dari registration + training data.
     */
    public function issueCertificate(array $registration, array $training): int
    {
        $companyId = (int) $training['company_id'];
        $now = date('Y-m-d H:i:s');
        $expires = null;
        if ((int) $training['certificate_validity_months'] > 0) {
            $expires = date('Y-m-d', strtotime('+' . (int) $training['certificate_validity_months'] . ' months'));
        }
        $insert = [
            'company_id'         => $companyId,
            'employee_id'        => (int) $registration['employee_id'],
            'training_id'        => (int) $training['id'],
            'registration_id'    => (int) $registration['id'],
            'certificate_number' => $this->nextCertNumber($companyId),
            'title'              => $training['title'],
            'category'           => $training['category'] === 'COMPLIANCE' ? 'COMPLIANCE' : 'TRAINING',
            'issuer'             => $training['provider'] ?: 'Internal',
            'issued_date'        => date('Y-m-d'),
            'expires_date'       => $expires,
            'score'              => $registration['final_score'] ?? null,
            'status'             => 'ACTIVE',
            'created_at'         => $now,
            'updated_at'         => $now,
        ];
        $this->db()->table('certificates')->insert($insert);
        return (int) $this->db()->insertID();
    }

    /**
     * Update reserved amount untuk budget. Dampak +1 untuk approve, -1 untuk complete/cancel.
     */
    private function updateBudgetReserved(int $companyId, ?int $deptId, float $amount, int $direction): void
    {
        if ($amount <= 0) return;
        $year = (int) date('Y');
        $delta = $direction * $amount;

        $b = $this->db()->table('training_budgets')
            ->where('company_id', $companyId)->where('year', $year);
        if ($deptId === null) $b->where('department_id IS NULL');
        else                  $b->where('department_id', $deptId);
        $row = $b->get()->getRowArray();

        if ($row) {
            $newReserved = max(0, (float) $row['reserved_amount'] + $delta);
            $this->db()->table('training_budgets')->where('id', $row['id'])->update([
                'reserved_amount' => $newReserved,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } elseif ($direction > 0) {
            // Buat row baru kalau belum ada — alokasi 0 sementara
            $this->db()->table('training_budgets')->insert([
                'company_id' => $companyId,
                'department_id' => $deptId,
                'year' => $year,
                'allocated_amount' => 0,
                'used_amount' => 0,
                'reserved_amount' => $delta,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function updateBudgetUsed(int $companyId, ?int $deptId, float $amount, int $direction): void
    {
        if ($amount <= 0) return;
        $year = (int) date('Y');
        $delta = $direction * $amount;

        $b = $this->db()->table('training_budgets')
            ->where('company_id', $companyId)->where('year', $year);
        if ($deptId === null) $b->where('department_id IS NULL');
        else                  $b->where('department_id', $deptId);
        $row = $b->get()->getRowArray();

        if ($row) {
            $this->db()->table('training_budgets')->where('id', $row['id'])->update([
                'used_amount' => max(0, (float) $row['used_amount'] + $delta),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Mark certificates yang sudah lewat expires_date sebagai EXPIRED.
     * Dipanggil oleh cron `hr:check-cert-expiry`.
     */
    public function expireOverdueCertificates(?int $companyId = null): int
    {
        $today = date('Y-m-d');
        $b = $this->db()->table('certificates')
            ->where('status', 'ACTIVE')
            ->where('expires_date IS NOT NULL')
            ->where('expires_date <', $today);
        if ($companyId) $b->where('company_id', $companyId);
        $rows = $b->get()->getResultArray();

        foreach ($rows as $r) {
            $this->db()->table('certificates')->where('id', $r['id'])->update([
                'status' => 'EXPIRED', 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return count($rows);
    }

    /**
     * List certificates yang akan expire dalam N hari (default 30).
     * Skip yang last_alert_sent_at belum lewat 7 hari (anti-spam).
     */
    public function findExpiringCertificates(int $daysAhead = 30, ?int $companyId = null): array
    {
        $today = date('Y-m-d');
        $cutoff = date('Y-m-d', strtotime("+{$daysAhead} days"));
        $b = $this->db()->table('certificates c')
            ->select('c.*, e.full_name AS employee_name, e.employee_number, e.user_id, e.department_id')
            ->join('employees e', 'e.id = c.employee_id')
            ->where('c.status', 'ACTIVE')
            ->where('c.expires_date IS NOT NULL')
            ->where('c.expires_date >=', $today)
            ->where('c.expires_date <=', $cutoff);
        if ($companyId) $b->where('c.company_id', $companyId);
        return $b->orderBy('c.expires_date', 'ASC')->get()->getResultArray();
    }

    /**
     * Stats untuk dashboard training.
     */
    public function stats(int $companyId, ?int $year = null): array
    {
        $year = $year ?: (int) date('Y');
        $db = $this->db();

        $yearStart = "{$year}-01-01";
        $yearEnd   = "{$year}-12-31";
        $totalTrainings = $db->table('trainings')
            ->where('company_id', $companyId)
            ->where('start_date >=', $yearStart)
            ->where('start_date <=', $yearEnd)
            ->where('deleted_at IS NULL')
            ->countAllResults();

        $activeRegs = $db->table('training_registrations')
            ->where('company_id', $companyId)
            ->whereIn('status', ['APPROVED','ATTENDING'])
            ->countAllResults();

        $completed = $db->table('training_registrations')
            ->where('company_id', $companyId)
            ->whereIn('status', ['COMPLETED','CERTIFIED'])
            ->countAllResults();

        $activeCerts = $db->table('certificates')
            ->where('company_id', $companyId)
            ->where('status', 'ACTIVE')
            ->where('deleted_at IS NULL')
            ->countAllResults();

        $expiring = count($this->findExpiringCertificates(30, $companyId));

        // Budget summary
        $budgetRows = $db->table('training_budgets')
            ->where('company_id', $companyId)->where('year', $year)
            ->get()->getResultArray();
        $totalAllocated = 0; $totalUsed = 0; $totalReserved = 0;
        foreach ($budgetRows as $b) {
            $totalAllocated += (float) $b['allocated_amount'];
            $totalUsed += (float) $b['used_amount'];
            $totalReserved += (float) $b['reserved_amount'];
        }

        return [
            'year' => $year,
            'total_trainings' => $totalTrainings,
            'active_registrations' => $activeRegs,
            'completed_registrations' => $completed,
            'active_certificates' => $activeCerts,
            'expiring_certificates' => $expiring,
            'budget' => [
                'allocated' => $totalAllocated,
                'used'      => $totalUsed,
                'reserved'  => $totalReserved,
                'available' => $totalAllocated - $totalUsed - $totalReserved,
            ],
        ];
    }
}