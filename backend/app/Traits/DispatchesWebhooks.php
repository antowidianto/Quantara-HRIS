<?php

namespace App\Traits;

use App\Constants\WebhookEvent;
use Config\Services;

/**
 * DispatchesWebhooks — trait helper untuk controller agar dispatch
 * webhook outbound jadi 1-liner. Pattern Sprint 12 di-extract jadi
 * reusable supaya wire ke 20+ controller lain bisa konsisten.
 *
 * Pakai:
 *
 *   class LeaveController extends ApiController {
 *       use \App\Traits\DispatchesWebhooks;
 *
 *       public function approve($id) {
 *           // ... approve logic ...
 *           $this->dispatchWebhook(WebhookEvent::LEAVE_APPROVED, $companyId, [
 *               'leave_id' => $id,
 *               // ...
 *           ]);
 *       }
 *   }
 *
 * Defensive: dispatch failure (cache miss / DB issue) di-log warning,
 * tidak throw — caller controller flow tetap lanjut.
 *
 * Auto-include `event`, `company_id`, `dispatched_at` di payload kalau
 * caller tidak explicit pass — supaya consumer tidak perlu cek field
 * dasar di setiap event.
 */
trait DispatchesWebhooks
{
    /**
     * Dispatch webhook outbound dengan defensive error handling +
     * auto-augment payload.
     *
     * @param string $eventType   Salah satu WebhookEvent::*
     * @param int    $companyId   Tenant scope
     * @param array  $payload     Event-specific data
     * @param string|null $eventId UUID untuk idempotency (default auto-generate)
     * @return int Jumlah delivery enqueued (0 kalau tidak ada subscriber atau gagal)
     */
    protected function dispatchWebhook(
        string $eventType,
        int $companyId,
        array $payload = [],
        ?string $eventId = null,
    ): int {
        // Auto-augment fields yang sering kepakai consumer
        $payload = array_merge([
            'event'         => $eventType,
            'company_id'    => $companyId,
            'dispatched_at' => date('c'),
        ], $payload);

        try {
            return Services::webhook()->dispatch($eventType, $companyId, $payload, $eventId);
        } catch (\Throwable $e) {
            log_message('warning', sprintf(
                'Webhook dispatch %s failed (company=%d): %s',
                $eventType,
                $companyId,
                $e->getMessage(),
            ));
            return 0;
        }
    }
}