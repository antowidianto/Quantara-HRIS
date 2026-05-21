<?php

namespace App\Libraries;

/**
 * ContractRenderer
 *
 * Mengubah template kontrak (HTML dgn placeholder Mustache-like) menjadi
 * dokumen final per karyawan + (opsional) menyisipkan klausul peraturan
 * perusahaan dari company_regulations.
 *
 * Placeholder format:
 *   {{employee.full_name}}, {{employee.nik}}, {{contract.start_date}},
 *   {{company.name}}, {{position.name}}, {{department.name}},
 *   {{work_location.name}}, {{supervisor.full_name}}, {{salary.basic}},
 *   {{regulations}}                              -> semua klausul aktif (HTML)
 *   {{regulations.LEAVE}}                        -> klausul kategori tertentu
 *   {{custom.<key>}}                             -> dari extras
 *
 * Filter sederhana via pipe:
 *   {{salary.basic|currency_id}}     -> Rp 5.000.000
 *   {{contract.start_date|date_id}}  -> 5 Mei 2026
 *   {{employee.gender|gender_id}}    -> Laki-laki
 */
class ContractRenderer
{
    /**
     * Bangun context untuk render. Tidak menyentuh database lebih dari yang
     * dibutuhkan; caller bisa override dengan $extras (mis. untuk preview).
     */
    public function buildContext(int $employeeId, ?int $contractId = null, array $extras = []): array
    {
        $db  = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray() ?: [];

        // Decrypt sensitive untuk dipakai di template (tidak ditampilkan
        // apa adanya kalau template tidak memintanya).
        if (! empty($emp)) {
            try {
                $enc = new Encryption();
                $emp['nik']          = $enc->decrypt($emp['nik_encrypted'] ?? null) ?? '-';
                $emp['npwp']         = $enc->decrypt($emp['npwp_encrypted'] ?? null) ?? '-';
                $emp['bank_account'] = $enc->decrypt($emp['bank_account_encrypted'] ?? null) ?? '-';
            } catch (\Throwable $e) {
                // ignore — fallback to raw fields
            }
            unset($emp['nik_encrypted'], $emp['npwp_encrypted'], $emp['bank_account_encrypted'], $emp['nik_hash']);
        }

        $companyId = (int) ($emp['company_id'] ?? 0);
        $company   = $companyId
            ? ($db->table('companies')->where('id', $companyId)->get()->getRowArray() ?: [])
            : [];
        $position  = ! empty($emp['position_id'])
            ? ($db->table('positions')->where('id', (int) $emp['position_id'])->get()->getRowArray() ?: [])
            : [];
        $department = ! empty($emp['department_id'])
            ? ($db->table('departments')->where('id', (int) $emp['department_id'])->get()->getRowArray() ?: [])
            : [];
        $branch    = ! empty($emp['branch_id'])
            ? ($db->table('branches')->where('id', (int) $emp['branch_id'])->get()->getRowArray() ?: [])
            : [];
        $workLoc   = ! empty($emp['work_location_id'])
            ? ($db->table('work_locations')->where('id', (int) $emp['work_location_id'])->get()->getRowArray() ?: [])
            : [];
        $supervisor = ! empty($emp['supervisor_id'])
            ? ($db->table('employees')->where('id', (int) $emp['supervisor_id'])->get()->getRowArray() ?: [])
            : [];
        $manager = ! empty($emp['manager_id'])
            ? ($db->table('employees')->where('id', (int) $emp['manager_id'])->get()->getRowArray() ?: [])
            : [];
        $jobGrade = ! empty($emp['job_grade_id'])
            ? ($db->table('job_grades')->where('id', (int) $emp['job_grade_id'])->get()->getRowArray() ?: [])
            : [];

        $contract = [];
        if ($contractId) {
            $contract = $db->table('employee_contracts')->where('id', $contractId)->get()->getRowArray() ?: [];
        }

        $today = date('Y-m-d');
        $context = [
            'today'        => $today,
            'today_long'   => $this->formatDateId($today),
            'company'      => $company,
            'employee'     => $emp,
            'position'     => $position,
            'department'   => $department,
            'branch'       => $branch,
            'work_location'=> $workLoc,
            'supervisor'   => $supervisor,
            'manager'      => $manager,
            'job_grade'    => $jobGrade,
            'contract'     => $contract,
            'salary'       => [
                'basic'      => (float) ($contract['basic_salary'] ?? $emp['basic_salary'] ?? 0),
                'basic_id'   => $this->formatCurrencyId((float) ($contract['basic_salary'] ?? $emp['basic_salary'] ?? 0)),
            ],
        ];

        return array_replace_recursive($context, $extras);
    }

    /**
     * Render template lengkap (header + body + footer + regulations) dengan
     * placeholder yang sudah disubstitusi.
     */
    public function render(array $template, array $context): string
    {
        $header = (string) ($template['header_html'] ?? '');
        $body   = (string) ($template['body_html']   ?? '');
        $footer = (string) ($template['footer_html'] ?? '');

        $regulations = '';
        if (! empty($template['include_regulations'])) {
            $regulations = $this->buildRegulationsHtml((int) ($context['company']['id'] ?? 0));
        }
        $context['regulations'] = $regulations;

        // Placeholder regulasi per kategori akan di-resolve di apply().
        $html = $header . $body . $footer;
        return $this->apply($html, $context);
    }

    public function buildRegulationsHtml(int $companyId, ?string $category = null): string
    {
        if (! $companyId) return '';
        $db = \Config\Database::connect();
        $today = date('Y-m-d');
        $builder = $db->table('company_regulations')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->groupStart()
                ->where('effective_from IS NULL')
                ->orWhere('effective_from <=', $today)
            ->groupEnd()
            ->groupStart()
                ->where('effective_to IS NULL')
                ->orWhere('effective_to >=', $today)
            ->groupEnd()
            ->orderBy('order_no', 'ASC')
            ->orderBy('id', 'ASC');
        if ($category) $builder->where('category', $category);
        $rows = $builder->get()->getResultArray();

        if (empty($rows)) return '';

        $html = '<div class="regulations">';
        $byCat = [];
        foreach ($rows as $r) $byCat[$r['category']][] = $r;
        foreach ($byCat as $cat => $items) {
            $html .= '<h3>' . htmlspecialchars($this->categoryLabel($cat), ENT_QUOTES, 'UTF-8') . '</h3>';
            $html .= '<ol>';
            foreach ($items as $r) {
                $html .= '<li><strong>' . htmlspecialchars($r['title'], ENT_QUOTES, 'UTF-8') . '</strong>'
                      . '<div>' . $r['body_html'] . '</div></li>';
            }
            $html .= '</ol>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Substitusi placeholder. Format:
     *   {{a.b.c}}            → context.a.b.c
     *   {{a.b|filter}}       → filter(context.a.b)
     *   {{regulations.CAT}}  → buildRegulationsHtml(companyId, CAT)
     */
    public function apply(string $template, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)(?:\s*\|\s*([a-zA-Z0-9_]+))?\s*\}\}/',
            function ($m) use ($context) {
                $path   = $m[1];
                $filter = $m[2] ?? null;

                // Khusus regulations.<CATEGORY>
                if (str_starts_with($path, 'regulations.') && $path !== 'regulations.') {
                    $cat = strtoupper(substr($path, strlen('regulations.')));
                    return $this->buildRegulationsHtml((int) ($context['company']['id'] ?? 0), $cat);
                }

                $value = $this->resolvePath($context, $path);
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                $value = $value === null ? '' : (string) $value;

                if ($filter) {
                    $value = $this->applyFilter($filter, $value);
                }
                return $value;
            },
            $template
        ) ?? $template;
    }

    /** Daftar variabel yang tersedia → untuk panel "Variabel" di UI editor. */
    public static function availableVariables(): array
    {
        return [
            'today', 'today_long',
            'company.name', 'company.legal_name', 'company.address', 'company.npwp',
            'employee.full_name', 'employee.first_name', 'employee.last_name',
            'employee.employee_number', 'employee.nik', 'employee.npwp',
            'employee.gender', 'employee.date_of_birth', 'employee.place_of_birth',
            'employee.email', 'employee.phone', 'employee.address',
            'employee.ktp_address', 'employee.kk_number', 'employee.mother_name',
            'employee.education_level', 'employee.citizenship',
            'employee.bpjs_kesehatan_no', 'employee.bpjs_tk_no',
            'employee.bank_name', 'employee.bank_account', 'employee.bank_account_holder',
            'employee.join_date', 'employee.probation_end_date',
            'position.name', 'position.code',
            'department.name', 'department.code',
            'branch.name', 'branch.address',
            'work_location.name', 'work_location.address', 'work_location.type',
            'job_grade.name', 'job_grade.level',
            'supervisor.full_name', 'manager.full_name',
            'contract.contract_number', 'contract.contract_type',
            'contract.start_date', 'contract.end_date', 'contract.basic_salary',
            'salary.basic', 'salary.basic_id',
            'regulations',                // semua klausul aktif
            'regulations.LEAVE',          // per kategori
            'regulations.SALARY',
            'regulations.OVERTIME',
            'regulations.WORKING_HOURS',
            'regulations.DISCIPLINE',
            'regulations.RESIGNATION',
            'regulations.CONFIDENTIALITY',
        ];
    }

    public static function availableFilters(): array
    {
        return [
            'currency_id'  => 'Format Rupiah (Rp 1.234.567)',
            'date_id'      => 'Format tanggal (1 Mei 2026)',
            'date_short'   => 'Format tanggal pendek (01-05-2026)',
            'gender_id'    => 'M → Laki-laki, F → Perempuan',
            'upper'        => 'KAPITAL',
            'lower'        => 'huruf kecil',
            'title'        => 'Judul Kapital',
            'number_id'    => 'Format angka (1.234.567)',
        ];
    }

    private function applyFilter(string $filter, string $value): string
    {
        return match ($filter) {
            'currency_id' => $this->formatCurrencyId((float) $value),
            'number_id'   => number_format((float) $value, 0, ',', '.'),
            'date_id'     => $this->formatDateId($value),
            'date_short'  => $value ? date('d-m-Y', strtotime($value)) : '',
            'gender_id'   => $value === 'M' ? 'Laki-laki' : ($value === 'F' ? 'Perempuan' : $value),
            'upper'       => mb_strtoupper($value),
            'lower'       => mb_strtolower($value),
            'title'       => mb_convert_case($value, MB_CASE_TITLE, 'UTF-8'),
            default       => $value,
        };
    }

    private function resolvePath(array $context, string $path)
    {
        $parts = explode('.', $path);
        $cur   = $context;
        foreach ($parts as $p) {
            if (is_array($cur) && array_key_exists($p, $cur)) {
                $cur = $cur[$p];
            } else {
                return null;
            }
        }
        return $cur;
    }

    private function formatCurrencyId(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }

    private function formatDateId(?string $date): string
    {
        if (! $date) return '';
        $ts = is_numeric($date) ? (int) $date : strtotime($date);
        if (! $ts) return $date;
        $months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        return date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }

    private function categoryLabel(string $cat): string
    {
        return match ($cat) {
            'GENERAL'              => 'Ketentuan Umum',
            'WORKING_HOURS'        => 'Jam Kerja',
            'LEAVE'                => 'Cuti & Izin',
            'SALARY'               => 'Pengupahan',
            'OVERTIME'             => 'Lembur',
            'DISCIPLINE'           => 'Disiplin Kerja',
            'RESIGNATION'          => 'Pengunduran Diri',
            'CONFIDENTIALITY'      => 'Kerahasiaan',
            'INTELLECTUAL_PROPERTY'=> 'Kekayaan Intelektual',
            'BENEFITS'             => 'Tunjangan & Fasilitas',
            'TERMINATION'          => 'Pemutusan Hubungan Kerja',
            'OTHER'                => 'Ketentuan Lain-lain',
            default                => ucwords(strtolower(str_replace('_', ' ', $cat))),
        };
    }
}