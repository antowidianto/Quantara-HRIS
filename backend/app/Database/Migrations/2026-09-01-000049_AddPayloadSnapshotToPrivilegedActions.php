<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Menyimpan snapshot payload privileged action untuk audit/review checker.
 *
 * Snapshot hanya diisi oleh filter untuk group RBAC dan tetap tenant-scoped.
 * Hash request tetap menjadi sumber otorisasi replay; kolom ini hanya evidence.
 */
final class AddPayloadSnapshotToPrivilegedActions extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('privileged_action_requests')
            || $this->db->fieldExists('request_payload', 'privileged_action_requests')
        ) {
            return;
        }

        $this->forge->addColumn('privileged_action_requests', [
            'request_payload' => [
                'type' => 'MEDIUMTEXT',
                'null' => true,
                'after' => 'request_hash',
            ],
        ]);
    }

    public function down(): void
    {
        // Keep privileged-action evidence for audit retention.
    }
}