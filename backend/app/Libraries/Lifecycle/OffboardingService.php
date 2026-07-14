<?php

namespace App\Libraries\Lifecycle;

use Config\Database;
use Config\Services;

/**
 * OffboardingService
 *
 * Domain logic untuk offboarding karyawan: clearance management,
 * final settlement → payroll integration, BPJS deactivation tracking.
 *
 * Tanggung jawab:
 *
 *   1. Clearance (OFF-4) — track 3 jenis clearance:
 *      - Asset clearance (laptop, ID card, kendaraan, dll dikembalikan)
 *      - Finance clearance (pinjaman, kasbon, hutang lunas)
 *      - BPJS deactivation (HR sudah report ke e-Dabu / SiPP)
 *      Tanpa asset clearance, paklaring & final settlement tidak boleh terbit.
 *
 *   2. Status assessment — `status($resignationId)` mengembalikan ringkasan
 *      kelengkapan offboarding dengan blocker reasons untuk UI.
 *
 *   3. Toggle clearance — HR boleh tandai asset/finance/BPJS clearance OK
 *      dengan optional note (untuk exception terdokumentasi).
 *
 *   4. Final settlement (OFF-3) — kalau sudah ada severance_calculations
 *      yang FINALIZED, link ke payroll period aktif di month resign_date.
 *      Severance dihitung pakai PPh21 progressive terpisah (PER-PJ 16/PJ/2016
 *      Pasal 13), bukan TER bulanan. Kewajiban bayar maks 7 hari setelah
 *      resign_date (UU 13/2003 Pasal 156 ayat 4).
 *
 *   5. BPJS SLA tracking (HIRE-4 + OFF-6) — dipanggil dari command harian
 *      untuk find karyawan yang H-25 / H-30 belum BPJS_REGISTER (onboarding)
 *      atau H+5 belum BPJS_DEACTIVATE (offboarding) dan kirim escalation.
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item OFF-3, OFF-4, OFF-6, HIRE-4.
 */
class OffboardingService
{
    /**
     * Build status lengkap offboarding untuk satu resignation request,
     * termasuk semua clearance gates dan link dokumen.
     *
     * @return array{
     *   resignation_id: int,
     *   employee_id: int,
     *   employee_name: string,
     *   status: string,
     *   resign_date: ?string,
     *   clearance: array{
     *     assets: array{ok: bool, returned_at: ?string, note: ?string, pending_count: int, pending_assets: array},
     *     finance: array{ok: bool, blocker: ?string, open_loans: int, open_advances: int},
     *     bpjs: array{ok: bool, deactivated_at: ?string, days_overdue: int, sla_due: ?string},
     *   },
     *   paklaring: array{generated: bool, contract_number: ?string, generated_at: ?string},
     *   final_settlement: array{linked: bool, payslip_id: ?int, amount: ?float, paid_at: ?string},
     *   ready_for_paklaring: bool,
     *   ready_for_final_settlement: bool,
     * }
     */
    public function status(int $resignationId): array
    {
        $db = Database::connect();
        $companyId = $this->activeCompanyId();
        $resign = $db->table('resignation_requests')
            ->where('id', $resignationId)
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $resign) {
            throw new \InvalidArgumentException("Resignation request #{$resignationId} tidak ditemukan");
        }
        $emp = $db->table('employees')->where('id', (int) $resign['employee_id'])
            ->where('company_id', $companyId)->get()->getRowArray() ?: [];

        $assets  = $this->assetClearanceStatus($db, $companyId, (int) $resign['employee_id'], $resign);
        $finance = $this->financeClearanceStatus($db, $companyId, (int) $resign['employee_id']);
        $bpjs    = $this->bpjsDeactivationStatus($resign);

        $resignDate = $emp['resign_date'] ?? $resign['effective_date'] ?? $resign['last_working_date'] ?? null;
        $resignReached = $resignDate && strtotime((string) $resignDate) <= strtotime(date('Y-m-d'));
        $approved = $resign['status'] === 'APPROVED';

        return [
            'resignation_id' => (int) $resign['id'],
            'employee_id'    => (int) $resign['employee_id'],
            'employee_name'  => (string) ($emp['full_name'] ?? '#' . $resign['employee_id']),
            'status'         => (string) $resign['status'],
            'resign_date'    => $resignDate,
            'clearance' => [
                'assets'  => $assets,
                'finance' => $finance,
                'bpjs'    => $bpjs,
            ],
            'paklaring' => [
                'generated'       => ! empty($resign['paklaring_id']),
                'contract_number' => $this->resolvePaklaringNumber($db, $resign),
                'generated_at'    => $resign['paklaring_generated_at'] ?? null,
            ],
            'final_settlement' => [
                'linked'    => ! empty($resign['final_settlement_payslip_id']),
                'payslip_id'=> $resign['final_settlement_payslip_id'] !== null ? (int) $resign['final_settlement_payslip_id'] : null,
                'amount'    => $resign['final_settlement_amount'] !== null ? (float) $resign['final_settlement_amount'] : null,
                'paid_at'   => $resign['final_settlement_paid_at'] ?? null,
            ],
            // Ready flags — untuk frontend supaya tombol di-disable / di-enable cepat.
            'ready_for_paklaring' =>
                $approved && $resignReached && $assets['ok'],
            'ready_for_final_settlement' =>
                $approved && $resignReached && $assets['ok'] && $finance['ok'],
        ];
    }

    /**
     * Toggle clearance asset. Kalau exception (laptop hilang dengan
     * polisi report) → wajib catatan note minimum 10 karakter.
     */
    public function setAssetClearance(int $resignationId, int $userId, bool $cleared, ?string $note = null): array
    {
        $db = Database::connect();
        $companyId = $this->activeCompanyId();
        $resign = $db->table('resignation_requests')
            ->where('id', $resignationId)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $resign) {
            throw new \InvalidArgumentException("Resignation #{$resignationId} tidak ditemukan");
        }

        $now = date('Y-m-d H:i:s');
        if ($cleared) {
            // Verifikasi tidak ada aset yang masih ASSIGNED — kecuali exception note diisi
            $stillAssigned = $db->table('assets')
                ->where('company_id', $companyId)
                ->where('current_employee_id', (int) $resign['employee_id'])
                ->where('status', 'ASSIGNED')
                ->where('deleted_at IS NULL')
                ->countAllResults();
            if ($stillAssigned > 0 && ! $note) {
                throw new \RuntimeException(
                    "Masih ada {$stillAssigned} aset yang status ASSIGNED. "
                    . "Selesaikan return asset terlebih dahulu, atau berikan catatan exception "
                    . "(mis. laporan polisi untuk laptop hilang) minimum 10 karakter."
                );
            }
            if ($stillAssigned > 0 && strlen(trim($note ?? '')) < 10) {
                throw new \InvalidArgumentException(
                    "Catatan exception minimum 10 karakter untuk override aset yang belum dikembalikan."
                );
            }
            $db->table('resignation_requests')->where('id', $resignationId)
                ->where('company_id', $companyId)->update([
                'assets_returned_at'   => $now,
                'assets_clearance_note'=> $note,
                'updated_at'           => $now,
            ]);
            $action = 'asset_cleared';
            $description = "Asset clearance OK untuk resignation #{$resignationId}"
                . ($stillAssigned > 0 ? " (override: {$stillAssigned} aset belum balik, exception didokumentasi)" : '');
            $severity = $stillAssigned > 0 ? 'WARNING' : 'NOTICE';
        } else {
            // Reset (HR salah klik / butuh re-verify)
            $db->table('resignation_requests')->where('id', $resignationId)
                ->where('company_id', $companyId)->update([
                'assets_returned_at'   => null,
                'assets_clearance_note'=> $note,
                'updated_at'           => $now,
            ]);
            $action = 'asset_unclear';
            $description = "Asset clearance di-reset untuk resignation #{$resignationId}";
            $severity = 'WARNING';
        }

        try {
            Services::audit()->forModule('OFFBOARDING')->log(
                action: 'offboarding.' . $action,
                entity: 'resignation_requests',
                entityId: $resignationId,
                description: $description,
                severity: $severity,
                metadata: ['note' => $note],
            );
        } catch (\Throwable $e) {
            log_message('warning', 'Audit offboarding clearance failed: ' . $e->getMessage());
        }

        return $this->status($resignationId);
    }

    /**
     * Mark BPJS deactivated. Dipanggil setelah HR submit deactivate ke
     * e-Dabu (Kesehatan) & SiPP (TK). UU SJSN: wajib H+7 dari resign_date.
     */
    public function setBpjsDeactivated(int $resignationId, int $userId, ?string $note = null): array
    {
        $db = Database::connect();
        $companyId = $this->activeCompanyId();
        $resign = $db->table('resignation_requests')
            ->where('id', $resignationId)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $resign) {
            throw new \InvalidArgumentException("Resignation #{$resignationId} tidak ditemukan");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('resignation_requests')->where('id', $resignationId)
            ->where('company_id', $companyId)->update([
            'bpjs_deactivated_at' => $now,
            'updated_at'          => $now,
        ]);

        // Auto-complete task BPJS_DEACTIVATE (kalau ada di template)
        try {
            Services::onboarding()->completeTaskByKey(
                (int) $resign['employee_id'],
                'OFFBOARD',
                'BPJS_DEACTIVATE',
                $note ?: 'BPJS Kesehatan & TK telah di-deactivate via e-Dabu/SiPP.',
            );
        } catch (\Throwable $e) {
            // Task mungkin tidak ada di template — silent skip
        }

        try {
            Services::audit()->forModule('OFFBOARDING')->log(
                action: 'offboarding.bpjs_deactivated',
                entity: 'resignation_requests',
                entityId: $resignationId,
                description: "BPJS deactivated untuk resignation #{$resignationId}",
                severity: 'NOTICE',
                metadata: ['note' => $note],
            );
        } catch (\Throwable $e) {}

        return $this->status($resignationId);
    }

    /**
     * Cek status clearance asset:
     *   - assets_returned_at terisi → OK
     *   - assets_clearance_note terisi → OK (exception terdokumentasi)
     *   - kalau tidak ada aset assigned → auto-OK (tidak perlu HR action)
     *   - selain itu → tidak OK + daftar aset yang belum balik
     */
    private function assetClearanceStatus(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        int $employeeId,
        array $resign,
    ): array {
        $alreadyCleared = ! empty($resign['assets_returned_at']);
        $hasException   = ! empty(trim((string) ($resign['assets_clearance_note'] ?? '')));

        $pending = $db->table('assets')
            ->select('id, asset_number, name, category')
            ->where('company_id', $companyId)
            ->where('current_employee_id', $employeeId)
            ->where('status', 'ASSIGNED')
            ->where('deleted_at IS NULL')
            ->orderBy('id', 'ASC')
            ->limit(50)
            ->get()->getResultArray();

        $pendingCount = count($pending);

        // OK kalau: explicit cleared OR (no pending & no override needed)
        $ok = $alreadyCleared || $hasException || $pendingCount === 0;

        return [
            'ok'             => $ok,
            'returned_at'    => $resign['assets_returned_at'] ?? null,
            'note'           => $resign['assets_clearance_note'] ?? null,
            'pending_count'  => $pendingCount,
            'pending_assets' => array_map(static fn ($a) => [
                'id'            => (int) $a['id'],
                'asset_number'  => $a['asset_number'],
                'name'          => $a['name'],
                'category'      => $a['category'],
            ], $pending),
        ];
    }

    /**
     * Cek finance clearance: tidak ada loan/advance aktif yang belum lunas.
     * Tabel `loans` dan `cash_advances` diasumsikan punya status ENUM
     * dengan APPROVED/DISBURSED yang bermakna "masih outstanding".
     */
    private function financeClearanceStatus(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        int $employeeId,
    ): array {
        $openLoans = 0;
        $openAdvances = 0;
        try {
            if ($db->tableExists('loans')) {
                $openLoans = $db->table('loans')
                    ->where('company_id', $companyId)
                    ->where('employee_id', $employeeId)
                    ->whereIn('status', ['APPROVED', 'DISBURSED', 'PARTIAL_REPAID'])
                    ->where('deleted_at IS NULL')
                    ->countAllResults();
            }
        } catch (\Throwable $e) {}
        try {
            if ($db->tableExists('cash_advances')) {
                $openAdvances = $db->table('cash_advances')
                    ->where('company_id', $companyId)
                    ->where('employee_id', $employeeId)
                    ->whereIn('status', ['APPROVED', 'DISBURSED'])
                    ->where('deleted_at IS NULL')
                    ->countAllResults();
            }
        } catch (\Throwable $e) {}

        $total = $openLoans + $openAdvances;
        $ok = $total === 0;
        $blocker = null;
        if (! $ok) {
            $parts = [];
            if ($openLoans > 0) $parts[] = "{$openLoans} pinjaman aktif";
            if ($openAdvances > 0) $parts[] = "{$openAdvances} kasbon belum lunas";
            $blocker = 'Belum lunas: ' . implode(', ', $parts) . '. '
                . 'Dapat dipotong dari final settlement, atau lunaskan terlebih dahulu.';
        }

        return [
            'ok'             => $ok,
            'blocker'        => $blocker,
            'open_loans'     => $openLoans,
            'open_advances'  => $openAdvances,
        ];
    }

    /**
     * Cek BPJS deactivation status. SLA: H+7 dari resign_date.
     */
    private function bpjsDeactivationStatus(array $resign): array
    {
        $deactivatedAt = $resign['bpjs_deactivated_at'] ?? null;
        $resignDate = $resign['effective_date'] ?? $resign['last_working_date'] ?? null;
        $slaDue = $resignDate ? date('Y-m-d', strtotime($resignDate . ' +7 days')) : null;

        $daysOverdue = 0;
        if (! $deactivatedAt && $slaDue && strtotime($slaDue) < strtotime(date('Y-m-d'))) {
            $daysOverdue = (int) round((strtotime(date('Y-m-d')) - strtotime($slaDue)) / 86400);
        }

        return [
            'ok'             => ! empty($deactivatedAt),
            'deactivated_at' => $deactivatedAt,
            'days_overdue'   => $daysOverdue,
            'sla_due'        => $slaDue,
        ];
    }

    private function resolvePaklaringNumber(
        \CodeIgniter\Database\BaseConnection $db,
        array $resign,
    ): ?string {
        if (empty($resign['paklaring_id'])) return null;
        try {
            $row = $db->table('generated_contracts')
                ->select('contract_number')
                ->where('id', (int) $resign['paklaring_id'])
                ->where('company_id', (int) $resign['company_id'])
                ->get()->getRowArray();
            return $row ? (string) $row['contract_number'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Link severance FINALIZED ke payroll periode aktif (final settlement).
     *
     * Aturan:
     *   1. Severance harus status FINALIZED (sudah disetujui & dihitung).
     *   2. Asset clearance harus OK (gate OFF-4).
     *   3. Cari payroll_periods yang status DRAFT/CALCULATED/REVIEW di
     *      bulan resign_date. Kalau tidak ada → throw error (HR harus buat
     *      periode payroll sebelum link).
     *   4. Insert / update payroll_entries untuk employee dengan komponen:
     *      - Gaji prorata (kalau resign tengah bulan)
     *      - UPH (uang penggantian hak — sisa cuti, ongkos pulang)
     *      - Pesangon + UPMK + UPH (gabungan dari severance)
     *      - PPh21 progressive terpisah (bukan TER bulanan)
     *   5. Update resignation_requests dengan link ke payslip + amount.
     *
     * Best-effort untuk MVP: kalau payroll_entries belum mendukung komponen
     * severance secara native, simpan amount cache di resignation_requests
     * + audit log dengan severity CRITICAL supaya finance bisa proses manual.
     *
     * @return array{
     *   resignation_id: int,
     *   payslip_id: ?int,
     *   amount: float,
     *   period_id: ?int,
     *   manual_action_required: bool,
     *   notes: string,
     * }
     */
    public function linkFinalSettlement(int $resignationId, int $userId): array
    {
        $db = Database::connect();
        $companyId = $this->activeCompanyId();
        $resign = $db->table('resignation_requests')
            ->where('id', $resignationId)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $resign) {
            throw new \InvalidArgumentException("Resignation #{$resignationId} tidak ditemukan");
        }
        if ($resign['status'] !== 'APPROVED') {
            throw new \RuntimeException("Resignation harus APPROVED dulu (current: {$resign['status']})");
        }

        // Gate asset clearance
        $emp = $db->table('employees')->where('id', (int) $resign['employee_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $emp) {
            throw new \RuntimeException('Employee does not belong to the active company');
        }
        $assetStatus = $this->assetClearanceStatus($db, $companyId, (int) $resign['employee_id'], $resign);
        if (! $assetStatus['ok']) {
            throw new \RuntimeException(
                "Asset clearance belum OK ({$assetStatus['pending_count']} aset belum dikembalikan). "
                . "Selesaikan terlebih dahulu sebelum proses final settlement."
            );
        }
        $financeStatus = $this->financeClearanceStatus($db, $companyId, (int) $resign['employee_id']);
        if (! $financeStatus['ok']) {
            throw new \RuntimeException((string) $financeStatus['blocker']);
        }

        // Severance — ambil yang FINALIZED untuk employee + termination_date dekat resign_date
        $severance = null;
        if ($db->tableExists('severance_calculations')) {
            $severance = $db->table('severance_calculations')
                ->where('company_id', $companyId)
                ->where('employee_id', (int) $resign['employee_id'])
                ->where('status', 'FINALIZED')
                ->where('deleted_at IS NULL')
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()->getRowArray();
        }

        // Hitung total final settlement amount.
        // Kalau ada severance FINALIZED, ambil dari kolom total_amount.
        // Tambah komponen lain (sisa cuti, gaji prorata) dari estimasi.
        $resignDate = $resign['effective_date'] ?? $resign['last_working_date'];
        $proratedSalary = $this->estimateProratedSalary($db, $emp, (string) $resignDate);
        $unusedLeaveCash = $severance
            ? 0.0
            : $this->estimateUnusedLeaveCash($db, $emp, (string) $resignDate);
        $severanceAmount = $severance ? (float) ($severance['total_amount'] ?? 0) : 0.0;
        $thr = (new ResignationThrEligibility())->resolve($db, $companyId, $emp, (string) $resignDate);
        $thrAmount = $thr['eligible'] ? (float) $thr['amount'] : 0.0;

        $totalAmount = $proratedSalary + $unusedLeaveCash + $severanceAmount + $thrAmount;

        // Cari payroll period aktif di bulan resign
        $period = null;
        if ($db->tableExists('payroll_periods')) {
            $year = (int) date('Y', strtotime($resignDate));
            $month = (int) date('n', strtotime($resignDate));
            $period = $db->table('payroll_periods')
                ->where('company_id', $companyId)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->whereIn('status', ['DRAFT', 'CALCULATED'])
                ->where('deleted_at IS NULL')
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()->getRowArray();
        }

        $now = date('Y-m-d H:i:s');
        $payslipId = null;
        $manualActionRequired = false;
        $notes = '';

        if ($period && $db->tableExists('payslips')) {
            // Cari payslip employee di period ini
            $payslip = $db->table('payslips')
                ->where('company_id', $companyId)
                ->where('payroll_period_id', (int) $period['id'])
                ->where('employee_id', (int) $emp['id'])
                ->where('deleted_at IS NULL')
                ->limit(1)
                ->get()->getRowArray();
            if ($payslip) {
                $payslipId = (int) $payslip['id'];
                $notes = "Final settlement direferensikan ke payslip existing #{$payslipId} di periode {$period['code']}. "
                    . "HR perlu adjust komponen secara manual (severance + sisa cuti) di Payroll module — "
                    . "saat ini sistem belum auto-merge komponen severance ke payslip.";
                $manualActionRequired = true;
            } else {
                $notes = "Periode payroll {$period['code']} ditemukan tapi karyawan belum punya payslip di periode tersebut. "
                    . "HR perlu jalankan calculate periode dulu.";
                $manualActionRequired = true;
            }
        } else {
            $notes = "Belum ada periode payroll DRAFT/CALCULATED/REVIEW di bulan resign ({$resignDate}). "
                . "Buat periode payroll terlebih dahulu, atau bayar manual via transfer bank "
                . "dan tandai final_settlement_paid_at lewat endpoint mark-paid.";
            $manualActionRequired = true;
        }

        if ($period && $db->tableExists('payroll_one_time_components')) {
            if ($unusedLeaveCash > 0) {
                $this->upsertOneTimeComponent(
                    $db, $companyId, (int) $period['id'], (int) $emp['id'], $resignationId, $userId,
                    'FINAL_LEAVE', 'Pembayaran Sisa Cuti', 'EARNING', 'FINAL_SETTLEMENT', $unusedLeaveCash, true,
                );
            }
            if ($thrAmount > 0) {
                $this->upsertOneTimeComponent(
                    $db, $companyId, (int) $period['id'], (int) $emp['id'], $resignationId, $userId,
                    'THR_RESIGNATION', 'THR Hak Saat Resign - ' . ($thr['holiday_name'] ?? 'Hari Raya'),
                    'EARNING', 'THR', $thrAmount, true,
                );
            }
            if ($severanceAmount > 0) {
                $this->upsertOneTimeComponent(
                    $db, $companyId, (int) $period['id'], (int) $emp['id'], $resignationId, $userId,
                    'SEVERANCE', 'Pesangon dan Penggantian Hak', 'EARNING', 'SEVERANCE', $severanceAmount, false,
                );
                $priorSeverance = $this->priorSeveranceInTwoCalendarYears(
                    $db, $companyId, (int) $emp['id'], $resignationId, (int) $period['period_year'],
                );
                $severanceTax = self::severanceFinalTax($priorSeverance + $severanceAmount)
                    - self::severanceFinalTax($priorSeverance);
                if ($severanceTax > 0) {
                    $this->upsertOneTimeComponent(
                        $db, $companyId, (int) $period['id'], (int) $emp['id'], $resignationId, $userId,
                        'SEVERANCE_TAX', 'PPh 21 Final Pesangon', 'DEDUCTION', 'PPH21_FINAL', $severanceTax, false,
                    );
                }
            }
            $payslipId = null;
            $manualActionRequired = false;
            $notes = "Komponen final settlement ditautkan ke periode {$period['code']}. "
                . 'Jalankan calculate/re-calculate agar payslip final dan tautan resignation diperbarui.';
        }

        // Update resignation request dengan link & amount cache
        $db->table('resignation_requests')->where('id', $resignationId)
            ->where('company_id', $companyId)->update([
            'final_settlement_payslip_id' => $payslipId,
            'final_settlement_amount'     => $totalAmount,
            'updated_at'                  => $now,
        ]);

        // Audit — severity CRITICAL karena involve finansial dengan tenggat hukum
        try {
            Services::audit()->forModule('OFFBOARDING')->log(
                action: 'offboarding.final_settlement_link',
                entity: 'resignation_requests',
                entityId: $resignationId,
                description: "Link final settlement: total " . number_format($totalAmount, 0, ',', '.')
                    . " (gaji prorata: " . number_format($proratedSalary, 0, ',', '.')
                    . ", sisa cuti: " . number_format($unusedLeaveCash, 0, ',', '.')
                    . ", pesangon: " . number_format($severanceAmount, 0, ',', '.')
                    . ", THR resign: " . number_format($thrAmount, 0, ',', '.') . ")"
                    . ($payslipId ? " ke payslip #{$payslipId}" : ' (belum ke payslip — manual)'),
                severity: 'CRITICAL',
                metadata: [
                    'total_amount'      => $totalAmount,
                    'prorated_salary'   => $proratedSalary,
                    'unused_leave_cash' => $unusedLeaveCash,
                    'severance_amount'  => $severanceAmount,
                    'thr_resignation'   => $thr,
                    'payslip_id'        => $payslipId,
                    'period_id'         => $period ? (int) $period['id'] : null,
                ],
            );
        } catch (\Throwable $e) {}

        return [
            'resignation_id'         => $resignationId,
            'payslip_id'             => $payslipId,
            'amount'                 => $totalAmount,
            'period_id'              => $period ? (int) $period['id'] : null,
            'manual_action_required' => $manualActionRequired,
            'notes'                  => $notes,
        ];
    }

    /**
     * Tandai final settlement sudah dibayar manual (mis. transfer bank
     * di luar payroll). Diperbolehkan kalau payroll period belum dibuat
     * di bulan resign — yang penting tercatat sudah bayar dengan timestamp
     * + bukti, supaya tenggat 7 hari (UU 13/2003 Pasal 156) tetap diaudit.
     */
    public function markFinalSettlementPaid(
        int $resignationId,
        int $userId,
        float $amount,
        ?string $proofPath = null,
        ?string $note = null,
    ): array {
        $db = Database::connect();
        $companyId = $this->activeCompanyId();
        $resign = $db->table('resignation_requests')
            ->where('id', $resignationId)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $resign) throw new \InvalidArgumentException("Resignation #{$resignationId} tidak ditemukan");
        if ($resign['status'] !== 'APPROVED') {
            throw new \RuntimeException("Resignation harus APPROVED dulu (current: {$resign['status']})");
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Jumlah final settlement harus > 0');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('resignation_requests')->where('id', $resignationId)
            ->where('company_id', $companyId)->update([
            'final_settlement_amount'  => $amount,
            'final_settlement_paid_at' => $now,
            'updated_at'               => $now,
        ]);

        // Compliance check: kalau lebih dari 7 hari setelah resign_date,
        // log severity CRITICAL — UU 13/2003 Pasal 156 ayat 4 wajibkan
        // pembayaran ≤ 7 hari.
        $resignDate = $resign['effective_date'] ?? $resign['last_working_date'] ?? null;
        $daysLate = 0;
        $isLate = false;
        if ($resignDate) {
            $daysLate = (int) round((strtotime($now) - strtotime($resignDate)) / 86400) - 7;
            $isLate = $daysLate > 0;
        }

        try {
            Services::audit()->forModule('OFFBOARDING')->log(
                action: 'offboarding.final_settlement_paid',
                entity: 'resignation_requests',
                entityId: $resignationId,
                description: "Final settlement dibayar: " . number_format($amount, 0, ',', '.')
                    . ($isLate ? " (TERLAMBAT {$daysLate} hari dari batas 7-hari UU 13/2003 Pasal 156)" : ''),
                severity: $isLate ? 'CRITICAL' : 'NOTICE',
                metadata: [
                    'amount'      => $amount,
                    'proof_path'  => $proofPath,
                    'note'        => $note,
                    'days_late'   => $daysLate,
                    'is_late'     => $isLate,
                ],
            );
        } catch (\Throwable $e) {}

        return $this->status($resignationId);
    }

    private function estimateProratedSalary(
        \CodeIgniter\Database\BaseConnection $db,
        array $emp,
        string $resignDate,
    ): float {
        // Estimasi gaji prorata dari awal bulan resign sampai resign_date.
        // Default 22 working days/month — caller bisa override via PayrollSettings.
        $basic = (float) ($emp['basic_salary'] ?? 0) + (float) ($emp['fixed_allowance'] ?? 0);
        if ($basic <= 0) return 0.0;
        $monthStart = date('Y-m-01', strtotime($resignDate));
        $resignDay = (int) date('j', strtotime($resignDate));
        $totalDaysInMonth = (int) date('t', strtotime($resignDate));
        // Linear prorata by calendar day. Untuk hitungan kerja yang lebih
        // akurat (skip weekend/libur) HR bisa adjust manual di payslip.
        return round($basic * ($resignDay / $totalDaysInMonth), 2);
    }

    /** PPh 21 final pesangon: 0%/5%/15%/25% progressive brackets. */
    public static function severanceFinalTax(float $gross): float
    {
        $remaining = max(0.0, $gross - 50_000_000);
        $tax = min($remaining, 50_000_000) * 0.05;
        $remaining = max(0.0, $remaining - 50_000_000);
        $tax += min($remaining, 400_000_000) * 0.15;
        $remaining = max(0.0, $remaining - 400_000_000);
        $tax += $remaining * 0.25;

        return round($tax, 2);
    }

    private function priorSeveranceInTwoCalendarYears(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        int $employeeId,
        int $currentResignationId,
        int $currentYear,
    ): float {
        $row = $db->table('payroll_one_time_components c')
            ->selectSum('c.amount', 'total')
            ->join('payroll_periods p', 'p.id = c.payroll_period_id')
            ->where('c.company_id', $companyId)
            ->where('p.company_id', $companyId)
            ->where('c.employee_id', $employeeId)
            ->where('c.source_type', 'RESIGNATION')
            ->where('c.source_id !=', $currentResignationId)
            ->where('c.code', 'SEVERANCE')
            ->where('p.period_year >=', $currentYear - 1)
            ->where('p.period_year <=', $currentYear)
            ->get()->getRowArray();

        return (float) ($row['total'] ?? 0);
    }

    private function upsertOneTimeComponent(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        int $periodId,
        int $employeeId,
        int $resignationId,
        int $userId,
        string $code,
        string $name,
        string $type,
        string $category,
        float $amount,
        bool $isTaxable,
    ): void {
        $builder = $db->table('payroll_one_time_components');
        $existing = $builder->where('company_id', $companyId)
            ->where('source_type', 'RESIGNATION')->where('source_id', $resignationId)
            ->where('code', $code)->get()->getRowArray();
        $values = [
            'payroll_period_id' => $periodId,
            'employee_id' => $employeeId,
            'name' => $name,
            'component_type' => $type,
            'category' => $category,
            'amount' => round($amount, 2),
            'is_taxable' => $isTaxable ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($existing) {
            $db->table('payroll_one_time_components')->where('id', (int) $existing['id'])
                ->where('company_id', $companyId)->update($values);
            return;
        }
        $db->table('payroll_one_time_components')->insert($values + [
            'company_id' => $companyId,
            'source_type' => 'RESIGNATION',
            'source_id' => $resignationId,
            'code' => $code,
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function estimateUnusedLeaveCash(
        \CodeIgniter\Database\BaseConnection $db,
        array $emp,
        string $resignDate,
    ): float {
        // UPH untuk sisa cuti tahunan. Pakai rumus: sisa_cuti × (gaji_pokok + tunjangan_tetap) ÷ 25.
        // 25 = working days per bulan default (mengikuti SeveranceCalculator).
        try {
            $year = (int) date('Y', strtotime($resignDate));
            $row = $db->table('leave_balances lb')
                ->select('lb.remaining')
                ->join('leave_types lt', 'lt.id = lb.leave_type_id')
                ->where('lb.company_id', (int) $emp['company_id'])
                ->where('lt.company_id', (int) $emp['company_id'])
                ->where('lb.employee_id', (int) $emp['id'])
                ->where('lb.period_year', $year)
                ->where('lt.category', 'ANNUAL')
                ->orderBy('lb.id', 'DESC')
                ->limit(1)
                ->get()->getRowArray();
            $remaining = $row ? (float) $row['remaining'] : 0;
            if ($remaining <= 0) return 0.0;
            $basic = (float) ($emp['basic_salary'] ?? 0) + (float) ($emp['fixed_allowance'] ?? 0);
            return round($remaining * $basic / 25, 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Cari karyawan yang BPJS_REGISTER overdue. Dipanggil dari command harian.
     *
     * Aturan SLA (HIRE-4):
     *   - H+25: WARNING — kirim reminder ke HR Manager
     *   - H+30: CRITICAL — wajib audit log + notifikasi escalation
     *
     * @return array<int, array{employee_id:int, employee_name:string, join_date:string, days_since_join:int, severity:string}>
     */
    public function findBpjsRegisterOverdue(?int $companyId = null): array
    {
        $db = Database::connect();
        $today = date('Y-m-d');

        // Cari employee_tasks BPJS_REGISTER yang masih PENDING dengan
        // due_date sudah lewat. Default due_offset_days dari template = 7
        // (lihat TaskTemplatesSeeder), tapi UU SJSN beri 30 hari window.
        $b = $db->table('employee_tasks et')
            ->select('et.id, et.employee_id, et.due_date, e.full_name, e.join_date, e.company_id')
            ->join('employees e', 'e.id = et.employee_id')
            ->where('et.task_key', 'BPJS_REGISTER')
            ->where('et.type', 'ONBOARD')
            ->where('et.status', 'PENDING')
            ->where('et.deleted_at IS NULL')
            ->where('e.deleted_at IS NULL')
            ->where('e.is_active', 1)
            ->orderBy('e.join_date', 'ASC');
        if ($companyId) $b->where('e.company_id', $companyId);

        $rows = $b->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $joinDate = (string) ($r['join_date'] ?? '');
            if ($joinDate === '') continue;
            $daysSinceJoin = (int) round((strtotime($today) - strtotime($joinDate)) / 86400);
            // Sebelum H+25 — belum overdue, skip
            if ($daysSinceJoin < 25) continue;
            $severity = $daysSinceJoin >= 30 ? 'CRITICAL' : 'WARNING';
            $out[] = [
                'employee_id'    => (int) $r['employee_id'],
                'employee_name'  => (string) $r['full_name'],
                'join_date'      => $joinDate,
                'days_since_join'=> $daysSinceJoin,
                'severity'       => $severity,
            ];
        }
        return $out;
    }

    /**
     * Cari resignation yang BPJS deactivation belum done & SLA H+7 lewat.
     * Counterpart dari `findBpjsRegisterOverdue` untuk offboarding side.
     */
    public function findBpjsDeactivateOverdue(?int $companyId = null): array
    {
        $db = Database::connect();
        $today = date('Y-m-d');

        $b = $db->table('resignation_requests rr')
            ->select('rr.id, rr.employee_id, rr.effective_date, rr.last_working_date, e.full_name, e.company_id')
            ->join('employees e', 'e.id = rr.employee_id')
            ->where('rr.status', 'APPROVED')
            ->where('rr.bpjs_deactivated_at IS NULL')
            ->where('rr.deleted_at IS NULL');
        if ($companyId) $b->where('rr.company_id', $companyId);

        $rows = $b->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $resignDate = $r['effective_date'] ?? $r['last_working_date'] ?? null;
            if (! $resignDate) continue;
            $slaDue = strtotime($resignDate . ' +7 days');
            if ($slaDue > strtotime($today)) continue; // belum overdue
            $daysOverdue = (int) round((strtotime($today) - $slaDue) / 86400);
            $out[] = [
                'resignation_id'    => (int) $r['id'],
                'employee_id'       => (int) $r['employee_id'],
                'employee_name'     => (string) $r['full_name'],
                'resign_date'       => $resignDate,
                'days_overdue'      => $daysOverdue,
                'severity'          => $daysOverdue >= 7 ? 'CRITICAL' : 'WARNING',
            ];
        }
        return $out;
    }

    private function activeCompanyId(): int
    {
        $companyId = Services::tenant()->getCompanyId();
        if ($companyId <= 0) {
            throw new \RuntimeException('Active company context is required for offboarding operations');
        }

        return $companyId;
    }
}
