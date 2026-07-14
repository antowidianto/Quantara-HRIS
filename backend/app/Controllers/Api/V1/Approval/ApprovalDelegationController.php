<?php

namespace App\Controllers\Api\V1\Approval;

use App\Controllers\Api\V1\ApiController;
use App\Models\Identity\UserModel;
use Config\Services;

/**
 * ApprovalDelegationController
 *
 * Kelola delegasi approval. Setiap user boleh menulis delegasinya sendiri
 * (`delegation.manage_self`); HR/admin boleh manage milik orang lain
 * (`delegation.manage`).
 *
 * Filter list:
 *   - mine=1     → hanya delegasi yang user_id = current user
 *   - active=1   → hanya yang sedang aktif (in date range + is_active)
 *   - module=    → filter modul tertentu
 */
class ApprovalDelegationController extends ApiController
{
    /** Minimal same-tenant directory used by the self-service delegate picker. */
    public function eligibleUsers()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->fail('Company context tidak tersedia', 422);

        $rows = (new UserModel())->eligibleDelegates($companyId, $this->userId());

        return $this->ok($rows);
    }

    public function index()
    {
        $db = \Config\Database::connect();
        $tenant = $this->tenant();
        $mine   = (int) ($this->request->getGet('mine') ?? 0) === 1;
        $active = (int) ($this->request->getGet('active') ?? 0) === 1;
        $module = trim((string) $this->request->getGet('module'));

        $builder = $db->table('approval_delegations d')
            ->select('d.*, u.name AS user_name, u.email AS user_email,'
                . ' du.name AS delegate_name, du.email AS delegate_email')
            ->join('users u', 'u.id = d.user_id', 'left')
            ->join('users du', 'du.id = d.delegate_to_user_id', 'left');

        $tenant->applyScope($builder, 'd.company_id');

        // Pegawai biasa (tidak punya delegation.view) hanya bisa lihat
        // delegasi miliknya sendiri ATAU di mana dia jadi delegate.
        if (! $tenant->isSuperAdmin() && ! $tenant->hasPermission('delegation.view')) {
            $userId = (int) $tenant->getUserId();
            $builder->groupStart()
                ->where('d.user_id', $userId)
                ->orWhere('d.delegate_to_user_id', $userId)
                ->groupEnd();
        } elseif ($mine) {
            $builder->where('d.user_id', (int) $tenant->getUserId());
        }

        if ($active) {
            $today = date('Y-m-d');
            $builder->where('d.is_active', 1)
                ->where('d.date_from <=', $today)
                ->where('d.date_to >=', $today);
        }
        if ($module !== '') {
            $builder->groupStart()
                ->where('d.module', $module)
                ->orWhere('d.module IS NULL')
                ->groupEnd();
        }

        $rows = $builder->orderBy('d.is_active', 'DESC')
            ->orderBy('d.date_from', 'DESC')
            ->get()->getResultArray();

        return $this->ok($rows);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $this->findForTenant($db, (int) $id);
        if (! $row) return $this->fail('Not found', 404);
        if (! $this->canTouch($row)) return $this->fail('Forbidden', 403);
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'delegate_to_user_id' => 'required|integer',
            'date_from'           => 'required|valid_date',
            'date_to'             => 'required|valid_date',
        ]);
        if (! $valid) return null;

        $tenant = $this->tenant();
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        // Default user_id = current user. HR/admin boleh memberi user_id lain.
        $userId = (int) ($data['user_id'] ?? $tenant->getUserId());
        if ($userId !== (int) $tenant->getUserId()
            && ! $tenant->isSuperAdmin()
            && ! $tenant->hasPermission('delegation.manage')
        ) {
            return $this->fail('Anda hanya boleh membuat delegasi untuk diri sendiri', 403);
        }

        $delegateTo = (int) $data['delegate_to_user_id'];
        if ($delegateTo === $userId) {
            return $this->fail('Anda tidak bisa mendelegasikan ke diri sendiri', 422, 'SELF_DELEGATION');
        }
        if ($data['date_to'] < $data['date_from']) {
            return $this->fail('Tanggal selesai harus >= tanggal mulai', 422);
        }

        $db = \Config\Database::connect();
        $userCount = $db->table('users')
            ->where('company_id', $companyId)
            ->whereIn('id', [$userId, $delegateTo])
            ->countAllResults();
        if ($userCount !== 2) {
            return $this->fail('Delegator dan penerima delegasi harus berasal dari perusahaan yang sama', 422, 'CROSS_TENANT_DELEGATION');
        }
        $module = trim((string) ($data['module'] ?? ''));
        $allowedModules = [
            'LEAVE', 'OVERTIME', 'ATTENDANCE_CHANGE', 'REIMBURSEMENT', 'LOAN',
            'MPP', 'RESIGNATION', 'TRANSFER', 'JIC', 'GENERIC',
        ];
        if ($module !== '' && ! in_array($module, $allowedModules, true)) {
            return $this->fail('Modul tidak valid', 422);
        }

        // Cek duplikasi: user yg sama, delegate yg sama, modul yg sama,
        // rentang yg overlap → tolak.
        $overlap = $db->table('approval_delegations')
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('delegate_to_user_id', $delegateTo)
            ->groupStart()
                ->where('module', $module ?: null)
                ->orWhere('module IS NULL')
            ->groupEnd()
            ->where('is_active', 1)
            ->where('date_from <=', $data['date_to'])
            ->where('date_to >=', $data['date_from'])
            ->countAllResults();
        if ($overlap > 0) {
            return $this->fail(
                'Sudah ada delegasi aktif untuk kombinasi yang sama di rentang tanggal yang overlap.',
                422, 'DUPLICATE_DELEGATION'
            );
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'          => $companyId,
            'user_id'             => $userId,
            'delegate_to_user_id' => $delegateTo,
            'module'              => $module ?: null,
            'date_from'           => $data['date_from'],
            'date_to'             => $data['date_to'],
            'reason'              => $data['reason'] ?? null,
            'is_active'           => (int) ($data['is_active'] ?? 1),
            'created_at'          => $now,
            'updated_at'          => $now,
        ];
        $db->table('approval_delegations')->insert($insert);
        $id = (int) $db->insertID();

        Services::audit()
            ->forModule('APPROVAL')
            ->logCreate('approval_delegation', $id, $insert,
                "Delegasi approval user #{$userId} → user #{$delegateTo} ({$insert['date_from']} s/d {$insert['date_to']})");

        return $this->ok(['id' => $id], 'Delegasi dibuat', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $this->findForTenant($db, $id);
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->canTouch($existing)) return $this->fail('Forbidden', 403);

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'delegate_to_user_id','module','date_from','date_to','reason','is_active',
        ]));
        if (isset($update['date_from']) && isset($update['date_to'])
            && $update['date_to'] < $update['date_from']) {
            return $this->fail('Tanggal selesai harus >= tanggal mulai', 422);
        }
        if (isset($update['module']) && $update['module'] === '') {
            $update['module'] = null;
        }
        if (isset($update['delegate_to_user_id'])) {
            $delegateId = (int) $update['delegate_to_user_id'];
            if ($delegateId === (int) $existing['user_id']) {
                return $this->fail('Anda tidak bisa mendelegasikan ke diri sendiri', 422, 'SELF_DELEGATION');
            }
            $delegateExists = $db->table('users')
                ->where('id', $delegateId)
                ->where('company_id', (int) $existing['company_id'])
                ->countAllResults();
            if ($delegateExists !== 1) {
                return $this->fail('Penerima delegasi harus berasal dari perusahaan yang sama', 422, 'CROSS_TENANT_DELEGATION');
            }
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('approval_delegations')->where('id', $id)->where('company_id', (int) $existing['company_id'])->update($update);

        Services::audit()
            ->forModule('APPROVAL')
            ->logUpdate('approval_delegation', $id,
                array_intersect_key($existing, $update), $update,
                "Mengubah delegasi #{$id}");
        return $this->ok(['id' => $id], 'Updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $this->findForTenant($db, $id);
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->canTouch($existing)) return $this->fail('Forbidden', 403);

        $db->table('approval_delegations')->where('id', $id)->where('company_id', (int) $existing['company_id'])->delete();
        Services::audit()
            ->forModule('APPROVAL')
            ->logDelete('approval_delegation', $id, $existing,
                "Menghapus delegasi user #{$existing['user_id']} → user #{$existing['delegate_to_user_id']}");
        return $this->ok(null, 'Deleted');
    }

    /**
     * Helper: cek apakah user boleh ubah/hapus row tertentu.
     */
    private function canTouch(array $row): bool
    {
        $tenant = $this->tenant();
        if ($tenant->isSuperAdmin()) return true;
        if ((int) $row['company_id'] !== $this->companyId()) return false;
        if ($tenant->hasPermission('delegation.manage')) return true;
        // Pemilik delegasi sendiri
        return (int) $row['user_id'] === (int) $tenant->getUserId();
    }

    private function findForTenant(\CodeIgniter\Database\BaseConnection $db, int $id): ?array
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) return null;

        return $db->table('approval_delegations')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->get()
            ->getRowArray();
    }
}
