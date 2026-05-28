<?php

namespace App\Controllers\Api\V1;

/**
 * JobTitleController — master jabatan terstandarisasi.
 *
 * Tabel `job_titles` menstandarisasi nama jabatan supaya laporan
 * compa-ratio, salary benchmark, dan WLKP tidak terganggu varian
 * penulisan ("Sr Backend Eng" vs "Senior Backend Engineer"). Lihat
 * migration #064 untuk detail.
 *
 * Authorization:
 *   - List/show: `job_title.view` ATAU permission master umum
 *   - Create/update/delete: `job_title.manage`
 *
 * Endpoint dipasang via Routes.php sebagai resource standar plus
 * /picker untuk dropdown UI.
 */
class JobTitleController extends MasterCrudController
{
    protected string $table = 'job_titles';
    protected array $fillable = ['code', 'name', 'family', 'level', 'description', 'is_active'];
    protected string $auditEntity = 'job_title';

    protected array $createRules = [
        'code'   => 'required|alpha_dash|max_length[32]',
        'name'   => 'required|max_length[200]',
        'family' => 'permit_empty|max_length[100]',
        'level'  => 'permit_empty|in_list[JUNIOR,MID,SENIOR,LEAD,MANAGER,DIRECTOR,EXECUTIVE]',
    ];

    protected array $updateRules = [
        'code'   => 'permit_empty|alpha_dash|max_length[32]',
        'name'   => 'permit_empty|max_length[200]',
        'family' => 'permit_empty|max_length[100]',
        'level'  => 'permit_empty|in_list[JUNIOR,MID,SENIOR,LEAD,MANAGER,DIRECTOR,EXECUTIVE]',
    ];

    protected function searchableColumns(): array
    {
        return ['name', 'code', 'family'];
    }

    /**
     * GET /job-titles/picker
     *
     * Endpoint ringkas untuk dropdown — tidak paginated, hanya yang aktif,
     * dan memuat hitungan position + employee per title untuk konteks.
     */
    public function picker()
    {
        $db = \Config\Database::connect();
        $builder = $db->table('job_titles jt')
            ->select('jt.id, jt.code, jt.name, jt.family, jt.level,'
                . ' (SELECT COUNT(*) FROM positions p WHERE p.job_title_id = jt.id AND p.deleted_at IS NULL) AS position_count,'
                . ' (SELECT COUNT(*) FROM employees e WHERE e.job_title_id = jt.id AND e.deleted_at IS NULL AND e.is_active = 1) AS employee_count')
            ->where('jt.deleted_at IS NULL')
            ->where('jt.is_active', 1);

        $this->tenant()->applyScope($builder, 'jt.company_id');

        $family = trim((string) $this->request->getGet('family'));
        $level  = trim((string) $this->request->getGet('level'));
        $search = trim((string) $this->request->getGet('search'));

        if ($family !== '') $builder->where('jt.family', $family);
        if ($level !== '' &&
            in_array($level, ['JUNIOR','MID','SENIOR','LEAD','MANAGER','DIRECTOR','EXECUTIVE'], true)) {
            $builder->where('jt.level', $level);
        }
        if ($search !== '') {
            $builder->groupStart()
                ->like('jt.name', $search)
                ->orLike('jt.code', $search)
                ->orLike('jt.family', $search)
                ->groupEnd();
        }

        $rows = $builder->orderBy('jt.family', 'ASC')->orderBy('jt.name', 'ASC')->get()->getResultArray();

        // Group by family untuk UI dropdown grouped
        $byFamily = [];
        foreach ($rows as $r) {
            $f = $r['family'] ?: 'Tanpa Kategori';
            $byFamily[$f][] = [
                'id'             => (int) $r['id'],
                'code'           => $r['code'],
                'name'           => $r['name'],
                'level'          => $r['level'],
                'position_count' => (int) $r['position_count'],
                'employee_count' => (int) $r['employee_count'],
            ];
        }

        return $this->ok([
            'items'    => $rows,
            'grouped'  => $byFamily,
            'total'    => count($rows),
        ]);
    }

    /**
     * GET /job-titles/(:num)/usage
     *
     * Detail pemakaian: position mana saja yang punya title ini, dan
     * berapa employee di tiap position. Berguna sebelum HR memutuskan
     * delete/rename title.
     */
    public function usage($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $title = $db->table('job_titles')->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $title) return $this->fail('Job title tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $title['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        $positions = $db->table('positions p')
            ->select('p.id, p.code, p.name, p.tier, p.is_active,'
                . ' (SELECT COUNT(*) FROM employees e WHERE e.position_id = p.id AND e.deleted_at IS NULL AND e.is_active = 1) AS employee_count')
            ->where('p.job_title_id', $id)
            ->where('p.deleted_at IS NULL')
            ->orderBy('p.name', 'ASC')
            ->get()->getResultArray();

        $totalEmployees = $db->table('employees')
            ->where('job_title_id', $id)
            ->where('deleted_at IS NULL')
            ->where('is_active', 1)
            ->countAllResults();

        return $this->ok([
            'job_title'        => $title,
            'positions'        => $positions,
            'position_count'   => count($positions),
            'employee_count'   => $totalEmployees,
        ]);
    }
}