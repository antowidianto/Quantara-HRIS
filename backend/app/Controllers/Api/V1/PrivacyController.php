<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * PrivacyController — endpoint UU PDP (Sprint 4).
 *
 * Self-service untuk Subjek Data:
 *   GET  /me/privacy/policy-version  — versi kebijakan saat ini
 *   GET  /me/privacy/consents        — riwayat persetujuan
 *   POST /me/privacy/consents        — record consent baru
 *   GET  /me/data-export             — download seluruh data pribadi (JSON)
 *   POST /me/data-deletion-request   — ajukan penghapusan data
 *
 * Untuk HR (perlu permission `privacy.review`):
 *   GET  /admin/privacy/deletion-requests
 *   POST /admin/privacy/deletion-requests/(:num)/review
 *   POST /admin/privacy/deletion-requests/(:num)/process
 *
 * Output endpoint export di-throttle di route via `throttle:export` supaya
 * tidak bisa di-loop untuk DoS.
 */
class PrivacyController extends ApiController
{
    public function policyVersion()
    {
        return $this->ok([
            'version'   => \App\Libraries\DataPrivacyService::CURRENT_POLICY_VERSION,
            'consent_types' => \App\Libraries\DataPrivacyService::CONSENT_TYPES,
        ]);
    }

    public function consents()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Unauthenticated', 401);
        $rows = Services::dataPrivacy()->consentHistory($userId);
        return $this->ok($rows);
    }

    public function recordConsent()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Unauthenticated', 401);

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'consent_type' => 'required|max_length[50]',
            'version'      => 'required|max_length[32]',
            'granted'      => 'permit_empty',
        ]);
        if (! $valid) return null;

        try {
            $id = Services::dataPrivacy()->recordConsent(
                userId: (int) $userId,
                consentType: (string) $data['consent_type'],
                version: (string) $data['version'],
                granted: ! isset($data['granted']) || (bool) $data['granted'],
                companyId: $this->tenant()->getCompanyId() ?: null,
                ip: $this->request->getIPAddress(),
                userAgent: (string) $this->request->getUserAgent(),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'INVALID_CONSENT_TYPE');
        }
        return $this->ok(['id' => $id], 'Consent recorded', 201);
    }

    /**
     * GET /me/data-export
     *
     * Stream JSON file dengan filename eksplisit supaya browser auto-save.
     * Throttle:export sudah apply di route untuk cegah loop.
     */
    public function dataExport()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Unauthenticated', 401);

        $bundle = Services::dataPrivacy()->exportUserData((int) $userId);
        $filename = sprintf('data-export-user%d-%s.json', $userId, date('Ymd_His'));

        return $this->response
            ->setHeader('Content-Type', 'application/json; charset=UTF-8')
            ->setHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->setBody(json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function requestDeletion()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Unauthenticated', 401);

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'reason' => 'permit_empty|max_length[2000]',
        ]);
        if (! $valid) return null;

        $id = Services::dataPrivacy()->requestDeletion(
            (int) $userId,
            $this->tenant()->getCompanyId() ?: null,
            $data['reason'] ?? null,
        );
        return $this->ok([
            'id' => $id,
            'note' => 'Permintaan penghapusan dikirim ke HR untuk review. ' .
                      'Anda akan diberi notifikasi setelah keputusan dibuat.',
        ], 'Deletion requested', 201);
    }

    // ─── HR endpoints ──────────────────────────────────────────────

    public function listRequests()
    {
        if (! $this->tenant()->hasPermission('privacy.review') && ! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Forbidden', 403);
        }
        $db = \Config\Database::connect();
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $status  = trim((string) $this->request->getGet('status'));

        $builder = $db->table('data_deletion_requests d')
            ->select('d.*, u.email AS user_email, u.name AS user_name')
            ->join('users u', 'u.id = d.user_id', 'left');

        // Multi-tenant: super admin lihat semua, HR cabang scope ke company
        if (! $this->tenant()->isSuperAdmin()) {
            $builder->where('d.company_id', $this->tenant()->getCompanyId());
        }
        if ($status !== '') $builder->where('d.status', $status);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('d.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function reviewRequest($id = null)
    {
        if (! $this->tenant()->hasPermission('privacy.review') && ! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Forbidden', 403);
        }
        $id = (int) $id;
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'approve' => 'required',
            'note'    => 'permit_empty|max_length[2000]',
        ]);
        if (! $valid) return null;

        try {
            Services::dataPrivacy()->reviewDeletion(
                $id,
                (int) $this->userId(),
                (bool) $data['approve'],
                $data['note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 404);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok(null, 'Reviewed');
    }

    public function processRequest($id = null)
    {
        if (! $this->tenant()->hasPermission('privacy.review') && ! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Forbidden', 403);
        }
        $id = (int) $id;
        try {
            $stats = Services::dataPrivacy()->processApproved($id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 404);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($stats, 'Anonimisasi selesai');
    }
}