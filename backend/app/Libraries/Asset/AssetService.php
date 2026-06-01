<?php

namespace App\Libraries\Asset;

use Config\Services;

/**
 * AssetService
 *
 * Logika domain Asset & Asset Assignment.
 *
 * Tanggung jawab:
 *  1. Generate `asset_number` unik per company (`AST-<YEAR>-<seq>`)
 *  2. Assign asset ke karyawan: buat row `asset_assignments`, update
 *     `assets.status=ASSIGNED` + cached pointer (`current_assignment_id`,
 *     `current_employee_id`).
 *  3. Return asset: tutup assignment, set `assets.status=AVAILABLE` (atau
 *     REPAIR/LOST kalau kondisi DAMAGED/LOST), update `assets.condition`
 *     ke nilai terakhir.
 *  4. Render BAST (Berita Acara Serah Terima) HTML untuk dicetak / di-PDF-kan.
 *  5. Validasi: asset harus AVAILABLE sebelum bisa di-assign; tidak bisa
 *     return assignment yang sudah closed.
 */
class AssetService
{
    /**
     * Generate asset_number unik. Format: `AST-<YEAR>-<5-digit-seq>`.
     */
    public function nextAssetNumber(int $companyId): string
    {
        $db = \Config\Database::connect();
        $year = date('Y');
        $prefix = "AST-{$year}-";
        $count = $db->table('assets')
            ->where('company_id', $companyId)
            ->like('asset_number', $prefix, 'after')
            ->countAllResults();
        $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
        $candidate = $prefix . $seq;

        // Edge case race
        $tries = 0;
        while ($tries < 50) {
            $exists = $db->table('assets')
                ->where('company_id', $companyId)
                ->where('asset_number', $candidate)
                ->countAllResults();
            if ($exists === 0) return $candidate;
            $tries++; $count++;
            $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
            $candidate = $prefix . $seq;
        }
        return $candidate;
    }

    /**
     * Assign asset ke karyawan. Atomic.
     *
     * @return int assignment_id
     */
    public function assignToEmployee(int $assetId, int $employeeId, array $params = []): int
    {
        $db = \Config\Database::connect();
        $asset = $db->table('assets')->where('id', $assetId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $asset) {
            throw new \InvalidArgumentException("Asset #{$assetId} tidak ditemukan");
        }
        if ($asset['status'] !== 'AVAILABLE') {
            throw new \RuntimeException("Asset {$asset['asset_number']} status='{$asset['status']}', hanya AVAILABLE yang bisa di-assign");
        }
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) {
            throw new \InvalidArgumentException("Employee #{$employeeId} tidak ditemukan");
        }
        if ((int) $emp['company_id'] !== (int) $asset['company_id']) {
            throw new \RuntimeException('Asset dan Employee dari perusahaan berbeda');
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'       => (int) $asset['company_id'],
            'asset_id'         => $assetId,
            'employee_id'      => $employeeId,
            'assigned_at'      => $params['assigned_at'] ?? $now,
            'expected_return'  => $params['expected_return'] ?? null,
            'purpose'          => $params['purpose'] ?? null,
            'condition_when_assigned' => $params['condition_when_assigned'] ?? $asset['condition'] ?? 'GOOD',
            'assigned_by'      => $params['assigned_by'] ?? null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
        $db->table('asset_assignments')->insert($insert);
        $assignmentId = (int) $db->insertID();

        // Update cached pointer & status
        $db->table('assets')->where('id', $assetId)->update([
            'status'                => 'ASSIGNED',
            'current_assignment_id' => $assignmentId,
            'current_employee_id'   => $employeeId,
            'updated_at'            => $now,
        ]);

        // Render BAST snapshot
        $insert['id'] = $assignmentId;
        $bast = $this->renderBastHtml($insert, $asset, $emp, 'ASSIGN');
        $db->table('asset_assignments')->where('id', $assignmentId)->update([
            'bast_html'  => $bast,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Notifikasi karyawan
        if (! empty($emp['user_id'])) {
            Services::notification()->send(
                (int) $emp['user_id'],
                (int) $asset['company_id'],
                'asset.assigned',
                "Aset {$asset['name']} telah diserahkan kepada Anda",
                "Asset {$asset['asset_number']} ({$asset['name']}) — kondisi {$insert['condition_when_assigned']}",
                ['asset_id' => $assetId, 'assignment_id' => $assignmentId],
                null, true
            );
        }

        return $assignmentId;
    }

    /**
     * Return asset (terima kembali dari karyawan). Atomic.
     */
    public function returnAsset(int $assignmentId, array $params = []): void
    {
        $db = \Config\Database::connect();
        $assignment = $db->table('asset_assignments')->where('id', $assignmentId)->get()->getRowArray();
        if (! $assignment) {
            throw new \InvalidArgumentException("Assignment #{$assignmentId} tidak ditemukan");
        }
        if (! empty($assignment['returned_at'])) {
            throw new \RuntimeException("Assignment ini sudah closed pada {$assignment['returned_at']}");
        }

        $asset = $db->table('assets')->where('id', $assignment['asset_id'])->get()->getRowArray();
        $emp   = $db->table('employees')->where('id', $assignment['employee_id'])->get()->getRowArray();

        $now = date('Y-m-d H:i:s');
        $cond = strtoupper((string) ($params['condition_when_returned'] ?? 'GOOD'));
        $validConds = ['NEW','GOOD','FAIR','POOR','DAMAGED','LOST'];
        if (! in_array($cond, $validConds, true)) $cond = 'GOOD';

        $update = [
            'returned_at'             => $params['returned_at'] ?? $now,
            'condition_when_returned' => $cond,
            'damage_note'             => $params['damage_note'] ?? null,
            'returned_by'             => $params['returned_by'] ?? null,
            'returned_acknowledged_at'=> $now,
            'updated_at'              => $now,
        ];
        $db->table('asset_assignments')->where('id', $assignmentId)->update($update);

        // Tentukan status asset baru
        $newStatus = match ($cond) {
            'LOST'    => 'LOST',
            'DAMAGED' => 'REPAIR',
            default   => 'AVAILABLE',
        };
        // Update asset condition utama (hanya kalau bukan LOST/REPAIR)
        $assetUpdate = [
            'status'                => $newStatus,
            'current_assignment_id' => null,
            'current_employee_id'   => null,
            'updated_at'            => $now,
        ];
        if ($newStatus === 'AVAILABLE') {
            // Update kondisi dasar ke kondisi pengembalian (dropdown utama)
            $assetUpdate['condition'] = match ($cond) {
                'NEW' => 'NEW',
                'GOOD' => 'GOOD',
                'FAIR' => 'FAIR',
                'POOR' => 'POOR',
                default => $asset['condition'] ?? 'GOOD',
            };
        }
        $db->table('assets')->where('id', $assignment['asset_id'])->update($assetUpdate);

        // Render BAST balikan
        $assignment = array_merge($assignment, $update);
        $bast = $this->renderBastHtml($assignment, $asset, $emp, 'RETURN');
        $db->table('asset_assignments')->where('id', $assignmentId)->update([
            'bast_return_html' => $bast,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Render BAST HTML. Mode: ASSIGN (penyerahan) atau RETURN (pengembalian).
     */
    public function renderBastHtml(array $assignment, array $asset, array $employee, string $mode = 'ASSIGN'): string
    {
        $db = \Config\Database::connect();
        $company = $db->table('companies')->where('id', $assignment['company_id'])->get()->getRowArray() ?: [];
        $position = ! empty($employee['position_id'])
            ? ($db->table('positions')->where('id', (int) $employee['position_id'])->get()->getRowArray() ?: [])
            : [];
        $department = ! empty($employee['department_id'])
            ? ($db->table('departments')->where('id', (int) $employee['department_id'])->get()->getRowArray() ?: [])
            : [];

        $title = $mode === 'RETURN' ? 'BERITA ACARA PENGEMBALIAN BARANG' : 'BERITA ACARA SERAH TERIMA BARANG';
        $verb  = $mode === 'RETURN' ? 'mengembalikan kepada' : 'menyerahkan kepada';
        $verbAck = $mode === 'RETURN' ? 'menerima kembali' : 'menerima dengan baik';

        $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                   'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $fmtDate = function ($d) use ($months) {
            if (! $d) return '-';
            $ts = strtotime($d);
            return date('j', $ts) . ' ' . $months[(int) date('n', $ts)] . ' ' . date('Y', $ts);
        };

        $eventDate = $mode === 'RETURN'
            ? ($assignment['returned_at'] ?? date('Y-m-d H:i:s'))
            : ($assignment['assigned_at'] ?? date('Y-m-d H:i:s'));

        $h = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
           . 'body{font-family:Arial,sans-serif;font-size:11px;color:#222;padding:24px}'
           . 'h1{font-size:14px;text-align:center;margin:0 0 4px;text-decoration:underline}'
           . 'h2{font-size:12px;margin:14px 0 6px}'
           . 'table{border-collapse:collapse;width:100%;margin-top:6px}'
           . 'td{padding:5px 8px;vertical-align:top}'
           . '.kv td{border:1px solid #ccc}'
           . '.right{text-align:right}.center{text-align:center}'
           . '</style></head><body>';
        $h .= '<h1>' . $title . '</h1>';
        $h .= '<div class="center" style="font-size:11px;margin-bottom:14px">';
        $h .= htmlspecialchars((string) ($company['name'] ?? '-'), ENT_QUOTES);
        $h .= '</div>';

        $h .= '<p>Pada hari ini, ' . $fmtDate($eventDate) . ', yang bertanda tangan di bawah ini:</p>';

        $h .= '<h2>PIHAK PERTAMA — ' . ($mode === 'RETURN' ? 'Penerima Pengembalian' : 'Pemberi') . '</h2>';
        $h .= '<table class="kv"><tbody>';
        $h .= '<tr><td style="width:30%">Nama</td><td>: HR / Manajemen ' . htmlspecialchars((string) ($company['name'] ?? '-'), ENT_QUOTES) . '</td></tr>';
        $h .= '<tr><td>Alamat</td><td>: ' . htmlspecialchars((string) ($company['address'] ?? '-'), ENT_QUOTES) . '</td></tr>';
        $h .= '</tbody></table>';

        $h .= '<h2>PIHAK KEDUA — ' . ($mode === 'RETURN' ? 'Pengembali' : 'Penerima') . '</h2>';
        $h .= '<table class="kv"><tbody>';
        $h .= '<tr><td style="width:30%">Nama</td><td>: <strong>' . htmlspecialchars((string) ($employee['full_name'] ?? '-'), ENT_QUOTES) . '</strong></td></tr>';
        $h .= '<tr><td>NIP</td><td>: ' . htmlspecialchars((string) ($employee['employee_number'] ?? '-'), ENT_QUOTES) . '</td></tr>';
        $h .= '<tr><td>Jabatan</td><td>: ' . htmlspecialchars((string) ($position['name'] ?? '-'), ENT_QUOTES) . '</td></tr>';
        $h .= '<tr><td>Departemen</td><td>: ' . htmlspecialchars((string) ($department['name'] ?? '-'), ENT_QUOTES) . '</td></tr>';
        $h .= '</tbody></table>';

        $h .= '<p style="margin-top:14px">Dengan ini Pihak Pertama ' . $verb . ' Pihak Kedua, '
            . 'dan Pihak Kedua ' . $verbAck . ' barang dengan rincian sebagai berikut:</p>';

        $h .= '<h2>RINCIAN BARANG</h2>';
        $h .= '<table class="kv"><tbody>';
        $h .= '<tr><td style="width:30%">No. Aset</td><td>: <strong>' . htmlspecialchars((string) ($asset['asset_number'] ?? '-'), ENT_QUOTES) . '</strong></td></tr>';
        $h .= '<tr><td>Nama</td><td>: ' . htmlspecialchars((string) ($asset['name'] ?? '-'), ENT_QUOTES) . '</td></tr>';
        $h .= '<tr><td>Kategori</td><td>: ' . htmlspecialchars((string) ($asset['category'] ?? '-'), ENT_QUOTES) . '</td></tr>';
        if (! empty($asset['brand']) || ! empty($asset['model'])) {
            $h .= '<tr><td>Merek / Model</td><td>: ' . htmlspecialchars(($asset['brand'] ?? '') . ' ' . ($asset['model'] ?? ''), ENT_QUOTES) . '</td></tr>';
        }
        if (! empty($asset['serial_number'])) {
            $h .= '<tr><td>Serial Number</td><td>: ' . htmlspecialchars((string) $asset['serial_number'], ENT_QUOTES) . '</td></tr>';
        }
        $cond = $mode === 'RETURN'
            ? ($assignment['condition_when_returned'] ?? '-')
            : ($assignment['condition_when_assigned'] ?? '-');
        $h .= '<tr><td>Kondisi saat ' . ($mode === 'RETURN' ? 'pengembalian' : 'penyerahan') . '</td><td>: <strong>' . $cond . '</strong></td></tr>';
        if ($mode === 'RETURN' && ! empty($assignment['damage_note'])) {
            $h .= '<tr><td>Catatan kerusakan</td><td>: ' . htmlspecialchars((string) $assignment['damage_note'], ENT_QUOTES) . '</td></tr>';
        }
        if ($mode === 'ASSIGN' && ! empty($assignment['purpose'])) {
            $h .= '<tr><td>Tujuan penggunaan</td><td>: ' . htmlspecialchars((string) $assignment['purpose'], ENT_QUOTES) . '</td></tr>';
        }
        if ($mode === 'ASSIGN' && ! empty($assignment['expected_return'])) {
            $h .= '<tr><td>Diharapkan kembali</td><td>: ' . $fmtDate($assignment['expected_return']) . '</td></tr>';
        }
        $h .= '</tbody></table>';

        $h .= '<p style="margin-top:18px">Demikian Berita Acara ini dibuat dengan sebenarnya '
            . 'untuk dipergunakan sebagaimana mestinya.</p>';

        $h .= '<table style="width:100%;margin-top:36px;border:none"><tr style="border:none">'
            . '<td style="border:none;width:50%;text-align:center">'
            . '<div>Pihak Pertama,</div><div style="height:80px"></div>'
            . '<div style="text-decoration:underline"><strong>(______________________)</strong></div>'
            . '<div>HR / Manajemen</div>'
            . '</td>'
            . '<td style="border:none;width:50%;text-align:center">'
            . '<div>Pihak Kedua,</div><div style="height:80px"></div>'
            . '<div style="text-decoration:underline"><strong>' . htmlspecialchars((string) ($employee['full_name'] ?? '-'), ENT_QUOTES) . '</strong></div>'
            . '<div>' . htmlspecialchars((string) ($position['name'] ?? '-'), ENT_QUOTES) . '</div>'
            . '</td>'
            . '</tr></table>';

        $h .= '</body></html>';
        return $h;
    }

    /**
     * Aset yang sedang dipegang seorang karyawan.
     * @return array<int,array>
     */
    public function listForEmployee(int $employeeId, bool $includeReturned = false): array
    {
        $db = \Config\Database::connect();
        $builder = $db->table('asset_assignments aa')
            ->select('aa.*, a.asset_number, a.name AS asset_name, a.category, a.brand, a.model, a.serial_number')
            ->join('assets a', 'a.id = aa.asset_id')
            ->where('aa.employee_id', $employeeId)
            ->orderBy('aa.assigned_at', 'DESC');
        if (! $includeReturned) $builder->where('aa.returned_at IS NULL');
        return $builder->get()->getResultArray();
    }
}