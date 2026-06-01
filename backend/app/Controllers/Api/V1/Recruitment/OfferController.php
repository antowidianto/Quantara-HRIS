<?php

namespace App\Controllers\Api\V1\Recruitment;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * OfferController — REST API untuk Candidate Offer Letter (Sprint 2 / REC-1).
 *
 * Endpoint:
 *   GET    /candidates/(:num)/offers       — list semua offer untuk satu kandidat
 *   POST   /candidates/(:num)/offers       — create DRAFT offer baru
 *   GET    /offers                          — list cross-candidate (HR dashboard)
 *   GET    /offers/(:num)                   — detail offer
 *   PUT    /offers/(:num)                   — update DRAFT offer
 *   DELETE /offers/(:num)                   — soft-delete (DRAFT only)
 *   POST   /offers/(:num)/send              — DRAFT → SENT
 *   POST   /offers/(:num)/respond           — record response (ACCEPTED/REJECTED) — HR proxy
 *   POST   /offers/(:num)/withdraw          — withdraw (DRAFT/SENT only)
 *
 * Authorization:
 *   - View:    `recruitment.offer.view` ATAU `recruitment.view`
 *   - Manage:  `recruitment.offer.manage` ATAU `recruitment.manage`
 *   - Respond: `recruitment.offer.respond` ATAU `recruitment.offer.manage`
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item REC-1.
 */
class OfferController extends ApiController
{
    /**
     * GET /api/v1/candidates/(:num)/offers
     */
    public function listForCandidate($candidateId = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $candidateId = (int) $candidateId;
        if ($candidateId <= 0) return $this->fail('Invalid candidate id', 400);

        // Verify candidate ownership
        $db = \Config\Database::connect();
        $cand = $db->table('candidates')
            ->where('id', $candidateId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $cand) return $this->fail('Candidate not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $cand['company_id'] !== $this->tenant()->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }

        $rows = $db->table('candidate_offers')
            ->where('candidate_id', $candidateId)
            ->where('deleted_at IS NULL')
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();

        // Decode JSON fields untuk client
        foreach ($rows as &$r) {
            $r['allowances'] = $r['allowances_json']
                ? (json_decode($r['allowances_json'], true) ?: [])
                : [];
            $r['benefits'] = $r['benefits_json']
                ? (json_decode($r['benefits_json'], true) ?: [])
                : [];
        }
        unset($r);

        return $this->ok($rows);
    }

    /**
     * GET /api/v1/offers — list cross-candidate untuk HR dashboard
     */
    public function index()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 30)));
        $status = strtoupper((string) ($this->request->getGet('status') ?? ''));

        $b = $db->table('candidate_offers o')
            ->select('o.*, c.full_name AS candidate_name, c.email AS candidate_email, '
                . 'c.current_stage, p.name AS position_name, d.name AS department_name')
            ->join('candidates c', 'c.id = o.candidate_id')
            ->join('positions p', 'p.id = o.position_id', 'left')
            ->join('departments d', 'd.id = o.department_id', 'left')
            ->where('o.deleted_at IS NULL');
        $this->tenant()->applyScope($b, 'o.company_id');

        if ($status !== '' && in_array($status, \App\Libraries\Recruitment\OfferService::STATUSES, true)) {
            $b->where('o.status', $status);
        }

        $total = $b->countAllResults(false);
        $rows = $b->orderBy('o.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    /**
     * GET /api/v1/offers/(:num)
     */
    public function show($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        try {
            $offer = Services::offer()->loadOffer((int) $id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 404);
        }
        if (! $this->tenant()->isSuperAdmin() && (int) $offer['company_id'] !== $this->tenant()->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($offer);
    }

    /**
     * POST /api/v1/candidates/(:num)/offers
     */
    public function createForCandidate($candidateId = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden — butuh recruitment.offer.manage', 403);
        $data = $this->getJsonInput();
        $data['candidate_id'] = (int) $candidateId;

        try {
            $offer = Services::offer()->create($data, $this->userId());
            return $this->ok($offer, "Offer DRAFT {$offer['offer_number']} dibuat", 201);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'INVALID_INPUT');
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422, 'OFFER_BLOCKED');
        }
    }

    /**
     * PUT /api/v1/offers/(:num)
     */
    public function update($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        try {
            // Verify ownership
            $offer = Services::offer()->loadOffer($id);
            if (! $this->tenant()->isSuperAdmin() && (int) $offer['company_id'] !== $this->tenant()->getCompanyId()) {
                return $this->fail('Forbidden', 403);
            }
            $updated = Services::offer()->update($id, $this->getJsonInput(), $this->userId());
            return $this->ok($updated, 'Offer updated');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * DELETE /api/v1/offers/(:num) — soft-delete DRAFT only
     */
    public function delete($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        try {
            $offer = Services::offer()->loadOffer($id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 404);
        }
        if (! $this->tenant()->isSuperAdmin() && (int) $offer['company_id'] !== $this->tenant()->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }
        if ($offer['status'] !== 'DRAFT') {
            return $this->fail("Hanya offer DRAFT yang bisa dihapus. Untuk SENT, pakai withdraw.", 422);
        }
        $now = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('candidate_offers')->where('id', $id)->update([
            'deleted_at' => $now,
            'updated_at' => $now,
        ]);
        try {
            Services::audit()->forModule('RECRUITMENT')->log(
                action: 'offer.delete',
                entity: 'candidate_offer',
                entityId: $id,
                description: "Hapus offer DRAFT {$offer['offer_number']}",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}
        return $this->ok(null, 'Deleted');
    }

    /**
     * POST /api/v1/offers/(:num)/send
     */
    public function send($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        try {
            $offer = Services::offer()->loadOffer($id);
            if (! $this->tenant()->isSuperAdmin() && (int) $offer['company_id'] !== $this->tenant()->getCompanyId()) {
                return $this->fail('Forbidden', 403);
            }
            $data = $this->getJsonInput();
            $note = isset($data['note']) ? trim((string) $data['note']) : null;
            $sent = Services::offer()->send($id, $this->userId(), $note ?: null);
            return $this->ok($sent, "Offer {$sent['offer_number']} sent ke kandidat");
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/v1/offers/(:num)/respond — HR proxy record kandidat response
     *
     * Body: { decision: 'ACCEPTED'|'REJECTED', note: string|null, rejection_reason: string|null }
     */
    public function respond($id = null)
    {
        if (! $this->canRespond()) return $this->fail('Forbidden — butuh recruitment.offer.respond', 403);
        $id = (int) $id;
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'decision' => 'required|in_list[ACCEPTED,REJECTED]',
            'note'     => 'permit_empty|max_length[2000]',
        ]);
        if (! $valid) return null;

        try {
            $offer = Services::offer()->loadOffer($id);
            if (! $this->tenant()->isSuperAdmin() && (int) $offer['company_id'] !== $this->tenant()->getCompanyId()) {
                return $this->fail('Forbidden', 403);
            }
            $result = Services::offer()->recordResponse(
                $id,
                $valid['decision'],
                $this->userId(),
                $valid['note'] ?? null,
                $data['rejection_reason'] ?? null,
            );
            return $this->ok($result, "Offer response tercatat: {$valid['decision']}");
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/v1/offers/(:num)/withdraw
     */
    public function withdraw($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'reason' => 'required|min_length[5]|max_length[1000]',
        ]);
        if (! $valid) return null;

        try {
            $offer = Services::offer()->loadOffer($id);
            if (! $this->tenant()->isSuperAdmin() && (int) $offer['company_id'] !== $this->tenant()->getCompanyId()) {
                return $this->fail('Forbidden', 403);
            }
            $result = Services::offer()->withdraw($id, $this->userId(), $valid['reason']);
            return $this->ok($result, 'Offer withdrawn');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function canView(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('recruitment.offer.view')
            || $t->hasPermission('recruitment.offer.manage')
            || $t->hasPermission('recruitment.view')
            || $t->hasPermission('recruitment.manage');
    }

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('recruitment.offer.manage')
            || $t->hasPermission('recruitment.manage');
    }

    private function canRespond(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('recruitment.offer.respond')
            || $t->hasPermission('recruitment.offer.manage')
            || $t->hasPermission('recruitment.manage');
    }
}