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
        $this->tenant()->applyScope($query);

        $devices = $query->get()->getResultArray();

        return $this->ok($devices);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $companyId = $this->requireCompanyId();
        if ($companyId === null) {
            return null;
        }
        unset($data['id'], $data['company_id'], $data['status'], $data['last_sync_at']);
        $data['company_id'] = $companyId;
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
        $builder = $db->table($this->table)->where('id', $id);
        $this->tenant()->applyScope($builder);
        $device = $builder->get()->getRowArray();
        if (!$device) {
            return $this->failNotFound('Device not found');
        }

        return $this->ok($device);
    }

    public function update($id)
    {
        $data = $this->getJsonInput();
        unset($data['id'], $data['company_id'], $data['created_at'], $data['last_sync_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');

        $db = \Config\Database::connect();
        $builder = $db->table($this->table)->where('id', $id);
        $this->tenant()->applyScope($builder);
        $builder->update($data);
        if ($db->affectedRows() !== 1) {
            return $this->failNotFound('Device not found');
        }

        return $this->ok(null, 'Device updated successfully');
    }

    public function delete($id)
    {
        $db = \Config\Database::connect();
        $builder = $db->table($this->table)->where('id', $id);
        $this->tenant()->applyScope($builder);
        $builder->delete();
        if ($db->affectedRows() !== 1) {
            return $this->failNotFound('Device not found');
        }

        return $this->ok(null, 'Device deleted successfully');
    }
}
