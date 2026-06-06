<?php

namespace App\Controllers\Api\V1\Attendance;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

class MachineSyncController extends ApiController
{
    /**
     * Endpoint to receive parsed attendance logs from the Node.js ADMS middleware.
     * Expected JSON payload:
     * {
     *    "device_sn": "12345",
     *    "logs": [
     *       {"pin": "101", "timestamp": "2023-10-25 08:00:00", "type": "in"},
     *       ...
     *    ]
     * }
     */
    public function sync()
    {
        // For security, this endpoint should require a special machine token or API key.
        // We'll assume a basic check against an environment API key for this internal service-to-service call.
        $apiKey = $this->request->getHeaderLine('X-Api-Key');
        if ($apiKey !== env('ADMS_API_KEY', 'default-adms-secret')) {
            return $this->fail('Unauthorized middleware', 401);
        }

        $data = $this->getJsonInput();
        $deviceSn = $data['device_sn'] ?? '';
        $logs = $data['logs'] ?? [];

        if (empty($deviceSn) || empty($logs)) {
            return $this->fail('Invalid payload', 400);
        }

        $db = \Config\Database::connect();
        
        // Check if device is registered
        $device = $db->table('attendance_devices')->where('serial_number', $deviceSn)->get()->getRowArray();
        if (! $device) {
            return $this->fail('Device not registered', 404);
        }

        if ($device['status'] === 'OFFLINE') {
            $db->table('attendance_devices')->where('id', $device['id'])->update(['status' => 'ONLINE']);
        }
        
        $db->table('attendance_devices')->where('id', $device['id'])->update(['last_sync_at' => date('Y-m-d H:i:s')]);

        $successCount = 0;
        foreach ($logs as $log) {
            $employeeNumber = $log['pin'];
            $timestamp = $log['timestamp']; // format Y-m-d H:i:s

            // Find employee by employee_number (PIN is usually mapped to employee_number or ID)
            $emp = $db->table('employees')
                ->where('company_id', $device['company_id'])
                ->where('employee_number', $employeeNumber)
                ->get()->getRowArray();

            if (! $emp) continue;

            $date = date('Y-m-d', strtotime($timestamp));
            $time = date('H:i:s', strtotime($timestamp));

            // Check existing attendance for this day
            $existing = $db->table('attendances')
                ->where('employee_id', $emp['id'])
                ->where('work_date', $date)
                ->get()->getRowArray();

            if ($existing) {
                // Determine if this is a clock-in or clock-out update
                // Simple logic: if time is later than current clock_out or clock_out is null, update clock_out
                $updateData = [];
                if (! $existing['clock_out'] || strtotime($time) > strtotime($existing['clock_out'])) {
                    $updateData['clock_out'] = $time;
                    $updateData['clock_out_source'] = 'MACHINE';
                    $updateData['updated_at'] = date('Y-m-d H:i:s');
                }
                
                if (! empty($updateData)) {
                    $db->table('attendances')->where('id', $existing['id'])->update($updateData);
                    $successCount++;
                }
            } else {
                // Create new attendance record
                $db->table('attendances')->insert([
                    'company_id' => $emp['company_id'],
                    'employee_id' => $emp['id'],
                    'work_date' => $date,
                    'clock_in' => $time,
                    'clock_in_source' => 'MACHINE',
                    'status' => 'PRESENT',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $successCount++;
            }
        }

        return $this->ok([
            'processed' => count($logs),
            'inserted_or_updated' => $successCount
        ], 'Logs synced');
    }
}
