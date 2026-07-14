<?php

namespace App\Controllers\Api\V1\Leave;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

class LeaveController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    /**
     * Aturan eligibilitas per kategori leave_types berdasarkan UU 13/2003,
     * UU 6/2023 (Cipta Kerja), PP 35/2021, dan praktik Kemenaker.
     *
     * Setiap entry mengembalikan:
     *   ['ok' => bool, 'reason' => string, 'default_days' => int|null]
     *
     * default_days hanya untuk informasi UI; perhitungan saldo tetap merujuk
     * ke leave_types.default_days yang dapat di-override per perusahaan.
     */
    private function categoryEligibility(string $category, array $emp): array
    {
        $gender  = strtoupper((string) ($emp['gender'] ?? ''));      // 'M' | 'F'
        $marital = strtoupper((string) ($emp['marital_status'] ?? '')); // SINGLE/MARRIED/DIVORCED/WIDOWED

        switch (strtoupper($category)) {
            case 'MATERNITY':
                if ($gender !== 'F') {
                    return ['ok' => false, 'reason' => 'Cuti melahirkan hanya untuk karyawan perempuan (UU 13/2003 Pasal 82).'];
                }
                return ['ok' => true, 'reason' => '', 'default_days' => 90];

            case 'PATERNITY':
                if ($gender !== 'M') {
                    return ['ok' => false, 'reason' => 'Cuti pendamping melahirkan hanya untuk karyawan laki-laki.'];
                }
                if ($marital !== 'MARRIED') {
                    return ['ok' => false, 'reason' => 'Cuti pendamping melahirkan hanya untuk karyawan yang berstatus menikah.'];
                }
                return ['ok' => true, 'reason' => '', 'default_days' => 2];

            case 'MENSTRUAL':
                if ($gender !== 'F') {
                    return ['ok' => false, 'reason' => 'Cuti haid hanya untuk karyawan perempuan (UU 13/2003 Pasal 81).'];
                }
                return ['ok' => true, 'reason' => '', 'default_days' => 2];

            case 'MARRIAGE':
                if ($marital === 'MARRIED') {
                    return ['ok' => false, 'reason' => 'Cuti menikah tidak dapat diajukan karena status Anda sudah menikah.'];
                }
                return ['ok' => true, 'reason' => '', 'default_days' => 3];

            case 'BEREAVEMENT':
                // 2 hari (suami/istri, orang tua/mertua, anak/menantu meninggal).
                return ['ok' => true, 'reason' => '', 'default_days' => 2];

            case 'HAJJ':
                // Hak istimewa untuk pekerja yang menjalankan ibadah agama
                // (UU 13/2003 Pasal 80). Default banyak perusahaan: 40 hari sekali seumur kerja.
                return ['ok' => true, 'reason' => '', 'default_days' => 40];

            case 'ANNUAL':
                $tenureMonths = (int) ((time() - strtotime($emp['join_date'] ?? 'now')) / 86400 / 30);
                if ($tenureMonths < 12) {
                    return ['ok' => false, 'reason' => "Cuti tahunan baru bisa diajukan setelah masa kerja 12 bulan (saat ini {$tenureMonths} bulan)."];
                }
                return ['ok' => true, 'reason' => '', 'default_days' => 12];

            case 'SICK':
            case 'UNPAID':
            case 'SPECIAL':
            default:
                return ['ok' => true, 'reason' => '', 'default_days' => null];
        }
    }

    /**
     * Tentukan window tanggal pengajuan berdasarkan kebijakan payroll +
     * kategori cuti. Mengembalikan:
     *   ['policy' => ..., 'per_category' => ['SICK' => ['min','max'], ...]]
     *
     * Aturan default:
     *  - Window pengajuan umum dimulai HARI INI (tidak boleh backdate),
     *    karena cuti yang sudah lewat membuat absensi historis kacau.
     *  - SICK boleh backdate maksimal ke awal periode payroll yang sedang
     *    AKTIF (status DRAFT) atau 14 hari ke belakang — mana yang lebih
     *    awal — supaya surat dokter yang baru diserahkan masih bisa dicatat.
     *  - Window berakhir di pay_date periode berikutnya (atau end_date
     *    periode aktif + 90 hari jika tidak ada periode berikutnya).
     *  - MATERNITY/PATERNITY/HAJJ punya look-ahead lebih panjang (180 hari)
     *    karena biasanya direncanakan jauh-jauh hari.
     *  - Kalau periode aktif sudah FINALIZED, semua tanggal di rentang itu
     *    di-block — pengajuan baru hanya untuk periode berikutnya.
     */
    private function getLeavePolicy(int $companyId): array
    {
        $db    = \Config\Database::connect();
        $today = date('Y-m-d');

        // Cari periode payroll yang relevan (DRAFT/OPEN, MONTHLY).
        $current = $db->table('payroll_periods')
            ->where('company_id', $companyId)
            ->where('start_date <=', $today)
            ->where('end_date >=', $today)
            ->whereIn('status', ['DRAFT', 'OPEN', 'CALCULATED'])
            ->orderBy('start_date', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        // Periode berikutnya (untuk batas atas tanggal cuti).
        $next = $db->table('payroll_periods')
            ->where('company_id', $companyId)
            ->where('start_date >', $today)
            ->whereIn('status', ['DRAFT', 'OPEN'])
            ->orderBy('start_date', 'ASC')
            ->limit(1)
            ->get()->getRowArray();

        // Default min: hari ini.
        $minStart = $today;

        // Default max: 180 hari ke depan kalau tidak ada periode berikutnya.
        $maxStart = $next['pay_date'] ?? date('Y-m-d', strtotime('+180 days'));

        // Boundary backdate untuk SICK.
        $sickMin = $today;
        if ($current) {
            // Awal periode aktif, atau 14 hari ke belakang — pilih yang
            // tidak melebihi periode berjalan (paling awal).
            $candidate = max($current['start_date'], date('Y-m-d', strtotime('-14 days')));
            $sickMin   = $candidate;
        } else {
            $sickMin = date('Y-m-d', strtotime('-14 days'));
        }

        // Window untuk cuti yang biasanya direncanakan lama (maternity/haji).
        $longLookaheadMax = date('Y-m-d', strtotime('+365 days'));

        return [
            'today'          => $today,
            'current_period' => $current ? [
                'id'         => (int) $current['id'],
                'code'       => $current['code'],
                'name'       => $current['name'],
                'start_date' => $current['start_date'],
                'end_date'   => $current['end_date'],
                'pay_date'   => $current['pay_date'],
                'status'     => $current['status'],
            ] : null,
            'next_period' => $next ? [
                'id'         => (int) $next['id'],
                'code'       => $next['code'],
                'name'       => $next['name'],
                'start_date' => $next['start_date'],
                'end_date'   => $next['end_date'],
                'pay_date'   => $next['pay_date'],
                'status'     => $next['status'],
            ] : null,
            'default_min_start_date' => $minStart,
            'default_max_start_date' => $maxStart,
            'per_category' => [
                // SICK: boleh backdate ke awal periode aktif / 14 hari lalu.
                'SICK'      => ['min_start_date' => $sickMin,            'max_start_date' => $maxStart],
                // BEREAVEMENT: boleh backdate sampai 7 hari (kondisi darurat).
                'BEREAVEMENT' => ['min_start_date' => date('Y-m-d', strtotime('-7 days')), 'max_start_date' => $maxStart],
                // MATERNITY/PATERNITY: tidak backdate, look-ahead 1 tahun.
                'MATERNITY' => ['min_start_date' => $minStart, 'max_start_date' => $longLookaheadMax],
                'PATERNITY' => ['min_start_date' => date('Y-m-d', strtotime('-3 days')), 'max_start_date' => $longLookaheadMax],
                // HAJJ: rencana jauh, tidak backdate.
                'HAJJ'      => ['min_start_date' => $minStart, 'max_start_date' => $longLookaheadMax],
                // ANNUAL/SPECIAL/MARRIAGE/MENSTRUAL/UNPAID: pakai default.
            ],
            'help_text' => 'Pengajuan cuti hanya bisa di periode payroll yang masih aktif (belum dikunci). Cuti sakit boleh diajukan mundur untuk hari-hari saat Anda sakit di periode berjalan.',
        ];
    }

    /**
     * Endpoint pembantu untuk frontend:
     *   GET /api/v1/leave-types/for-me
     * Mengembalikan tipe cuti yang berlaku untuk user saat ini, dengan
     * flag `eligible` dan alasan ketidaklayakan kalau ada, plus window
     * tanggal yang dibolehkan per kategori (lihat getLeavePolicy()).
     */
    public function eligibleTypes()
    {
        $employeeId = $this->tenant()->getEmployeeId();
        if (! $employeeId) return $this->fail('User tidak terhubung dengan karyawan', 400, 'NO_EMPLOYEE_LINK');

        $db  = \Config\Database::connect();
        $activeCompanyId = $this->requireCompanyId();
        $emp = $db->table('employees')
            ->where('id', $employeeId)
            ->where('company_id', $activeCompanyId)
            ->get()->getRowArray();
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);

        $companyId = (int) $emp['company_id'];
        $policy    = $this->getLeavePolicy($companyId);

        $types = $db->table('leave_types')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->orderBy('category', 'ASC')
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();

        $year = (int) date('Y');
        $balances = $db->table('leave_balances')
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('period_year', $year)
            ->get()->getResultArray();
        $balByType = [];
        foreach ($balances as $b) $balByType[(int) $b['leave_type_id']] = $b;

        $out = [];
        foreach ($types as $t) {
            $reasons = [];
            // 1) Filter gender (kolom resmi di leave_types).
            $gr = strtoupper((string) ($t['gender_restriction'] ?? 'ALL'));
            $empGender = strtoupper((string) ($emp['gender'] ?? ''));
            if ($gr !== 'ALL' && $gr !== $empGender) {
                $reasons[] = $gr === 'F'
                    ? 'Hanya untuk karyawan perempuan'
                    : 'Hanya untuk karyawan laki-laki';
            }
            // 2) Filter berdasarkan kategori (gender turunan + marital status + tenure).
            $cat = strtoupper((string) ($t['category'] ?? 'ANNUAL'));
            $catCheck = $this->categoryEligibility($cat, $emp);
            if (! $catCheck['ok']) $reasons[] = $catCheck['reason'];

            // 3) Tenure khusus per leave_types
            $minMonths = (int) ($t['min_tenure_months'] ?? 0);
            if ($minMonths > 0 && ! empty($emp['join_date'])) {
                $tenureMonths = (int) ((time() - strtotime($emp['join_date'])) / 86400 / 30);
                if ($tenureMonths < $minMonths) {
                    $reasons[] = "Belum memenuhi masa kerja minimal {$minMonths} bulan (saat ini {$tenureMonths} bulan)";
                }
            }

            // 4) Window tanggal per kategori (override default).
            $win = $policy['per_category'][$cat] ?? [
                'min_start_date' => $policy['default_min_start_date'],
                'max_start_date' => $policy['default_max_start_date'],
            ];

            $bal = $balByType[(int) $t['id']] ?? null;
            $out[] = [
                'id'                 => (int) $t['id'],
                'code'               => $t['code'],
                'name'               => $t['name'],
                'category'           => $cat,
                'is_paid'            => (int) $t['is_paid'] === 1,
                'requires_doc'       => (int) $t['requires_doc'] === 1,
                'gender_restriction' => $gr,
                'default_days'       => (int) ($t['default_days'] ?? 0),
                'min_tenure_months'  => $minMonths,
                'reset_yearly'       => (int) $t['reset_yearly'] === 1,
                'description'        => $t['description'],
                'eligible'           => empty($reasons),
                'reasons'            => $reasons,
                'balance'            => $bal ? [
                    'allocated' => (float) $bal['allocated'],
                    'used'      => (float) $bal['used'],
                    'remaining' => (float) $bal['remaining'],
                ] : null,
                'min_start_date'     => $win['min_start_date'],
                'max_start_date'     => $win['max_start_date'],
            ];
        }
        return $this->ok([
            'types'  => $out,
            'policy' => $policy,
        ]);
    }

    private function getGender(){
        $db = \Config\Database::connect();
        $employeeId = $this->tenant()->getEmployeeId();
        $emp = $db->table('employees')
            ->where('id', $employeeId)
            ->where('company_id', $this->requireCompanyId())
            ->get()->getRowArray();
        return strtoupper((string) ($emp['gender'] ?? ''));
    }
    
    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $status = trim((string) $this->request->getGet('status'));
        // employee_id dari query param di-sanitasi via PrivacyScope:
        //   - canViewAll: pakai apa adanya
        //   - hanya view.own: di-paksa ke employee_id user, abaikan input
        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
        $mine = $this->request->getGet('mine');

        $builder = $db->table('leaves l')
            ->select('l.*, e.full_name AS employee_name, e.employee_number, lt.code AS leave_code, lt.name AS leave_name')
            ->join('employees e', 'e.id = l.employee_id')
            ->join('leave_types lt', 'lt.id = l.leave_type_id');

        // Privacy scope: tenant + (kalau view.own only) paksa employee_id sendiri.
        // `mine=1` query param adalah shortcut yang di-honor oleh applyListScope
        // saat user punya canViewAll dan ingin filter eksplisit ke diri sendiri.
        $effectiveEmpId = $this->privacy()->applyListScope(
            $builder,
            module: 'leave',
            companyColumn: 'l.company_id',
            employeeColumn: 'l.employee_id',
            requestedEmployeeId: $mine ? $this->tenant()->getEmployeeId() : ($requestedEmpId ?: null),
        );
        if ($effectiveEmpId === null) {
            return $this->fail('Anda tidak memiliki akses ke modul cuti.', 403);
        }

        // Filter status di-allow untuk semua role (tidak ada PII di status).
        if ($status) $builder->where('l.status', $status);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('l.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('leaves')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $row['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'leave_type_id' => 'required|integer',
            'start_date'    => 'required|valid_date',
            'end_date'      => 'required|valid_date',
            'reason'        => 'required|max_length[1000]',
        ]);
        if (! $valid) return null;

        $employeeId = (int) ($data['employee_id'] ?? $this->tenant()->getEmployeeId());
        if (! $employeeId) return $this->fail('Employee not specified', 400);

        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        $emp = $db->table('employees')
            ->where('id', $employeeId)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $emp['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        $leaveType = $db->table('leave_types')
            ->where('id', $data['leave_type_id'])
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $leaveType) return $this->fail('Tipe cuti tidak ditemukan', 404);

        // ─── Validasi tanggal & rentang ────────────────────────────────
        if (strtotime($data['end_date']) < strtotime($data['start_date'])) {
            return $this->fail('Tanggal selesai harus setelah atau sama dengan tanggal mulai.', 422, 'INVALID_DATE_RANGE');
        }

        // ─── Window pengajuan berdasarkan kebijakan payroll ────────────
        // HR yang punya permission `leave.approve` atau super admin boleh
        // mengajukan/mengoreksi cuti backdate untuk karyawan lain. End-user
        // biasa wajib mengikuti window di getLeavePolicy().
        $isHrLike = $this->tenant()->isSuperAdmin() || $this->tenant()->hasPermission('leave.approve');
        $submittedByOther = (int) ($data['employee_id'] ?? 0) !== 0
            && (int) $data['employee_id'] !== (int) $this->tenant()->getEmployeeId();
        if (! ($isHrLike && $submittedByOther)) {
            $cat    = strtoupper((string) ($leaveType['category'] ?? 'ANNUAL'));
            $policy = $this->getLeavePolicy((int) $emp['company_id']);
            $win    = $policy['per_category'][$cat] ?? [
                'min_start_date' => $policy['default_min_start_date'],
                'max_start_date' => $policy['default_max_start_date'],
            ];

            if ($data['start_date'] < $win['min_start_date']) {
                $minLabel = date('d M Y', strtotime($win['min_start_date']));
                return $this->fail(
                    "Tanggal mulai tidak boleh sebelum {$minLabel}. " .
                    ($cat === 'SICK'
                        ? 'Cuti sakit hanya bisa di-backdate maksimal ke awal periode payroll berjalan.'
                        : 'Pengajuan untuk hari yang sudah lewat tidak diizinkan; gunakan menu pengajuan perubahan absensi.'),
                    422, 'BACKDATE_NOT_ALLOWED'
                );
            }
            if ($data['start_date'] > $win['max_start_date']) {
                $maxLabel = date('d M Y', strtotime($win['max_start_date']));
                return $this->fail(
                    "Tanggal mulai tidak boleh setelah {$maxLabel}. Pengajuan terlalu jauh ke depan.",
                    422, 'TOO_FAR_AHEAD'
                );
            }

            // Periode payroll yang sudah dikunci (FINALIZED/PAID/CLOSED)
            // tidak boleh menerima cuti baru di rentang tanggalnya.
            $db2 = \Config\Database::connect();
            $locked = $db2->table('payroll_periods')
                ->where('company_id', (int) $emp['company_id'])
                ->whereIn('status', ['FINALIZED', 'PAID', 'CLOSED'])
                ->where('start_date <=', $data['end_date'])
                ->where('end_date >=', $data['start_date'])
                ->get()->getRowArray();
            if ($locked) {
                return $this->fail(
                    "Periode payroll {$locked['name']} ({$locked['start_date']} s/d {$locked['end_date']}) sudah dikunci/dibayar. Pengajuan cuti pada rentang ini tidak diperbolehkan.",
                    422, 'PERIOD_LOCKED'
                );
            }
        }

        // ─── Gender restriction (kolom resmi di leave_types) ───────────
        $gr = strtoupper((string) ($leaveType['gender_restriction'] ?? 'ALL'));
        $empGender = strtoupper((string) ($emp['gender'] ?? ''));
        if ($gr !== 'ALL' && $gr !== $empGender) {
            $msg = $gr === 'F'
                ? "Tipe cuti \"{$leaveType['name']}\" hanya untuk karyawan perempuan."
                : "Tipe cuti \"{$leaveType['name']}\" hanya untuk karyawan laki-laki.";
            return $this->fail($msg, 422, 'GENDER_RESTRICTED');
        }

        // ─── Tenure check ──────────────────────────────────────────────
        if ((int) $leaveType['min_tenure_months'] > 0) {
            $tenureMonths = ! empty($emp['join_date'])
                ? (int) ((time() - strtotime($emp['join_date'])) / 86400 / 30)
                : 0;
            if ($tenureMonths < (int) $leaveType['min_tenure_months']) {
                return $this->fail(
                    "Tipe cuti \"{$leaveType['name']}\" baru bisa diajukan setelah masa kerja {$leaveType['min_tenure_months']} bulan (saat ini {$tenureMonths} bulan).",
                    422, 'INSUFFICIENT_TENURE'
                );
            }
        }

        // ─── Eligibilitas berbasis kategori (UU 13/2003, PP 35/2021) ───
        $cat = strtoupper((string) ($leaveType['category'] ?? 'ANNUAL'));
        $catCheck = $this->categoryEligibility($cat, $emp);
        if (! $catCheck['ok']) {
            return $this->fail($catCheck['reason'], 422, 'CATEGORY_NOT_ELIGIBLE');
        }

        // ─── Cek tumpang tindih dengan pengajuan PENDING/APPROVED ──────
        $overlap = $db->table('leaves')
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->where('start_date <=', $data['end_date'])
            ->where('end_date >=', $data['start_date'])
            ->countAllResults();
        if ($overlap > 0) {
            return $this->fail(
                'Anda sudah memiliki pengajuan cuti pada periode tersebut. Cek di Daftar Cuti.',
                422, 'OVERLAPPING_LEAVE'
            );
        }

        // ─── Days calculation (excluding weekends + holidays) ──────────
        $days = $this->countWorkingDays($db, (int) $emp['company_id'], $data['start_date'], $data['end_date']);
        $isHalf = ! empty($data['is_half_day']);
        if ($isHalf) $days = 0.5;

        if ($days <= 0) {
            return $this->fail(
                'Rentang tanggal yang dipilih tidak mengandung hari kerja (mungkin bertepatan dengan akhir pekan atau hari libur).',
                422, 'NO_WORKING_DAYS'
            );
        }

        // Max days check (kalau leave_types punya batas).
        if (! empty($leaveType['max_days']) && $days > (float) $leaveType['max_days']) {
            return $this->fail(
                "Tipe cuti \"{$leaveType['name']}\" maksimal {$leaveType['max_days']} hari per pengajuan.",
                422, 'MAX_DAYS_EXCEEDED'
            );
        }

        // Balance check (only for paid annual-style)
        if ((int) $leaveType['is_paid'] === 1 && (int) $leaveType['reset_yearly'] === 1 && $leaveType['default_days'] > 0) {
            $year = (int) date('Y', strtotime($data['start_date']));
            $bal = $db->table('leave_balances')
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $leaveType['id'])
                ->where('period_year', $year)
                ->get()->getRowArray();
            $remaining = $bal ? (float) $bal['remaining'] : (float) $leaveType['default_days'];
            if ($days > $remaining) {
                return $this->fail("Saldo cuti tidak cukup (sisa: {$remaining}, diminta: {$days})", 422, 'INSUFFICIENT_BALANCE');
            }
        }

        // ─── Normalisasi half_day_part ─────────────────────────────────
        // Kolom ini punya CHECK constraint hanya MORNING|AFTERNOON|NULL —
        // string kosong akan ditolak DB. Jadi kita paksa null kalau bukan
        // half day, dan validasi nilainya kalau half day.
        $halfPart = null;
        if ($isHalf) {
            $halfPart = strtoupper((string) ($data['half_day_part'] ?? ''));
            if (! in_array($halfPart, ['MORNING', 'AFTERNOON'], true)) {
                return $this->fail(
                    'Pilih bagian setengah hari (Pagi atau Siang) terlebih dahulu.',
                    422, 'HALF_DAY_PART_REQUIRED'
                );
            }
        }

        // ─── Lampiran wajib untuk tipe yang memerlukan dokumen ─────────
        if ((int) ($leaveType['requires_doc'] ?? 0) === 1 && empty($data['attachment'])) {
            return $this->fail(
                "Tipe cuti \"{$leaveType['name']}\" memerlukan lampiran dokumen pendukung (mis. surat dokter).",
                422, 'ATTACHMENT_REQUIRED'
            );
        }

        $reqNumber = 'LV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $insert = [
            'company_id'     => $emp['company_id'],
            'employee_id'    => $employeeId,
            'leave_type_id'  => $leaveType['id'],
            'request_number' => $reqNumber,
            'start_date'     => $data['start_date'],
            'end_date'       => $data['end_date'],
            'days'           => $days,
            'is_half_day'    => $isHalf ? 1 : 0,
            'half_day_part'  => $halfPart,
            'reason'         => $data['reason'],
            'attachment'     => $data['attachment'] ?? null,
            'contact_during_leave' => $data['contact_during_leave'] ?? null,
            'handover_to'    => ! empty($data['handover_to']) ? (int) $data['handover_to'] : null,
            'status'         => 'PENDING',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];
        $db->table('leaves')->insert($insert);
        $id = (int) $db->insertID();

        // Pakai approval engine kalau chain didefinisikan untuk LEAVE.
        // Engine akan resolve approver step pertama dan kirim notifikasi.
        try {
            $approvalRes = Services::approval()->start([
                'company_id'   => (int) $emp['company_id'],
                'module'       => 'LEAVE',
                'entity_table' => 'leaves',
                'entity_id'    => $id,
                'employee_id'  => $employeeId,
                'meta'         => ['days' => $days, 'leave_type_id' => (int) $leaveType['id']],
            ]);
        } catch (\Throwable $e) {
            log_message('warning', 'ApprovalEngine.start failed: ' . $e->getMessage());
            $approvalRes = null;
        }

        // Fallback: kalau engine tidak menghasilkan approver (mis. tidak ada
        // chain), kirim notifikasi ke manager + HR seperti sebelumnya supaya
        // alur lama tetap jalan.
        if (! $approvalRes || empty($approvalRes['next_approvers'])) {
            $approvers = $db->table('users')->where('company_id', $emp['company_id'])->whereIn('role_id', [2, 3])->get()->getResultArray();
            $notifier = Services::notification();
            foreach ($approvers as $u) {
                $notifier->send((int) $u['id'], (int) $emp['company_id'], 'leave.requested',
                    'Pengajuan cuti baru',
                    "{$emp['full_name']} mengajukan {$leaveType['name']} dari {$data['start_date']} sampai {$data['end_date']} ({$days} hari)",
                    ['leave_id' => $id], '/leaves/' . $id, true);
            }
        }

        Services::audit()
            ->forModule('LEAVE')
            ->logCreate(
                'leave',
                $id,
                $insert,
                "Pengajuan cuti {$leaveType['name']}: {$emp['full_name']} ({$data['start_date']} s/d {$data['end_date']}, {$days} hari)"
            );

        // Sprint 14+ — webhook outbound `leave.requested`
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::LEAVE_REQUESTED,
            (int) $emp['company_id'],
            [
                'leave_id'       => $id,
                'request_number' => $reqNumber,
                'employee'       => [
                    'id'              => (int) $emp['id'],
                    'employee_number' => $emp['employee_number'] ?? null,
                    'name'            => $emp['full_name'] ?? null,
                ],
                'leave_type'     => [
                    'id'   => (int) $leaveType['id'],
                    'code' => $leaveType['code'] ?? null,
                    'name' => $leaveType['name'] ?? null,
                ],
                'start_date'     => $data['start_date'],
                'end_date'       => $data['end_date'],
                'days'           => $days,
                'is_half_day'    => $isHalf,
                'status'         => 'PENDING',
                'requested_at'   => date('c'),
            ],
        );

        return $this->ok([
            'id' => $id,
            'request_number' => $reqNumber,
            'days' => $days,
            'approval' => $approvalRes,
        ], 'Leave requested', 201);
    }

    public function approve($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        $leave = $db->table('leaves')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $leave) return $this->fail('Not found', 404);
        if ($leave['status'] !== 'PENDING') return $this->fail('Leave not pending', 422);
        if (! $this->tenant()->isSuperAdmin() && (int) $leave['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        $db->transStart();
        $db->table('leaves')->where('id', $id)->where('company_id', $companyId)->update([
            'status' => 'APPROVED',
            'approved_by' => $this->userId(),
            'approved_at' => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        // Decrement balance
        $leaveType = $db->table('leave_types')
            ->where('id', $leave['leave_type_id'])
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if ($leaveType && (int) $leaveType['reset_yearly'] === 1 && $leaveType['default_days'] > 0) {
            $year = (int) date('Y', strtotime($leave['start_date']));
            $bal = $db->table('leave_balances')
                ->where('company_id', $companyId)
                ->where('employee_id', $leave['employee_id'])
                ->where('leave_type_id', $leave['leave_type_id'])
                ->where('period_year', $year)
                ->get()->getRowArray();
            if (! $bal) {
                $db->table('leave_balances')->insert([
                    'company_id'    => $leave['company_id'],
                    'employee_id'   => $leave['employee_id'],
                    'leave_type_id' => $leave['leave_type_id'],
                    'period_year'   => $year,
                    'allocated'     => $leaveType['default_days'],
                    'used'          => $leave['days'],
                    'remaining'     => (float) $leaveType['default_days'] - (float) $leave['days'],
                    'created_at'    => date('Y-m-d H:i:s'),
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
            } else {
                $db->table('leave_balances')->where('id', $bal['id'])->where('company_id', $companyId)->update([
                    'used'      => (float) $bal['used'] + (float) $leave['days'],
                    'remaining' => (float) $bal['remaining'] - (float) $leave['days'],
                    'updated_at'=> date('Y-m-d H:i:s'),
                ]);
            }
        }

        // Mark attendance LEAVE for the period
        $period = new \DatePeriod(new \DateTime($leave['start_date']), new \DateInterval('P1D'), (new \DateTime($leave['end_date']))->modify('+1 day'));
        foreach ($period as $d) {
            $date = $d->format('Y-m-d');
            $existing = $db->table('attendances')->where('company_id', $companyId)->where('employee_id', $leave['employee_id'])->where('work_date', $date)->get()->getRowArray();
            if ($existing) {
                $db->table('attendances')->where('id', $existing['id'])->where('company_id', $companyId)->update(['status' => 'LEAVE']);
            } else {
                $db->table('attendances')->insert([
                    'company_id'   => $leave['company_id'],
                    'employee_id'  => $leave['employee_id'],
                    'work_date'    => $date,
                    'status'       => 'LEAVE',
                    'created_at'   => date('Y-m-d H:i:s'),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $db->transComplete();

        // Notify employee
        $emp = $db->table('employees')->where('id', $leave['employee_id'])->where('company_id', $companyId)->get()->getRowArray();
        if ($emp && $emp['user_id']) {
            Services::notification()->send((int) $emp['user_id'], (int) $leave['company_id'], 'leave.approved',
                'Pengajuan cuti disetujui', 'Pengajuan cuti Anda telah disetujui',
                ['leave_id' => $id], '/leaves/' . $id, true);
        }

        // Sprint 13+ — dispatch webhook outbound via trait helper.
        // Defensive: trait sudah handle exception + log warning.
        $leaveType = $leaveType ?? $db->table('leave_types')
            ->where('id', $leave['leave_type_id'])->where('company_id', $companyId)->get()->getRowArray();
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::LEAVE_APPROVED,
            (int) $leave['company_id'],
            [
                'leave_id'   => $id,
                'employee'   => $emp ? [
                    'id'   => (int) $emp['id'],
                    'nik'  => $emp['nik'] ?? null,
                    'name' => $emp['name'] ?? null,
                ] : null,
                'leave_type' => $leaveType ? ($leaveType['code'] ?? $leaveType['name'] ?? null) : null,
                'start_date' => $leave['start_date'],
                'end_date'   => $leave['end_date'],
                'days'       => (float) $leave['days'],
                'approved_by' => $this->userId(),
                'approved_at' => date('c'),
            ],
        );
        Services::audit()
            ->forModule('LEAVE')
            ->logApprove(
                'leave',
                $id,
                $leave,
                "Menyetujui cuti #{$leave['request_number']} ({$leave['days']} hari)"
            );
        return $this->ok(null, 'Leave approved');
    }

    public function reject($id = null)
    {
        $id = (int) $id;
        $data = $this->getJsonInput();
        $reason = $data['reason'] ?? '';

        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        $leave = $db->table('leaves')->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $leave) return $this->fail('Not found', 404);
        if ($leave['status'] !== 'PENDING') return $this->fail('Leave not pending', 422);

        $db->table('leaves')->where('id', $id)->where('company_id', $companyId)->update([
            'status' => 'REJECTED',
            'approved_by' => $this->userId(),
            'approved_at' => date('Y-m-d H:i:s'),
            'rejection_note' => $reason,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $emp = $db->table('employees')->where('id', $leave['employee_id'])->where('company_id', $companyId)->get()->getRowArray();
        if ($emp && $emp['user_id']) {
            Services::notification()->send((int) $emp['user_id'], (int) $leave['company_id'], 'leave.rejected',
                'Pengajuan cuti ditolak', "Alasan: {$reason}", ['leave_id' => $id], '/leaves/' . $id, true);
        }

        // Sprint 13+ — dispatch webhook untuk integrasi external
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::LEAVE_REJECTED,
            (int) $leave['company_id'],
            [
                'leave_id'   => $id,
                'employee'   => $emp ? [
                    'id'   => (int) $emp['id'],
                    'nik'  => $emp['nik'] ?? null,
                    'name' => $emp['name'] ?? null,
                ] : null,
                'reason'      => $reason,
                'rejected_by' => $this->userId(),
                'rejected_at' => date('c'),
            ],
        );

        Services::audit()
            ->forModule('LEAVE')
            ->logReject('leave', $id, $leave, $reason ?: '(tanpa alasan)');
        return $this->ok(null, 'Leave rejected');
    }

    /**
     * POST /api/v1/leaves/(:num)/cancel
     *
     * Karyawan self-cancel cuti sebelum/saat mulai. Aturan (Sprint 17+):
     *   - Pemilik cuti = pemanggil (atau super_admin/HR dengan
     *     `leave.approve` boleh cancel atas nama)
     *   - Status awal harus PENDING atau APPROVED
     *   - Kalau APPROVED dan start_date sudah ≥ 2 hari kerja lampau, tolak —
     *     karyawan tidak bisa cancel cuti yang sudah lebih dari 2 hari
     *     berjalan; minta hubungi HR untuk koreksi attendance
     *   - Wajib alasan minimal 5 karakter
     *
     * Side effects saat cancel APPROVED:
     *   - Restore saldo cuti (used -= days, remaining += days)
     *   - Hapus mark `attendances.status = LEAVE` untuk hari yang BELUM
     *     terjadi (≥ today). Hari yang sudah lewat dipertahankan supaya
     *     audit attendance historis tidak rusak — HR yang putus apakah
     *     mau koreksi attendance lewat AttendanceChangeRequest.
     */
    public function cancel($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        $leave = $db->table('leaves')->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $leave) return $this->fail('Not found', 404);

        $tenant = $this->tenant();
        $isOwner = (int) $leave['employee_id'] === (int) $tenant->getEmployeeId();
        $canActAsHr = $tenant->isSuperAdmin() || $tenant->hasPermission('leave.approve');

        if (! $isOwner && ! $canActAsHr) {
            return $this->fail('Hanya pemilik cuti atau HR yang dapat membatalkan',
                403, \App\Constants\ErrorCode::PERMISSION_DENIED);
        }
        if (! in_array($leave['status'], ['PENDING', 'APPROVED'], true)) {
            return $this->fail('Hanya cuti PENDING/APPROVED yang bisa dibatalkan; status saat ini: ' . $leave['status'],
                422, \App\Constants\ErrorCode::APPROVAL_INVALID_STATE);
        }

        // Tolak self-cancel kalau sudah > 2 hari berjalan (HR-only path)
        $today = date('Y-m-d');
        $start = (string) $leave['start_date'];
        $daysSinceStart = (int) round((strtotime($today) - strtotime($start)) / 86400);
        if (! $canActAsHr && $daysSinceStart > 2) {
            return $this->fail(
                'Cuti sudah berjalan lebih dari 2 hari; hubungi HR untuk koreksi.',
                422, \App\Constants\ErrorCode::APPROVAL_INVALID_STATE,
            );
        }

        $data = $this->getJsonInput();
        $reason = trim((string) ($data['reason'] ?? ''));
        if (strlen($reason) < 5) {
            return $this->fail('Alasan pembatalan minimal 5 karakter', 422,
                \App\Constants\ErrorCode::VALIDATION_FAILED);
        }

        $previousStatus = (string) $leave['status'];
        $now = date('Y-m-d H:i:s');

        $db->transStart();

        $db->table('leaves')->where('id', $id)->where('company_id', $companyId)->update([
            'status'              => 'CANCELLED',
            'cancellation_reason' => $reason,
            'cancelled_by'        => $this->userId(),
            'cancelled_at'        => $now,
            'updated_at'          => $now,
        ]);

        // Kalau cuti sudah APPROVED, restore saldo + hapus mark attendance
        // di hari yang BELUM terjadi.
        if ($previousStatus === 'APPROVED') {
            $leaveType = $db->table('leave_types')
                ->where('id', $leave['leave_type_id'])->where('company_id', $companyId)->get()->getRowArray();
            if ($leaveType && (int) $leaveType['reset_yearly'] === 1
                && (float) $leaveType['default_days'] > 0
            ) {
                $year = (int) date('Y', strtotime($leave['start_date']));
                $bal = $db->table('leave_balances')
                    ->where('company_id', $companyId)
                    ->where('employee_id', $leave['employee_id'])
                    ->where('leave_type_id', $leave['leave_type_id'])
                    ->where('period_year', $year)
                    ->get()->getRowArray();
                if ($bal) {
                    $db->table('leave_balances')->where('id', $bal['id'])->where('company_id', $companyId)->update([
                        'used'      => max(0, (float) $bal['used'] - (float) $leave['days']),
                        'remaining' => (float) $bal['remaining'] + (float) $leave['days'],
                        'updated_at'=> $now,
                    ]);
                }
            }

            // Hapus mark attendance LEAVE untuk hari yang belum terjadi
            $db->table('attendances')
                ->where('company_id', $companyId)
                ->where('employee_id', $leave['employee_id'])
                ->where('status', 'LEAVE')
                ->where('work_date >=', $today)
                ->where('work_date <=', $leave['end_date'])
                ->where('clock_in_at IS NULL')   // safe: hanya yg memang LEAVE-only, bukan campuran clock+leave
                ->delete();
        }

        $db->transComplete();
        if ($db->transStatus() === false) {
            return $this->fail('Gagal membatalkan cuti', 500);
        }

        // Notifikasi: kalau HR cancel atas nama karyawan, kasih tahu karyawan
        $emp = $db->table('employees')->where('id', $leave['employee_id'])->where('company_id', $companyId)->get()->getRowArray();
        if (! $isOwner && $emp && ! empty($emp['user_id'])) {
            Services::notification()->send(
                (int) $emp['user_id'], (int) $leave['company_id'], 'leave.canceled',
                'Cuti dibatalkan',
                "Cuti #{$leave['request_number']} dibatalkan oleh HR. Alasan: {$reason}",
                ['leave_id' => $id], '/leaves/' . $id, true,
            );
        }
        // Kalau pemilik cancel sendiri tapi sudah APPROVED, kasih tahu approver
        if ($isOwner && $previousStatus === 'APPROVED' && ! empty($leave['approved_by'])) {
            Services::notification()->send(
                (int) $leave['approved_by'], (int) $leave['company_id'], 'leave.canceled',
                'Cuti yang Anda setujui dibatalkan',
                "Karyawan {$emp['full_name']} membatalkan cuti #{$leave['request_number']}. Alasan: {$reason}",
                ['leave_id' => $id], '/leaves/' . $id, true,
            );
        }

        // Sprint 17+ — webhook outbound `leave.canceled`
        $leaveType = $leaveType ?? $db->table('leave_types')
            ->where('id', $leave['leave_type_id'])->get()->getRowArray();
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::LEAVE_CANCELED,
            (int) $leave['company_id'],
            [
                'leave_id'        => $id,
                'request_number'  => $leave['request_number'] ?? null,
                'previous_status' => $previousStatus,
                'employee'        => $emp ? [
                    'id'              => (int) $emp['id'],
                    'employee_number' => $emp['employee_number'] ?? null,
                    'name'            => $emp['full_name'] ?? null,
                ] : null,
                'leave_type' => $leaveType ? ($leaveType['code'] ?? $leaveType['name'] ?? null) : null,
                'start_date' => $leave['start_date'],
                'end_date'   => $leave['end_date'],
                'days'       => (float) $leave['days'],
                'reason'     => $reason,
                'cancelled_by'    => $this->userId(),
                'cancelled_by_hr' => $canActAsHr && ! $isOwner,
                'cancelled_at'    => $now,
            ],
        );

        Services::audit()
            ->forModule('LEAVE')
            ->log(
                action: 'leave.cancel',
                entity: 'leave',
                entityId: $id,
                before: ['status' => $previousStatus],
                after: ['status' => 'CANCELLED', 'reason' => $reason],
                description: ($isOwner ? 'Karyawan' : 'HR')
                    . " membatalkan cuti #{$leave['request_number']} ({$leave['days']} hari): {$reason}",
                severity: $previousStatus === 'APPROVED' ? 'WARNING' : 'NOTICE',
            );

        return $this->ok(null, 'Cuti dibatalkan');
    }

    /**
     * Saldo cuti karyawan untuk satu tahun, dengan info prorate &
     * carry-over. Auto-create entry yg hilang via LeaveBalanceService.
     *
     * GET /api/v1/leave-balances/(:num)?year=YYYY
     */
    public function balance($id = null)
    {
        $id   = (int) $id;
        $year = (int) ($this->request->getGet('year') ?? date('Y'));

        // Authorization: karyawan boleh lihat saldonya sendiri; HR/manager
        // butuh permission `leave_balance.view` atau `leave.view`.
        $tenant = $this->tenant();
        $isOwn  = (int) $tenant->getEmployeeId() === $id;
        if (! $isOwn
            && ! $tenant->isSuperAdmin()
            && ! $tenant->hasPermission('leave_balance.view')
            && ! $tenant->hasPermission('leave.view')
        ) {
            return $this->fail('Forbidden', 403);
        }

        $summary = Services::leaveBalance()->summary($id, $year);
        return $this->ok([
            'employee_id' => $id,
            'year'        => $year,
            'balances'    => $summary,
            'today'       => date('Y-m-d'),
        ]);
    }

    /**
     * Kalender cuti tim — siapa cuti di rentang tanggal (membantu manager
     * menghindari approve cuti yang bentrok).
     *
     * GET /api/v1/leaves/team-calendar?branch_id=&department_id=&start_date=&end_date=
     *
     * Default rentang: 30 hari ke depan dari hari ini.
     */
    public function teamCalendar()
    {
        $tenant = $this->tenant();
        $start = (string) ($this->request->getGet('start_date') ?? date('Y-m-d'));
        $end   = (string) ($this->request->getGet('end_date')   ?? date('Y-m-d', strtotime('+30 days')));
        if (strtotime($start) === false || strtotime($end) === false) {
            return $this->fail('Format tanggal tidak valid', 422);
        }
        if (strtotime($end) < strtotime($start)) {
            return $this->fail('end_date harus >= start_date', 422);
        }
        if ((strtotime($end) - strtotime($start)) / 86400 > 180) {
            return $this->fail('Rentang maksimal 180 hari', 422);
        }

        $branchId = (int) ($this->request->getGet('branch_id') ?? 0);
        $deptId   = (int) ($this->request->getGet('department_id') ?? 0);

        // Branch isolation: HR cabang dipaksa ke cabangnya
        if (! $tenant->isSuperAdmin()
            && ! $tenant->hasPermission('attendance.view_all_branches')
            && $branchId > 0
            && $branchId !== $tenant->getBranchId()
        ) {
            return $this->fail('Akses lintas cabang tidak diizinkan', 403, 'CROSS_BRANCH_DENIED');
        }

        $db = \Config\Database::connect();
        $builder = $db->table('leaves l')
            ->select('l.id, l.employee_id, l.start_date, l.end_date, l.days, l.status, l.is_half_day, l.half_day_part,'
                . ' lt.code AS leave_code, lt.name AS leave_name, lt.category,'
                . ' e.full_name AS employee_name, e.employee_number, e.branch_id, e.department_id,'
                . ' b.name AS branch_name, d.name AS department_name')
            ->join('leave_types lt', 'lt.id = l.leave_type_id')
            ->join('employees e', 'e.id = l.employee_id')
            ->join('branches b', 'b.id = e.branch_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->whereIn('l.status', ['PENDING', 'APPROVED'])
            ->where('l.start_date <=', $end)
            ->where('l.end_date >=', $start);

        $tenant->applyAttendanceScope($builder, 'l.company_id', 'e.branch_id');
        if ($branchId) $builder->where('e.branch_id', $branchId);
        if ($deptId)   $builder->where('e.department_id', $deptId);

        $rows = $builder->orderBy('l.start_date', 'ASC')->get()->getResultArray();

        return $this->ok([
            'start_date' => $start,
            'end_date'   => $end,
            'leaves'     => $rows,
            'count'      => count($rows),
        ]);
    }

    private function countWorkingDays($db, int $companyId, string $start, string $end): float
    {
        $startTs = strtotime($start);
        $endTs   = strtotime($end);
        if ($endTs < $startTs) return 0;

        $holidays = $db->table('holidays')
            ->groupStart()
                ->where('company_id', $companyId)
                ->orWhere('company_id IS NULL')
            ->groupEnd()
            ->where('date >=', $start)
            ->where('date <=', $end)
            ->get()->getResultArray();
        $holidayDates = array_column($holidays, 'date');

        $days = 0;
        for ($t = $startTs; $t <= $endTs; $t += 86400) {
            $dow = (int) date('N', $t);  // 1=Mon, 7=Sun
            if ($dow >= 6) continue;
            $date = date('Y-m-d', $t);
            if (in_array($date, $holidayDates, true)) continue;
            $days++;
        }
        return (float) $days;
    }
}
