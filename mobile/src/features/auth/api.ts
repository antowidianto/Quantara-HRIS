import { api } from '@api/client';
import type { CurrentUser } from '@store/auth';
import type { AuthTokens } from '@store/secureStorage';

interface RawLoginResponse {
  access_token: string;
  refresh_token: string;
  expires_in: number;
  two_factor?: boolean;
  challenge_token?: string;
  user?: {
    id: number;
    email: string;
    full_name: string;
    role_id: number;
    employee_id: number | null;
    company_id: number | null;
    avatar_url?: string | null;
    is_super_admin?: boolean;
  };
}

export interface LoginResult {
  twoFactorRequired: false;
  user: CurrentUser;
  tokens: AuthTokens;
}

export interface TwoFactorChallenge {
  twoFactorRequired: true;
  challengeToken: string;
}

const toUser = (raw: RawLoginResponse['user'], userId: number): CurrentUser => ({
  id: raw?.id ?? userId,
  email: raw?.email ?? '',
  fullName: raw?.full_name ?? '',
  roleId: raw?.role_id ?? 0,
  employeeId: raw?.employee_id ?? null,
  companyId: raw?.company_id ?? null,
  avatarUrl: raw?.avatar_url ?? null,
  isSuperAdmin: Boolean(raw?.is_super_admin) || raw?.role_id === 1,
});

const toTokens = (raw: RawLoginResponse, userId: number): AuthTokens => ({
  accessToken: raw.access_token,
  refreshToken: raw.refresh_token,
  expiresAt: Date.now() + raw.expires_in * 1000,
  userId,
});

export const AuthApi = {
  async login(identifier: string, password: string): Promise<LoginResult | TwoFactorChallenge> {
    const data = await api.post<RawLoginResponse>('/auth/login', { identifier, password });
    if (data.two_factor && data.challenge_token) {
      return { twoFactorRequired: true, challengeToken: data.challenge_token };
    }
    if (!data.user) throw new Error('Login response missing user');
    const user = toUser(data.user, data.user.id);
    const tokens = toTokens(data, user.id);
    return { twoFactorRequired: false, user, tokens };
  },

  async verifyTwoFactor(challengeToken: string, code: string): Promise<LoginResult> {
    const data = await api.post<RawLoginResponse>('/auth/verify-2fa', {
      challenge_token: challengeToken,
      code,
    });
    if (!data.user) throw new Error('2FA response missing user');
    const user = toUser(data.user, data.user.id);
    const tokens = toTokens(data, user.id);
    return { twoFactorRequired: false, user, tokens };
  },

  async me(): Promise<CurrentUser> {
    const raw = await api.get<RawLoginResponse['user']>('/auth/me');
    return toUser(raw, raw?.id ?? 0);
  },

  async logout(): Promise<void> {
    try {
      await api.post('/auth/logout');
    } catch {
      // best-effort; abaikan error
    }
  },
};