import axios, {
  AxiosError,
  type AxiosInstance,
  type AxiosRequestConfig,
  type InternalAxiosRequestConfig,
} from 'axios';
import { v4 as uuidv4 } from 'uuid';

import { env } from '@/config';
import { SecureStorage, type AuthTokens } from '@store/secureStorage';

/**
 * API client — HRIS Mobile.
 *
 * Tanggung jawab:
 *
 *   1. **JWT bearer injection** dari Keychain di setiap request, kecuali
 *      endpoint public (auth/login, auth/refresh, openapi.json).
 *
 *   2. **Refresh-on-401**: kalau access token expire, otomatis call
 *      `/auth/refresh` dan retry request asli SEKALI. Multi-request
 *      concurrency: hanya 1 refresh berjalan pada satu waktu (request
 *      lain antri).
 *
 *   3. **Idempotency-Key** untuk POST critical (clock-in/out, leaves,
 *      reimbursement, approval decisions). Auto-generate UUID v4 kalau
 *      caller tidak supply via config.idempotencyKey. Backend Filter
 *      `idempotency` (lihat backend/app/Filters/IdempotencyFilter.php)
 *      akan replay response cached kalau retry.
 *
 *   4. **Error normalization**: convert AxiosError jadi `ApiError` dengan
 *      `code`, `message`, `errors`. UI bisa pakai langsung tanpa peek
 *      response shape.
 *
 *   5. **Multi-tenant header**: pasang `X-Company-Id` opsional kalau user
 *      adalah super_admin (di-isi dari auth store).
 */

export interface ApiErrorPayload {
  status: 'error';
  code?: string;
  message: string;
  errors?: Record<string, string[]>;
}

export class ApiError extends Error {
  public readonly code: string;
  public readonly httpStatus: number;
  public readonly errors?: Record<string, string[]>;
  public readonly isNetwork: boolean;

  constructor(
    message: string,
    code: string,
    httpStatus: number,
    errors?: Record<string, string[]>,
    isNetwork = false,
  ) {
    super(message);
    this.code = code;
    this.httpStatus = httpStatus;
    this.errors = errors;
    this.isNetwork = isNetwork;
  }
}

const IDEMPOTENT_PATH_PATTERNS: RegExp[] = [
  /^\/attendance\/clock-(in|out)$/,
  /^\/leaves$/,
  /^\/overtimes$/,
  /^\/reimbursements$/,
  /^\/loans$/,
  /^\/business-trips$/,
  /^\/approval-requests\/\d+\/(approve|reject)$/,
  /^\/approval-requests\/bulk-(approve|reject)$/,
  /^\/payroll-periods\/\d+\/(calculate|finalize|disburse)$/,
  /^\/bonus-runs$/,
  /^\/bonus-runs\/\d+\/(calculate|approve|mark-paid|cancel)$/,
  /^\/bonus-runs\/\d+\/recipients\/include-all$/,
  /^\/devices\/register$/,
];

const PUBLIC_PATHS = new Set<string>([
  '/auth/login',
  '/auth/refresh',
  '/auth/verify-2fa',
  '/auth/forgot-password',
  '/auth/reset-password',
  '/health',
  '/openapi.json',
]);

interface RequestExtras {
  /** Force-set Idempotency-Key. Kalau undefined dan path matchidempotent, generate auto. */
  idempotencyKey?: string;
  /** Skip auth header (untuk public route). */
  skipAuth?: boolean;
  /** Sudah retry refresh token? */
  _retried?: boolean;
}

declare module 'axios' {
  // eslint-disable-next-line @typescript-eslint/no-empty-interface
  export interface InternalAxiosRequestConfig extends RequestExtras {}
  export interface AxiosRequestConfig extends RequestExtras {}
}

let refreshPromise: Promise<AuthTokens | null> | null = null;
let activeCompanyId: number | null = null; // di-set dari auth store saat login super admin

export const setActiveCompanyId = (companyId: number | null): void => {
  activeCompanyId = companyId;
};

const isIdempotentPath = (path: string): boolean =>
  IDEMPOTENT_PATH_PATTERNS.some((re) => re.test(path));

const isPublicPath = (path: string): boolean => {
  for (const p of PUBLIC_PATHS) {
    if (path === p) return true;
  }
  return false;
};

const buildClient = (): AxiosInstance => {
  const instance = axios.create({
    baseURL: env.apiBaseUrl,
    timeout: 20_000,
    headers: {
      Accept: 'application/json',
      'X-App-Version': env.appVersion,
      'X-App-Build': env.appBuild,
    },
  });

  // ─── Request interceptor ────────────────────────────────────────
  instance.interceptors.request.use(async (cfg: InternalAxiosRequestConfig) => {
    const path = (cfg.url ?? '').replace(/^https?:\/\/[^/]+\/api\/v1/, '');

    // 1. Auth header
    if (!cfg.skipAuth && !isPublicPath(path)) {
      const tokens = await SecureStorage.loadAuth();
      if (tokens?.accessToken) {
        cfg.headers.set('Authorization', `Bearer ${tokens.accessToken}`);
      }
    }

    // 2. Multi-tenant header
    if (activeCompanyId !== null && activeCompanyId > 0) {
      cfg.headers.set('X-Company-Id', String(activeCompanyId));
    }

    // 3. Idempotency-Key for critical POSTs
    const method = (cfg.method ?? 'get').toUpperCase();
    if (method === 'POST' && isIdempotentPath(path)) {
      const key = cfg.idempotencyKey ?? uuidv4();
      cfg.headers.set('Idempotency-Key', key);
    }

    return cfg;
  });

  // ─── Response interceptor ───────────────────────────────────────
  instance.interceptors.response.use(
    (resp) => resp,
    async (error: AxiosError<ApiErrorPayload>) => {
      // Network error (no response)
      if (!error.response) {
        throw new ApiError(
          error.message || 'Network error',
          'NETWORK_ERROR',
          0,
          undefined,
          true,
        );
      }

      const original = error.config as InternalAxiosRequestConfig | undefined;
      const status = error.response.status;
      const data = error.response.data;

      // 401 → refresh & retry once
      if (status === 401 && original && !original._retried) {
        const path = (original.url ?? '').replace(/^https?:\/\/[^/]+\/api\/v1/, '');
        if (!isPublicPath(path)) {
          original._retried = true;
          const fresh = await ensureRefresh();
          if (fresh) {
            original.headers = original.headers ?? {};
            original.headers.Authorization = `Bearer ${fresh.accessToken}`;
            return instance.request(original);
          }
          // refresh gagal — clear & lempar error supaya UI redirect ke login
          await SecureStorage.clearAuth();
        }
      }

      throw new ApiError(
        data?.message || error.message || `HTTP ${status}`,
        data?.code ?? `HTTP_${status}`,
        status,
        data?.errors,
      );
    },
  );

  return instance;
};

/**
 * Refresh access token. Pakai single-flight supaya beberapa request 401
 * tidak race-condition dan call /refresh berkali-kali.
 */
const ensureRefresh = async (): Promise<AuthTokens | null> => {
  if (refreshPromise) return refreshPromise;
  refreshPromise = (async () => {
    try {
      const current = await SecureStorage.loadAuth();
      if (!current?.refreshToken) return null;

      // Pakai axios biasa (bukan instance) supaya tidak rekursif kena interceptor
      const resp = await axios.post(
        `${env.apiBaseUrl}/auth/refresh`,
        { refresh_token: current.refreshToken },
        { timeout: 15_000 },
      );
      const data = resp.data?.data;
      if (!data?.access_token || !data?.refresh_token) return null;
      const next: AuthTokens = {
        accessToken: data.access_token,
        refreshToken: data.refresh_token,
        expiresAt: Date.now() + ((data.expires_in ?? 900) * 1000),
        userId: current.userId,
      };
      await SecureStorage.saveAuth(next);
      return next;
    } catch {
      return null;
    } finally {
      refreshPromise = null;
    }
  })();
  return refreshPromise;
};

export const apiClient = buildClient();

/**
 * Helper supaya caller tidak perlu unwrap `.data.data` setiap kali.
 *
 *   const list = await api.get<LeaveRecord[]>('/leaves');
 */
export const api = {
  get: async <T>(url: string, config?: AxiosRequestConfig): Promise<T> => {
    const r = await apiClient.get(url, config);
    return (r.data?.data ?? r.data) as T;
  },
  post: async <T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> => {
    const r = await apiClient.post(url, body, config);
    return (r.data?.data ?? r.data) as T;
  },
  put: async <T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> => {
    const r = await apiClient.put(url, body, config);
    return (r.data?.data ?? r.data) as T;
  },
  delete: async <T>(url: string, config?: AxiosRequestConfig): Promise<T> => {
    const r = await apiClient.delete(url, config);
    return (r.data?.data ?? r.data) as T;
  },
};
