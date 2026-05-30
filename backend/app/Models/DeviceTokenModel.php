<?php

namespace App\Models;

/**
 * DeviceTokenModel — Mobile Prep / MOB-1
 *
 * Registry FCM/APNs token per user untuk push notification fanout.
 * Lihat docs/MOBILE_APP_PLAN.md section 6.2 #3 dan migration #074.
 *
 * Convention pemakaian:
 *
 *   // Mobile app saat login berhasil
 *   $deviceModel->upsertToken([
 *       'user_id'     => $userId,
 *       'employee_id' => $employeeId,
 *       'platform'    => 'ANDROID',
 *       'token'       => $fcmToken,
 *       'device_id'   => $androidId,
 *       ...
 *   ]);
 *
 *   // NotificationDispatcher saat fanout
 *   foreach ($deviceModel->activeForUser($userId) as $device) { ... }
 *
 *   // Logout single device
 *   $deviceModel->revokeByToken($fcmToken);
 *
 *   // Sign-out-all
 *   $deviceModel->revokeAllForUser($userId);
 */
class DeviceTokenModel extends BaseModel
{
    protected $table         = 'device_tokens';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'company_id', 'user_id', 'employee_id',
        'platform', 'token', 'device_id', 'device_model',
        'os_version', 'app_version', 'locale', 'timezone',
        'last_seen_at', 'revoked_at', 'failure_count',
    ];

    protected bool $multiTenant = true;

    /**
     * Insert atau update token. Dipakai mobile saat login atau saat
     * FCM rotate token (callback `onNewToken`). Token unik global,
     * jadi kalau sudah ada di user lain (mis. user pinjam HP), entry
     * lama di-revoke + entry baru dibuat.
     *
     * @param array{
     *   user_id:int,
     *   company_id:int,
     *   employee_id?:int|null,
     *   platform:string,
     *   token:string,
     *   device_id?:string|null,
     *   device_model?:string|null,
     *   os_version?:string|null,
     *   app_version?:string|null,
     *   locale?:string|null,
     *   timezone?:string|null,
     * } $data
     */
    public function upsertToken(array $data): int
    {
        $existing = $this->where('token', $data['token'])->first();
        $now      = date('Y-m-d H:i:s');

        if ($existing !== null) {
            // Token sudah ada — bisa milik user yang sama (re-login) atau
            // user lain (HP pindah tangan). Update record saja.
            $update = array_merge($data, [
                'last_seen_at'  => $now,
                'revoked_at'    => null,
                'failure_count' => 0,
            ]);
            $this->update((int) $existing['id'], $update);
            return (int) $existing['id'];
        }

        $payload = array_merge($data, [
            'last_seen_at'  => $now,
            'failure_count' => 0,
        ]);
        return (int) $this->insert($payload, true);
    }

    /**
     * Daftar token aktif untuk satu user (untuk fanout push).
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeForUser(int $userId): array
    {
        return $this->where('user_id', $userId)
            ->where('revoked_at', null)
            ->findAll();
    }

    /**
     * Daftar token aktif untuk banyak user sekaligus.
     *
     * @param array<int,int> $userIds
     * @return array<int,array<string,mixed>>
     */
    public function activeForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        return $this->whereIn('user_id', $userIds)
            ->where('revoked_at', null)
            ->findAll();
    }

    /**
     * Revoke single token (mobile logout).
     */
    public function revokeByToken(string $token): bool
    {
        return $this->where('token', $token)
            ->set(['revoked_at' => date('Y-m-d H:i:s')])
            ->update();
    }

    /**
     * Revoke semua token milik user (sign-out-all-devices).
     */
    public function revokeAllForUser(int $userId): int
    {
        $this->where('user_id', $userId)
            ->where('revoked_at', null)
            ->set(['revoked_at' => date('Y-m-d H:i:s')])
            ->update();
        return (int) $this->db->affectedRows();
    }

    /**
     * Tandai token failed (FCM 410 Unregistered / APNs BadDeviceToken).
     * Auto-revoke saat counter ≥ 3 supaya backend tidak terus-terusan
     * coba kirim ke device yang sudah uninstall app.
     */
    public function markFailure(string $token): void
    {
        $row = $this->where('token', $token)->first();
        if ($row === null) {
            return;
        }
        $count = ((int) ($row['failure_count'] ?? 0)) + 1;
        $patch = ['failure_count' => $count];
        if ($count >= 3) {
            $patch['revoked_at'] = date('Y-m-d H:i:s');
        }
        $this->update((int) $row['id'], $patch);
    }
}