<?php

namespace App\Controllers\Api\V1\Notification;

use App\Controllers\Api\V1\ApiController;
use Config\Database;
use Config\Services;

class NotificationBroadcastController extends ApiController
{
    public function options()
    {
        if (! $this->authorized()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $db = Database::connect();

        return $this->ok([
            'roles' => $db->table('roles')->select('id, name, slug')
                ->groupStart()->where('company_id', $companyId)->orWhere('company_id IS NULL')->groupEnd()
                ->orderBy('name')->get()->getResultArray(),
            'departments' => $db->table('departments')->select('id, name')->where('company_id', $companyId)
                ->where('deleted_at IS NULL')->orderBy('name')->get()->getResultArray(),
            'branches' => $db->table('branches')->select('id, name')->where('company_id', $companyId)
                ->where('deleted_at IS NULL')->orderBy('name')->get()->getResultArray(),
        ]);
    }

    public function preview()
    {
        if (! $this->authorized()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        try {
            $users = $this->resolveRecipients($companyId, $this->getJsonInput());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok(['count' => count($users), 'sample' => array_slice($users, 0, 10)]);
    }

    public function send()
    {
        if (! $this->authorized()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $data = $this->getJsonInput();
        $title = trim((string) ($data['title'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        if ($title === '' || mb_strlen($title) > 150) return $this->fail('Judul wajib diisi, maksimal 150 karakter', 422);
        if ($message === '' || mb_strlen($message) > 2000) return $this->fail('Pesan wajib diisi, maksimal 2000 karakter', 422);

        try {
            $users = $this->resolveRecipients($companyId, $data);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        if (! $users) return $this->fail('Tidak ada penerima yang cocok', 422);
        if (count($users) > 5000) return $this->fail('Maksimal 5.000 penerima per broadcast', 422);

        $type = trim((string) ($data['notif_type'] ?? 'broadcast.general')) ?: 'broadcast.general';
        $linkUrl = trim((string) ($data['link_url'] ?? '')) ?: null;
        $sent = 0;
        foreach ($users as $user) {
            Services::notification()->send((int) $user['user_id'], $companyId, $type, $title, $message,
                ['broadcast' => true, 'sent_by_user_id' => $this->userId()], $linkUrl);
            $sent++;
        }

        Services::audit()->forModule('NOTIFICATION')->log(
            action: 'broadcast.sent', entity: 'notifications',
            description: "Broadcast '{$title}' dikirim ke {$sent} user",
            after: ['target_type' => $data['target_type'] ?? null, 'recipient_count' => $sent],
            severity: 'NOTICE',
        );
        return $this->ok(['sent' => $sent], 'Broadcast berhasil dikirim');
    }

    private function resolveRecipients(int $companyId, array $data): array
    {
        $type = strtoupper(trim((string) ($data['target_type'] ?? '')));
        if (! in_array($type, ['ALL', 'ROLE', 'DEPARTMENT', 'BRANCH', 'NIK', 'USER_ID'], true)) {
            throw new \InvalidArgumentException('Target penerima tidak valid');
        }
        $db = Database::connect();
        $b = $db->table('users u')->distinct()->select('u.id AS user_id, u.name, u.email, e.employee_number')
            ->join('employees e', 'e.user_id = u.id', 'left')
            ->where('u.company_id', $companyId)->where('u.is_active', 1);

        if ($type === 'ROLE') {
            $id = (int) ($data['target_id'] ?? 0);
            if (! $id) throw new \InvalidArgumentException('Role wajib dipilih');
            $b->where('u.role_id', $id);
        } elseif ($type === 'DEPARTMENT') {
            $id = (int) ($data['target_id'] ?? 0);
            if (! $id) throw new \InvalidArgumentException('Department wajib dipilih');
            $b->where('e.department_id', $id)->where('e.is_active', 1)->where('e.deleted_at IS NULL');
        } elseif ($type === 'BRANCH') {
            $id = (int) ($data['target_id'] ?? 0);
            if (! $id) throw new \InvalidArgumentException('Branch wajib dipilih');
            $b->where('e.branch_id', $id)->where('e.is_active', 1)->where('e.deleted_at IS NULL');
        } elseif ($type === 'NIK') {
            $values = $this->parseIds((string) ($data['target_values'] ?? ''), false);
            if (! $values) throw new \InvalidArgumentException('Masukkan minimal satu NIK');
            $b->whereIn('e.employee_number', $values);
        } elseif ($type === 'USER_ID') {
            $values = $this->parseIds((string) ($data['target_values'] ?? ''), true);
            if (! $values) throw new \InvalidArgumentException('Masukkan minimal satu user ID');
            $b->whereIn('u.id', $values);
        }
        return $b->orderBy('u.name')->get()->getResultArray();
    }

    private function parseIds(string $input, bool $numeric): array
    {
        $values = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\n;]+/', $input) ?: []))));
        if ($numeric) $values = array_values(array_filter(array_map('intval', $values), fn ($v) => $v > 0));
        return array_slice($values, 0, 5000);
    }

    private function authorized(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('notifications.broadcast');
    }
}
