<?php

namespace App\Libraries\Attendance;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class BusinessTripApprovalWorkflowService
{
    public function __construct(
        private readonly ?BaseConnection $connection = null,
        private readonly ?BusinessTripService $tripService = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function approve(int $id, int $companyId, int $userId): array
    {
        $db = $this->db();
        $trip = $this->pending($id, $companyId);
        $now = date('Y-m-d H:i:s');
        $db->table('business_trips')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING')->update([
                'status' => 'APPROVED', 'approved_by' => $userId,
                'approved_at' => $now, 'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new BusinessTripApprovalException('SPPD berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }
        $sppd = ($this->tripService ?? new BusinessTripService())->renderSppdHtml($id, $companyId);
        $db->table('business_trips')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'APPROVED')->update(['sppd_html' => $sppd, 'updated_at' => $now]);
        if ($db->affectedRows() !== 1) {
            throw new BusinessTripApprovalException('Gagal menyimpan snapshot SPPD', 'SPPD_SNAPSHOT_FAILED', 409);
        }
        $employee = $db->table('employees')->where('id', (int) $trip['employee_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        return ['business_trip' => $trip, 'employee' => $employee, 'sppd_html' => $sppd];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new BusinessTripApprovalException('Alasan penolakan minimal 5 karakter', 'REASON_REQUIRED');
        }
        $db = $this->db();
        $trip = $this->pending($id, $companyId);
        $now = date('Y-m-d H:i:s');
        $db->table('business_trips')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING')->update([
                'status' => 'REJECTED', 'rejected_at' => $now,
                'rejection_reason' => $reason, 'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new BusinessTripApprovalException('SPPD berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }
        return ['business_trip' => $trip, 'reason' => $reason, 'rejected_by' => $userId];
    }

    /** @return array<string,mixed> */
    private function pending(int $id, int $companyId): array
    {
        $row = $this->db()->table('business_trips')->where('id', $id)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new BusinessTripApprovalException('SPPD tidak ditemukan', 'RESOURCE_NOT_FOUND', 404);
        if ($row['status'] !== 'PENDING') {
            throw new BusinessTripApprovalException('SPPD tidak dalam status PENDING', 'APPROVAL_INVALID_STATE');
        }
        return $row;
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
