<?php

namespace App\Controllers\Api\V1\Attendance;

use App\Controllers\Api\V1\ApiController;

class AttendanceDeviceController extends ApiController
{
    protected $table = 'attendance_devices';

    public function index()
    {
        $db = \Config\Database::connect();
        $query = $db->table($this->table)->orderBy('created_at', 'DESC');
        
        $companyId = $this->request->getGet('company_id');
        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $devices = $query->get()->getResultArray();
        return $this->ok($devices);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['status'] = 'OFFLINE';

        $db = \Config\Database::connect();
        $db->table($this->table)->insert($data);
        $id = $db->insertID();

        return $this->created(['id' => $id], 'Device registered successfully');
    }

    public function show($id)
    {
        $db = \Config\Database::connect();
        $device = $db->table($this->table)->where('id', $id)->get()->getRowArray();
        if (! $device) return $this->failNotFound('Device not found');
        return $this->ok($device);
    }

    public function update($id)
    {
        $data = $this->getJsonInput();
        $data['updated_at'] = date('Y-m-d H:i:s');

        $db = \Config\Database::connect();
        $db->table($this->table)->where('id', $id)->update($data);
        return $this->ok(null, 'Device updated successfully');
    }

    public function delete($id)
    {
        $db = \Config\Database::connect();
        $db->table($this->table)->where('id', $id)->delete();
        return $this->ok(null, 'Device deleted successfully');
    }
}
