import ReactNativeBiometrics, { BiometryTypes } from 'react-native-biometrics';
import { mmkv } from '@store/mmkv';
import { SecureStorage, type AuthTokens } from '@store/secureStorage';

/**
 * Biometric login — Sprint M1.
 *
 * Pola yang dipakai (ringan, tanpa simpan password user):
 *
 *   1. Saat login berhasil pertama kali, tanyakan: "Aktifkan Face ID / fingerprint
 *      untuk login cepat?". Kalau iya, simpan refresh_token snapshot di
 *      Keychain (kita sudah simpan via SecureStorage), dan set flag
 *      `biometric.enabled = true` di MMKV.
 *
 *   2. Saat user logout `manual`, set flag false → next session prompt
 *      ulang.
 *
 *   3. Saat app launch (ada token di Keychain + flag aktif), prompt
 *      biometric → kalau OK, lanjut. Kalau gagal/cancel → fallback
 *      AuthStack (login lewat password).
 *
 * Tidak menyimpan password atau token tambahan di luar Keychain default.
 * Refresh token sudah diproteksi accessible: WHEN_UNLOCKED di SecureStorage.
 */

const PREF_KEY = 'biometric.enabled';

export interface BiometricCapability {
  available: boolean;
  type?: keyof typeof BiometryTypes;
}

const rnb = new ReactNativeBiometrics({ allowDeviceCredentials: true });

export const Biometric = {
  async capability(): Promise<BiometricCapability> {
    try {
      const r = await rnb.isSensorAvailable();
      return { available: r.available, type: r.biometryType as keyof typeof BiometryTypes | undefined };
    } catch {
      return { available: false };
    }
  },

  isEnabled(): boolean {
    return mmkv.getString(PREF_KEY) === '1';
  },

  setEnabled(on: boolean): void {
    if (on) mmkv.set(PREF_KEY, '1');
    else mmkv.remove(PREF_KEY);
  },

  /**
   * Prompt biometric. Return true kalau user otentikasi sukses.
   * Pesan UI di-customize per platform (iOS pakai allowDeviceCredentials
   * fallback ke passcode device).
   */
  async authenticate(promptMessage: string): Promise<boolean> {
    try {
      const r = await rnb.simplePrompt({ promptMessage, cancelButtonText: 'Batal' });
      return Boolean(r.success);
    } catch {
      return false;
    }
  },

  /**
   * Quick-login flow saat app start: cek flag + token + biometric.
   * Return AuthTokens kalau berhasil; null kalau user batal / sensor gagal.
   */
  async quickUnlock(): Promise<AuthTokens | null> {
    if (!Biometric.isEnabled()) return null;
    const tokens = await SecureStorage.loadAuth();
    if (!tokens) return null;
    const ok = await Biometric.authenticate('Buka HRIS dengan biometrik');
    return ok ? tokens : null;
  },
};
