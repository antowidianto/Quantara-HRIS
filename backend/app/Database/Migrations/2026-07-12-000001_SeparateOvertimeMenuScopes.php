<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Bedakan konteks self-service dan HR untuk menu lembur yang memakai route
 * dan component frontend yang sama.
 */
class SeparateOvertimeMenuScopes extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('menus')) {
            return;
        }

        $this->db->table('menus')
            ->where('code', 'my.overtimes')
            ->update(['route_path' => '/overtimes?scope=mine']);

        $this->db->table('menus')
            ->where('code', 'hr.overtimes')
            ->update(['route_path' => '/overtimes?scope=all&status=PENDING']);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('menus')) {
            return;
        }

        $this->db->table('menus')
            ->where('code', 'my.overtimes')
            ->update(['route_path' => '/overtimes']);

        $this->db->table('menus')
            ->where('code', 'hr.overtimes')
            ->update(['route_path' => '/overtimes?status=PENDING']);
    }
}
