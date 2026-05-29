import * as Keychain from 'react-native-keychain';

/**
 * Secure storage untuk JWT access + refresh token.
 * Pakai Keychain (iOS) / EncryptedSharedPreferences (Android via Keychain bridge).
 *
 * Konvensi 1 service = 1 namespace. Dibedakan untuk:
 *   - HRIS_AUTH        : access + refresh token + user id
 *   - HRIS_DEVICE_FCM  : device id + FCM token (bukan rahasia tapi kita keep terpusat)
 */

const AUTH_SERVICE = 'HRIS_AUTH';

export interface AuthTokens {
  accessToken: string;
  refreshToken: string;
  expiresAt: number; // epoch ms
  userId: number;
}

export const SecureStorage = {
  async saveAuth(tokens: AuthTokens): Promise<void> {
    await Keychain.setGenericPassword(
      'auth',
      JSON.stringify(tokens),
      { service: AUTH_SERVICE, accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED },
    );
  },

  async loadAuth(): Promise<AuthTokens | null> {
    const credentials = await Keychain.getGenericPassword({ service: AUTH_SERVICE });
    if (!credentials) return null;
    try {
      return JSON.parse(credentials.password) as AuthTokens;
    } catch {
      return null;
    }
  },

  async clearAuth(): Promise<void> {
    await Keychain.resetGenericPassword({ service: AUTH_SERVICE });
  },
};