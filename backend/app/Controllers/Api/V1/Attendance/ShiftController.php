<?php

namespace App\Controllers\Api\V1\Attendance;

use App\Controllers\Api\V1\MasterCrudController;
use Config\Services;

/**
 * ShiftController
 *
 * CRUD shift dengan dukungan scope branch / work_location / department.
 * Perilaku list:
 *   - Mendukung filter `branch_id`, `work_location_id`, `department_id`,
 *     `is_default`, `is_active` lewat query string.
 *   - Join branch + work_location + department untuk memudahkan UI.
 */
class ShiftController extends MasterCrudController
{
    protected string $table = 'shifts';
    protected array $fillable = [
        'code','name','start_time','end_time','break_minutes',
        'crosses_midnight','late_tolerance_minutes','early_leave_tolerance_minutes',
        'is_active','branch_id','work_location_id','department_id','is_default',
        'color','working_days','description',
    ];
    protected string $auditEntity = 'shift';
    protected array $createRules = [
        'code'       => 'required|alpha_dash|max_length[32]',
        'name'       => 'required|max_length[100]',
        'start_time' => 'required',
        'end_time'   => 'required',
        'color'      => 'permit_empty|max_length[9]',
        'working_days' => 'permit_empty|max_length[32]',
    ];

    /**
     * Override index supaya bisa join dan filter scope.
     */
    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 50)));
        $search = trim((string) $this->request->getGet('search'));

        $branchId = (int) ($this->request->getGet('branch_id') ?? 0);
        $wlId     = (int) ($this->request->getGet('work_location_id') ?? 0);
        $deptId   = (int) ($this->request->getGet('department_id') ?? 0);
        $isActive = $this->request->getGet('is_active');
        $isDefault= $this->request->getGet('is_default');

        $builder = $db->table('shifts s')
            ->select('s.*, b.name AS branch_name, w.name AS work_location_name, d.name AS department_name')
            ->join('branches b', 'b.id = s.branch_id', 'left')
            ->join('work_locations w', 'w.id = s.work_location_id', 'left')
            ->join('departments d', 'd.id = s.department_id', 'left')
            ->where('s.deleted_at IS NULL');

        $this->tenant()->applyScope($builder, 's.company_id');

        if ($branchId) $builder->where('s.branch_id', $branchId);
        if ($wlId)     $builder->where('s.work_location_id', $wlId);
        if ($deptId)   $builder->where('s.department_id', $deptId);
        if ($isActive !== null && $isActive !== '') $builder->where('s.is_active', (int) $isActive);
        if ($isDefault !== null && $isDefault !== '') $builder->where('s.is_default', (int) $isDefault);

        if ($search !== '') {
            $builder->groupStart()
                ->like('s.name', $search)
                ->orLike('s.code', $search)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('s.is_default', 'DESC')
            ->orderBy('s.name', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }
}