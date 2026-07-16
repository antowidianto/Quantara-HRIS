<?php

namespace App\Libraries\Attendance;

use Config\Services;

/**
 * BusinessTripService
 *
 * Logika domain SPPD (Surat Perjalanan Dinas).
 *
 * Tanggung jawab:
 *  1. Generate `reference_number` unik per company (`SPPD/<COMPANY_CODE>/<YEAR>/<5digit>`).
 *  2. Hitung `duration_days` dari start_date/end_date.
 *  3. Submit untuk approval (modul `BUSINESS_TRIP`).
 *  4. Recompute total `actual_amount` dari sum line items billable.
 *  5. Settlement: hitung selisih actual − advance.
 *      - Selisih positif → buat reimbursement otomatis.
 *      - Selisih negatif → catat sebagai potongan (tercatat di settlement_amount,
 *        UI bisa link ke loan/potongan gaji manual oleh finance).
 *  6. Render SPPD HTML formal (Surat Perintah Perjalanan Dinas).
 */
class BusinessTripService
{
    /**
     * Generate reference number: SPPD/<COMPANY_CODE>/<YEAR>/<5digit>
     */
    public function nextReferenceNumber(int $companyId): string
    {
        $db = \Config\Database::connect();
        $company = $db->table('companies')->where('id', $companyId)->get()->getRowArray() ?: [];
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', (string) ($company['code'] ?? 'CO')));
        if ($code === '') $code = 'CO';
        $year = date('Y');
        $prefix = "SPPD/{$code}/{$year}/";
        $count = $db->table('business_trips')
            ->where('company_id', $companyId)
            ->like('reference_number', $prefix, 'after')
            ->countAllResults();
        $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
        $candidate = $prefix . $seq;

        $tries = 0;
        while ($tries < 50) {
            $exists = $db->table('business_trips')
                ->where('company_id', $companyId)
                ->where('reference_number', $candidate)
                ->countAllResults();
            if ($exists === 0) return $candidate;
            $tries++; $count++;
            $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
            $candidate = $prefix . $seq;
        }
        return $candidate;
    }

    public function computeDurationDays(string $start, string $end): int
    {
        $s = strtotime($start);
        $e = strtotime($end);
        if (! $s || ! $e || $e < $s) return 1;
        return (int) round(($e - $s) / 86400) + 1;
    }

    /**
     * Submit untuk approval. Pindahkan status DRAFT → PENDING dan trigger
     * approval chain modul BUSINESS_TRIP.
     */
    public function submit(int $tripId, int $companyId, ?int $submittedBy = null): array
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trips')->where('id', $tripId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException("Trip #{$tripId} tidak ditemukan");
        if ($row['status'] !== 'DRAFT') {
            throw new \RuntimeException("Hanya status DRAFT yang bisa di-submit, sekarang {$row['status']}");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('business_trips')->where('id', $tripId)->where('company_id', $companyId)
            ->where('status', 'DRAFT')->update([
            'status'       => 'PENDING',
            'submitted_at' => $now,
            'updated_at'   => $now,
        ]);

        try {
            $approval = Services::approval()->start([
                'company_id' => $companyId,
                'module' => 'BUSINESS_TRIP',
                'entity_table' => 'business_trips',
                'entity_id' => $tripId,
                'employee_id' => (int) $row['employee_id'],
                'meta' => [
                    'amount' => (float) $row['advance_amount'],
                    'days' => (int) $row['duration_days'],
                    'is_overseas' => ($row['destination_type'] ?? 'DOMESTIC') === 'INTERNATIONAL',
                    'submitted_by' => $submittedBy,
                    'cost_center_id' => $row['cost_center_id'] ?? null,
                ],
            ]);
        } catch (\Throwable $exception) {
            $db->table('business_trips')->where('id', $tripId)->where('company_id', $companyId)
                ->where('status', 'PENDING')->update([
                    'status' => 'DRAFT', 'submitted_at' => null, 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            throw new \RuntimeException('Gagal membuat workflow approval SPPD: ' . $exception->getMessage(), 0, $exception);
        }

        return ['status' => 'PENDING', 'approval' => $approval];
    }

    /**
     * Recompute actual_amount dari sum line items (billable only).
     */
    public function recomputeActual(int $tripId, int $companyId): float
    {
        $db = \Config\Database::connect();
        $sum = (float) $db->table('business_trip_expenses')
            ->selectSum('amount', 'total')
            ->where('business_trip_id', $tripId)
            ->where('company_id', $companyId)
            ->where('is_billable', 1)
            ->get()->getRowArray()['total'];
        $db->table('business_trips')->where('id', $tripId)->where('company_id', $companyId)->update([
            'actual_amount' => $sum,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        return $sum;
    }

    /**
     * Karyawan tandai trip COMPLETED setelah upload semua bukti.
     */
    public function markCompleted(int $tripId, int $companyId): void
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trips')->where('id', $tripId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException("Trip not found");
        if (! in_array($row['status'], ['APPROVED', 'IN_PROGRESS'], true)) {
            throw new \RuntimeException("Hanya APPROVED/IN_PROGRESS yang bisa COMPLETED");
        }
        $this->recomputeActual($tripId, $companyId);
        $db->table('business_trips')->where('id', $tripId)->where('company_id', $companyId)->update([
            'status'       => 'COMPLETED',
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Settlement: hitung selisih actual vs advance dan buat reimbursement
     * otomatis kalau actual > advance.
     */
    public function settle(int $tripId, int $companyId, ?int $settledBy = null): array
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trips')->where('id', $tripId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException("Trip not found");
        if ($row['status'] !== 'COMPLETED') {
            throw new \RuntimeException("Trip harus COMPLETED dulu sebelum di-settle, sekarang {$row['status']}");
        }

        $actual = (float) $row['actual_amount'];
        $advance = (float) $row['advance_amount'];
        $diff = round($actual - $advance, 2);

        $reimbursementId = null;
        $reimbursementApproval = null;
        $now = date('Y-m-d H:i:s');
        $db->transBegin();
        try {
            if ($diff > 0) {
            // Karyawan tombok → buat reimbursement
            $reqNumber = 'RB-SPPD-' . $row['id'] . '-' . date('Ymd');
            $db->table('reimbursements')->insert([
                'company_id'     => (int) $row['company_id'],
                'employee_id'    => (int) $row['employee_id'],
                'request_number' => $reqNumber,
                'category'       => 'BUSINESS_TRIP',
                'expense_date'   => $row['end_date'],
                'amount'         => $diff,
                'description'    => "Selisih SPPD {$row['reference_number']} ({$row['destination']})",
                'status'         => 'PENDING',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $reimbursementId = (int) $db->insertID();
                $reimbursementApproval = Services::approval()->start([
                    'company_id' => $companyId,
                    'module' => 'REIMBURSEMENT',
                    'entity_table' => 'reimbursements',
                    'entity_id' => $reimbursementId,
                    'employee_id' => (int) $row['employee_id'],
                    'meta' => [
                        'amount' => $diff,
                        'category' => 'BUSINESS_TRIP',
                        'expense_date' => (string) $row['end_date'],
                        'source_business_trip_id' => $tripId,
                    ],
                ]);
            }
        // Kalau diff < 0: karyawan kelebihan advance → finance manually catat potongan;
        // settlement_amount tetap negatif sebagai indikator.

            $db->table('business_trips')->where('id', $tripId)->where('company_id', $companyId)
                ->where('status', 'COMPLETED')->update([
            'status'           => 'SETTLED',
            'settlement_amount'=> $diff,
            'reimbursement_id' => $reimbursementId,
            'settled_at'       => $now,
            'updated_at'       => $now,
                ]);
            if ($db->affectedRows() !== 1) throw new \RuntimeException('SPPD berubah; muat ulang data');
            if (! $db->transStatus()) throw new \RuntimeException('Settlement transaction failed');
            $db->transCommit();
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }

        return [
            'settlement_amount' => $diff,
            'reimbursement_id'  => $reimbursementId,
            'reimbursement_approval' => $reimbursementApproval,
            'overpay_to_return' => $diff < 0 ? abs($diff) : 0,
            'underpay_to_reimburse' => $diff > 0 ? $diff : 0,
        ];
    }

    public function cancel(int $tripId, int $companyId, ?string $reason = null): void
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trips')->where('id', $tripId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException("Trip not found");
        if (in_array($row['status'], ['SETTLED', 'CANCELLED', 'REJECTED'], true)) {
            throw new \RuntimeException("Trip status {$row['status']} tidak bisa di-cancel");
        }
        $db->transBegin();
        try {
            $db->table('business_trips')->where('id', $tripId)->where('company_id', $companyId)
                ->where('status', (string) $row['status'])->update([
                    'status' => 'CANCELLED', 'rejection_reason' => $reason,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if ($db->affectedRows() !== 1) throw new \RuntimeException('SPPD berubah; muat ulang data');
            if ($row['status'] === 'PENDING') {
                Services::approval()->cancelEntity('BUSINESS_TRIP', 'business_trips', $tripId, $companyId);
            }
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membatalkan SPPD');
            $db->transCommit();
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /**
     * Render SPPD HTML untuk dicetak.
     */
    public function renderSppdHtml(int $tripId, ?int $companyId = null): string
    {
        $db = \Config\Database::connect();
        $tripBuilder = $db->table('business_trips')->where('id', $tripId);
        if ($companyId !== null) $tripBuilder->where('company_id', $companyId);
        $row = $tripBuilder->get()->getRowArray();
        if (! $row) return '';

        $emp = $db->table('employees')->where('id', $row['employee_id'])
            ->where('company_id', (int) $row['company_id'])->get()->getRowArray() ?: [];
        $position = ! empty($emp['position_id'])
            ? ($db->table('positions')->where('id', (int) $emp['position_id'])
                ->where('company_id', (int) $row['company_id'])->get()->getRowArray() ?: [])
            : [];
        $department = ! empty($emp['department_id'])
            ? ($db->table('departments')->where('id', (int) $emp['department_id'])
                ->where('company_id', (int) $row['company_id'])->get()->getRowArray() ?: [])
            : [];
        $company = $db->table('companies')->where('id', $row['company_id'])
            ->where('id', (int) $row['company_id'])->get()->getRowArray() ?: [];

        $months = ['', 'Januari','Februari','Maret','April','Mei','Juni',
                   'Juli','Agustus','September','Oktober','November','Desember'];
        $fmtDate = function ($d) use ($months) {
            if (! $d) return '-';
            $ts = strtotime($d);
            return date('j', $ts) . ' ' . $months[(int) date('n', $ts)] . ' ' . date('Y', $ts);
        };
        $fmtMoney = fn($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');

        $h = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
           . 'body{font-family:Arial,sans-serif;font-size:11px;color:#222;padding:24px}'
           . 'h1{font-size:14px;text-align:center;margin:0 0 4px;text-decoration:underline}'
           . 'h2{font-size:12px;margin:14px 0 6px}'
           . 'table{border-collapse:collapse;width:100%;margin-top:6px}'
           . 'td{padding:5px 8px;vertical-align:top}'
           . '.kv td{border:1px solid #ccc}'
           . '.center{text-align:center}'
           . '</style></head><body>';
        $h .= '<h1>SURAT PERINTAH PERJALANAN DINAS</h1>';
        $h .= '<div class="center" style="font-size:11px;margin-bottom:14px">';
        $h .= 'No: ' . htmlspecialchars((string) $row['reference_number'], ENT_QUOTES);
        $h .= ' &middot; ' . htmlspecialchars((string) ($company['name'] ?? '-'), ENT_QUOTES);
        $h .= '</div>';

        $h .= '<p>Berdasarkan keperluan dinas, dengan ini menugaskan:</p>';
        $h .= '<table class="kv"><tbody>';
        $h .= '<tr><td style="width:30%">Nama</td><td>: <strong>' . htmlspecialchars((string) ($emp['full_name'] ?? '-'), ENT_QUOTES) . '</strong></td></tr>';
        $h .= '<tr><td>NIP</td><td>: ' . htmlspecialchars((string) ($emp['employee_number'] ?? '-'), ENT_QUOTES) . '</td></tr>';
        $h .= '<tr><td>Jabatan</td><td>: ' . htmlspecialchars((string) ($position['name'] ?? '-'), ENT_QUOTES) . '</td></tr>';
        $h .= '<tr><td>Departemen</td><td>: ' . htmlspecialchars((string) ($department['name'] ?? '-'), ENT_QUOTES) . '</td></tr>';
        $h .= '</tbody></table>';

        $h .= '<h2>RINCIAN PERJALANAN</h2>';
        $h .= '<table class="kv"><tbody>';
        $h .= '<tr><td style="width:30%">Tujuan</td><td>: <strong>' . htmlspecialchars((string) $row['destination'], ENT_QUOTES) . '</strong> (' . $row['destination_type'] . ')</td></tr>';
        $h .= '<tr><td>Maksud Perjalanan</td><td>: ' . nl2br(htmlspecialchars((string) $row['purpose'], ENT_QUOTES)) . '</td></tr>';
        $h .= '<tr><td>Tanggal Berangkat</td><td>: ' . $fmtDate($row['start_date']) . '</td></tr>';
        $h .= '<tr><td>Tanggal Kembali</td><td>: ' . $fmtDate($row['end_date']) . '</td></tr>';
        $h .= '<tr><td>Lama Perjalanan</td><td>: ' . (int) $row['duration_days'] . ' hari</td></tr>';
        if (! empty($row['transport_mode'])) {
            $h .= '<tr><td>Transportasi</td><td>: ' . htmlspecialchars((string) $row['transport_mode'], ENT_QUOTES) . '</td></tr>';
        }
        if (! empty($row['accommodation'])) {
            $h .= '<tr><td>Akomodasi</td><td>: ' . htmlspecialchars((string) $row['accommodation'], ENT_QUOTES) . '</td></tr>';
        }
        if ((float) $row['advance_amount'] > 0) {
            $h .= '<tr><td>Uang Muka</td><td>: <strong>' . $fmtMoney($row['advance_amount']) . '</strong></td></tr>';
        }
        $h .= '</tbody></table>';

        $h .= '<p style="margin-top:14px">Yang bersangkutan diharapkan menjalankan tugas '
            . 'sebaik-baiknya dan melaporkan hasilnya kepada atasan setelah kembali. '
            . 'Bukti pengeluaran harus dikumpulkan dan diserahkan ke Bagian Keuangan '
            . 'untuk proses settlement.</p>';

        $h .= '<table style="width:100%;margin-top:36px;border:none"><tr style="border:none">'
            . '<td style="border:none;width:50%;text-align:center">'
            . '<div>' . htmlspecialchars((string) ($company['city'] ?? '-'), ENT_QUOTES) . ', ' . $fmtDate($row['approved_at'] ?? $row['submitted_at'] ?? date('Y-m-d')) . '</div>'
            . '<div>Mengetahui,</div><div style="height:80px"></div>'
            . '<div style="text-decoration:underline"><strong>(______________________)</strong></div>'
            . '<div>Atasan Langsung / HR</div>'
            . '</td>'
            . '<td style="border:none;width:50%;text-align:center">'
            . '<div>&nbsp;</div><div>Yang Ditugaskan,</div><div style="height:80px"></div>'
            . '<div style="text-decoration:underline"><strong>' . htmlspecialchars((string) ($emp['full_name'] ?? '-'), ENT_QUOTES) . '</strong></div>'
            . '<div>' . htmlspecialchars((string) ($position['name'] ?? '-'), ENT_QUOTES) . '</div>'
            . '</td>'
            . '</tr></table>';

        $h .= '</body></html>';
        return $h;
    }
}
