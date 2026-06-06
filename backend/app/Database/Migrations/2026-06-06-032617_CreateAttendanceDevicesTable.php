<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAttendanceDevicesTable extends Migration
{
    public function up()
    {
        // ALREADY RUN
    }

    public function down()
    {
        $this->forge->dropTable('attendance_devices');
    }
}
