<?php

namespace App\Controllers\Api\V1;

class NotificationController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $unread = (string) $this->request->getGet('unread');

        $builder = $db->table('notifications')->where('user_id', $this->userId());
        if ($unread === '1') $builder->where('is_read', 0);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('id', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function unreadCount()
    {
        $db = \Config\Database::connect();
        $count = $db->table('notifications')->where('user_id', $this->userId())->where('is_read', 0)->countAllResults();
        return $this->ok(['count' => $count]);
    }

    public function markRead($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $db->table('notifications')->where('id', $id)->where('user_id', $this->userId())->update([
            'is_read' => 1,
            'read_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(null, 'Marked as read');
    }

    public function markAllRead()
    {
        $db = \Config\Database::connect();
        $db->table('notifications')->where('user_id', $this->userId())->where('is_read', 0)->update([
            'is_read' => 1,
            'read_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(null, 'All marked as read');
    }
}