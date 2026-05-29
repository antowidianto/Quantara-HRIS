import { AppState, type AppStateStatus } from 'react-native';

import { mmkv } from '@store/mmkv';
import { Biometric } from '@features/auth/biometric';

/**
 * Idle auto-lock — Sprint M4 hardening.
 *
 * Goal: kalau app pernah ditinggal background ≥ N detik dan biometric
 * sudah pernah di-enable user, prompt biometric lagi saat kembali ke
 * foreground. Sebelum biometric sukses, UI di-block oleh `IdleLockGate`
 * (lihat App.tsx).
 *
 * Tidak menyimpan pin/passcode tambahan. Yang dilakukan hanya:
 *   - Catat timestamp saat blur
 *   - Hitung selisih saat focus
 *   - Kalau > threshold dan biometric enabled → set flag `idle.locked = 1`
 *
 * Flag dibaca oleh `useIdleLock()` di App.tsx untuk render gate.
 *
 * Threshold default 5 menit. User bisa override via Settings (bukan
 * yang lebih lama dari 60 menit, untuk memenuhi audit security).
 */

const KEY_LAST_BG = 'idle.last_background_at';
const KEY_LOCKED  = 'idle.locked';
const KEY_THRESHOLD = 'idle.threshold_sec';

const DEFAULT_THRESHOLD_SEC = 300; // 5 menit
const MAX_THRESHOLD_SEC = 60 * 60; // 1 jam (audit guard)

export const IdleLock = {
  getThresholdSec(): number {
    const raw = mmkv.getString(KEY_THRESHOLD);
    const n = raw ? Number(raw) : DEFAULT_THRESHOLD_SEC;
    if (!Number.isFinite(n) || n <= 0) return DEFAULT_THRESHOLD_SEC;
    return Math.min(n, MAX_THRESHOLD_SEC);
  },

  setThresholdSec(seconds: number): void {
    if (!Number.isFinite(seconds) || seconds <= 0) {
      mmkv.delete(KEY_THRESHOLD);
      return;
    }
    const v = Math.min(Math.floor(seconds), MAX_THRESHOLD_SEC);
    mmkv.set(KEY_THRESHOLD, String(v));
  },

  isLocked(): boolean {
    return mmkv.getString(KEY_LOCKED) === '1';
  },

  lock(): void {
    mmkv.set(KEY_LOCKED, '1');
  },

  unlock(): void {
    mmkv.delete(KEY_LOCKED);
    mmkv.delete(KEY_LAST_BG);
  },

  /**
   * Pasang AppState listener. Return unsubscribe fn untuk cleanup di
   * App effect.
   */
  install(): () => void {
    const handler = (state: AppStateStatus) => {
      if (state === 'background' || state === 'inactive') {
        mmkv.set(KEY_LAST_BG, String(Date.now()));
      } else if (state === 'active') {
        const last = Number(mmkv.getString(KEY_LAST_BG) ?? '0');
        if (!last) return;
        const elapsed = (Date.now() - last) / 1000;
        if (elapsed >= IdleLock.getThresholdSec() && Biometric.isEnabled()) {
          IdleLock.lock();
        }
      }
    };
    const sub = AppState.addEventListener('change', handler);
    return () => sub.remove();
  },
};