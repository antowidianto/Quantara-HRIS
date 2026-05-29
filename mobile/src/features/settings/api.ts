import { api } from '@api/client';

export interface TwoFactorEnableResult {
  secret: string;
  /** otpauth:// URL — encode jadi QR di UI. */
  otpauth_url: string;
  backup_codes: string[];
}

export const SettingsApi = {
  changePassword(oldPassword: string, newPassword: string): Promise<unknown> {
    return api.post('/auth/change-password', {
      old_password: oldPassword,
      new_password: newPassword,
    });
  },

  enable2fa(): Promise<TwoFactorEnableResult> {
    return api.post<TwoFactorEnableResult>('/auth/2fa/enable');
  },

  disable2fa(currentCode: string): Promise<unknown> {
    return api.post('/auth/2fa/disable', { code: currentCode });
  },

  /** Daftar device session aktif (untuk "Sign out other devices"). */
  listSessions(): Promise<Array<{
    id: string | number;
    device_label?: string;
    last_seen_at: string;
    ip_address?: string;
    is_current?: boolean;
  }>> {
    return api.get('/auth/sessions');
  },

  revokeOtherSessions(): Promise<unknown> {
    return api.post('/auth/sessions/revoke-others');
  },

  revokeSession(sessionId: string | number): Promise<unknown> {
    return api.delete(`/auth/sessions/${sessionId}`);
  },
};