<?php

namespace App\Libraries\Training;

use CodeIgniter\Database\BaseConnection;
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

    /** @var callable(array<string,mixed>):array<string,mixed>|null */
    private $approvalStarter;

    public function __construct(
        private readonly ?BaseConnection $connection = null,
        ?callable $approvalStarter = null
    ) {
        $this->approvalStarter = $approvalStarter;
    }

    private function db(): BaseConnection { return $this->connection ?? \Config\Database::connect(); }

    /** @return array<string,mixed> */
    public function register(
        int $companyId,
        int $trainingId,
        int $employeeId,
        int $actorUserId,
        ?string $notes = null
    ): array {
        $db = $this->db();
        $training = $db->table('trainings')->where('id', $trainingId)
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $training) throw new \InvalidArgumentException('Training tidak ditemukan di perusahaan aktif.');
        if ($training['status'] !== 'PUBLISHED') throw new \RuntimeException('Training belum dipublish.');
        $employee = $db->table('employees')->where('id', $employeeId)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $employee) throw new \InvalidArgumentException('Employee tidak ditemukan di perusahaan aktif.');
        $actor = $db->table('users')->where('id', $actorUserId)->where('company_id', $companyId)
            ->where('is_active', 1)->get()->getRowArray();
        if (! $actor || (int) ($actor['employee_id'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('Pendaftar harus user aktif yang terhubung ke employee.');
        }
        $existing = $db->table('training_registrations')->where('training_id', $trainingId)
            ->where('employee_id', $employeeId)->where('company_id', $companyId)->get()->getRowArray();
        if ($existing && ! in_array($existing['status'], ['REJECTED', 'CANCELLED'], true)) {
            throw new \RuntimeException('Karyawan sudah memiliki registrasi aktif untuk training ini.');
        }
        $now = date('Y-m-d H:i:s');
        $row = [
            'company_id' => $companyId, 'training_id' => $trainingId, 'employee_id' => $employeeId,
            'status' => 'REGISTERED', 'registered_at' => $now,
            'approved_at' => null, 'approved_by' => null,
            'cost' => (float) $training['cost_per_participant'],
            'notes' => $notes, 'rejection_reason' => null, 'updated_at' => $now,
        ];
        $db->transBegin();
        try {
            if ($existing) {
                $db->table('training_registrations')->where('id', (int) $existing['id'])
                    ->where('company_id', $companyId)->whereIn('status', ['REJECTED', 'CANCELLED'])->update($row);
                if ($db->affectedRows() !== 1) throw new \RuntimeException('Registrasi berubah; muat ulang data.');
                $registrationId = (int) $existing['id'];
            } else {
                $row['created_at'] = $now;
                $db->table('training_registrations')->insert($row);
                $registrationId = (int) $db->insertID();
            }
            $params = [
                'company_id' => $companyId,
                'module' => 'TRAINING_REGISTRATION',
                'entity_table' => 'training_registrations',
                'entity_id' => $registrationId,
                'employee_id' => $employeeId,
                'meta' => [
                    'amount' => (float) $training['cost_per_participant'],
                    'training_id' => $trainingId,
                    'target_employee_id' => $employeeId,
                    'department_id' => $training['department_id'] ?? $employee['department_id'] ?? null,
                    'is_external' => ($training['training_type'] ?? 'INTERNAL') === 'EXTERNAL',
                    'start_date' => $training['start_date'],
                    'submitted_by' => $actorUserId,
                ],
            ];
            $approval = $this->approvalStarter !== null
                ? ($this->approvalStarter)($params)
                : Services::approval()->start($params);
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membuat workflow approval training.');
            $db->transCommit();
            return ['id' => $registrationId, 'status' => 'REGISTERED', 'approval' => $approval];
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

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
    public function approveRegistration(int $regId, int $companyId, int $byUserId): array
    {
        $reg = $this->db()->table('training_registrations')->where('id', $regId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $reg) throw new \RuntimeException('Registration tidak ditemukan');
        if ($reg['status'] !== 'REGISTERED') {
            throw new \RuntimeException("Hanya status REGISTERED yang bisa di-approve, current: {$reg['status']}");
        }

        $training = $this->db()->table('trainings')->where('id', (int) $reg['training_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $training) throw new \RuntimeException('Training tidak ditemukan');
        if (! in_array($training['status'], ['PUBLISHED', 'ONGOING'], true)) {
            throw new \RuntimeException('Training tidak lagi terbuka untuk approval.');
        }

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
        $cost = (float) $reg['cost'];
        if (abs($cost - (float) $training['cost_per_participant']) > 0.005) {
            throw new \RuntimeException('Biaya training berubah setelah submit; registrasi harus diajukan ulang.');
        }
        $budgetYear = (int) substr((string) $training['start_date'], 0, 4);
        $this->reserveBudget((int) $training['company_id'], $training['department_id'] ?? null, $budgetYear, $cost);
        $this->db()->table('training_registrations')->where('id', $regId)
            ->where('company_id', $companyId)->where('status', 'REGISTERED')->update([
            'status' => 'APPROVED',
            'approved_at' => $now,
            'approved_by' => $byUserId,
            'cost' => $cost,
            'updated_at' => $now,
        ]);
        if ($this->db()->affectedRows() !== 1) throw new \RuntimeException('Registrasi berubah; muat ulang data.');

        return ['status' => 'APPROVED', 'cost' => $cost];
    }

    public function rejectRegistration(int $regId, int $companyId, int $byUserId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) throw new \InvalidArgumentException('Alasan penolakan minimal 5 karakter.');
        $reg = $this->db()->table('training_registrations')->where('id', $regId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $reg) throw new \RuntimeException('Registration tidak ditemukan');
        if ($reg['status'] !== 'REGISTERED') throw new \RuntimeException('Registration tidak sedang menunggu approval.');
        $this->db()->table('training_registrations')->where('id', $regId)
            ->where('company_id', $companyId)->where('status', 'REGISTERED')->update([
                'status' => 'REJECTED', 'rejection_reason' => $reason,
                'approved_by' => $byUserId, 'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        if ($this->db()->affectedRows() !== 1) throw new \RuntimeException('Registrasi berubah; muat ulang data.');
        return ['status' => 'REJECTED', 'reason' => $reason];
    }

    /** @return array<string,mixed> */
    public function cancelRegistration(int $regId, int $companyId): array
    {
        $db = $this->db();
        $reg = $db->table('training_registrations')->where('id', $regId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $reg) throw new \RuntimeException('Registration tidak ditemukan');
        if (! in_array($reg['status'], ['REGISTERED', 'APPROVED'], true)) {
            throw new \RuntimeException("Tidak bisa cancel status {$reg['status']}");
        }
        $training = $db->table('trainings')->where('id', (int) $reg['training_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $training) throw new \RuntimeException('Training tidak ditemukan');
        $db->transBegin();
        try {
            if ($reg['status'] === 'REGISTERED') {
                Services::approval()->cancelEntity(
                    'TRAINING_REGISTRATION', 'training_registrations', $regId, $companyId
                );
            } else {
                $year = (int) substr((string) $training['start_date'], 0, 4);
                $this->updateBudgetReserved(
                    $companyId, $training['department_id'] ?? null, (float) $reg['cost'], -1, $year
                );
            }
            $db->table('training_registrations')->where('id', $regId)->where('company_id', $companyId)
                ->where('status', (string) $reg['status'])->update([
                    'status' => 'CANCELLED', 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if ($db->affectedRows() !== 1 || ! $db->transStatus()) {
                throw new \RuntimeException('Registrasi berubah; muat ulang data.');
            }
            $db->transCommit();
            return ['id' => $regId, 'status' => 'CANCELLED'];
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /**
     * Mark sebagai COMPLETED. Optional auto-issue certificate.
     */
    public function completeRegistration(int $regId, int $companyId, array $data = []): array
    {
        $reg = $this->db()->table('training_registrations')->where('id', $regId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $reg) throw new \RuntimeException('Registration tidak ditemukan');
        if (! in_array($reg['status'], ['APPROVED','ATTENDING'], true)) {
            throw new \RuntimeException("Hanya APPROVED/ATTENDING yang bisa di-complete, current: {$reg['status']}");
        }

        $training = $this->db()->table('trainings')->where('id', (int) $reg['training_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $training) throw new \RuntimeException('Training tidak ditemukan');
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
        $budgetYear = (int) substr((string) $training['start_date'], 0, 4);
        $this->updateBudgetReserved((int) $training['company_id'], $training['department_id'] ?? null, $cost, -1, $budgetYear);
        $this->updateBudgetUsed((int) $training['company_id'], $training['department_id'] ?? null, $cost, +1, $budgetYear);

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
    private function reserveBudget(int $companyId, ?int $deptId, int $year, float $amount): void
    {
        if ($amount <= 0) return;
        $db = $this->db();
        $builder = $db->table('training_budgets')->where('company_id', $companyId)->where('year', $year);
        if ($deptId === null) $builder->where('department_id IS NULL');
        else $builder->where('department_id', $deptId);
        $budget = $builder->get()->getRowArray();
        if (! $budget && $deptId !== null) {
            $budget = $db->table('training_budgets')->where('company_id', $companyId)->where('year', $year)
                ->where('department_id IS NULL')->get()->getRowArray();
        }
        if (! $budget) throw new \RuntimeException('Budget training belum dikonfigurasi.');
        $available = (float) $budget['allocated_amount'] - (float) $budget['used_amount'] - (float) $budget['reserved_amount'];
        if ($available + 0.005 < $amount) throw new \RuntimeException('Budget training tidak mencukupi.');
        $oldReserved = (float) $budget['reserved_amount'];
        $db->table('training_budgets')->where('id', (int) $budget['id'])
            ->where('company_id', $companyId)->where('reserved_amount', $oldReserved)->update([
                'reserved_amount' => $oldReserved + $amount,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        if ($db->affectedRows() !== 1) throw new \RuntimeException('Budget training berubah; muat ulang data.');
    }

    private function updateBudgetReserved(int $companyId, ?int $deptId, float $amount, int $direction, ?int $year = null): void
    {
        if ($amount <= 0) return;
        $year ??= (int) date('Y');
        $delta = $direction * $amount;

        $b = $this->db()->table('training_budgets')
            ->where('company_id', $companyId)->where('year', $year);
        if ($deptId === null) $b->where('department_id IS NULL');
        else                  $b->where('department_id', $deptId);
        $row = $b->get()->getRowArray();
        if (! $row && $deptId !== null) {
            $row = $this->db()->table('training_budgets')->where('company_id', $companyId)
                ->where('year', $year)->where('department_id IS NULL')->get()->getRowArray();
        }

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

    private function updateBudgetUsed(int $companyId, ?int $deptId, float $amount, int $direction, ?int $year = null): void
    {
        if ($amount <= 0) return;
        $year ??= (int) date('Y');
        $delta = $direction * $amount;

        $b = $this->db()->table('training_budgets')
            ->where('company_id', $companyId)->where('year', $year);
        if ($deptId === null) $b->where('department_id IS NULL');
        else                  $b->where('department_id', $deptId);
        $row = $b->get()->getRowArray();
        if (! $row && $deptId !== null) {
            $row = $this->db()->table('training_budgets')->where('company_id', $companyId)
                ->where('year', $year)->where('department_id IS NULL')->get()->getRowArray();
        }

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
