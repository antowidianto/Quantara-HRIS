<?php

namespace App\Controllers\Api\V1\Lifecycle;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Lifecycle\PaklaringService;
use Config\Services;

/**
 * OffboardingController
 *
 * REST API untuk lifecycle offboarding compliance (Sprint 1):
 *   - GET    /resignations/(:num)/offboarding         — status clearance + ready flags
 *   - POST   /resignations/(:num)/offboarding/asset-clearance       — toggle asset OK + optional exception note
 *   - POST   /resignations/(:num)/offboarding/bpjs-deactivated      — tandai BPJS Kes/TK sudah di-deactivate
 *   - POST   /resignations/(:num)/offboarding/paklaring             — generate paklaring (gate: asset OK + resign_date lewat)
 *   - GET    /resignations/(:num)/offboarding/paklaring/(:num)      — download paklaring (HTML/PDF)
 *   - POST   /resignations/(:num)/offboarding/final-settlement      — link severance ke payroll periode aktif
 *   - POST   /resignations/(:num)/offboarding/final-settlement/paid — tandai sudah dibayar manual + bukti
 *   - GET    /offboarding/bpjs-overdue                              — list BPJS register/deactivate yang overdue
 *
 * Authorization:
 *   - View status: pemilik resignation (karyawan ybs) ATAU `offboarding.clearance.view`
 *   - Modify: `offboarding.clearance.manage`
 *   - Generate paklaring: `paklaring.generate`
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item OFF-2, OFF-3, OFF-4, OFF-6, HIRE-4.
 */
class OffboardingController extends ApiController
{
    /**
     * GET /resignations/(:num)/offboarding
     *
     * Status lengkap clearance untuk satu resignation. Karyawan yang
     * bersangkutan boleh lihat status-nya sendiri (transparency); HR
     * yang punya manage perm boleh lihat semua.
     */
    public function status($id = null)
    {
        $resignationId = (int) $id;
        $resign = $this->loadResignation($resignationId);
        if (! $resign) return $this->response;

        // Authorization: karyawan ybs OR HR view perm
        if (! $this->canViewResignation($resign)) {
            return $this->fail('Forbidden', 403);
        }

        try {
            $status = Services::offboarding()->status($resignationId);
            return $this->ok($status);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * POST /resignations/(:num)/offboarding/asset-clearance
     *
     * Body: { cleared: bool, note: string (opsional, wajib kalau ada aset belum balik) }
     */
    public function setAssetClearance($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden — butuh permission offboarding.clearance.manage', 403);
        $resignationId = (int) $id;
        $resign = $this->loadResignation($resignationId);
        if (! $resign) return $this->response;

        $data = $this->getJsonInput();
        $cleared = (bool) ($data['cleared'] ?? true);
        $note = isset($data['note']) ? trim((string) $data['note']) : null;
        if ($note === '') $note = null;

        try {
            $status = Services::offboarding()->setAssetClearance(
                $resignationId,
                $this->userId(),
                $cleared,
                $note,
            );
            return $this->ok($status);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'INVALID_INPUT');
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422, 'CLEARANCE_BLOCKED');
        }
    }

    /**
     * POST /resignations/(:num)/offboarding/bpjs-deactivated
     *
     * HR sudah submit deactivate ke e-Dabu (Kesehatan) & SiPP (Ketenagakerjaan).
     * Body: { note: string (opsional) }
     */
    public function setBpjsDeactivated($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $resignationId = (int) $id;
        $resign = $this->loadResignation($resignationId);
        if (! $resign) return $this->response;

        $data = $this->getJsonInput();
        $note = isset($data['note']) ? trim((string) $data['note']) : null;

        try {
            $status = Services::offboarding()->setBpjsDeactivated(
                $resignationId,
                $this->userId(),
                $note ?: null,
            );
            return $this->ok($status);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * POST /resignations/(:num)/offboarding/paklaring
     *
     * Generate paklaring (Surat Keterangan Kerja). Idempotent — kalau
     * sudah pernah generate, return existing.
     */
    public function generatePaklaring($id = null)
    {
        if (! $this->canGeneratePaklaring()) {
            return $this->fail('Forbidden — butuh permission paklaring.generate', 403);
        }
        $resignationId = (int) $id;
        $resign = $this->loadResignation($resignationId);
        if (! $resign) return $this->response;

        try {
            $svc = new PaklaringService();
            $result = $svc->generate($resignationId, $this->userId());
            $msg = $result['already_existed']
                ? "Paklaring {$result['contract_number']} sudah pernah diterbitkan sebelumnya."
                : "Paklaring {$result['contract_number']} berhasil diterbitkan untuk {$result['employee_name']}.";
            return $this->ok($result, $msg, $result['already_existed'] ? 200 : 201);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 404);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422, 'PAKLARING_BLOCKED');
        } catch (\Throwable $e) {
            log_message('error', 'Generate paklaring failed: ' . $e->getMessage());
            return $this->fail('Gagal generate paklaring: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /resignations/(:num)/offboarding/paklaring/(:num)?format=pdf|html
     *
     * Render paklaring HTML / PDF. Karyawan ybs ATAU HR boleh download.
     * Path generated_contract_id juga dikirim sebagai segment kedua untuk
     * verifikasi bahwa paklaring ini memang milik resignation tersebut
     * (mencegah IDOR — karyawan A ambil paklaring karyawan B).
     */
    public function downloadPaklaring($resignationId = null, $generatedId = null)
    {
        $resignationId = (int) $resignationId;
        $generatedId = (int) $generatedId;

        $resign = $this->loadResignation($resignationId);
        if (! $resign) return $this->response;
        if (! $this->canViewResignation($resign)) {
            return $this->fail('Forbidden', 403);
        }
        if ((int) ($resign['paklaring_id'] ?? 0) !== $generatedId) {
            return $this->fail('Paklaring ID tidak sesuai dengan resignation ini', 403, 'IDOR_PROTECTION');
        }

        $db = \Config\Database::connect();
        $row = $db->table('generated_contracts')
            ->where('id', $generatedId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) return $this->fail('Paklaring not found', 404);

        $html = (string) ($row['rendered_html'] ?? '');
        if ($html === '') return $this->fail('Paklaring HTML kosong, silakan re-generate', 422);

        $format = strtolower((string) $this->request->getGet('format')) ?: 'pdf';
        $filename = 'Paklaring_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string) $row['contract_number']);

        // Audit untuk akses dokumen sensitif (UU PDP)
        try {
            Services::audit()->forModule('OFFBOARDING')->log(
                action: 'paklaring.download',
                entity: 'generated_contracts',
                entityId: $generatedId,
                description: "Download paklaring {$row['contract_number']} format={$format}",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        if ($format === 'html') {
            return $this->response
                ->setHeader('Content-Type', 'text/html; charset=UTF-8')
                ->setBody($html);
        }

        // PDF via Dompdf — sama dengan pattern di DisciplinaryController::letter
        try {
            $dompdf = new \Dompdf\Dompdf(['isHtml5ParserEnabled' => true]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $output = $dompdf->output();
            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '.pdf"')
                ->setBody($output);
        } catch (\Throwable $e) {
            log_message('error', 'Paklaring PDF render failed: ' . $e->getMessage());
            return $this->fail('Gagal render PDF: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /resignations/(:num)/offboarding/final-settlement
     *
     * Link severance FINALIZED + estimasi gaji prorata + sisa cuti ke
     * payroll periode aktif. Tidak otomatis bayar — HR perlu adjust di
     * Payroll module dan mark paid via endpoint berikutnya.
     */
    public function linkFinalSettlement($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $resignationId = (int) $id;
        $resign = $this->loadResignation($resignationId);
        if (! $resign) return $this->response;

        try {
            $result = Services::offboarding()->linkFinalSettlement(
                $resignationId,
                $this->userId(),
            );
            return $this->ok($result, $result['notes'], 200);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 404);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422, 'FINAL_SETTLEMENT_BLOCKED');
        }
    }

    /**
     * POST /resignations/(:num)/offboarding/final-settlement/paid
     *
     * Tandai final settlement dibayar (lewat payroll atau transfer manual).
     * Body: { amount: float, proof_path: string|null, note: string|null }
     *
     * Compliance: kalau lewat 7 hari dari resign_date → audit CRITICAL
     * (UU 13/2003 Pasal 156 ayat 4 wajibkan ≤ 7 hari).
     */
    public function markFinalSettlementPaid($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $resignationId = (int) $id;
        $resign = $this->loadResignation($resignationId);
        if (! $resign) return $this->response;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'amount' => 'required|numeric|greater_than[0]',
        ]);
        if (! $valid) return null;

        $amount = (float) $valid['amount'];
        $proofPath = isset($data['proof_path']) ? trim((string) $data['proof_path']) : null;
        $note = isset($data['note']) ? trim((string) $data['note']) : null;

        try {
            $status = Services::offboarding()->markFinalSettlementPaid(
                $resignationId,
                $this->userId(),
                $amount,
                $proofPath ?: null,
                $note ?: null,
            );
            return $this->ok($status, 'Final settlement ditandai sudah dibayar.');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * GET /offboarding/bpjs-overdue
     *
     * List karyawan yang BPJS_REGISTER overdue (HIRE-4) + resignation
     * yang BPJS_DEACTIVATE overdue (OFF-6). Untuk dashboard HR Manager.
     */
    public function bpjsOverdue()
    {
        if (! $this->canManage() && ! $this->tenant()->hasPermission('onboarding.view')) {
            return $this->fail('Forbidden', 403);
        }
        $companyId = $this->tenant()->getCompanyId() ?: null;
        $svc = Services::offboarding();

        return $this->ok([
            'register_overdue'   => $svc->findBpjsRegisterOverdue($companyId),
            'deactivate_overdue' => $svc->findBpjsDeactivateOverdue($companyId),
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function loadResignation(int $id): ?array
    {
        if ($id <= 0) {
            $this->response = $this->fail('Invalid resignation id', 400);
            return null;
        }
        $row = \Config\Database::connect()
            ->table('resignation_requests')
            ->where('id', $id)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Resignation not found', 404);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        return $row;
    }

    /**
     * Karyawan ybs (subjek resignation) selalu boleh lihat status
     * offboarding-nya sendiri (transparency). HR butuh perm.
     */
    private function canViewResignation(array $resign): bool
    {
        $tenant = $this->tenant();
        if ($tenant->isSuperAdmin()) return true;
        if ($tenant->hasPermission('offboarding.clearance.view')
            || $tenant->hasPermission('offboarding.clearance.manage')
            || $tenant->hasPermission('resignation.view')
            || $tenant->hasPermission('resignation.manage')) {
            return true;
        }
        // Karyawan ybs — cek via employees.user_id
        $emp = \Config\Database::connect()->table('employees')
            ->select('user_id')
            ->where('id', (int) $resign['employee_id'])
            ->get()->getRowArray();
        return $emp && (int) ($emp['user_id'] ?? 0) === $tenant->getUserId();
    }

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('offboarding.clearance.manage')
            || $t->hasPermission('resignation.manage');
    }

    private function canGeneratePaklaring(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('paklaring.generate')
            || $t->hasPermission('resignation.manage');
    }
}