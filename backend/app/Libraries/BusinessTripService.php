<?php

namespace App\Libraries;

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
    public function submit(int $tripId, ?int $submittedBy = null): array
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trips')->where('id', $tripId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException("Trip #{$tripId} tidak ditemukan");
        if ($row['status'] !== 'DRAFT') {
            throw new \RuntimeException("Hanya status DRAFT yang bisa di-submit, sekarang {$row['status']}");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('business_trips')->where('id', $tripId)->update([
            'status'       => 'PENDING',
            'submitted_at' => $now,
            'updated_at'   => $now,
        ]);

        // Trigger approval chain (kalau ada chain BUSINESS_TRIP)
        $approvalRequestId = null;
        try {
            $approvalRequestId = Services::approval()->submit('BUSINESS_TRIP', $tripId, [
                'employee_id'    => (int) $row['employee_id'],
                'company_id'     => (int) $row['company_id'],
                'amount'         => (float) $row['advance_amount'],
                'submitted_by'   => $submittedBy,
            ]);
        } catch (\Throwable $e) {
            // Kalau modul approval belum dikonfigurasi, lanjut tanpa approval chain.
            // HR bisa langsung approve via endpoint khusus.
            log_message('info', 'BusinessTrip submit without approval chain: ' . $e->getMessage());
        }

        return ['status' => 'PENDING', 'approval_request_id' => $approvalRequestId];
    }

    /**
     * Approve trip langsung (untuk admin/HR yang punya business_trip.approve).
     */
    public function approve(int $tripId, ?int $approvedBy = null, ?string $note = null): void
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trips')->where('id', $tripId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException("Trip #{$tripId} tidak ditemukan");
        if (! in_array($row['status'], ['PENDING', 'DRAFT'], true)) {
            throw new \RuntimeException("Hanya status PENDING/DRAFT yang bisa di-approve, sekarang {$row['status']}");
        }
        $now = date('Y-m-d H:i:s');
        $db->table('business_trips')->where('id', $tripId)->update([
            'status'      => 'APPROVED',
            'approved_at' => $now,
            'updated_at'  => $now,
        ]);

        // Render snapshot SPPD
        $sppd = $this->renderSppdHtml($tripId);
        $db->table('business_trips')->where('id', $tripId)->update([
            'sppd_html'  => $sppd,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Notifikasi karyawan
        $emp = $db->table('employees')->where('id', $row['employee_id'])->get()->getRowArray();
        if ($emp && ! empty($emp['user_id'])) {
            Services::notification()->send(
                (int) $emp['user_id'],
                (int) $row['company_id'],
                'business_trip.approved',
                "SPPD {$row['reference_number']} disetujui",
                "Perjalanan dinas ke {$row['destination']} ({$row['start_date']} → {$row['end_date']}) telah disetujui. Advance: " . number_format((float) $row['advance_amount']),
                ['business_trip_id' => $tripId],
                null, true
            );
        }
    }

    public function reject(int $tripId, string $reason, ?int $rejectedBy = null): void
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trips')->where('id', $tripId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException("Trip not found");
        if (! in_array($row['status'], ['PENDING', 'DRAFT'], true)) {
            throw new \RuntimeException("Hanya status PENDING/DRAFT yang bisa di-reject");
        }
        $now = date('Y-m-d H:i:s');
        $db->table('business_trips')->where('id', $tripId)->update([
            'status'           => 'REJECTED',
            'rejected_at'      => $now,
            'rejection_reason' => $reason,
            'updated_at'       => $now,
        ]);

        $emp = $db->table('employees')->where('id', $row['employee_id'])->get()->getRowArray();
        if ($emp && ! empty($emp['user_id'])) {
            Services::notification()->send(
                (int) $emp['user_id'],
                (int) $row['company_id'],
                'business_trip.rejected',
                "SPPD {$row['reference_number']} ditolak",
                "Alasan: {$reason}",
                ['business_trip_id' => $tripId],
                null, true
            );
        }
    }

    /**
     * Recompute actual_amount dari sum line items (billable only).
     */
    public function recomputeActual(int $tripId): float
    {
        $db = \Config\Database::connect();
        $sum = (float) $db->table('business_trip_expenses')
            ->selectSum('amount', 'total')
            ->where('business_trip_id', $tripId)
            ->where('is_billable', 1)
            ->get()->getRowArray()['total'];
        $db->table('business_trips')->where('id', $tripId)->update([
            'actual_amount' => $sum,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        return $sum;
    }

    /**
     * Karyawan tandai trip COMPLETED setelah upload semua bukti.
     */
    public function markCompleted(int $tripId): void
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trips')->where('id', $tripId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException("Trip not found");
        if (! in_array($row['status'], ['APPROVED', 'IN_PROGRESS'], true)) {
            throw new \RuntimeException("Hanya APPROVED/IN_PROGRESS yang bisa COMPLETED");
        }
        $this->recomputeActual($tripId);
        $db->table('business_trips')->where('id', $tripId)->update([
            'status'       => 'COMPLETED',
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Settlement: hitung selisih actual vs advance dan buat reimbursement
     * otomatis kalau actual > advance.
     */
    public function settle(int $tripId, ?int $settledBy = null): array
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trips')->where('id', $tripId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException("Trip not found");
        if ($row['status'] !== 'COMPLETED') {
            throw new \RuntimeException("Trip harus COMPLETED dulu sebelum di-settle, sekarang {$row['status']}");
        }

        $actual = (float) $row['actual_amount'];
        $advance = (float) $row['advance_amount'];
        $diff = round($actual - $advance, 2);

        $reimbursementId = null;
        $now = date('Y-m-d H:i:s');

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
        }
        // Kalau diff < 0: karyawan kelebihan advance → finance manually catat potongan;
        // settlement_amount tetap negatif sebagai indikator.

        $db->table('business_trips')->where('id', $tripId)->update([
            'status'           => 'SETTLED',
            'settlement_amount'=> $diff,
            'reimbursement_id' => $reimbursementId,
            'settled_at'       => $now,
            'updated_at'       => $now,
        ]);

        return [
            'settlement_amount' => $diff,
            'reimbursement_id'  => $reimbursementId,
            'overpay_to_return' => $diff < 0 ? abs($diff) : 0,
            'underpay_to_reimburse' => $diff > 0 ? $diff : 0,
        ];
    }

    public function cancel(int $tripId, ?string $reason = null): void
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trips')->where('id', $tripId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException("Trip not found");
        if (in_array($row['status'], ['SETTLED', 'CANCELLED', 'REJECTED'], true)) {
            throw new \RuntimeException("Trip status {$row['status']} tidak bisa di-cancel");
        }
        $db->table('business_trips')->where('id', $tripId)->update([
            'status'           => 'CANCELLED',
            'rejection_reason' => $reason,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Render SPPD HTML untuk dicetak.
     */
    public function renderSppdHtml(int $tripId): string
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trips')->where('id', $tripId)->get()->getRowArray();
        if (! $row) return '';

        $emp = $db->table('employees')->where('id', $row['employee_id'])->get()->getRowArray() ?: [];
        $position = ! empty($emp['position_id'])
            ? ($db->table('positions')->where('id', (int) $emp['position_id'])->get()->getRowArray() ?: [])
            : [];
        $department = ! empty($emp['department_id'])
            ? ($db->table('departments')->where('id', (int) $emp['department_id'])->get()->getRowArray() ?: [])
            : [];
        $company = $db->table('companies')->where('id', $row['company_id'])->get()->getRowArray() ?: [];

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