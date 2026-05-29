import { api } from '@api/client';

/**
 * Forgot password flow — Sprint M1.
 *
 * Backend AuthController::forgotPassword sudah ada (lihat
 * `/api/v1/auth/forgot-password`). Throttle bucket khusus supaya tidak
 * di-abuse. Reset link biasanya dikirim via email; mobile app cukup
 * tampilkan konfirmasi "kalau email terdaftar, kami akan kirim link
 * reset" tanpa enumerate akun (security best practice).
 */
export const ForgotApi = {
  async request(identifier: string): Promise<void> {
    await api.post('/auth/forgot-password', { identifier: identifier.trim() });
  },
};