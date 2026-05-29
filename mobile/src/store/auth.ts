import { create } from 'zustand';
import { SecureStorage, type AuthTokens } from './secureStorage';
import { setActiveCompanyId } from '@api/client';

/**
 * Auth state — Zustand store.
 *
 * - **bootstrapped**: sudah selesai cek Keychain saat app start. UI awal
 *   tampilkan splash sampai bootstrapped=true supaya tidak flicker
 *   login → home (kalau token masih valid).
 *
 * - **user**: ringkas snapshot user (full_name, role, employee_id) untuk
 *   header & avatar. Detail lengkap fetch lewat /auth/me kalau perlu.
 */

export interface CurrentUser {
  id: number;
  email: string;
  fullName: string;
  roleId: number;
  employeeId: number | null;
  companyId: number | null;
  avatarUrl: string | null;
  isSuperAdmin: boolean;
}

interface AuthState {
  bootstrapped: boolean;
  user: CurrentUser | null;
  tokens: AuthTokens | null;
  bootstrap: () => Promise<void>;
  setSession: (user: CurrentUser, tokens: AuthTokens) => Promise<void>;
  setUser: (user: CurrentUser) => void;
  switchCompany: (companyId: number) => void;
  signOut: () => Promise<void>;
}

export const useAuthStore = create<AuthState>((set, get) => ({
  bootstrapped: false,
  user: null,
  tokens: null,

  async bootstrap() {
    const tokens = await SecureStorage.loadAuth();
    set({ tokens, bootstrapped: true });
    // user dipopulasi via /auth/me di App effect setelah bootstrap
  },

  async setSession(user, tokens) {
    await SecureStorage.saveAuth(tokens);
    if (user.isSuperAdmin && user.companyId) {
      setActiveCompanyId(user.companyId);
    }
    set({ user, tokens });
  },

  setUser(user) {
    set({ user });
  },

  switchCompany(companyId) {
    setActiveCompanyId(companyId);
    const u = get().user;
    if (u) set({ user: { ...u, companyId } });
  },

  async signOut() {
    await SecureStorage.clearAuth();
    setActiveCompanyId(null);
    set({ user: null, tokens: null });
  },
}));