<?php

namespace App\Controllers\Api\V1\Master;

use App\Controllers\Api\V1\MasterCrudController;
/**
 * PositionController — master posisi (slot organisasi).
 *
 * Catatan: `position` di sini adalah slot/role di department (mis.
 * "Backend Engineer di Tim Platform"), sementara `job_title` (sejak
 * migration #064) adalah label jabatan terstandarisasi yang reusable
 * lintas department/team. Satu job_title bisa dipakai banyak position.
 */
class PositionController extends MasterCrudController
{
    protected string $table = 'positions';
    protected array $fillable = [
        'department_id', 'job_grade_id', 'job_title_id',
        'code', 'name', 'description', 'tier', 'is_active',
    ];
    protected string $auditEntity = 'position';
    protected array $createRules = [
        'code'         => 'required|alpha_dash|max_length[32]',
        'name'         => 'required|max_length[200]',
        'tier'         => 'permit_empty|in_list[MANAGER,ASST_MANAGER,SUPERVISOR,STAFF]',
        'job_title_id' => 'permit_empty|integer|greater_than[0]',
    ];
    protected array $updateRules = [
        'code'         => 'permit_empty|alpha_dash|max_length[32]',
        'name'         => 'permit_empty|max_length[200]',
        'tier'         => 'permit_empty|in_list[MANAGER,ASST_MANAGER,SUPERVISOR,STAFF]',
        'job_title_id' => 'permit_empty|integer|greater_than[0]',
    ];

    /**
     * Override index supaya join ke job_titles & departments untuk
     * menampilkan nama yang lebih informatif di UI list.
     */
    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $search = trim((string) $this->request->getGet('search'));
        $deptId = (int) ($this->request->getGet('department_id') ?? 0);
        $jobTitleId = (int) ($this->request->getGet('job_title_id') ?? 0);

        $builder = $db->table('positions p')
            ->select('p.*, d.name AS department_name, jg.name AS job_grade_name,'
                . ' jt.name AS job_title_name, jt.family AS job_title_family,'
                . ' jt.level AS job_title_level')
            ->join('departments d', 'd.id = p.department_id', 'left')
            ->join('job_grades jg', 'jg.id = p.job_grade_id', 'left')
            ->join('job_titles jt', 'jt.id = p.job_title_id', 'left')
            ->where('p.deleted_at IS NULL');

        $this->tenant()->applyScope($builder, 'p.company_id');

        if ($search !== '') {
            $builder->groupStart()
                ->like('p.name', $search)
                ->orLike('p.code', $search)
                ->orLike('jt.name', $search)
                ->groupEnd();
        }
        if ($deptId)     $builder->where('p.department_id', $deptId);
        if ($jobTitleId) $builder->where('p.job_title_id', $jobTitleId);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('p.name', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }
}
