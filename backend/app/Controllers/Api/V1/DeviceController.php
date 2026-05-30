<?php

namespace App\Controllers\Api\V1;

use App\Models\DeviceTokenModel;
use Config\Services;

/**
 * DeviceController — Mobile Prep / MOB-1
 *
 * Endpoint untuk mobile app register / unregister FCM/APNs token.
 * Lihat docs/MOBILE_APP_PLAN.md section 6.2 dan migration #074.
 *
 * Routes (didefinisikan di Config/Routes.php):
 *   POST   /api/v1/devices/register       — pair token ke user
 *   GET    /api/v1/devices                — list device aktif user
 *   DELETE /api/v1/devices/(:num)         — unregister single device
 *   POST   /api/v1/devices/test-push      — kirim test push (dev/QA only)
 */
class DeviceController extends ApiController
{
    public function register()
    {
        $data = $this->getJsonInput();

        $valid = $this->validateOrFail($data, [
            'platform' => 'required|in_list[ANDROID,IOS,WEB]',
            'token'    => 'required|min_length[10]|max_length[512]',
            // Optional fingerprint
            'device_id'    => 'permit_empty|max_length[100]',
            'device_model' => 'permit_empty|max_length[120]',
            'os_version'   => 'permit_empty|max_length[40]',
            'app_version'  => 'permit_empty|max_length[40]',
            'locale'       => 'permit_empty|max_length[10]',
            'timezone'     => 'permit_empty|max_length[60]',
        ]);
        if (! $valid) return null;

        $userId    = $this->userId();
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        // Resolve employee_id (kalau user adalah karyawan) supaya push
        // bisa di-route per employee saat fanout module kirim notif.
        $db = \Config\Database::connect();
        $employee = $db->table('employees')
            ->select('id')
            ->where('user_id', $userId)
            ->where('deleted_at IS NULL')
            ->get()
            ->getRowArray();

        $model = new DeviceTokenModel();
        $id = $model->upsertToken([
            'user_id'      => $userId,
            'company_id'   => $companyId,
            'employee_id'  => $employee['id'] ?? null,
            'platform'     => $data['platform'],
            'token'        => $data['token'],
            'device_id'    => $data['device_id']    ?? null,
            'device_model' => $data['device_model'] ?? null,
            'os_version'   => $data['os_version']   ?? null,
            'app_version'  => $data['app_version']  ?? null,
            'locale'       => $data['locale']       ?? null,
            'timezone'     => $data['timezone']     ?? null,
        ]);

        Services::audit()
            ->forModule('AUTH')
            ->log('device.register', 'device_token', $id,
                "Device {$data['platform']} registered for push (user_id={$userId})");

        return $this->ok(['id' => $id], 'Device registered', 201);
    }

    public function index()
    {
        $userId = $this->userId();
        $model  = new DeviceTokenModel();
        $rows   = $model->where('user_id', $userId)
            ->orderBy('last_seen_at', 'DESC')
            ->findAll();

        // Sanitize: jangan kirim raw token ke client (security — sama
        // seperti kita tidak kirim password_hash).
        $items = array_map(static function (array $r): array {
            return [
                'id'           => (int) $r['id'],
                'platform'     => $r['platform'],
                'device_model' => $r['device_model'],
                'os_version'   => $r['os_version'],
                'app_version'  => $r['app_version'],
                'last_seen_at' => $r['last_seen_at'],
                'revoked_at'   => $r['revoked_at'],
                'is_active'    => empty($r['revoked_at']),
                // 8 char prefix saja untuk identifikasi user
                'token_hint'   => substr((string) $r['token'], 0, 8) . '…',
            ];
        }, $rows);

        return $this->ok($items);
    }

    public function unregister(int $id)
    {
        $userId = $this->userId();
        $model  = new DeviceTokenModel();
        $row    = $model->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if ($row === null) {
            return $this->fail('Device not found', 404, 'NOT_FOUND');
        }
        if (! empty($row['revoked_at'])) {
            return $this->ok(null, 'Already revoked');
        }

        $model->update($id, ['revoked_at' => date('Y-m-d H:i:s')]);

        Services::audit()
            ->forModule('AUTH')
            ->log('device.unregister', 'device_token', $id,
                "Device unregistered (user_id={$userId}, platform={$row['platform']})");

        return $this->ok(null, 'Device unregistered');
    }

    /**
     * Unregister berdasarkan token raw (dipanggil saat mobile logout).
     * Lebih praktis untuk mobile karena tidak perlu simpan device_id
     * server-side — cukup punya FCM token.
     */
    public function unregisterByToken()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'token' => 'required|min_length[10]|max_length[512]',
        ]);
        if (! $valid) return null;

        $userId = $this->userId();
        $model  = new DeviceTokenModel();

        // Hanya boleh revoke token milik user sendiri (defense in depth)
        $row = $model->where('token', $data['token'])
            ->where('user_id', $userId)
            ->first();
        if ($row === null) {
            return $this->ok(null, 'Token not found or already revoked');
        }
        $model->revokeByToken($data['token']);

        return $this->ok(null, 'Device unregistered');
    }
}