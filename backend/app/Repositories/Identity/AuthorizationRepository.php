<?php

namespace App\Repositories\Identity;

use Config\Database;

class AuthorizationRepository
{
    /**
     * @return array{user: array<string,mixed>, role: array<string,mixed>|null}|null
     */
    public function snapshotForUser(int $userId, int $companyId, bool $isSuperAdmin): ?array
    {
        $db = Database::connect();
        $builder = $db->table('users')
            ->select('id, role_id')
            ->where('id', $userId)
            ->where('is_active', 1);

        if (!$isSuperAdmin) {
            $builder->where('company_id', $companyId);
        }

        $user = $builder->get()->getRowArray();
        if (!$user) {
            return null;
        }

        $role = null;
        if (!empty($user['role_id'])) {
            $roleBuilder = $db->table('roles')
                ->select('id, slug, name, updated_at')
                ->where('id', (int) $user['role_id']);

            if (!$isSuperAdmin) {
                $roleBuilder
                    ->groupStart()
                    ->where('company_id', $companyId)
                    ->orWhere('company_id IS NULL')
                    ->groupEnd();
            }

            $role = $roleBuilder->get()->getRowArray();
        }

        return ['user' => $user, 'role' => $role];
    }
}