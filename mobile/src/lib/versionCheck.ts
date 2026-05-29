import { api, ApiError } from '@api/client';
import { env } from '@/config';

/**
 * Minimum-version gate — Sprint M4 hardening.
 *
 * Saat app start, fetch `/mobile/version` dari backend. Response:
 *   {
 *     min_supported: "0.1.0",   // di bawah ini → force update
 *     latest:        "0.2.0",   // optional info
 *     update_url_ios:     "...",
 *     update_url_android: "..."
 *   }
 *
 * Kalau current `env.appVersion` < `min_supported` → returnvalue
 * `{ status: 'force', ... }` dan App.tsx render ForceUpdate screen.
 *
 * Endpoint dibuat **public** (tanpa auth) supaya user yang belum login
 * juga bisa kena gate. Bukan rahasia (cuma cetak versi minimum).
 *
 * Strategy:
 *   - Network gagal → 'ok' (jangan block user kalau backend down)
 *   - Response invalid → 'ok'
 *   - Hanya block kalau response valid + version comparison ber-confirm
 */

export interface VersionGateResult {
  status: 'ok' | 'force' | 'recommended';
  latest?: string;
  minSupported?: string;
  updateUrl?: string;
  message?: string;
}

interface ServerResponse {
  min_supported?: string;
  latest?: string;
  update_url_ios?: string;
  update_url_android?: string;
  message?: string;
}

/**
 * Bandingkan dua semver string (mayor.minor.patch). Return:
 *   negatif kalau a < b, 0 kalau sama, positif kalau a > b.
 * Tidak handle prerelease ("-alpha"); cukup untuk mobile build code.
 */
export const compareSemver = (a: string, b: string): number => {
  const parse = (s: string) =>
    s.split('.').map((p) => {
      const n = parseInt(p, 10);
      return Number.isFinite(n) ? n : 0;
    });
  const aa = parse(a);
  const bb = parse(b);
  const len = Math.max(aa.length, bb.length);
  for (let i = 0; i < len; i++) {
    const av = aa[i] ?? 0;
    const bv = bb[i] ?? 0;
    if (av !== bv) return av - bv;
  }
  return 0;
};

const platformUpdateUrl = (resp: ServerResponse): string | undefined => {
  // RN doesn't import platform here untuk testability; pilih iOS dulu,
  // fallback ke android. App.tsx boleh override pakai Platform.OS.
  return resp.update_url_ios ?? resp.update_url_android;
};

export const checkVersionGate = async (): Promise<VersionGateResult> => {
  try {
    const resp = await api.get<ServerResponse>('/mobile/version');
    const minSup = resp.min_supported ?? '';
    const latest = resp.latest ?? '';
    const current = env.appVersion;

    if (minSup && compareSemver(current, minSup) < 0) {
      return {
        status: 'force',
        latest,
        minSupported: minSup,
        updateUrl: platformUpdateUrl(resp),
        message: resp.message ?? 'Update aplikasi untuk melanjutkan.',
      };
    }
    if (latest && compareSemver(current, latest) < 0) {
      return {
        status: 'recommended',
        latest,
        minSupported: minSup,
        updateUrl: platformUpdateUrl(resp),
      };
    }
    return { status: 'ok' };
  } catch (e: unknown) {
    // Tidak boleh block kalau network error / endpoint belum di-deploy
    if (e instanceof ApiError && e.httpStatus === 404) {
      return { status: 'ok' };
    }
    return { status: 'ok' };
  }
};