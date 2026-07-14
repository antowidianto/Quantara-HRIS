<?php

namespace App\Libraries\Employee;

use App\Libraries\Payroll\JicRetroPayrollService;
use Config\Database;
use Config\Services;

/**
 * JicService — Job Information Change service.
 *
 * Berisi business logic untuk:
 *   - Validasi compliance Indonesia (UMP, consent, dokumen pendukung)
 *   - Snapshot data lama dari `employees`
 *   - Klasifikasi otomatis change_type (promotion/demotion/lateral) berdasar
 *     perbandingan grade tier & basic_salary
 *   - Apply (implementasi) JIC ke records pada effective_date
 *   - Generate SK / surat amandemen kontrak otomatis
 *
 * Semua aksi dipakai bersama oleh:
 *   - JicController (HR/Manager-driven flow)
 *   - ApplyJic command (cron harian, mencari APPROVED yang sudah jatuh tempo)
 *
 * Lihat docs/JIC.md.
 */
class JicService
{
    /** Tier hierarchy untuk klasifikasi otomatis (lebih besar = lebih tinggi). */
    private const TIER_RANK = [
        'STAFF'        => 1,
        'SUPERVISOR'   => 2,
        'ASST_MANAGER' => 3,
        'MANAGER'      => 4,
    ];

    /**
     * Threshold persen kenaikan/penurunan upah yang dianggap "material".
     * Di bawah ini → SALARY_ADJUSTMENT, di atas → PROMOTION/DEMOTION.
     */
    private const SALARY_MATERIALITY_PCT = 5.0;

    /** Tier yang butuh approval BOD selain HR (manager dan ke atas). */
    private const REQUIRES_BOD_TIERS = ['MANAGER', 'ASST_MANAGER'];

    /**
     * Klasifikasi change_type berdasar perbandingan posisi lama vs baru.
     *
     * Aturan:
     *   - Tier baru > tier lama                           → PROMOTION
     *   - Tier baru < tier lama                           → DEMOTION
     *   - Tier sama, salary up >= 5%                      → PROMOTION (merit)
     *   - Tier sama, salary down >= 5%                    → DEMOTION
     *   - Tier sama, |Δ salary| < 5%, dept/branch berubah → LATERAL
     *   - Tier sama, |Δ salary| < 5%, hanya salary        → SALARY_ADJUSTMENT
     *   - employment_status berubah (PROBATION→PKWTT)     → STATUS_CHANGE
     *
     * @return array{change_type:string, salary_change_pct:float}
     */
    public function classify(array $oldData, array $newData): array
    {
        $oldTier = $oldData['old_position_tier']  ?? 'STAFF';
        $newTier = $newData['new_position_tier']  ?? $oldTier;
        $oldRank = self::TIER_RANK[$oldTier] ?? 1;
        $newRank = self::TIER_RANK[$newTier] ?? 1;

        $oldSalary = (float) ($oldData['old_basic_salary'] ?? 0);
        $newSalary = (float) ($newData['new_basic_salary'] ?? $oldSalary);
        $deltaPct  = $oldSalary > 0
            ? (($newSalary - $oldSalary) / $oldSalary) * 100.0
            : 0.0;

        $statusChanged = ! empty($newData['new_employment_status'])
            && ($newData['new_employment_status'] !== ($oldData['old_employment_status'] ?? null));

        if ($statusChanged && abs($oldRank - $newRank) === 0 && abs($deltaPct) < self::SALARY_MATERIALITY_PCT) {
            return ['change_type' => 'STATUS_CHANGE', 'salary_change_pct' => round($deltaPct, 4)];
        }

        if ($newRank > $oldRank) {
            return ['change_type' => 'PROMOTION', 'salary_change_pct' => round($deltaPct, 4)];
        }
        if ($newRank < $oldRank) {
            return ['change_type' => 'DEMOTION', 'salary_change_pct' => round($deltaPct, 4)];
        }

        // Tier sama
        if ($deltaPct >= self::SALARY_MATERIALITY_PCT) {
            return ['change_type' => 'PROMOTION', 'salary_change_pct' => round($deltaPct, 4)];
        }
        if ($deltaPct <= -self::SALARY_MATERIALITY_PCT) {
            return ['change_type' => 'DEMOTION', 'salary_change_pct' => round($deltaPct, 4)];
        }

        // Δsalary kecil → bedakan LATERAL vs SALARY_ADJUSTMENT
        $deptChanged   = ! empty($newData['new_department_id'])
            && (int) $newData['new_department_id'] !== (int) ($oldData['old_department_id'] ?? 0);
        $branchChanged = ! empty($newData['new_branch_id'])
            && (int) $newData['new_branch_id'] !== (int) ($oldData['old_branch_id'] ?? 0);
        $locChanged    = ! empty($newData['new_work_location_id'])
            && (int) $newData['new_work_location_id'] !== (int) ($oldData['old_work_location_id'] ?? 0);
        $posChanged    = ! empty($newData['new_position_id'])
            && (int) $newData['new_position_id'] !== (int) ($oldData['old_position_id'] ?? 0);

        if ($deptChanged || $branchChanged || $locChanged || $posChanged) {
            return ['change_type' => 'LATERAL', 'salary_change_pct' => round($deltaPct, 4)];
        }
        return ['change_type' => 'SALARY_ADJUSTMENT', 'salary_change_pct' => round($deltaPct, 4)];
    }

    /**
     * Build snapshot kolom `old_*` dari record employees + position tier.
     *
     * @return array<string,mixed> kolom siap untuk insert/update job_information_changes
     */
    public function snapshotEmployee(int $employeeId): array
    {
        $db  = Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) {
            throw new \RuntimeException("Employee #{$employeeId} tidak ditemukan");
        }

        $posName = null; $tier = 'STAFF';
        if (! empty($emp['position_id'])) {
            $row = $db->table('positions')
                ->select('name, tier')
                ->where('id', (int) $emp['position_id'])->get()->getRowArray();
            if ($row) {
                $posName = $row['name'];
                $tier    = $row['tier'] ?? 'STAFF';
            }
        }

        $deptName = null;
        if (! empty($emp['department_id'])) {
            $row = $db->table('departments')->select('name')
                ->where('id', (int) $emp['department_id'])->get()->getRowArray();
            $deptName = $row['name'] ?? null;
        }

        $gradeName = null;
        if (! empty($emp['job_grade_id'])) {
            $row = $db->table('job_grades')->select('name')
                ->where('id', (int) $emp['job_grade_id'])->get()->getRowArray();
            $gradeName = $row['name'] ?? null;
        }

        return [
            'old_position_id'       => $emp['position_id'] ?: null,
            'old_position_name'     => $posName,
            'old_position_tier'     => $tier, // bukan kolom DB; dipakai classifier
            'old_department_id'     => $emp['department_id'] ?: null,
            'old_department_name'   => $deptName,
            'old_branch_id'         => $emp['branch_id'] ?: null,
            'old_work_location_id'  => $emp['work_location_id'] ?? null,
            'old_job_grade_id'      => $emp['job_grade_id'] ?: null,
            'old_job_grade_name'    => $gradeName,
            'old_employment_status' => $emp['employment_status'] ?? null,
            'old_basic_salary'      => (float) ($emp['basic_salary'] ?? 0),
            'old_supervisor_id'     => $emp['supervisor_id'] ?? null,
            'old_manager_id'        => $emp['manager_id'] ?? null,
        ];
    }

    /**
     * Cek upah baru ≥ UMP/UMK rujukan company.
     *
     * Mengembalikan:
     *   - status: PASS | FAIL | NOT_APPLICABLE
     *   - reference: angka UMP saat snapshot
     *
     * UMP diambil dari:
     *   1. settings table dengan key `payroll.minimum_wage` (per-company)
     *   2. settings table dengan key `ump.<province_code>` (city-spesifik kalau ada)
     *   3. fallback NULL → NOT_APPLICABLE
     *
     * STATUS_CHANGE / SALARY_ADJUSTMENT yang tidak menurunkan upah tidak
     * butuh cek UMP secara hukum, tapi kita tetap evaluate untuk audit.
     */
    public function checkUmp(int $companyId, ?float $newSalary, ?int $branchId = null): array
    {
        if ($newSalary === null || $newSalary <= 0) {
            return ['status' => 'NOT_APPLICABLE', 'reference' => null];
        }
        $db = Database::connect();

        // Ambil dari settings — fallback berurutan
        $keys = ['payroll.minimum_wage', 'ump.default'];
        if ($branchId) {
            // Cari province code dari branch supaya bisa lookup ump.<province>
            $row = $db->table('branches')->select('province')
                ->where('id', $branchId)->get()->getRowArray();
            if ($row && ! empty($row['province'])) {
                $keys = array_merge(['ump.' . strtolower($row['province'])], $keys);
            }
        }

        $ump = null;
        foreach ($keys as $key) {
            $r = $db->table('settings')
                ->where('company_id', $companyId)
                ->where('key', $key)
                ->get()->getRowArray();
            if ($r && is_numeric($r['value'])) {
                $ump = (float) $r['value'];
                break;
            }
        }
        if ($ump === null || $ump <= 0) {
            return ['status' => 'NOT_APPLICABLE', 'reference' => null];
        }

        return [
            'status'    => $newSalary >= $ump ? 'PASS' : 'FAIL',
            'reference' => $ump,
        ];
    }

    /**
     * Tentukan apakah JIC ini butuh consent karyawan.
     *
     * Aturan: butuh consent kalau DEMOTION dan reason ≠ DISCIPLINARY.
     * Pasal 31 UU 13/2003 + KUHPerdata 1320 (asas konsensual perjanjian).
     */
    public function requiresEmployeeConsent(string $changeType, string $reasonCategory): bool
    {
        return $changeType === 'DEMOTION' && $reasonCategory !== 'DISCIPLINARY';
    }

    /**
     * Tentukan apakah perlu approval BOD.
     *
     * Aturan: butuh BOD kalau:
     *   - DEMOTION (apa pun reason-nya, sensitif legal)
     *   - PROMOTION ke tier MANAGER/ASST_MANAGER
     *   - Salary change > 25% (signifikan)
     */
    public function requiresBodApproval(string $changeType, ?string $newTier, float $salaryChangePct): bool
    {
        if ($changeType === 'DEMOTION') return true;
        if ($changeType === 'PROMOTION' && in_array($newTier, self::REQUIRES_BOD_TIERS, true)) return true;
        if (abs($salaryChangePct) > 25.0) return true;
        return false;
    }

    /**
     * Validasi dokumen pendukung wajib sesuai reason_category.
     *
     * Mengembalikan list error string. Empty array = lolos.
     */
    public function validateSupportingDocuments(array $jic): array
    {
        $errors = [];
        switch ($jic['reason_category'] ?? null) {
            case 'DISCIPLINARY':
                if (empty($jic['disciplinary_record_id'])) {
                    $errors[] = 'reason DISCIPLINARY wajib link ke disciplinary_record_id (rujukan SP).';
                }
                break;
            case 'PERFORMANCE':
            case 'MERIT':
                if (empty($jic['performance_review_id'])) {
                    $errors[] = 'reason PERFORMANCE/MERIT wajib link ke performance_review_id.';
                }
                break;
            case 'COMPETENCY':
                if (empty($jic['training_certificate_id'])) {
                    $errors[] = 'reason COMPETENCY wajib link ke training_certificate_id.';
                }
                break;
            case 'RESTRUCTURE':
                if (empty($jic['mpp_id'])) {
                    $errors[] = 'reason RESTRUCTURE direkomendasikan link ke MPP yang relevan.';
                }
                break;
        }
        return $errors;
    }

    /**
     * Generate JIC number unik per company. Format: JIC/{YYYY}/{MM}/{seq04}
     */
    public function generateJicNumber(int $companyId): string
    {
        $db    = Database::connect();
        $year  = date('Y');
        $month = date('m');
        $like  = "JIC/{$year}/{$month}/%";
        $row   = $db->table('job_information_changes')
            ->select('jic_number')
            ->like('jic_number', $like, 'after')
            ->where('company_id', $companyId)
            ->orderBy('id', 'DESC')
            ->limit(1)->get()->getRowArray();

        $next = 1;
        if ($row && preg_match('#/(\d+)$#', $row['jic_number'], $m)) {
            $next = (int) $m[1] + 1;
        }
        return sprintf('JIC/%s/%s/%04d', $year, $month, $next);
    }

    /**
     * Apply (implementasi) JIC yang berstatus APPROVED & sudah jatuh tempo.
     *
     * Aksi:
     *   1. Update employees: position_id, department_id, branch_id, work_location_id,
     *      job_grade_id, employment_status, basic_salary, supervisor_id, manager_id.
     *   2. Insert employee_contracts row baru kalau employment_status berubah
     *      atau ini PROMOTION/DEMOTION (dokumen amandemen).
     *   3. Recompute employee_allowances kalau allowance service tersedia.
     *   4. Generate SK number kalau belum ada.
     *   5. Set status = IMPLEMENTED.
     *
     * Idempotent: kalau status sudah IMPLEMENTED, langsung return tanpa side-effect.
     *
     * @return array{success:bool, message:string, contract_id?:int}
     */
    public function apply(int $jicId, ?int $actorUserId = null): array
    {
        $db  = Database::connect();
        $jic = $db->table('job_information_changes')->where('id', $jicId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $jic) {
            return ['success' => false, 'message' => 'JIC tidak ditemukan'];
        }
        if ($jic['status'] === 'IMPLEMENTED') {
            return ['success' => true, 'message' => 'JIC sudah ter-implementasi sebelumnya (idempotent)'];
        }
        if ($jic['status'] !== 'APPROVED') {
            return ['success' => false, 'message' => 'JIC harus berstatus APPROVED, saat ini: ' . $jic['status']];
        }

        $now           = date('Y-m-d H:i:s');
        $effectiveDate = $jic['effective_date'];

        $db->transStart();

        // A past-effective salary change must create an auditable correction
        // before the employee master is changed. A missing target period fails
        // the transaction so retro pay can never be silently dropped.
        $retroAdjustmentIds = (new JicRetroPayrollService($db))->createForJic(
            $jic,
            $actorUserId ?: (int) ($jic['created_by'] ?? 0),
        );

        // 1. Update employees dengan field non-null saja (jangan timpa null tak sengaja)
        $update = [];
        foreach ([
            'new_position_id'        => 'position_id',
            'new_department_id'      => 'department_id',
            'new_branch_id'          => 'branch_id',
            'new_work_location_id'   => 'work_location_id',
            'new_job_grade_id'       => 'job_grade_id',
            'new_employment_status'  => 'employment_status',
            'new_basic_salary'       => 'basic_salary',
            'new_supervisor_id'      => 'supervisor_id',
            'new_manager_id'         => 'manager_id',
        ] as $src => $dst) {
            if ($jic[$src] !== null && $jic[$src] !== '') {
                $update[$dst] = $jic[$src];
            }
        }
        if (! empty($update)) {
            $update['updated_at'] = $now;
            $db->table('employees')->where('id', (int) $jic['employee_id'])
                ->where('company_id', (int) $jic['company_id'])->update($update);
        }

        // 2. Insert employee_contracts amandemen untuk PROMOTION/DEMOTION/STATUS_CHANGE
        $contractId = null;
        if (in_array($jic['change_type'], ['PROMOTION', 'DEMOTION', 'STATUS_CHANGE'], true)) {
            $contractType = $jic['new_employment_status'] ?? $jic['old_employment_status'] ?? 'PKWTT';
            // PKHL/HARIAN/MAGANG di employees enum, tapi employee_contracts hanya
            // menerima PKWT/PKWTT/PROBATION/PKHL/MAGANG. Map seperlunya.
            $allowed = ['PKWT', 'PKWTT', 'PROBATION', 'PKHL', 'MAGANG'];
            if (! in_array($contractType, $allowed, true)) {
                $contractType = 'PKWTT';
            }
            $insert = [
                'company_id'      => (int) $jic['company_id'],
                'employee_id'     => (int) $jic['employee_id'],
                'contract_number' => $jic['sk_number'] ?? $this->generateContractNumber((int) $jic['company_id']),
                'contract_type'   => $contractType,
                'start_date'      => $effectiveDate,
                'end_date'        => null, // PKWTT tidak ada akhir; PKWT belum tahu, HR isi manual
                'basic_salary'    => $jic['new_basic_salary'] ?? $jic['old_basic_salary'] ?? 0,
                'position_id'     => $jic['new_position_id'] ?? $jic['old_position_id'] ?? null,
                'department_id'   => $jic['new_department_id'] ?? $jic['old_department_id'] ?? null,
                'document_path'   => $jic['sk_signed_path'] ?? null,
                'notes'           => sprintf(
                    'Amandemen kontrak via JIC #%d (%s) tanggal efektif %s',
                    (int) $jic['id'], $jic['change_type'], $effectiveDate,
                ),
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
            $db->table('employee_contracts')->insert($insert);
            $contractId = (int) $db->insertID();
        }

        // 3. Recompute allowances best-effort. AllowanceService::recomputeEmployee
        // pakai data master saat ini (dept/branch/grade), jadi panggil setelah
        // employees ter-update di langkah 1.
        try {
            if (class_exists(\App\Libraries\Payroll\AllowanceService::class)) {
                Services::allowance()->recomputeEmployee((int) $jic['employee_id']);
            }
        } catch (\Throwable $e) {
            log_message('warning', '[JIC#' . $jic['id'] . '] recompute allowances failed: ' . $e->getMessage());
        }

        // 4. Set status IMPLEMENTED
        $db->table('job_information_changes')->where('id', $jicId)
            ->where('company_id', (int) $jic['company_id'])->update([
            'status'           => 'IMPLEMENTED',
            'implemented_at'   => $now,
            'implemented_by'   => $actorUserId,
            'updated_at'       => $now,
        ]);

        (new EmployeeEffectiveHistoryService($db))->recordAppliedSnapshot(
            (int) $jic['company_id'], (int) $jic['employee_id'], (string) $effectiveDate,
            'JIC', $jicId, $actorUserId,
        );

        $db->transComplete();
        if ($db->transStatus() === false) {
            return ['success' => false, 'message' => 'Transaksi DB gagal saat apply JIC'];
        }

        Services::audit()->forModule('JIC')->log(
            action: 'jic.implement',
            entity: 'job_information_changes',
            entityId: $jicId,
            description: sprintf(
                'Apply JIC #%d (%s) untuk employee #%d, effective %s%s',
                $jicId, $jic['change_type'], (int) $jic['employee_id'], $effectiveDate,
                $contractId ? " — kontrak amandemen #{$contractId} dibuat" : '',
            ),
            severity: 'WARNING',
            metadata: ['contract_id' => $contractId, 'retro_adjustment_ids' => $retroAdjustmentIds],
        );

        // 5. Notifikasi karyawan
        $this->notifyImplemented($jic, $contractId);

        return [
            'success' => true,
            'message' => 'JIC berhasil di-implementasi',
            'contract_id' => $contractId,
            'retro_adjustment_ids' => $retroAdjustmentIds,
        ];
    }

    /** Generate nomor kontrak amandemen unik per company. */
    public function generateContractNumber(int $companyId): string
    {
        $db    = Database::connect();
        $year  = date('Y');
        $month = date('m');
        $like  = "AMD/{$year}/{$month}/%";
        $row   = $db->table('employee_contracts')
            ->select('contract_number')
            ->like('contract_number', $like, 'after')
            ->where('company_id', $companyId)
            ->orderBy('id', 'DESC')
            ->limit(1)->get()->getRowArray();

        $next = 1;
        if ($row && preg_match('#/(\d+)$#', $row['contract_number'], $m)) {
            $next = (int) $m[1] + 1;
        }
        return sprintf('AMD/%s/%s/%04d', $year, $month, $next);
    }

    /**
     * Cari semua JIC yang sudah APPROVED dan effective_date <= hari ini.
     * Dipakai oleh command apply-jic.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findDueForImplementation(?int $companyId = null): array
    {
        $db = Database::connect();
        $b  = $db->table('job_information_changes')
            ->where('status', 'APPROVED')
            ->where('effective_date <=', date('Y-m-d'))
            ->where('deleted_at IS NULL')
            ->orderBy('effective_date', 'ASC');
        if ($companyId) $b->where('company_id', $companyId);

        return $b->get()->getResultArray();
    }

    private function notifyImplemented(array $jic, ?int $contractId): void
    {
        $db  = Database::connect();
        $emp = $db->table('employees')->where('id', (int) $jic['employee_id'])
            ->where('company_id', (int) $jic['company_id'])->get()->getRowArray();
        if (! $emp) return;

        $title = match ($jic['change_type']) {
            'PROMOTION'         => 'Selamat atas promosi Anda',
            'DEMOTION'          => 'Perubahan posisi Anda telah berlaku',
            'LATERAL'           => 'Mutasi Anda telah berlaku',
            'SALARY_ADJUSTMENT' => 'Penyesuaian upah Anda telah berlaku',
            'STATUS_CHANGE'     => 'Status kepegawaian Anda telah diperbarui',
            default             => 'Perubahan informasi pekerjaan',
        };
        $msg = sprintf(
            'Perubahan posisi/kompensasi Anda berlaku efektif %s. Lihat detail SK dan amandemen kontrak di profil Anda.',
            $jic['effective_date'],
        );

        // 1. Notifikasi ke karyawan ybs
        if (! empty($emp['user_id'])) {
            try {
                Services::notification()->send(
                    (int) $emp['user_id'],
                    (int) $jic['company_id'],
                    'jic.implemented',
                    $title,
                    $msg,
                    [
                        'jic_id'      => (int) $jic['id'],
                        'jic_number'  => $jic['jic_number'],
                        'contract_id' => $contractId,
                        'change_type' => $jic['change_type'],
                    ],
                    null,
                    true,
                );
            } catch (\Throwable $e) {
                log_message('warning', 'JIC notify employee failed: ' . $e->getMessage());
            }
        }

        // 2. Notifikasi ke atasan baru — supervisor & manager.
        // Penting: kalau JIC mengubah supervisor/manager (mutasi/promosi),
        // atasan baru harus tahu bahwa karyawan ini sekarang di bawah mereka,
        // supaya bisa adjust assignment / approval routing. Atasan lama
        // notif terpisah biar mereka aware tidak lagi punya tanggung jawab.
        $this->notifyNewReporters($db, $jic, $emp);
    }

    /**
     * Kirim notifikasi ke supervisor/manager baru (dan lama, kalau berbeda)
     * saat JIC mengubah reporting line. Best-effort: kegagalan satu pengirim
     * tidak block lainnya.
     */
    private function notifyNewReporters(
        \CodeIgniter\Database\BaseConnection $db,
        array $jic,
        array $emp,
    ): void {
        $changeNames = [];

        $oldSupId = isset($jic['old_supervisor_id']) ? (int) $jic['old_supervisor_id'] : 0;
        $newSupId = isset($jic['new_supervisor_id']) ? (int) $jic['new_supervisor_id'] : 0;
        $oldMgrId = isset($jic['old_manager_id'])    ? (int) $jic['old_manager_id']    : 0;
        $newMgrId = isset($jic['new_manager_id'])    ? (int) $jic['new_manager_id']    : 0;

        // Kumpulkan target notif: (employee_id, role_label, type)
        $targets = [];
        if ($newSupId && $newSupId !== $oldSupId) {
            $targets[] = ['emp_id' => $newSupId, 'role' => 'Atasan Langsung', 'kind' => 'new'];
            if ($oldSupId && $oldSupId !== (int) $emp['id']) {
                $targets[] = ['emp_id' => $oldSupId, 'role' => 'Atasan Langsung Lama', 'kind' => 'old'];
            }
        }
        if ($newMgrId && $newMgrId !== $oldMgrId && $newMgrId !== $newSupId) {
            $targets[] = ['emp_id' => $newMgrId, 'role' => 'Manager Kepegawaian', 'kind' => 'new'];
            if ($oldMgrId && $oldMgrId !== (int) $emp['id'] && $oldMgrId !== $oldSupId) {
                $targets[] = ['emp_id' => $oldMgrId, 'role' => 'Manager Kepegawaian Lama', 'kind' => 'old'];
            }
        }

        if (empty($targets)) return;

        // Resolve user_id untuk setiap target
        $empIds = array_unique(array_column($targets, 'emp_id'));
        $rows = $db->table('employees')
            ->select('id, user_id, full_name')
            ->whereIn('id', $empIds)
            ->where('company_id', (int) $jic['company_id'])
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();
        $byEmp = [];
        foreach ($rows as $r) $byEmp[(int) $r['id']] = $r;

        $effectiveDate = (string) ($jic['effective_date'] ?? date('Y-m-d'));
        $employeeName  = (string) ($emp['full_name'] ?? '#' . $emp['id']);

        foreach ($targets as $t) {
            $row = $byEmp[(int) $t['emp_id']] ?? null;
            if (! $row || empty($row['user_id'])) continue;

            $isNew = $t['kind'] === 'new';
            $title = $isNew
                ? sprintf('Anda menjadi %s untuk %s', $t['role'], $employeeName)
                : sprintf('Karyawan keluar dari reporting line Anda: %s', $employeeName);
            $msg = $isNew
                ? sprintf(
                    '%s sekarang berada di bawah Anda sebagai %s, efektif %s. '
                    . 'Approval cuti, lembur, attendance, serta task ownership akan ter-routing ke Anda.',
                    $employeeName, strtolower($t['role']), $effectiveDate,
                )
                : sprintf(
                    '%s tidak lagi berada di reporting line Anda efektif %s. '
                    . 'Approval pending mungkin ter-redirect — pastikan handover dengan atasan baru.',
                    $employeeName, $effectiveDate,
                );

            try {
                Services::notification()->send(
                    (int) $row['user_id'],
                    (int) $jic['company_id'],
                    'jic.reporter_changed',
                    $title,
                    $msg,
                    [
                        'jic_id'      => (int) $jic['id'],
                        'jic_number'  => $jic['jic_number'],
                        'employee_id' => (int) $emp['id'],
                        'role'        => $t['role'],
                        'kind'        => $t['kind'],
                    ],
                    '/employees/' . (int) $emp['id'],
                    $isNew, // important = new reporter
                );
            } catch (\Throwable $e) {
                log_message('warning', 'JIC notify reporter failed: ' . $e->getMessage());
            }
        }
    }
}
