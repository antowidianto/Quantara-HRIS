<?php

namespace App\Libraries\Approval;

use Config\Database;

/**
 * ApprovalDelegationResolver — pure helper untuk resolve delegasi approver
 * di module yang tidak pakai ApprovalEngine (MPP, Resignation, Transfer, JIC).
 *
 * ApprovalEngine sudah punya resolver internal sendiri lewat
 * `expandWithDelegates()` — resolver ini tidak menggantikan, tapi melengkapi
 * untuk module yang punya custom approval logic.
 *
 * Pemakaian:
 *   $resolver = new ApprovalDelegationResolver();
 *   // Saat user X seharusnya approve, cek apakah dia punya delegasi aktif
 *   if ($resolver->isOnDelegation($userId, 'MPP')) {
 *       $delegateUserId = $resolver->getActiveDelegate($userId, 'MPP');
 *       // Notify delegate; resolveCanApprove kembalikan kedua user_id
 *   }
 *
 *   $approvers = $resolver->resolveCanApprove($expectedUserId, $companyId, 'MPP');
 *   // Returns: [originalUserId, ...delegateUserIds] — semua boleh approve
 *
 * Aturan:
 *   - Delegasi aktif kalau date_from <= today <= date_to & is_active = 1
 *   - Module match: NULL (semua) atau exact match ke module yang dicek
 *   - User asli tetap boleh approve — delegasi tidak menghilangkan kewenangan
 *   - Kalau ada delegasi chain (A→B→C), kita stop di level pertama untuk
 *     hindari recursion infinite (B yang aktif sebagai delegate, dipakai)
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item CC-3.
 */
class ApprovalDelegationResolver
{
    /**
     * Cek apakah user sedang dalam masa delegasi (mendelegasikan ke orang lain)
     * untuk module tertentu.
     */
    public function isOnDelegation(int $userId, string $module = ''): bool
    {
        return $this->getActiveDelegate($userId, $module) !== null;
    }

    /**
     * Get user_id pengganti yang aktif untuk userId saat ini.
     * Kembalikan null kalau tidak ada delegasi aktif.
     *
     * @param int    $userId User asli yang punya kewenangan approval
     * @param string $module Optional filter (MPP, RESIGNATION, JIC, ...).
     *                       Kosong → cek semua delegasi (NULL module).
     */
    public function getActiveDelegate(int $userId, string $module = ''): ?int
    {
        $db = Database::connect();
        if (! $db->tableExists('approval_delegations')) return null;

        $today = date('Y-m-d');
        $b = $db->table('approval_delegations')
            ->select('delegate_to_user_id, module')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->where('date_from <=', $today)
            ->where('date_to >=', $today);

        $rows = $b->get()->getResultArray();
        if (empty($rows)) return null;

        // Prefer match yang spesifik untuk module ini (kalau ada beberapa delegasi
        // aktif, pilih yang module-nya match dulu, baru fallback ke NULL/wildcard).
        if ($module !== '') {
            foreach ($rows as $r) {
                if (($r['module'] ?? '') === $module) {
                    return (int) $r['delegate_to_user_id'];
                }
            }
        }
        // Wildcard delegasi (NULL module) berlaku untuk semua module
        foreach ($rows as $r) {
            if ($r['module'] === null || $r['module'] === '') {
                return (int) $r['delegate_to_user_id'];
            }
        }
        return null;
    }

    /**
     * Daftar semua user_id yang BOLEH approve mewakili expected user untuk
     * module tertentu. Mengembalikan ID-ID dalam urutan:
     *   - User asli (expected) selalu di posisi 0
     *   - Delegate aktif (kalau ada) di posisi 1+
     *
     * Caller yang execute approval cek apakah caller-nya ada di list ini.
     *
     * @return array<int, int> Daftar user_id (deduplicated)
     */
    public function resolveCanApprove(?int $expectedUserId, int $companyId, string $module = ''): array
    {
        if (! $expectedUserId) return [];
        $result = [$expectedUserId];

        $delegate = $this->getActiveDelegate($expectedUserId, $module);
        if ($delegate && $delegate !== $expectedUserId) {
            $result[] = $delegate;
        }

        return array_values(array_unique($result));
    }

    /**
     * Sebaliknya: dari sisi delegate, cari semua user yang sedang
     * mendelegasikan ke dia untuk module tertentu. Dipakai di inbox query
     * untuk menampilkan request yang ditujukan ke "atasan saya yang lagi cuti".
     *
     * @return array<int, int> Daftar user_id pendelegasi
     */
    public function findDelegatorsFor(int $delegateUserId, string $module = ''): array
    {
        $db = Database::connect();
        if (! $db->tableExists('approval_delegations')) return [];

        $today = date('Y-m-d');
        $b = $db->table('approval_delegations')
            ->select('user_id, module')
            ->where('delegate_to_user_id', $delegateUserId)
            ->where('is_active', 1)
            ->where('date_from <=', $today)
            ->where('date_to >=', $today);

        $rows = $b->get()->getResultArray();
        $delegators = [];
        foreach ($rows as $r) {
            $rowModule = $r['module'] ?? '';
            // Match kalau: filter kosong (cek semua), atau wildcard delegation
            // (module di DB null/empty), atau exact match.
            if ($module === ''
                || $rowModule === null
                || $rowModule === ''
                || $rowModule === $module) {
                $delegators[] = (int) $r['user_id'];
            }
        }
        return array_values(array_unique($delegators));
    }

    /**
     * Cek apakah `actorUserId` sah untuk approve atas nama `expectedUserId`
     * di module tertentu. True kalau:
     *   1. actorUserId === expectedUserId (orangnya sendiri), atau
     *   2. actorUserId adalah delegate aktif dari expectedUserId untuk module ini.
     *
     * Dipakai di guard approve() di MppService, ResignationController, dll.
     */
    public function canApproveOnBehalfOf(
        int $actorUserId,
        ?int $expectedUserId,
        int $companyId,
        string $module = '',
    ): bool {
        if (! $expectedUserId) return false;
        if ($actorUserId === $expectedUserId) return true;

        $delegate = $this->getActiveDelegate($expectedUserId, $module);
        return $delegate === $actorUserId;
    }
}