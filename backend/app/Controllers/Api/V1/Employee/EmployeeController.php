<?php

namespace App\Controllers\Api\V1\Employee;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

class EmployeeController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $search = trim((string) $this->request->getGet('search'));

        // Filter dasar
        $deptId       = (int) ($this->request->getGet('department_id') ?? 0);
        $branchId     = (int) ($this->request->getGet('branch_id') ?? 0);
        $positionId   = (int) ($this->request->getGet('position_id') ?? 0);
        $jobGradeId   = (int) ($this->request->getGet('job_grade_id') ?? 0);
        $minGradeLvl  = (int) ($this->request->getGet('min_grade_level') ?? 0);
        $positionTier = trim((string) $this->request->getGet('position_tier'));
        $status       = trim((string) $this->request->getGet('status'));
        $employmentStatus = trim((string) $this->request->getGet('employment_status'));
        $employmentType   = trim((string) $this->request->getGet('employment_type'));
        $gender       = trim((string) $this->request->getGet('gender'));

        $builder = $db->table('employees e')
            ->select('e.id, e.employee_number, e.full_name, e.email, e.phone, e.gender,
                e.employment_status, e.employment_type, e.is_active,
                e.join_date, e.resign_date, e.basic_salary, e.avatar_path,
                e.department_id, e.position_id, e.branch_id, e.job_grade_id, e.job_title_id,
                d.name AS department_name, p.name AS position_name, p.tier AS position_tier,
                b.name AS branch_name, jg.name AS job_grade_name, jg.code AS job_grade_code,
                jg.level AS job_grade_level,
                jt.name AS job_title_name, jt.family AS job_title_family, jt.level AS job_title_level')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->join('branches b', 'b.id = e.branch_id', 'left')
            ->join('job_grades jg', 'jg.id = e.job_grade_id', 'left')
            ->join('job_titles jt', 'jt.id = e.job_title_id', 'left')
            ->where('e.deleted_at IS NULL');

        $this->tenant()->applyScope($builder, 'e.company_id');

        if ($search !== '') {
            $builder->groupStart()
                ->like('e.full_name', $search)
                ->orLike('e.employee_number', $search)
                ->orLike('e.email', $search)
                ->groupEnd();
        }
        if ($deptId)     $builder->where('e.department_id', $deptId);
        if ($branchId)   $builder->where('e.branch_id', $branchId);
        if ($positionId) $builder->where('e.position_id', $positionId);
        $jobTitleId = (int) ($this->request->getGet('job_title_id') ?? 0);
        if ($jobGradeId) $builder->where('e.job_grade_id', $jobGradeId);
        if ($jobTitleId) $builder->where('e.job_title_id', $jobTitleId);
        // Filter by minimum job grade level — dipakai mis. picker manager
        // (yang harus level Manager keatas, bukan Staff). Default seeder
        // memakai level: 7 = Manager, 8 = VP, 10 = Direktur, 11 = Komisaris.
        if ($minGradeLvl > 0) {
            $builder->where('jg.level >=', $minGradeLvl);
        }
        // Filter by position tier (MANAGER / ASST_MANAGER / SUPERVISOR / STAFF).
        // Untuk picker manager cost center: tier=MANAGER, ASST_MANAGER.
        if ($positionTier !== '' &&
            in_array($positionTier, ['MANAGER','ASST_MANAGER','SUPERVISOR','STAFF'], true)) {
            $builder->where('p.tier', $positionTier);
        }
        if ($status === 'active')   $builder->where('e.is_active', 1);
        if ($status === 'inactive') $builder->where('e.is_active', 0);
        if ($employmentStatus !== '' &&
            in_array($employmentStatus, ['PKWT','PKWTT','PROBATION','HARIAN','MAGANG','OUTSOURCING'], true)) {
            $builder->where('e.employment_status', $employmentStatus);
        }
        if ($employmentType !== '' &&
            in_array($employmentType, ['FULL_TIME','PART_TIME','CONTRACT','INTERN'], true)) {
            $builder->where('e.employment_type', $employmentType);
        }
        if ($gender !== '' && in_array($gender, ['M','F'], true)) {
            $builder->where('e.gender', $gender);
        }

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('e.full_name', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', (int) $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $emp['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        // Decrypt sensitive fields
        $enc = \Config\Services::encryption();
        $emp['nik'] = $enc->decrypt($emp['nik_encrypted']);
        $emp['npwp'] = $enc->decrypt($emp['npwp_encrypted']);
        $emp['bank_account'] = $enc->decrypt($emp['bank_account_encrypted']);
        unset($emp['nik_encrypted'], $emp['npwp_encrypted'], $emp['bank_account_encrypted']);

        return $this->ok($emp);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'employee_number' => 'required|alpha_dash|max_length[32]',
            'first_name'      => 'required|max_length[100]',
            'join_date'       => 'required|valid_date',
            'gender'          => 'permit_empty|in_list[M,F]',
            'email'           => 'permit_empty|valid_email|max_length[191]',
            'employment_status' => 'permit_empty|in_list[PKWT,PKWTT,PROBATION,HARIAN,MAGANG,OUTSOURCING]',
            'basic_salary'    => 'permit_empty|numeric|greater_than_equal_to[0]',
            // ─── Validasi khas Indonesia ────────────────────────────
            'nik'               => 'permit_empty|valid_nik',
            'npwp'              => 'permit_empty|valid_npwp',
            'phone'             => 'permit_empty|valid_phone_id',
            'bpjs_kesehatan_no' => 'permit_empty|valid_bpjs_kesehatan',
            'bpjs_tk_no'        => 'permit_empty|valid_bpjs_ketenagakerjaan',
            'postal_code'       => 'permit_empty|valid_postal_id',
            'ptkp_status'       => 'permit_empty|valid_ptkp',
            'emergency_contact_phone' => 'permit_empty|valid_phone_id',
            // ─── Tambahan field Indonesia ──────────────────────────
            'kk_number'         => 'permit_empty|max_length[32]',
            'mother_name'       => 'permit_empty|max_length[200]',
            'father_name'       => 'permit_empty|max_length[200]',
            'citizenship'       => 'permit_empty|max_length[50]',
            'ethnicity'         => 'permit_empty|max_length[50]',
            'education_level'   => 'permit_empty|in_list[SD,SMP,SMA,SMK,D1,D2,D3,D4,S1,S2,S3]',
            'marital_certificate_no' => 'permit_empty|max_length[50]',
            'ktp_postal_code'   => 'permit_empty|valid_postal_id',
            'work_location_id'  => 'permit_empty|integer',
            'supervisor_id'     => 'permit_empty|integer',
        ]);
        if (! $valid) return null;

        // Normalize nomor HP ke format 08…
        if (! empty($data['phone'])) {
            $data['phone'] = \App\Libraries\System\IndonesianValidator::normalizePhoneId($data['phone']) ?: $data['phone'];
        }
        if (! empty($data['emergency_contact_phone'])) {
            $data['emergency_contact_phone'] = \App\Libraries\System\IndonesianValidator::normalizePhoneId($data['emergency_contact_phone']) ?: $data['emergency_contact_phone'];
        }

        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        // Uniqueness scoped to company
        $exists = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('employee_number', $data['employee_number'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($exists > 0) {
            return $this->fail('Employee number already exists', 422, 'DUPLICATE_EMPLOYEE_NUMBER');
        }

        $enc = \Config\Services::encryption();
        $now = date('Y-m-d H:i:s');

        // Resolve job_title_id otomatis dari position kalau tidak di-set
        // di payload. Pola umum: HR memilih position, sistem inherit
        // job_title-nya. HR boleh override eksplisit lewat payload.
        $jobTitleId = isset($data['job_title_id']) && $data['job_title_id'] !== ''
            ? (int) $data['job_title_id']
            : null;
        if ($jobTitleId === null && ! empty($data['position_id'])) {
            $posRow = $db->table('positions')
                ->select('job_title_id')
                ->where('id', (int) $data['position_id'])
                ->get()->getRowArray();
            if ($posRow && ! empty($posRow['job_title_id'])) {
                $jobTitleId = (int) $posRow['job_title_id'];
            }
        }

        $insert = [
            'company_id'        => $companyId,
            'branch_id'         => $data['branch_id'] ?? null,
            'department_id'     => $data['department_id'] ?? null,
            'position_id'       => $data['position_id'] ?? null,
            'job_grade_id'      => $data['job_grade_id'] ?? null,
            'job_title_id'      => $jobTitleId,
            'manager_id'        => $data['manager_id'] ?? null,
            'supervisor_id'     => $data['supervisor_id'] ?? null,
            'work_location_id'  => $data['work_location_id'] ?? null,
            'employee_number'   => $data['employee_number'],
            'first_name'        => $data['first_name'],
            'last_name'         => $data['last_name'] ?? null,
            'full_name'         => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
            'gender'            => $data['gender'] ?? null,
            'religion'          => $data['religion'] ?? null,
            'marital_status'    => $data['marital_status'] ?? null,
            'place_of_birth'    => $data['place_of_birth'] ?? null,
            'date_of_birth'     => $data['date_of_birth'] ?? null,
            'blood_type'        => $data['blood_type'] ?? null,
            'nik_encrypted'     => $enc->encrypt($data['nik'] ?? null),
            'nik_hash'          => isset($data['nik']) ? $enc->hash($data['nik']) : null,
            'npwp_encrypted'    => $enc->encrypt($data['npwp'] ?? null),
            'bpjs_kesehatan_no' => $data['bpjs_kesehatan_no'] ?? null,
            'bpjs_tk_no'        => $data['bpjs_tk_no'] ?? null,
            'bank_name'         => $data['bank_name'] ?? null,
            'bank_account_encrypted' => $enc->encrypt($data['bank_account'] ?? null),
            'bank_account_holder' => $data['bank_account_holder'] ?? null,
            'email'             => $data['email'] ?? null,
            'phone'             => $data['phone'] ?? null,
            'address'           => $data['address'] ?? null,
            'province'          => $data['province'] ?? null,
            'city'              => $data['city'] ?? null,
            'postal_code'       => $data['postal_code'] ?? null,
            'home_lat'          => isset($data['home_lat']) && $data['home_lat'] !== '' ? (float) $data['home_lat'] : null,
            'home_lng'          => isset($data['home_lng']) && $data['home_lng'] !== '' ? (float) $data['home_lng'] : null,
            'emergency_contact_name'  => $data['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
            'emergency_contact_rel'   => $data['emergency_contact_rel'] ?? null,
            'employment_status' => $data['employment_status'] ?? 'PKWTT',
            'employment_type'   => $data['employment_type'] ?? 'FULL_TIME',
            'join_date'         => $data['join_date'],
            'probation_end_date'=> $data['probation_end_date'] ?? null,
            'contract_end_date' => $data['contract_end_date'] ?? null,
            'basic_salary'      => $data['basic_salary'] ?? 0,
            'tax_method'        => $data['tax_method'] ?? 'GROSS',
            'ptkp_status'       => $data['ptkp_status'] ?? 'TK0',
            'pph21_method'      => $data['pph21_method'] ?? 'TER',
            // Field khas Indonesia
            'kk_number'         => $data['kk_number'] ?? null,
            'mother_name'       => $data['mother_name'] ?? null,
            'father_name'       => $data['father_name'] ?? null,
            'citizenship'       => $data['citizenship'] ?? 'WNI',
            'ethnicity'         => $data['ethnicity'] ?? null,
            'education_level'   => $data['education_level'] ?? null,
            'marital_certificate_no' => $data['marital_certificate_no'] ?? null,
            'ktp_address'       => $data['ktp_address'] ?? null,
            'ktp_rt_rw'         => $data['ktp_rt_rw'] ?? null,
            'ktp_kelurahan'     => $data['ktp_kelurahan'] ?? null,
            'ktp_kecamatan'     => $data['ktp_kecamatan'] ?? null,
            'ktp_city'          => $data['ktp_city'] ?? null,
            'ktp_province'      => $data['ktp_province'] ?? null,
            'ktp_postal_code'   => $data['ktp_postal_code'] ?? null,
            // Override approval chain (opsional)
            'approval_chain_leave_id'      => $data['approval_chain_leave_id'] ?? null,
            'approval_chain_overtime_id'   => $data['approval_chain_overtime_id'] ?? null,
            'approval_chain_attendance_id' => $data['approval_chain_attendance_id'] ?? null,
            'is_active'         => 1,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        // ─── Warning UMP/UMK ────────────────────────────────────────
        // Karyawan PKWT/PKWTT/HARIAN full-time tunduk pada UMK/UMP.
        // Kita catat warning ke audit log (tidak memblokir create) supaya
        // admin/HR sadar saat input gaji di bawah upah minimum daerah.
        $umpInfo = null;
        if (! empty($insert['province']) && (float) $insert['basic_salary'] > 0
            && in_array($insert['employment_status'], ['PKWT','PKWTT','HARIAN'], true)
            && in_array($insert['employment_type'], ['FULL_TIME','CONTRACT'], true)
        ) {
            $umpInfo = \App\Libraries\System\IndonesianValidator::findMinimumWage(
                $insert['province'],
                $insert['city'],
                (int) date('Y', strtotime($insert['join_date']))
            );
            if ($umpInfo && (float) $insert['basic_salary'] < (float) $umpInfo['amount']) {
                Services::audit()
                    ->forModule('EMPLOYEE')
                    ->log(
                        action: 'employee.below_minimum_wage',
                        entity: 'employee',
                        entityId: null,
                        description: sprintf(
                            'Peringatan: gaji pokok %s di bawah %s %s (%s) untuk %s, %s tahun %d',
                            'Rp ' . number_format((float) $insert['basic_salary'], 0, ',', '.'),
                            $umpInfo['level'],
                            $umpInfo['city'] ?? $insert['province'],
                            'Rp ' . number_format($umpInfo['amount'], 0, ',', '.'),
                            $insert['city'] ?? '-',
                            $insert['province'],
                            $umpInfo['year']
                        ),
                        severity: 'WARNING',
                        metadata: ['ump' => $umpInfo, 'basic_salary' => (float) $insert['basic_salary']]
                    );
            }
        }

        $db->table('employees')->insert($insert);
        $id = (int) $db->insertID();

        // Optionally create a user account
        if (! empty($data['create_user_account']) && ! empty($data['email'])) {
            $tempPassword = bin2hex(random_bytes(8));
            $userId = $this->createUserForEmployee($db, $id, $companyId, $data['email'], trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')), $tempPassword);
            $db->table('employees')->where('id', $id)->update(['user_id' => $userId]);

            // Notify user with temp password
            Services::notification()->queueEmail(
                $companyId,
                $data['email'],
                $insert['full_name'],
                'Akun HRIS Anda',
                "<p>Halo {$insert['full_name']},</p><p>Akun HRIS Anda sudah dibuat.</p><p>Email: {$data['email']}<br>Password sementara: <strong>{$tempPassword}</strong></p><p>Mohon login dan ubah password Anda segera.</p>"
            );
        }

        Services::audit()
            ->forModule('EMPLOYEE')
            ->logCreate(
                'employee',
                $id,
                array_diff_key($insert, array_flip(['nik_encrypted','nik_hash','npwp_encrypted','bank_account_encrypted'])),
                "Menambah karyawan: {$insert['full_name']} ({$insert['employee_number']})"
            );

        // Auto-trigger ONBOARD checklist menggunakan template default perusahaan
        // (TPL_ONBOARD_DEFAULT seeder). HR bisa menonaktifkan dengan
        // setting `onboarding_auto_apply` = "0" di tabel settings, atau
        // mengirim payload `skip_onboarding=1` saat create.
        if (empty($data['skip_onboarding'])) {
            try {
                $autoApply = $db->table('settings')
                    ->where('company_id', $companyId)
                    ->where('key', 'onboarding_auto_apply')
                    ->get()->getRowArray();
                $enabled = $autoApply ? ((string) $autoApply['value']) !== '0' : true;
                if ($enabled) {
                    $tpl = $db->table('task_templates')
                        ->where('company_id', $companyId)
                        ->where('type', 'ONBOARD')
                        ->where('is_active', 1)
                        ->where('deleted_at IS NULL')
                        ->orderBy('is_default', 'DESC')
                        ->orderBy('id', 'ASC')
                        ->limit(1)->get()->getRowArray();
                    if ($tpl) {
                        $anchor = $insert['join_date'] ?: date('Y-m-d');
                        Services::onboarding()->applyTemplate(
                            (int) $id,
                            (int) $tpl['id'],
                            'ONBOARD',
                            $anchor
                        );
                        Services::audit()->forModule('ONBOARDING')->log(
                            action: 'onboarding.auto_apply',
                            entity: 'employee_tasks',
                            entityId: $id,
                            description: "Auto-apply ONBOARD template '{$tpl['name']}' utk karyawan {$insert['full_name']}",
                        );
                    }
                }
            } catch (\Throwable $e) {
                // Tidak boleh menggagalkan create employee — log sebagai warning saja.
                log_message('warning', "Auto-onboarding gagal untuk employee #{$id}: " . $e->getMessage());
            }
        }

        // Sprint 13+ — webhook dispatch via trait helper. Sengaja tidak
        // include PII sensitif (NIK/NPWP/rekening) di payload — consumer
        // hanya butuh id + employee_number + nama untuk sync.
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::EMPLOYEE_CREATED,
            $companyId,
            [
                'employee_id'       => (int) $id,
                'employee_number'   => $insert['employee_number'] ?? null,
                'full_name'         => $insert['full_name'] ?? null,
                'email'             => $insert['email'] ?? null,
                'employment_status' => $insert['employment_status'] ?? null,
                'employment_type'   => $insert['employment_type'] ?? null,
                'branch_id'         => isset($insert['branch_id']) ? (int) $insert['branch_id'] : null,
                'department_id'     => isset($insert['department_id']) ? (int) $insert['department_id'] : null,
                'position_id'       => isset($insert['position_id']) ? (int) $insert['position_id'] : null,
                'join_date'         => $insert['join_date'] ?? null,
                'created_at'        => date('c'),
            ],
        );

        return $this->ok(['id' => $id], 'Employee created', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('employees')->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $existing) return $this->fail('Employee not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        $data = $this->getJsonInput();
        $enc = \Config\Services::encryption();

        $updatable = ['branch_id','department_id','position_id','job_grade_id','job_title_id','manager_id',
            'supervisor_id','work_location_id',
            'first_name','last_name','gender','religion','marital_status','place_of_birth','date_of_birth',
            'blood_type','bpjs_kesehatan_no','bpjs_tk_no','bank_name','bank_account_holder',
            'email','phone','address','province','city','postal_code','home_lat','home_lng',
            'emergency_contact_name','emergency_contact_phone','emergency_contact_rel',
            'employment_status','employment_type','join_date','probation_end_date','contract_end_date',
            'resign_date','resign_reason','basic_salary','tax_method','ptkp_status','pph21_method',
            'is_active','avatar_path',
            // Field khas Indonesia
            'kk_number','mother_name','father_name','citizenship','ethnicity','education_level',
            'marital_certificate_no',
            'ktp_address','ktp_rt_rw','ktp_kelurahan','ktp_kecamatan','ktp_city','ktp_province','ktp_postal_code',
            // Override approval chain
            'approval_chain_leave_id','approval_chain_overtime_id','approval_chain_attendance_id'];
        $update = array_intersect_key($data, array_flip($updatable));

        // NIK (employee_number) hanya boleh di-rename oleh super admin.
        // NIK ini dipakai sebagai login identifier (lihat AuthController::login),
        // jadi perubahan harus diaudit & dijamin unik per perusahaan.
        if (array_key_exists('employee_number', $data)) {
            if (! $this->tenant()->isSuperAdmin()) {
                return $this->fail(
                    'Hanya Super Admin yang dapat mengubah NIK karyawan',
                    403,
                    'NIK_CHANGE_FORBIDDEN'
                );
            }
            $newNik = trim((string) $data['employee_number']);
            if ($newNik === '') {
                return $this->fail('NIK tidak boleh kosong', 422, 'INVALID_NIK');
            }
            if (! preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $newNik)) {
                return $this->fail(
                    'Format NIK tidak valid (huruf, angka, "-" atau "_", maks 32 karakter)',
                    422,
                    'INVALID_NIK_FORMAT'
                );
            }
            if ($newNik !== $existing['employee_number']) {
                $dup = $db->table('employees')
                    ->where('company_id', (int) $existing['company_id'])
                    ->where('employee_number', $newNik)
                    ->where('id !=', $id)
                    ->where('deleted_at IS NULL')
                    ->countAllResults();
                if ($dup > 0) {
                    return $this->fail(
                        "NIK \"{$newNik}\" sudah dipakai karyawan lain",
                        422,
                        'DUPLICATE_EMPLOYEE_NUMBER'
                    );
                }
                $update['employee_number'] = $newNik;
            }
        }
        // Normalize coords
        foreach (['home_lat','home_lng'] as $k) {
            if (array_key_exists($k, $update) && ($update[$k] === '' || $update[$k] === null)) {
                $update[$k] = null;
            } elseif (isset($update[$k])) {
                $update[$k] = (float) $update[$k];
            }
        }

        if (isset($data['nik'])) {
            $update['nik_encrypted'] = $enc->encrypt($data['nik']);
            $update['nik_hash']      = $enc->hash($data['nik']);
        }
        if (isset($data['npwp'])) {
            $update['npwp_encrypted'] = $enc->encrypt($data['npwp']);
        }
        if (isset($data['bank_account'])) {
            $update['bank_account_encrypted'] = $enc->encrypt($data['bank_account']);
        }
        if (isset($update['first_name']) || isset($update['last_name'])) {
            $first = $update['first_name'] ?? $existing['first_name'];
            $last  = $update['last_name'] ?? $existing['last_name'];
            $update['full_name'] = trim($first . ' ' . $last);
        }
        $update['updated_at'] = date('Y-m-d H:i:s');

        $db->table('employees')->where('id', $id)->update($update);
        $beforeFiltered = array_intersect_key($existing, $update);
        Services::audit()
            ->forModule('EMPLOYEE')
            ->logUpdate(
                'employee',
                $id,
                $beforeFiltered,
                $update,
                "Memperbarui data karyawan: {$existing['full_name']} ({$existing['employee_number']})"
            );

        // Sprint 15+ — webhook outbound. Detect 2 event:
        //   - employee.resigned: resign_date sebelumnya kosong, sekarang ada
        //   - employee.updated: ada perubahan field non-PII penting
        // Sengaja exclude PII detail (NIK/NPWP/rekening/alamat) dari payload —
        // consumer hanya butuh signal "ada perubahan" + identifier; data
        // detail bisa di-pull via API kalau perlu (least-privilege).
        $companyId = (int) $existing['company_id'];
        $resignedNow = ! empty($update['resign_date']) && empty($existing['resign_date']);
        if ($resignedNow) {
            $this->dispatchWebhook(
                \App\Constants\WebhookEvent::EMPLOYEE_RESIGNED,
                $companyId,
                [
                    'employee_id'     => $id,
                    'employee_number' => $existing['employee_number'] ?? null,
                    'name'            => $existing['full_name'] ?? null,
                    'resign_date'     => $update['resign_date'],
                    'resign_reason'   => $update['resign_reason'] ?? null,
                    'updated_at'      => date('c'),
                ],
            );
        } else {
            // Trigger employee.updated hanya kalau field signifikan berubah,
            // bukan untuk update minor (last_login_at di token refresh, dll).
            $significantKeys = [
                'employment_status', 'employment_type', 'branch_id',
                'department_id', 'position_id', 'job_grade_id', 'manager_id',
                'is_active', 'employee_number', 'email', 'phone',
                'contract_end_date', 'probation_end_date',
            ];
            $changedSignificant = array_intersect(array_keys($update), $significantKeys);
            if (! empty($changedSignificant)) {
                $this->dispatchWebhook(
                    \App\Constants\WebhookEvent::EMPLOYEE_UPDATED,
                    $companyId,
                    [
                        'employee_id'     => $id,
                        'employee_number' => $existing['employee_number'] ?? null,
                        'name'            => $existing['full_name'] ?? null,
                        'changed_fields'  => array_values($changedSignificant),
                        'updated_at'      => date('c'),
                    ],
                );
            }
        }

        // Kalau lokasi rumah / branch / grade berubah, recompute tunjangan
        // berbasis jarak agar snapshot karyawan ter-update.
        $needRecompute = array_intersect(
            array_keys($update),
            ['home_lat', 'home_lng', 'branch_id', 'job_grade_id']
        );
        if (! empty($needRecompute)) {
            try {
                Services::allowance()->recomputeEmployee($id);
            } catch (\Throwable $e) {
                log_message('warning', 'Recompute allowance failed: ' . $e->getMessage());
            }
        }
        return $this->ok(['id' => $id], 'Employee updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('employees')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $db->table('employees')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ]);
        // Disable linked user
        if ($existing['user_id']) {
            $db->table('users')->where('id', $existing['user_id'])->update(['is_active' => 0]);
        }
        Services::audit()
            ->forModule('EMPLOYEE')
            ->logDelete(
                'employee',
                $id,
                array_diff_key($existing, array_flip(['nik_encrypted','nik_hash','npwp_encrypted','bank_account_encrypted'])),
                "Menonaktifkan karyawan: {$existing['full_name']} ({$existing['employee_number']})"
            );
        return $this->ok(null, 'Employee deleted');
    }

    public function contracts($id = null)
    {
        $db = \Config\Database::connect();
        $rows = $db->table('employee_contracts')
            ->where('employee_id', (int) $id)
            ->orderBy('start_date', 'DESC')
            ->get()
            ->getResultArray();
        return $this->ok($rows);
    }

    public function addContract($id = null)
    {
        $id = (int) $id;
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'contract_number' => 'required|max_length[64]',
            'contract_type'   => 'required|in_list[PKWT,PKWTT,PROBATION,PKHL,MAGANG]',
            'start_date'      => 'required|valid_date',
            'basic_salary'    => 'required|numeric',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $id)->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);

        $insert = [
            'company_id' => $emp['company_id'],
            'employee_id' => $id,
            'contract_number' => $data['contract_number'],
            'contract_type'   => $data['contract_type'],
            'start_date'      => $data['start_date'],
            'end_date'        => $data['end_date'] ?? null,
            'basic_salary'    => $data['basic_salary'],
            'position_id'     => $data['position_id'] ?? $emp['position_id'],
            'department_id'   => $data['department_id'] ?? $emp['department_id'],
            'document_path'   => $data['document_path'] ?? null,
            'notes'           => $data['notes'] ?? null,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];
        $db->table('employee_contracts')->insert($insert);
        $contractId = (int) $db->insertID();
        Services::audit()
            ->forModule('EMPLOYEE')
            ->logCreate(
                'employee_contract',
                $contractId,
                $insert,
                "Menambah kontrak {$data['contract_type']} #{$data['contract_number']} untuk karyawan {$emp['full_name']}"
            );
        return $this->ok(['id' => $contractId], 'Contract added', 201);
    }

    public function documents($id = null)
    {
        $db = \Config\Database::connect();
        $rows = $db->table('employee_documents')
            ->where('employee_id', (int) $id)
            ->where('deleted_at IS NULL')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();
        return $this->ok($rows);
    }

    public function uploadDocument($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $id)->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);

        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->fail('Invalid upload', 400);
        }

        $maxSize = (int) env('upload.maxSize', 5242880);
        if ($file->getSize() > $maxSize) {
            return $this->fail('File too large', 413);
        }
        $allowed = explode(',', (string) env('upload.allowedMimes', ''));
        if (! in_array($file->getMimeType(), $allowed, true)) {
            return $this->fail('File type not allowed', 415);
        }

        $dir = WRITEPATH . 'uploads/employees/' . $id;
        if (! is_dir($dir)) mkdir($dir, 0750, true);
        $newName = $file->getRandomName();
        $file->move($dir, $newName);

        $insert = [
            'company_id'  => $emp['company_id'],
            'employee_id' => $id,
            'doc_type'    => $this->request->getPost('doc_type') ?: 'OTHER',
            'title'       => $this->request->getPost('title') ?: $file->getClientName(),
            'file_path'   => 'uploads/employees/' . $id . '/' . $newName,
            'file_mime'   => $file->getMimeType(),
            'file_size'   => $file->getSize(),
            'expires_at'  => $this->request->getPost('expires_at') ?: null,
            'uploaded_by' => $this->userId(),
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
        $db->table('employee_documents')->insert($insert);
        $docId = (int) $db->insertID();
        Services::audit()
            ->forModule('EMPLOYEE')
            ->logCreate(
                'employee_document',
                $docId,
                array_diff_key($insert, array_flip(['file_path'])),
                "Mengunggah dokumen '{$insert['title']}' ({$insert['doc_type']}) untuk karyawan {$emp['full_name']}"
            );
        return $this->ok(['id' => $docId], 'Document uploaded', 201);
    }

    private function createUserForEmployee($db, int $employeeId, int $companyId, string $email, string $name, string $password): int
    {
        $db->table('users')->insert([
            'company_id'           => $companyId,
            'role_id'              => 4, // employee role
            'employee_id'          => $employeeId,
            'email'                => strtolower(trim($email)),
            'password_hash'        => password_hash($password, PASSWORD_ARGON2ID),
            'name'                 => $name,
            'is_active'            => 1,
            'must_change_password' => 1,
            'password_changed_at'  => date('Y-m-d H:i:s'),
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);
        return (int) $db->insertID();
    }
}