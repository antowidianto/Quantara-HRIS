<?php

namespace App\Controllers\Api\V1\Signing;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Signing\PsreSigningService;
use Config\Database;

/**
 * H3.7 — PSrE Signing Controller.
 *
 * Endpoints:
 *   GET    /api/v1/signing/providers
 *   POST   /api/v1/signing/providers
 *   PUT    /api/v1/signing/providers/{id}
 *   DELETE /api/v1/signing/providers/{id}
 *
 *   GET    /api/v1/signing/envelopes                  ?status=&document_type=
 *   POST   /api/v1/signing/envelopes
 *   GET    /api/v1/signing/envelopes/{id}
 *   POST   /api/v1/signing/envelopes/{id}/signers
 *   POST   /api/v1/signing/envelopes/{id}/send
 *   POST   /api/v1/signing/envelopes/{id}/cancel
 *   POST   /api/v1/signing/envelopes/{id}/wet-sign
 *   GET    /api/v1/signing/envelopes/{id}/audit
 *
 *   POST   /api/v1/signing/envelopes/{id}/signers/{signerId}/event
 *          (untuk callback PSrE / dev simulation)
 */
class SigningController extends ApiController
{
    private PsreSigningService $service;

    public function __construct()
    {
        $this->service = new PsreSigningService();
    }

    // ─── Providers ────────────────────────────────────────────────────

    public function listProviders()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('company_id required', 400, 'COMPANY_REQUIRED');
        }
        $rows = Database::connect()->table('psre_providers')
            ->select('id, provider, base_url, merchant_id, is_active, created_at')
            ->where('company_id', $companyId)
            ->where('deleted_at', null)
            ->orderBy('provider', 'ASC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createProvider()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $body = $this->validateOrFail($this->getJsonInput(), [
            'provider' => 'required|in_list[PRIVYID,VIDA,PERURI,MOCK_DEV]',
        ]);
        if ($body === null) return null;

        $db = Database::connect();
        $exists = $db->table('psre_providers')
            ->where('company_id', $companyId)
            ->where('provider', $body['provider'])
            ->where('deleted_at', null)
            ->countAllResults();
        if ($exists) {
            return $this->fail('Provider sudah ada', 409, 'DUPLICATE_PROVIDER');
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'company_id'        => $companyId,
            'provider'          => $body['provider'],
            'base_url'          => $body['base_url']    ?? null,
            'merchant_id'       => $body['merchant_id'] ?? null,
            'api_key_encrypted' => isset($body['api_key']) && $body['api_key'] !== ''
                ? $this->encryptKey($body['api_key'])
                : null,
            'callback_secret'   => $body['callback_secret'] ?? bin2hex(random_bytes(16)),
            'is_active'         => 1,
            'extra_config_json' => isset($body['extra_config'])
                ? json_encode($body['extra_config'], JSON_UNESCAPED_UNICODE)
                : null,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];
        $db->table('psre_providers')->insert($row);
        return $this->ok(['id' => (int) $db->insertID()], 'Provider configured', 201);
    }

    public function updateProvider(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $existing = $db->table('psre_providers')
            ->where('id', $id)->where('company_id', $companyId)->where('deleted_at', null)
            ->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404, 'NOT_FOUND');

        $body = $this->getJsonInput();
        $upd = ['updated_at' => date('Y-m-d H:i:s')];
        foreach (['base_url', 'merchant_id'] as $f) {
            if (array_key_exists($f, $body)) $upd[$f] = $body[$f] !== '' ? $body[$f] : null;
        }
        if (isset($body['is_active'])) $upd['is_active'] = (int) $body['is_active'];
        if (! empty($body['api_key'])) $upd['api_key_encrypted'] = $this->encryptKey($body['api_key']);
        if (isset($body['extra_config'])) {
            $upd['extra_config_json'] = json_encode($body['extra_config'], JSON_UNESCAPED_UNICODE);
        }
        $db->table('psre_providers')->where('id', $id)->update($upd);
        return $this->ok(['id' => $id], 'Updated');
    }

    public function deleteProvider(int $id)
    {
        $companyId = $this->companyId();
        Database::connect()->table('psre_providers')
            ->where('id', $id)->where('company_id', $companyId)
            ->update([
                'deleted_at' => date('Y-m-d H:i:s'),
                'is_active'  => 0,
            ]);
        return $this->ok(null, 'Deleted');
    }

    // ─── Envelopes ────────────────────────────────────────────────────

    public function listEnvelopes()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('company_id required', 400, 'COMPANY_REQUIRED');
        }
        $b = Database::connect()->table('signing_envelopes')
            ->select('id, document_type, subject, status, sent_at, completed_at, expires_at, fallback_wet_sign, created_at')
            ->where('company_id', $companyId);
        if ($s = $this->request->getGet('status'))         $b->where('status', $s);
        if ($t = $this->request->getGet('document_type'))  $b->where('document_type', $t);
        $rows = $b->orderBy('id', 'DESC')->limit(200)->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createEnvelope()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $body = $this->getJsonInput();
        $errors = PsreSigningService::validateEnvelopePayloadPure($body);
        if ($errors !== []) {
            return $this->fail('Validation failed', 422, 'VALIDATION_ERROR', $errors);
        }

        try {
            $id = $this->service->createEnvelope($companyId, $this->userId(), $body);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'CREATE_FAILED');
        }
        return $this->ok(['id' => $id], 'Envelope created', 201);
    }

    public function showEnvelope(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $envelope = $db->table('signing_envelopes')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $envelope) return $this->fail('Not found', 404, 'NOT_FOUND');
        $signers = $db->table('signing_envelope_signers')
            ->where('envelope_id', $id)->orderBy('sign_order', 'ASC')
            ->get()->getResultArray();
        return $this->ok([
            'envelope' => $envelope,
            'signers'  => $signers,
        ]);
    }

    public function addSigner(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $envelope = $db->table('signing_envelopes')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $envelope) return $this->fail('Not found', 404, 'NOT_FOUND');

        try {
            $signerId = $this->service->addSigner($id, $this->getJsonInput());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'ADD_SIGNER_FAILED');
        }
        return $this->ok(['id' => $signerId], 'Signer added', 201);
    }

    public function send(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $envelope = $db->table('signing_envelopes')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $envelope) return $this->fail('Not found', 404, 'NOT_FOUND');
        try {
            $result = $this->service->send($id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'SEND_FAILED');
        }
        return $this->ok($result);
    }

    public function cancel(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $envelope = $db->table('signing_envelopes')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $envelope) return $this->fail('Not found', 404, 'NOT_FOUND');
        $reason = (string) ($this->getJsonInput()['reason'] ?? '');
        try {
            $this->service->cancel($id, $reason);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'CANCEL_FAILED');
        }
        return $this->ok(['id' => $id, 'status' => 'CANCELLED']);
    }

    public function wetSignFallback(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $envelope = $db->table('signing_envelopes')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $envelope) return $this->fail('Not found', 404, 'NOT_FOUND');

        $body = $this->validateOrFail($this->getJsonInput(), [
            'scan_path' => 'required|min_length[3]',
        ]);
        if ($body === null) return null;

        $this->service->recordWetSignFallback($id, (string) $body['scan_path']);
        return $this->ok(['id' => $id, 'status' => 'COMPLETED', 'fallback_wet_sign' => true]);
    }

    public function audit(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $envelope = $db->table('signing_envelopes')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $envelope) return $this->fail('Not found', 404, 'NOT_FOUND');
        return $this->ok($this->service->audit($id));
    }

    /**
     * Catat event signer (untuk callback PSrE atau dev simulation).
     * Untuk PoC MOCK_DEV ini bisa dipanggil langsung; production akan
     * dipanggil dari webhook handler dengan HMAC verification.
     */
    public function recordSignerEvent(int $envelopeId, int $signerId)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $envelope = $db->table('signing_envelopes')
            ->where('id', $envelopeId)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $envelope) return $this->fail('Not found', 404, 'NOT_FOUND');

        $body = $this->validateOrFail($this->getJsonInput(), [
            'event_type' => 'required|in_list[SIGNED,DECLINED,VIEWED,EXPIRED]',
        ]);
        if ($body === null) return null;

        $context = [
            'ip'                     => $this->request->getIPAddress(),
            'user_agent'             => $this->request->getUserAgent()->getAgentString(),
            'reason'                 => $body['reason']                  ?? null,
            'certificate_fingerprint'=> $body['certificate_fingerprint'] ?? null,
        ];

        try {
            $result = $this->service->recordSignerEvent($envelopeId, $signerId, $body['event_type'], $context);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'EVENT_FAILED');
        }
        return $this->ok($result);
    }

    // ─── Internal ─────────────────────────────────────────────────────

    private function encryptKey(string $plain): string
    {
        try {
            if (class_exists(\App\Libraries\Security\EncryptionService::class)) {
                $svc = new \App\Libraries\Security\EncryptionService();
                if (method_exists($svc, 'encrypt')) {
                    return (string) $svc->encrypt($plain);
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return 'b64:' . base64_encode($plain);
    }
}