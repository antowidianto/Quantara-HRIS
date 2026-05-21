<?php

namespace App\Notifications;

/**
 * NotificationChannel — kontrak generic untuk semua channel pengiriman notifikasi.
 *
 * Implementasi yang tersedia:
 *   - InAppChannel    → simpan ke tabel `notifications` (default, selalu ON)
 *   - EmailChannel    → enqueue ke `email_queue` dengan retry exponential backoff
 *   - WhatsAppChannel → kirim via Fonnte / Twilio (driver di-set lewat env)
 *   - WebPushChannel  → kirim via VAPID web push protocol (PWA service worker)
 *
 * Channel di-dispatch oleh `NotificationService::send()` berdasar preference user
 * (tabel `user_notification_preferences`) dan availability channel itu sendiri
 * (mis. WhatsApp tidak available kalau user belum daftar nomor WA).
 *
 * Setiap implementasi WAJIB:
 *   - Tidak throw exception saat send gagal (return false saja). Audit log
 *     menangani failure tracking. Throw hanya untuk error programming (config salah).
 *   - Idempotent untuk retry: kirim ulang dengan payload sama tidak duplikat
 *     atau pakai dedup key (mis. notif id).
 */
interface NotificationChannel
{
    /**
     * Slug channel — lowercase, tanpa spasi (in_app, email, whatsapp, web_push).
     * Dipakai untuk lookup preference user di DB.
     */
    public function slug(): string;

    /**
     * Cek apakah channel ini bisa dipakai untuk user tertentu.
     * Mis. EmailChannel butuh user.email != null;
     *      WhatsAppChannel butuh user.whatsapp_number;
     *      WebPushChannel butuh user.web_push_subscription.
     */
    public function isAvailable(array $user): bool;

    /**
     * Kirim notifikasi. Param `$payload` selalu berisi:
     *   - notification_id   (int)  ID baris di tabel notifications (untuk idempotency)
     *   - notif_type        (string)
     *   - title             (string)
     *   - message           (string)
     *   - data              (array)
     *   - link_url          (string|null)
     *   - company_id        (int)
     *
     * Channel boleh menambah field tambahan (mis. selfie path, dst).
     *
     * @return bool True kalau sukses kirim/queue. False kalau gagal (lihat log
     *              untuk alasan, audit_logs untuk trail).
     */
    public function send(array $user, array $payload): bool;
}