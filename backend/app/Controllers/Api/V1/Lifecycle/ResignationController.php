<?php

namespace App\Controllers\Api\V1\Lifecycle;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Recruitment\OnboardingService;
use Config\Services;

/**
 * ResignationController
 *
 * Self-service surat resignation karyawan dengan workflow approval atasan
 * langsung → HR. Saat HR approve, otomatis trigger:
 *   - set employees.resign_date + resign_reason
 *   - generate offboarding tasks (lewat OnboardingService) yang ter-assign
 *     ke karyawan, supervisor, HR, IT, dan Finance sesuai template OFFBOARD
 *
 * Perizinan:
 *   - submit & view-own        → setiap user yang punya record employee
 *   - approve/reject sebagai supervisor → user yang `employees.user_id`
 *     muncul sebagai `supervisor_id`/`manager_id` dari karyawan ybs
 *   - approve/reject sebagai HR        → permission `resignation.manage`
 *   - view all in company             → permission `resignation.view`
 *
 * Lihat docs/RESIGNATION.md untuk flow lengkap.
 */
class ResignationController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    private const REASON_CATEGORIES = [
        'PERSONAL'    => 'Alasan pribadi',
        'CAREER'      => 'Pindah karir / karir baru',
        'HEALTH'      => 'Alasan kesehatan',
        'FAMILY'      => 'Alasan keluarga',
        'EDUCATION'   => 'Melanjutkan pendidikan',
        'RETIREMENT'  => 'Pensiun',
        'OTHER'       => 'Lainnya',
    ];

    /**
     * GET /api/v1/resignations
     *
     * Filter:
     *   ?status=PENDING_SUPERVISOR|PENDING_HR|APPROVED|...
     *   ?employee_id=N        (HR / supervisor saja)
     *   ?as=mine|supervisor|hr  (default: mine kalau tidak punya perm view)
     */
    public function index()
    {
        $db = \Config\Database::connect();
        $companyId = $this->companyId();
        $userId    = $this->userId();
        $can       = $this->tenant();

        $hasViewAll = $can->isSuperAdmin() || $can->hasPermission('resignation.view') || $can->hasPermission('resignation.manage');
        $as = (string) $this->request->getGet('as');

        $builder = $db->table('resignation_requests rr')
            ->select('rr.*, e.full_name AS employee_name, e.employee_number, e.department_id, '
                . 'd.name AS department_name, p.name AS position_name')
            ->join('employees e',   'e.id = rr.employee_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('positions p',   'p.id = e.position_id',  'left')
            ->where('rr.deleted_at IS NULL');

        if ($companyId > 0 && ! $can->isSuperAdmin()) {
            $builder->where('rr.company_id', $companyId);
        }

        // Scope berdasarkan role caller — tetap berfungsi meski permission
        // belum di-grant (mode "mine" selalu valid karena karyawan boleh
        // lihat resign-nya sendiri).
        if (! $hasViewAll || $as === 'mine') {
            // Hanya yang ID employee.user_id == caller
            $builder->where('e.user_id', $userId);
        } elseif ($as === 'supervisor') {
            // Resignation dari karyawan yang supervisor/manager-nya = caller's employee
            $myEmp = $db->table('employees')->select('id')->where('user_id', $userId)
                ->where('deleted_at IS NULL')->get()->getRowArray();
            $myEmpId = $myEmp ? (int) $myEmp['id'] : 0;
            $builder->groupStart()
                ->where('e.supervisor_id', $myEmpId)
                ->orWhere('e.manager_id', $myEmpId)
                ->groupEnd();
        }
        // 'hr' atau default (untuk yang punya view all) → tampilkan semua

        if ($status = $this->request->getGet('status')) {
            $builder->where('rr.status', $status);
        }
        if ($empId = (int) $this->request->getGet('employee_id')) {
            if ($hasViewAll) $builder->where('rr.employee_id', $empId);
        }

        $perPage = max(1, min((int) $this->request->getGet('per_page') ?: 20, 100));
        $page    = max(1, (int) $this->request->getGet('page') ?: 1);
        $total   = (clone $builder)->countAllResults(false);
        $rows    = $builder->orderBy('rr.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /**
     * GET /api/v1/resignations/{id}
     *
     * Sprint 1 — sertakan offboarding clearance status (asset/finance/BPJS)
     * + paklaring + final settlement supaya UI ResignationReview bisa render
     * dalam 1 round-trip. Kalau OffboardingService gagal (mis. migration
     * 51 belum dijalankan), `offboarding` di-set null & UI fallback ke
     * mode tanpa clearance.
     */
    public function show($id)
    {
        $row = $this->loadRequestForUser((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau akses ditolak', 404);

        try {
            $row['offboarding'] = Services::offboarding()->status((int) $row['id']);
        } catch (\Throwable $e) {
            log_message('warning', 'Resignation::show offboarding status failed: ' . $e->getMessage());
            $row['offboarding'] = null;
        }

        return $this->ok($row);
    }

    /**
     * POST /api/v1/resignations
     *
     * Body:
     *   {
     *     "last_working_date": "2026-07-31",
     *     "effective_date":    "2026-08-31",     // optional, default = last_working_date
     *     "reason_category":   "CAREER",
     *     "reason_text":       "...",
     *     "attachment_path":   "..."             // optional, dari upload/attachment context=misc
     *   }
     *
     * Validasi:
     *   - notice period minimum 30 hari (UU 13/2003 Pasal 162)
     *     → bisa di-bypass oleh HR via PUT, tapi karyawan tidak bisa.
     *   - employee.user_id == caller
     *   - tidak ada PENDING_SUPERVISOR/PENDING_HR/APPROVED yang masih aktif
     */
    public function create()
    {
        $data  = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'last_working_date' => 'required|valid_date[Y-m-d]',
            'reason_category'   => 'required|in_list[' . implode(',', array_keys(self::REASON_CATEGORIES)) . ']',
            'reason_text'       => 'permit_empty|max_length[2000]',
            'attachment_path'   => 'permit_empty|max_length[500]',
            'effective_date'    => 'permit_empty|valid_date[Y-m-d]',
        ]);
        if (! $valid) return null;

        $userId = $this->userId();
        $db     = \Config\Database::connect();
        $emp    = $db->table('employees')
            ->where('user_id', $userId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();

        if (! $emp) {
            return $this->fail('Akun ini tidak terhubung ke record karyawan. Hubungi HR.', 422, 'NO_EMPLOYEE');
        }
        if ((int) $emp['is_active'] !== 1) {
            return $this->fail('Akun karyawan tidak aktif.', 422, 'INACTIVE_EMPLOYEE');
        }
        if (! empty($emp['resign_date'])) {
            return $this->fail('Status karyawan sudah resign per ' . $emp['resign_date'], 422, 'ALREADY_RESIGNED');
        }

        // Cek tidak ada pending/approved yang masih aktif
        $activeExists = $db->table('resignation_requests')
            ->where('employee_id', (int) $emp['id'])
            ->whereIn('status', ['PENDING_SUPERVISOR', 'PENDING_HR', 'APPROVED'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($activeExists > 0) {
            return $this->fail(
                'Sudah ada pengajuan resignation yang aktif. Silakan batalkan dulu sebelum mengajukan baru.',
                409, 'DUPLICATE_REQUEST'
            );
        }

        // Hitung notice period
        $today  = date('Y-m-d');
        $lwd    = $valid['last_working_date'];
        $diff   = (int) round((strtotime($lwd) - strtotime($today)) / 86400);
        if ($diff < 30) {
            return $this->fail(
                "Pemberitahuan resignation minimum 30 hari sebelum tanggal terakhir kerja (UU 13/2003 Pasal 162). "
                . "Saat ini hanya {$diff} hari. Hubungi HR jika ada kondisi khusus.",
                422, 'NOTICE_PERIOD_TOO_SHORT'
            );
        }

        // Resolve supervisor user_id (untuk routing approval)
        $supervisorUserId = null;
        if (! empty($emp['supervisor_id'])) {
            $sup = $db->table('employees')->select('user_id')
                ->where('id', (int) $emp['supervisor_id'])->get()->getRowArray();
            $supervisorUserId = $sup ? (int) $sup['user_id'] : null;
        }
        if (! $supervisorUserId && ! empty($emp['manager_id'])) {
            $mgr = $db->table('employees')->select('user_id')
                ->where('id', (int) $emp['manager_id'])->get()->getRowArray();
            $supervisorUserId = $mgr ? (int) $mgr['user_id'] : null;
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'         => (int) $emp['company_id'],
            'employee_id'        => (int) $emp['id'],
            'submitted_at'       => $now,
            'last_working_date'  => $lwd,
            'effective_date'     => $valid['effective_date'] ?? $lwd,
            'reason_category'    => $valid['reason_category'],
            'reason_text'        => $valid['reason_text'] ?? null,
            'attachment_path'    => $valid['attachment_path'] ?? null,
            'notice_period_days' => $diff,
            'status'             => $supervisorUserId ? 'PENDING_SUPERVISOR' : 'PENDING_HR',
            'supervisor_user_id' => $supervisorUserId, // disimpan agar audit trail jelas
            'created_at'         => $now,
            'updated_at'         => $now,
        ];
        $db->table('resignation_requests')->insert($insert);
        $id = (int) $db->insertID();

        // Notify approver
        $this->notifyApprover((int) $emp['company_id'], $insert['status'], $id, $emp, $supervisorUserId);

        // Audit
        Services::audit()->forModule('RESIGNATION')->logCreate(
            'resignation_requests',
            $id,
            $insert,
            "Pengajuan resignation oleh {$emp['full_name']} (LWD: {$lwd}, kategori: {$valid['reason_category']})"
        );

        return $this->ok(['id' => $id, 'status' => $insert['status']],
            'Pengajuan resignation berhasil dikirim. Status: ' . $insert['status'], 201);
    }

    /**
     * POST /api/v1/resignations/{id}/approve
     *
     * Aksi tergantung status saat ini:
     *   - PENDING_SUPERVISOR → caller harus supervisor → → PENDING_HR
     *   - PENDING_HR         → caller harus HR (perm `resignation.manage`)
     *                          → APPROVED + trigger offboarding generation
     *
     * Body:
     *   {
     *     "note": "...",
     *     "offboarding_template_id": 5,   // optional, HR only — kalau null pakai default
     *     "create_severance": false       // optional, HR only — kalau true buat severance draft
     *   }
     */
    public function approve($id)
    {
        $row = $this->loadRequestForApproval((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau akses ditolak', 404);

        $data = $this->getJsonInput();
        $note = trim((string) ($data['note'] ?? '')) ?: null;

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $userId = $this->userId();

        if ($row['status'] === 'PENDING_SUPERVISOR') {
            // Cek caller adalah supervisor sah
            if (! $this->isSupervisorOf($userId, (int) $row['employee_id'])) {
                return $this->fail('Hanya atasan langsung yang dapat menyetujui di tahap ini.', 403);
            }
            $update = [
                'status'              => 'PENDING_HR',
                'supervisor_user_id'  => $userId,
                'supervisor_action_at'=> $now,
                'supervisor_decision' => 'APPROVED',
                'supervisor_note'     => $note,
                'updated_at'          => $now,
            ];
            $db->table('resignation_requests')->where('id', $row['id'])->update($update);

            // Notify HR
            $this->notifyApprover(
                (int) $row['company_id'], 'PENDING_HR', (int) $row['id'],
                $this->loadEmployee((int) $row['employee_id']), null
            );

            Services::audit()->forModule('RESIGNATION')->log(
                action: 'resignation.approve_supervisor',
                entity: 'resignation_requests',
                entityId: (int) $row['id'],
                description: "Atasan menyetujui resignation #{$row['id']}",
                severity: 'NOTICE',
            );

            return $this->ok(['status' => 'PENDING_HR'],
                'Persetujuan atasan tercatat. Pengajuan diteruskan ke HR.');
        }

        if ($row['status'] === 'PENDING_HR') {
            if (! $this->canActAsHr()) {
                return $this->fail('Hanya HR yang dapat menyetujui di tahap ini.', 403);
            }

            $emp = $this->loadEmployee((int) $row['employee_id']);
            if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);

            $offboardingTemplateId = (int) ($data['offboarding_template_id'] ?? 0) ?: null;

            // Trigger offboarding generation. Kalau gagal, jangan biarkan
            // approval gagal total karena bisa retry manual lewat
            // /employee-tasks/generate.
            $tasksCreated = 0;
            $generationNote = null;
            try {
                $svc = new OnboardingService();
                $result = $svc->applyTemplate(
                    (int) $row['employee_id'],
                    $offboardingTemplateId,
                    'OFFBOARD',
                    $row['last_working_date'],
                );
                $tasksCreated = (int) ($result['created'] ?? 0);
                $offboardingTemplateId = $result['template']['id'] ?? $offboardingTemplateId;
                $generationNote = $result['note'] ?? null;
            } catch (\Throwable $e) {
                $generationNote = 'Gagal generate offboarding tasks otomatis: ' . $e->getMessage()
                    . ' — silakan trigger manual via menu Onboarding/Offboarding.';
                log_message('error', '[Resignation] Auto-generate offboarding failed: ' . $e->getMessage());
            }

            // Update request + employee
            $update = [
                'status'                  => 'APPROVED',
                'hr_user_id'              => $userId,
                'hr_action_at'            => $now,
                'hr_decision'             => 'APPROVED',
                'hr_note'                 => $note,
                'offboarding_template_id' => $offboardingTemplateId,
                'tasks_generated_count'   => $tasksCreated,
                'updated_at'              => $now,
            ];
            $db->table('resignation_requests')->where('id', $row['id'])->update($update);

            // Set resign_date di employees + reason — ini yang membuat
            // payroll dan modul lain tahu karyawan akan resign.
            $reasonLabel = self::REASON_CATEGORIES[$row['reason_category']] ?? $row['reason_category'];
            $effectiveDateForReplacement = $row['effective_date'] ?? $row['last_working_date'];
            $db->table('employees')->where('id', (int) $row['employee_id'])->update([
                'resign_date'   => $effectiveDateForReplacement,
                'resign_reason' => 'RESIGN_VOLUNTARY: ' . $reasonLabel
                    . (! empty($row['reason_text']) ? ' — ' . mb_substr($row['reason_text'], 0, 200) : ''),
                'updated_at'    => $now,
            ]);

            // Auto-create MPP DRAFT untuk pengganti, target_start_date =
            // tanggal efektif berhenti. HRD tinggal review & submit, atau
            // bisa di-cancel kalau memang posisi mau dirombak/ditiadakan.
            // Best-effort: kegagalan tidak boleh membatalkan resignation.
            $autoMppId = null;
            try {
                $autoMppId = Services::manpower()->autoCreateForResignation(
                    (int) $row['company_id'],
                    (int) $row['employee_id'],
                    $userId,
                    (string) $effectiveDateForReplacement,
                    $emp,
                );
            } catch (\Throwable $e) {
                log_message('warning', '[Resignation] Auto-create MPP failed: ' . $e->getMessage());
            }

            // Notify karyawan: resignation diterima.
            if (! empty($emp['user_id'])) {
                Services::notification()->send(
                    (int) $emp['user_id'],
                    (int) $row['company_id'],
                    'resignation.approved',
                    'Pengajuan resignation Anda disetujui',
                    'HR telah menerima pengajuan resignation Anda. Tanggal terakhir kerja: '
                        . $row['last_working_date'] . '. Silakan cek board offboarding.',
                    ['resignation_id' => (int) $row['id'], 'last_working_date' => $row['last_working_date']],
                    null,
                    true,
                );
            }

            Services::audit()->forModule('RESIGNATION')->log(
                action: 'resignation.approve_hr',
                entity: 'resignation_requests',
                entityId: (int) $row['id'],
                description: "HR menyetujui resignation #{$row['id']} — set resign_date "
                    . $effectiveDateForReplacement . ", generate {$tasksCreated} offboarding tasks"
                    . ($autoMppId ? ", auto-create MPP draft #{$autoMppId} untuk pengganti" : ''),
                severity: 'WARNING',
                metadata: [
                    'tasks_created'     => $tasksCreated,
                    'template_id'       => $offboardingTemplateId,
                    'mpp_auto_created'  => $autoMppId,
                ],
            );

            // Sprint 15+ — webhook outbound `employee.resigned`
            // Source-of-truth approval voluntary resignation. Beda dengan
            // EmployeeController::update yang detect resign_date diset
            // manually oleh HR — di sini resign_date berasal dari workflow
            // resignation request, dengan reference id-nya.
            $this->dispatchWebhook(
                \App\Constants\WebhookEvent::EMPLOYEE_RESIGNED,
                (int) $row['company_id'],
                [
                    'resignation_id'     => (int) $row['id'],
                    'employee_id'        => (int) $row['employee_id'],
                    'employee_number'    => $emp['employee_number'] ?? null,
                    'name'               => $emp['full_name'] ?? null,
                    'resign_date'        => $effectiveDateForReplacement,
                    'last_working_date'  => $row['last_working_date'] ?? null,
                    'reason_category'    => $row['reason_category'] ?? null,
                    'mpp_auto_created'   => $autoMppId,
                    'tasks_generated'    => $tasksCreated,
                    'approved_by'        => $userId,
                    'approved_at'        => $now,
                ],
            );

            $msg = 'Resignation disetujui.';
            if ($tasksCreated > 0) $msg .= " {$tasksCreated} task offboarding ter-generate.";
            if ($autoMppId)        $msg .= " MPP pengganti dibuat sebagai draft (#{$autoMppId}).";

            return $this->ok([
                'status'                => 'APPROVED',
                'tasks_generated_count' => $tasksCreated,
                'mpp_auto_created'      => $autoMppId,
                'note'                  => $generationNote,
            ], $msg);
        }

        return $this->fail('Status saat ini tidak bisa di-approve: ' . $row['status'], 409, 'INVALID_STATE');
    }

    /**
     * POST /api/v1/resignations/{id}/reject
     */
    public function reject($id)
    {
        $row = $this->loadRequestForApproval((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau akses ditolak', 404);

        $data  = $this->getJsonInput();
        $valid = $this->validateOrFail($data, ['note' => 'required|min_length[10]|max_length[2000]']);
        if (! $valid) return null;

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $userId = $this->userId();
        $stage  = $row['status'];

        if ($stage === 'PENDING_SUPERVISOR') {
            if (! $this->isSupervisorOf($userId, (int) $row['employee_id'])) {
                return $this->fail('Hanya atasan langsung yang dapat menolak di tahap ini.', 403);
            }
            $update = [
                'status'              => 'REJECTED',
                'supervisor_user_id'  => $userId,
                'supervisor_action_at'=> $now,
                'supervisor_decision' => 'REJECTED',
                'supervisor_note'     => $valid['note'],
                'updated_at'          => $now,
            ];
        } elseif ($stage === 'PENDING_HR') {
            if (! $this->canActAsHr()) {
                return $this->fail('Hanya HR yang dapat menolak di tahap ini.', 403);
            }
            $update = [
                'status'      => 'REJECTED',
                'hr_user_id'  => $userId,
                'hr_action_at'=> $now,
                'hr_decision' => 'REJECTED',
                'hr_note'     => $valid['note'],
                'updated_at'  => $now,
            ];
        } else {
            return $this->fail('Status saat ini tidak bisa di-reject: ' . $stage, 409, 'INVALID_STATE');
        }

        $db->table('resignation_requests')->where('id', $row['id'])->update($update);

        // Notify karyawan
        $emp = $this->loadEmployee((int) $row['employee_id']);
        if ($emp && ! empty($emp['user_id'])) {
            Services::notification()->send(
                (int) $emp['user_id'],
                (int) $row['company_id'],
                'resignation.rejected',
                'Pengajuan resignation ditolak',
                'Pengajuan resignation Anda ditolak di tahap ' . $stage . '. Alasan: '
                    . mb_substr($valid['note'], 0, 200),
                ['resignation_id' => (int) $row['id'], 'stage' => $stage],
                null,
                true,
            );
        }

        Services::audit()->forModule('RESIGNATION')->log(
            action: 'resignation.reject',
            entity: 'resignation_requests',
            entityId: (int) $row['id'],
            description: "Resignation #{$row['id']} ditolak di tahap {$stage}",
            severity: 'NOTICE',
        );

        return $this->ok(['status' => 'REJECTED'], 'Pengajuan resignation ditolak.');
    }

    /**
     * POST /api/v1/resignations/{id}/cancel
     *
     * Karyawan boleh cancel selama belum APPROVED.
     * HR boleh cancel kapan saja (override).
     */
    public function cancel($id)
    {
        $row = $this->loadRequestForUser((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau akses ditolak', 404);

        $data  = $this->getJsonInput();
        $valid = $this->validateOrFail($data, ['note' => 'permit_empty|max_length[2000]']);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $userId = $this->userId();
        $isHr   = $this->canActAsHr();

        $allowedByOwner = in_array($row['status'], ['DRAFT', 'PENDING_SUPERVISOR', 'PENDING_HR'], true);
        $allowedByHr    = in_array($row['status'], ['PENDING_SUPERVISOR', 'PENDING_HR', 'APPROVED'], true);

        // Cek caller pemilik request? load owner user_id
        $emp = $this->loadEmployee((int) $row['employee_id']);
        $isOwner = $emp && (int) ($emp['user_id'] ?? 0) === $userId;

        if (! (($isOwner && $allowedByOwner) || ($isHr && $allowedByHr))) {
            return $this->fail('Tidak diizinkan membatalkan pada status saat ini.', 403);
        }

        $now = date('Y-m-d H:i:s');
        $update = [
            'status'              => 'CANCELLED',
            'cancelled_at'        => $now,
            'cancelled_by'        => $userId,
            'cancellation_reason' => $valid['note'] ?? null,
            'updated_at'          => $now,
        ];

        // Edge case: kalau sudah APPROVED dan HR cancel, harus revert juga
        // employees.resign_date supaya status karyawan kembali aktif.
        if ($row['status'] === 'APPROVED' && $isHr) {
            $db->table('employees')->where('id', (int) $row['employee_id'])->update([
                'resign_date'   => null,
                'resign_reason' => null,
                'updated_at'    => $now,
            ]);
            // Catatan: offboarding tasks yang sudah ter-generate TIDAK
            // di-auto-delete supaya audit trail tetap utuh. HR harus
            // bersihkan manual dari board onboarding kalau memang perlu.
        }

        $db->table('resignation_requests')->where('id', $row['id'])->update($update);

        Services::audit()->forModule('RESIGNATION')->log(
            action: 'resignation.cancel',
            entity: 'resignation_requests',
            entityId: (int) $row['id'],
            description: "Resignation #{$row['id']} dibatalkan oleh "
                . ($isHr && ! $isOwner ? 'HR' : 'karyawan'),
            severity: $row['status'] === 'APPROVED' ? 'WARNING' : 'NOTICE',
        );

        return $this->ok(['status' => 'CANCELLED'], 'Pengajuan resignation dibatalkan.');
    }

    /** GET /api/v1/resignations/reasons — daftar kategori untuk dropdown UI. */
    public function reasons()
    {
        $out = [];
        foreach (self::REASON_CATEGORIES as $key => $label) {
            $out[] = ['code' => $key, 'label' => $label];
        }
        return $this->ok($out);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * Load resignation request + cek akses sebagai pemilik / supervisor / HR.
     */
    private function loadRequestForUser(int $id): ?array
    {
        $db  = \Config\Database::connect();
        $row = $db->table('resignation_requests rr')
            ->select('rr.*, e.full_name AS employee_name, e.employee_number, e.user_id AS employee_user_id, '
                . 'e.supervisor_id, e.manager_id')
            ->join('employees e', 'e.id = rr.employee_id', 'left')
            ->where('rr.id', $id)
            ->where('rr.deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) return null;

        $userId = $this->userId();

        // Owner
        if ((int) ($row['employee_user_id'] ?? 0) === $userId) return $row;
        // HR
        if ($this->canActAsHr() && (int) $row['company_id'] === $this->companyId()) return $row;
        // Supervisor
        if ($this->isSupervisorOf($userId, (int) $row['employee_id'])) return $row;

        // Super admin
        if ($this->tenant()->isSuperAdmin()) return $row;

        return null;
    }

    /**
     * Load + restrict ke yang punya hak approve di stage saat ini.
     */
    private function loadRequestForApproval(int $id): ?array
    {
        $row = $this->loadRequestForUser($id);
        if (! $row) return null;
        // Stage-specific check dilakukan di approve()/reject() biar pesan
        // error-nya lebih jelas; di sini hanya pastikan ada akses dasar.
        return $row;
    }

    private function loadEmployee(int $id): ?array
    {
        return \Config\Database::connect()
            ->table('employees')->where('id', $id)->get()->getRowArray() ?: null;
    }

    /**
     * True kalau caller adalah supervisor / manager dari karyawan ybs
     * (pakai mapping employee → user di table employees).
     */
    private function isSupervisorOf(int $callerUserId, int $employeeId): bool
    {
        $db  = \Config\Database::connect();
        $emp = $this->loadEmployee($employeeId);
        if (! $emp) return false;

        $myEmp = $db->table('employees')->select('id')
            ->where('user_id', $callerUserId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $myEmp) return false;
        $myEmpId = (int) $myEmp['id'];

        return $myEmpId === (int) ($emp['supervisor_id'] ?? 0)
            || $myEmpId === (int) ($emp['manager_id'] ?? 0);
    }

    private function canActAsHr(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('resignation.manage')
            || $t->hasPermission('resignation.view');
    }

    /**
     * Notifikasi ke approver berikutnya. Untuk PENDING_SUPERVISOR notify
     * supervisor; untuk PENDING_HR notify semua user dengan permission
     * resignation.manage di company.
     */
    private function notifyApprover(int $companyId, string $stage, int $requestId, ?array $emp, ?int $supervisorUserId): void
    {
        if (! $emp) return;
        $name = $emp['full_name'] ?? 'Karyawan';

        if ($stage === 'PENDING_SUPERVISOR' && $supervisorUserId) {
            Services::notification()->send(
                $supervisorUserId,
                $companyId,
                'resignation.pending_supervisor',
                'Persetujuan resignation diperlukan',
                "{$name} mengajukan resignation. Mohon review.",
                ['resignation_id' => $requestId, 'employee_name' => $name],
                null,
                true,
            );
            return;
        }

        if ($stage === 'PENDING_HR') {
            $db = \Config\Database::connect();
            $hrUsers = $db->table('users u')
                ->select('u.id')
                ->join('role_permissions rp', 'rp.role_id = u.role_id', 'left')
                ->join('permissions p',       'p.id = rp.permission_id', 'left')
                ->where('u.company_id', $companyId)
                ->where('u.is_active', 1)
                ->where('p.slug', 'resignation.manage')
                ->groupBy('u.id')
                ->get()->getResultArray();
            foreach ($hrUsers as $u) {
                Services::notification()->send(
                    (int) $u['id'],
                    $companyId,
                    'resignation.pending_hr',
                    'Resignation menunggu review HR',
                    "{$name} sudah disetujui atasan. Mohon final review.",
                    ['resignation_id' => $requestId, 'employee_name' => $name],
                    null,
                    true,
                );
            }
        }
    }
}