/**
 * Crash reporting wrapper — Sprint M3 polish.
 *
 * Stub yang siap di-replace dengan Sentry / Firebase Crashlytics
 * saat tim ops siap. Kenapa pakai wrapper, bukan langsung pakai Sentry
 * SDK di tiap call site:
 *
 *   1. Test-friendly: jest.setup.js bisa mock 1 file vs scatter
 *   2. Zero coupling: developer bisa swap provider tanpa edit screens
 *   3. PII redaction terpusat (sebelum kirim ke 3rd-party)
 *
 * Pemakaian:
 *
 *   import { crash } from '@/lib/crash';
 *   try { ... } catch (e) {
 *     crash.captureException(e, { feature: 'attendance', step: 'clock-in' });
 *   }
 *
 *   crash.breadcrumb('user.tap', { screen: 'login' });
 *
 * Production setup (M3+ developer task):
 *
 *   import * as Sentry from '@sentry/react-native';
 *   Sentry.init({ dsn: env.sentryDsn, ... });
 *   // Lalu replace stub `captureException` & `breadcrumb` dengan
 *   //   Sentry.captureException + Sentry.addBreadcrumb
 */

import { env } from '@/config';

interface BreadcrumbContext {
  category?: string;
  data?: Record<string, unknown>;
}

interface ExceptionContext {
  feature?: string;
  step?: string;
  user_id?: number;
  [key: string]: unknown;
}

const enabled = (env.sentryDsn ?? '').length > 0;

const redactPii = (data: Record<string, unknown> | undefined): Record<string, unknown> | undefined => {
  if (!data) return undefined;
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(data)) {
    // Redact NIK, password, phone, NPWP, bank account
    if (/password|nik|npwp|bank_account|phone|email/i.test(k)) {
      out[k] = '[redacted]';
      continue;
    }
    out[k] = v;
  }
  return out;
};

export const crash = {
  captureException(error: unknown, context?: ExceptionContext): void {
    const ctx = redactPii(context);
    if (!enabled) {
      // Dev mode: log ke console saja
      // eslint-disable-next-line no-console
      console.warn('[crash] (stub)', error, ctx);
      return;
    }
    // Production: Sentry.captureException(error, { extra: ctx });
  },

  breadcrumb(message: string, context?: BreadcrumbContext): void {
    if (!enabled) return;
    // Production: Sentry.addBreadcrumb({ message, category: context?.category, data: redactPii(context?.data) });
  },

  setUser(user: { id: number; email?: string } | null): void {
    if (!enabled) return;
    // Production:
    //   if (user) Sentry.setUser({ id: String(user.id) }); // no email/PII
    //   else Sentry.setUser(null);
  },
};