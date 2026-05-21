<?php

namespace App\Notifications\Channels;

use App\Notifications\NotificationChannel;

/**
 * InAppChannel — channel default. Notif sudah disimpan ke tabel `notifications`
 * oleh `NotificationService::send()` sebelum channel di-dispatch, jadi channel
 * ini tidak perlu duplikat insert. Tugasnya: ack saja (return true).
 *
 * Selalu available untuk semua user yang punya akun. Tidak bisa di-disable
 * di preference (in-app adalah satu-satunya channel "ground truth").
 */
class InAppChannel implements NotificationChannel
{
    public function slug(): string
    {
        return 'in_app';
    }

    public function isAvailable(array $user): bool
    {
        return ! empty($user['id']);
    }

    public function send(array $user, array $payload): bool
    {
        // Notif sudah ter-insert ke `notifications` table sebelum dispatch.
        // Channel ini cuma menandai bahwa "delivery" via in-app sukses.
        return true;
    }
}