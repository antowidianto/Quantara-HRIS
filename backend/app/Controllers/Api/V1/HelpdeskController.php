<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * HelpdeskController
 *
 * Endpoint untuk modul HR Helpdesk / Ticket System.
 *
 * Permission:
 *   helpdesk.view       — semua role: lihat tiket sendiri
 *   helpdesk.view_all   — HR/admin: lihat semua tiket
 *   helpdesk.handle     — HR/admin: assign, change status, close, reply
 *   helpdesk.internal   — HR/admin: lihat & post komentar internal
 */
class HelpdeskController extends ApiController
{
    private const SLA_HOURS = [
        'URGENT' => 4,
        'HIGH'   => 24,
        'MEDIUM' => 72,
        'LOW'    => 168, // 1 minggu
    ];

    private const CATEGORIES = [
        'PAYROLL','LEAVE','ATTENDANCE','BENEFITS','CONTRACT','IT','FACILITY','OTHER',
    ];

    private function db() { return \Config\Database::connect(); }

    private function can(string $slug): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission($slug);
    }

    private function getOwnEmployeeId(): ?int
    {
        $empId = (int) $this->tenant()->getEmployeeId();
        if ($empId > 0) return $empId;
        $userId = (int) $this->tenant()->getUserId();
        if (! $userId) return null;
        $emp = $this->db()->table('employees')->where('user_id', $userId)
            ->where('deleted_at IS NULL')->limit(1)->get()->getRowArray();
        return $emp ? (int) $emp['id'] : null;
    }

    private function nextTicketNumber(int $companyId): string
    {
        $year = date('Y');
        $prefix = "TKT-{$year}-";
        $count = $this->db()->table('hr_tickets')
            ->where('company_id', $companyId)
            ->like('ticket_number', $prefix, 'after')->countAllResults();
        do {
            $count++;
            $candidate = $prefix . str_pad((string) $count, 5, '0', STR_PAD_LEFT);
            $exists = $this->db()->table('hr_tickets')
                ->where('company_id', $companyId)
                ->where('ticket_number', $candidate)->countAllResults();
        } while ($exists > 0 && $count < 99999);
        return $candidate;
    }

    private function slaDueAt(string $priority, string $createdAt): string
    {
        $hours = self::SLA_HOURS[$priority] ?? 72;
        return date('Y-m-d H:i:s', strtotime($createdAt) + ($hours * 3600));
    }

    /** GET /helpdesk/tickets */
    public function index()
    {
        if (! $this->can('helpdesk.view')) return $this->fail('Forbidden', 403);

        $perPage = max(1, min(100, (int) ($this->request->getGet('per_page') ?? 20)));
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $q       = trim((string) $this->request->getGet('q'));
        $status  = trim((string) $this->request->getGet('status'));
        $cat     = trim((string) $this->request->getGet('category'));
        $mine    = (int) ($this->request->getGet('mine') ?? 0);

        $b = $this->db()->table('hr_tickets t')
            ->select('t.*, e.full_name AS employee_name, e.employee_number,'
                . ' au.name AS assignee_name')
            ->join('employees e', 'e.id = t.employee_id', 'left')
            ->join('users au', 'au.id = t.assignee_user_id', 'left')
            ->where('t.deleted_at IS NULL');
        $this->tenant()->applyScope($b, 't.company_id');

        // Authorization scope: kalau tidak punya view_all → only own
        $myEmpId = $this->getOwnEmployeeId();
        if (! $this->can('helpdesk.view_all') || $mine === 1) {
            if (! $myEmpId) return $this->paginated([], 0, $page, $perPage);
            $b->where('t.employee_id', $myEmpId);
        }

        if ($q !== '') {
            $b->groupStart()
                ->like('t.subject', $q)
                ->orLike('t.body', $q)
                ->orLike('t.ticket_number', $q)
                ->orLike('e.full_name', $q)
              ->groupEnd();
        }
        if ($status !== '') $b->where('t.status', $status);
        if ($cat !== '') $b->where('t.category', $cat);

        $total = $b->countAllResults(false);
        $rows = $b->orderBy('t.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /** POST /helpdesk/tickets */
    public function create()
    {
        if (! $this->can('helpdesk.view')) return $this->fail('Forbidden', 403);

        $myEmpId = $this->getOwnEmployeeId();
        $userId  = (int) $this->tenant()->getUserId();
        if (! $myEmpId) return $this->fail('Employee profile tidak ditemukan untuk user ini', 400);

        $data = $this->getJsonInput();
        $data = $this->validateOrFail($data, [
            'subject'  => 'required|min_length[5]|max_length[200]',
            'body'     => 'required|min_length[10]',
            'category' => 'required|in_list[' . implode(',', self::CATEGORIES) . ']',
        ]);
        if ($data === null) return $this->response;

        $priority = strtoupper((string) ($data['priority'] ?? 'MEDIUM'));
        if (! in_array($priority, ['LOW','MEDIUM','HIGH','URGENT'], true)) $priority = 'MEDIUM';

        $emp = $this->db()->table('employees')->where('id', $myEmpId)->get()->getRowArray();
        $companyId = (int) ($emp['company_id'] ?? $this->tenant()->getCompanyId());
        $now = date('Y-m-d H:i:s');

        $insert = [
            'company_id'      => $companyId,
            'ticket_number'   => $this->nextTicketNumber($companyId),
            'employee_id'     => $myEmpId,
            'created_by_user_id' => $userId,
            'subject'         => $data['subject'],
            'body'            => $data['body'],
            'category'        => $data['category'],
            'priority'        => $priority,
            'attachment_path' => $data['attachment_path'] ?? null,
            'status'          => 'OPEN',
            'sla_due_at'      => $this->slaDueAt($priority, $now),
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $this->db()->table('hr_tickets')->insert($insert);
        $id = (int) $this->db()->insertID();

        // Notif HR (role 1, 2)
        $hrUsers = $this->db()->table('users')
            ->where('company_id', $companyId)
            ->whereIn('role_id', [1, 2])
            ->where('is_active', 1)->get()->getResultArray();
        foreach ($hrUsers as $hr) {
            try {
                Services::notification()->send(
                    (int) $hr['id'], $companyId,
                    'helpdesk.new_ticket',
                    "🎫 Tiket baru: {$insert['ticket_number']}",
                    "{$data['subject']} — Prioritas {$priority}, kategori {$data['category']}.",
                    ['ticket_id' => $id],
                    "/helpdesk/tickets/{$id}", true
                );
            } catch (\Throwable $e) { /* skip */ }
        }

        try {
            Services::audit()->forModule('HELPDESK')->log(
                action: 'ticket.create',
                entity: 'hr_tickets',
                entityId: $id,
                after: $insert,
                description: "Tiket dibuat: {$insert['ticket_number']} — {$data['subject']}",
            );
        } catch (\Throwable $e) {}

        return $this->ok(['id' => $id, 'ticket_number' => $insert['ticket_number']], 'OK', 201);
    }

    /** GET /helpdesk/tickets/(:num) */
    public function show($id = null)
    {
        $id = (int) $id;
        if (! $this->can('helpdesk.view')) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail($id, allowAccess: true);
        if (! is_array($row)) return $this->response;

        $b = $this->db()->table('hr_ticket_comments c')
            ->select('c.*, u.name AS author_name')
            ->join('users u', 'u.id = c.author_user_id', 'left')
            ->where('c.ticket_id', $id);
        if (! $this->can('helpdesk.internal')) {
            $b->where('c.is_internal', 0);
        }
        $comments = $b->orderBy('c.id', 'ASC')->get()->getResultArray();

        $emp = $this->db()->table('employees e')
            ->select('e.id, e.full_name, e.employee_number, e.email, e.phone, d.name AS department_name, b.name AS branch_name')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('branches b', 'b.id = e.branch_id', 'left')
            ->where('e.id', (int) $row['employee_id'])->get()->getRowArray();

        $row['employee'] = $emp;
        $row['comments'] = $comments;
        return $this->ok($row);
    }

    /** PUT /helpdesk/tickets/(:num) */
    public function update($id = null)
    {
        $id = (int) $id;
        $row = $this->loadOrFail($id, allowAccess: true);
        if (! is_array($row)) return $this->response;

        $data = $this->getJsonInput();
        $update = [];
        foreach (['subject','body','category','priority'] as $f) {
            if (array_key_exists($f, $data)) $update[$f] = $data[$f];
        }
        if (! $update) return $this->fail('Tidak ada perubahan', 400);

        $isOwner = ((int) $row['employee_id'] === $this->getOwnEmployeeId());
        $canHandle = $this->can('helpdesk.handle');
        if (! $isOwner && ! $canHandle) return $this->fail('Forbidden', 403);

        if (isset($update['priority']) && in_array($update['priority'], ['LOW','MEDIUM','HIGH','URGENT'], true)) {
            $update['sla_due_at'] = $this->slaDueAt($update['priority'], $row['created_at']);
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        $this->db()->table('hr_tickets')->where('id', $id)->update($update);

        try {
            Services::audit()->forModule('HELPDESK')->log(
                action: 'ticket.update', entity: 'hr_tickets', entityId: $id,
                before: $row, after: array_merge($row, $update),
                description: "Update tiket {$row['ticket_number']}",
            );
        } catch (\Throwable $e) {}
        return $this->ok(['id' => $id]);
    }

    /** DELETE /helpdesk/tickets/(:num) */
    public function delete($id = null)
    {
        $id = (int) $id;
        $row = $this->loadOrFail($id, allowAccess: true);
        if (! is_array($row)) return $this->response;
        $isOwner = ((int) $row['employee_id'] === $this->getOwnEmployeeId());
        if (! $isOwner && ! $this->can('helpdesk.handle')) return $this->fail('Forbidden', 403);

        $this->db()->table('hr_tickets')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);
        try {
            Services::audit()->forModule('HELPDESK')->log(
                action: 'ticket.delete', entity: 'hr_tickets', entityId: $id, before: $row,
                description: "Hapus tiket {$row['ticket_number']}",
            );
        } catch (\Throwable $e) {}
        return $this->ok(['id' => $id]);
    }

    /** POST /helpdesk/tickets/(:num)/assign */
    public function assign($id = null)
    {
        $id = (int) $id;
        if (! $this->can('helpdesk.handle')) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail($id);
        if (! is_array($row)) return $this->response;

        $data = $this->getJsonInput();
        $assigneeId = (int) ($data['assignee_user_id'] ?? 0);
        if (! $assigneeId) return $this->fail('assignee_user_id wajib', 400);

        $now = date('Y-m-d H:i:s');
        $update = [
            'assignee_user_id' => $assigneeId,
            'assigned_at'      => $now,
            'updated_at'       => $now,
        ];
        if ($row['status'] === 'OPEN') $update['status'] = 'IN_PROGRESS';
        $this->db()->table('hr_tickets')->where('id', $id)->update($update);

        try {
            Services::notification()->send(
                $assigneeId, (int) $row['company_id'],
                'helpdesk.assigned',
                "📌 Tiket di-assign ke Anda: {$row['ticket_number']}",
                $row['subject'],
                ['ticket_id' => $id],
                "/helpdesk/tickets/{$id}", true
            );
        } catch (\Throwable $e) {}

        try {
            Services::audit()->forModule('HELPDESK')->log(
                action: 'ticket.assign', entity: 'hr_tickets', entityId: $id,
                before: $row, after: array_merge($row, $update),
                description: "Tiket {$row['ticket_number']} di-assign ke user #{$assigneeId}",
            );
        } catch (\Throwable $e) {}

        return $this->ok(['id' => $id]);
    }

    /** POST /helpdesk/tickets/(:num)/status */
    public function changeStatus($id = null)
    {
        $id = (int) $id;
        if (! $this->can('helpdesk.handle')) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail($id);
        if (! is_array($row)) return $this->response;

        $data = $this->getJsonInput();
        $status = strtoupper((string) ($data['status'] ?? ''));
        if (! in_array($status, ['OPEN','IN_PROGRESS','ANSWERED','CLOSED','REOPENED'], true)) {
            return $this->fail('Status tidak valid', 400);
        }

        $now = date('Y-m-d H:i:s');
        $update = ['status' => $status, 'updated_at' => $now];
        if ($status === 'ANSWERED' && empty($row['first_response_at'])) {
            $update['first_response_at'] = $now;
        }
        if ($status === 'CLOSED') {
            $update['closed_at']   = $now;
            $update['resolved_at'] = $row['resolved_at'] ?? $now;
        }
        $this->db()->table('hr_tickets')->where('id', $id)->update($update);

        $emp = $this->db()->table('employees')->where('id', (int) $row['employee_id'])->get()->getRowArray();
        if ($emp && ! empty($emp['user_id'])) {
            try {
                Services::notification()->send(
                    (int) $emp['user_id'], (int) $row['company_id'],
                    'helpdesk.status_changed',
                    "🔄 Tiket {$row['ticket_number']}: {$status}",
                    "Status tiket Anda berubah dari {$row['status']} menjadi {$status}.",
                    ['ticket_id' => $id],
                    "/helpdesk/tickets/{$id}", true
                );
            } catch (\Throwable $e) {}
        }

        try {
            Services::audit()->forModule('HELPDESK')->log(
                action: 'ticket.status_change', entity: 'hr_tickets', entityId: $id,
                before: $row, after: array_merge($row, $update),
                description: "Tiket {$row['ticket_number']} status: {$row['status']} → {$status}",
            );
        } catch (\Throwable $e) {}
        return $this->ok(['id' => $id]);
    }

    /** POST /helpdesk/tickets/(:num)/close */
    public function close($id = null)
    {
        $id = (int) $id;
        $row = $this->loadOrFail($id, allowAccess: true);
        if (! is_array($row)) return $this->response;
        $isOwner = ((int) $row['employee_id'] === $this->getOwnEmployeeId());
        if (! $isOwner && ! $this->can('helpdesk.handle')) return $this->fail('Forbidden', 403);

        $data = $this->getJsonInput();
        $rating = isset($data['satisfaction_rating']) ? (int) $data['satisfaction_rating'] : null;
        $note   = (string) ($data['satisfaction_note'] ?? '');

        $now = date('Y-m-d H:i:s');
        $update = [
            'status'      => 'CLOSED',
            'closed_at'   => $now,
            'resolved_at' => $row['resolved_at'] ?? $now,
            'updated_at'  => $now,
        ];
        if ($rating !== null && $rating >= 1 && $rating <= 5) {
            $update['satisfaction_rating'] = $rating;
            $update['satisfaction_note'] = $note;
        }
        $this->db()->table('hr_tickets')->where('id', $id)->update($update);

        try {
            Services::audit()->forModule('HELPDESK')->log(
                action: 'ticket.close', entity: 'hr_tickets', entityId: $id,
                before: $row, after: array_merge($row, $update),
                description: "Tiket {$row['ticket_number']} ditutup" . ($rating ? " dgn rating {$rating}/5" : ''),
            );
        } catch (\Throwable $e) {}
        return $this->ok(['id' => $id]);
    }

    /** POST /helpdesk/tickets/(:num)/reopen */
    public function reopen($id = null)
    {
        $id = (int) $id;
        $row = $this->loadOrFail($id, allowAccess: true);
        if (! is_array($row)) return $this->response;
        $isOwner = ((int) $row['employee_id'] === $this->getOwnEmployeeId());
        if (! $isOwner && ! $this->can('helpdesk.handle')) return $this->fail('Forbidden', 403);
        if ($row['status'] !== 'CLOSED') return $this->fail('Hanya tiket CLOSED yg bisa di-reopen', 422);

        $now = date('Y-m-d H:i:s');
        $update = [
            'status'     => 'REOPENED',
            'closed_at'  => null,
            'updated_at' => $now,
        ];
        $this->db()->table('hr_tickets')->where('id', $id)->update($update);
        try {
            Services::audit()->forModule('HELPDESK')->log(
                action: 'ticket.reopen', entity: 'hr_tickets', entityId: $id,
                before: $row, after: array_merge($row, $update),
                description: "Tiket {$row['ticket_number']} di-reopen",
            );
        } catch (\Throwable $e) {}
        return $this->ok(['id' => $id]);
    }

    /** POST /helpdesk/tickets/(:num)/comments */
    public function addComment($id = null)
    {
        $id = (int) $id;
        $row = $this->loadOrFail($id, allowAccess: true);
        if (! is_array($row)) return $this->response;

        $userId = (int) $this->tenant()->getUserId();
        $isOwner = ((int) $row['employee_id'] === $this->getOwnEmployeeId());
        $canHandle = $this->can('helpdesk.handle');
        if (! $isOwner && ! $canHandle) return $this->fail('Forbidden', 403);

        $data = $this->getJsonInput();
        $data = $this->validateOrFail($data, [
            'body' => 'required|min_length[2]',
        ]);
        if ($data === null) return $this->response;

        $isInternal = ! empty($data['is_internal']) && $this->can('helpdesk.internal');
        $authorRole = $canHandle ? 'HR' : 'EMPLOYEE';
        if ((int) $this->tenant()->getRoleId() === 1) $authorRole = 'ADMIN';

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'    => (int) $row['company_id'],
            'ticket_id'     => $id,
            'author_user_id'=> $userId,
            'author_role'   => $authorRole,
            'body'          => $data['body'],
            'is_internal'   => $isInternal ? 1 : 0,
            'attachment_path'=> $data['attachment_path'] ?? null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];
        $this->db()->table('hr_ticket_comments')->insert($insert);
        $cid = (int) $this->db()->insertID();

        // Update parent
        $tUpdate = ['updated_at' => $now];
        if (! $isInternal) {
            if ($canHandle && empty($row['first_response_at'])) {
                $tUpdate['first_response_at'] = $now;
            }
            if ($canHandle && in_array($row['status'], ['OPEN','REOPENED'], true)) {
                $tUpdate['status'] = 'ANSWERED';
            }
            if (! $canHandle && $row['status'] === 'ANSWERED') {
                $tUpdate['status'] = 'IN_PROGRESS';
            }
        }
        if (count($tUpdate) > 1) {
            $this->db()->table('hr_tickets')->where('id', $id)->update($tUpdate);
        }

        // Notif (kalau bukan internal)
        if (! $isInternal) {
            try {
                if ($isOwner && ! empty($row['assignee_user_id'])) {
                    Services::notification()->send(
                        (int) $row['assignee_user_id'], (int) $row['company_id'],
                        'helpdesk.new_comment',
                        "💬 Komentar baru di {$row['ticket_number']}",
                        substr($data['body'], 0, 100),
                        ['ticket_id' => $id, 'comment_id' => $cid],
                        "/helpdesk/tickets/{$id}", true
                    );
                } elseif (! $isOwner) {
                    $emp = $this->db()->table('employees')->where('id', (int) $row['employee_id'])->get()->getRowArray();
                    if ($emp && ! empty($emp['user_id'])) {
                        Services::notification()->send(
                            (int) $emp['user_id'], (int) $row['company_id'],
                            'helpdesk.reply',
                            "💬 Balasan untuk tiket {$row['ticket_number']}",
                            substr($data['body'], 0, 100),
                            ['ticket_id' => $id, 'comment_id' => $cid],
                            "/helpdesk/tickets/{$id}", true
                        );
                    }
                }
            } catch (\Throwable $e) {}
        }

        try {
            Services::audit()->forModule('HELPDESK')->log(
                action: $isInternal ? 'comment.internal' : 'comment.add',
                entity: 'hr_ticket_comments',
                entityId: $cid, after: $insert,
                description: "Komentar di tiket {$row['ticket_number']}" . ($isInternal ? ' (internal)' : ''),
            );
        } catch (\Throwable $e) {}

        return $this->ok(['id' => $cid], 'OK', 201);
    }

    /** GET /helpdesk/stats */
    public function stats()
    {
        if (! $this->can('helpdesk.view')) return $this->fail('Forbidden', 403);

        $b = $this->db()->table('hr_tickets')
            ->select('status, COUNT(*) AS total')
            ->where('deleted_at IS NULL');
        $this->tenant()->applyScope($b, 'company_id');

        if (! $this->can('helpdesk.view_all')) {
            $myEmpId = $this->getOwnEmployeeId();
            if (! $myEmpId) return $this->ok(['total' => 0]);
            $b->where('employee_id', $myEmpId);
        }
        $rows = $b->groupBy('status')->get()->getResultArray();

        $stats = ['OPEN' => 0, 'IN_PROGRESS' => 0, 'ANSWERED' => 0, 'CLOSED' => 0, 'REOPENED' => 0, 'total' => 0];
        foreach ($rows as $r) {
            $stats[$r['status']] = (int) $r['total'];
            $stats['total'] += (int) $r['total'];
        }

        $bOver = $this->db()->table('hr_tickets')
            ->where('deleted_at IS NULL')
            ->where('sla_due_at <', date('Y-m-d H:i:s'))
            ->whereIn('status', ['OPEN','IN_PROGRESS','REOPENED']);
        $this->tenant()->applyScope($bOver, 'company_id');
        if (! $this->can('helpdesk.view_all')) {
            $myEmpId = $this->getOwnEmployeeId();
            if ($myEmpId) $bOver->where('employee_id', $myEmpId);
        }
        $stats['overdue'] = $bOver->countAllResults();

        return $this->ok($stats);
    }

    /**
     * Load ticket dengan otorisasi: pemilik atau punya helpdesk.view_all.
     * Returns array of row, atau set $this->response dengan error response.
     */
    private function loadOrFail(int $id, bool $allowAccess = false)
    {
        $row = $this->db()->table('hr_tickets')->where('id', $id)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Tiket tidak ditemukan', 404);
            return false;
        }

        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Akses ditolak (multi-tenant)', 403);
            return false;
        }

        if ($allowAccess) {
            $isOwner = ((int) $row['employee_id'] === $this->getOwnEmployeeId());
            if (! $isOwner && ! $this->can('helpdesk.view_all')) {
                $this->response = $this->fail('Forbidden', 403);
                return false;
            }
        }
        return $row;
    }
}