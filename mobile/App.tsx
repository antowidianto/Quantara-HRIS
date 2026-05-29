import React, { useEffect } from 'react';
import { ActivityIndicator, AppState, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import NetInfo from '@react-native-community/netinfo';

import { ThemeProvider, useTheme } from '@theme/ThemeProvider';
import { useAuthStore } from '@store/auth';
import { AuthApi } from '@features/auth/api';
import { AuthStack } from '@features/auth/AuthStack';
import { MainTabs } from './src/MainTabs';
import { Outbox } from '@api/outbox';
import { Biometric } from '@features/auth/biometric';
import { usePushSync } from '@features/notifications/push';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { IdleLockGate } from '@/components/IdleLockGate';
import { ForceUpdateGate } from '@/components/ForceUpdateGate';
import { IdleLock } from '@/lib/idleLock';

/**
 * Root App.
 *
 * Boot sequence:
 *   1. ThemeProvider + SafeAreaProvider + react-query mount
 *   2. useAuthStore.bootstrap() — load token dari Keychain
 *   3. Kalau ada token: fetch /auth/me untuk hydrate user object,
 *      lalu render MainTabs. Kalau gagal (401 ke-clear di interceptor) →
 *      AuthStack.
 *   4. NetInfo + AppState listener → trigger Outbox.sweep() saat online
 *      atau saat app jadi active.
 */

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 30_000,
      refetchOnWindowFocus: false,
    },
  },
});

const Boot: React.FC = () => {
  const bootstrapped = useAuthStore((s) => s.bootstrapped);
  const tokens = useAuthStore((s) => s.tokens);
  const user = useAuthStore((s) => s.user);
  const setUser = useAuthStore((s) => s.setUser);
  const signOut = useAuthStore((s) => s.signOut);
  const bootstrap = useAuthStore((s) => s.bootstrap);

  // Auto-register FCM token saat user login + cleanup saat logout
  usePushSync();

  // Initial bootstrap dari Keychain
  useEffect(() => {
    if (!bootstrapped) bootstrap();
  }, [bootstrapped, bootstrap]);

  // Biometric quickUnlock saat ada token tapi user belum di-hydrate
  // Kalau user sudah enable biometric flag, prompt sebelum hit /me
  useEffect(() => {
    if (!bootstrapped || !tokens || user) return;
    if (!Biometric.isEnabled()) return;
    void (async () => {
      const ok = await Biometric.authenticate('Buka HRIS dengan biometrik');
      if (!ok) {
        // User cancel biometric → sign out, kembali ke login
        signOut();
      }
    })();
    // sekali per session; deps minimal
  }, [bootstrapped, tokens, user, signOut]);

  // Hydrate user dari /me kalau token sudah ada tapi user belum
  useEffect(() => {
    if (bootstrapped && tokens && !user) {
      // Kalau biometric required & belum approved, tunda sampai di-approve
      if (Biometric.isEnabled()) {
        // Hook biometric di atas akan signOut kalau cancel; di sini
        // kita tetap call /me karena Biometric.authenticate berjalan paralel.
        // Jika gagal, signOut akan reset state.
      }
      AuthApi.me()
        .then((u) => setUser(u))
        .catch(() => {
          // Refresh sudah dicoba di interceptor; sampai sini berarti
          // token benar-benar invalid → kembali ke login.
          signOut();
        });
    }
  }, [bootstrapped, tokens, user, setUser, signOut]);

  // Outbox sweep saat online / app aktif kembali
  useEffect(() => {
    const sweepIfOnline = async () => {
      const net = await NetInfo.fetch();
      if (net.isConnected && net.isInternetReachable !== false) {
        Outbox.sweep().catch(() => undefined);
      }
    };
    sweepIfOnline();
    const netSub = NetInfo.addEventListener((state) => {
      if (state.isConnected && state.isInternetReachable !== false) {
        Outbox.sweep().catch(() => undefined);
      }
    });
    const appSub = AppState.addEventListener('change', (s) => {
      if (s === 'active') sweepIfOnline();
    });
    return () => {
      netSub();
      appSub.remove();
    };
  }, []);

  // M5 — pasang IdleLock AppState listener (catat blur, set lock saat
  // resume melebihi threshold). IdleLockGate yang akan render UI
  // overlay-nya saat flag aktif.
  useEffect(() => {
    const cleanup = IdleLock.install();
    return cleanup;
  }, []);

  const theme = useTheme();
  if (!bootstrapped) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: theme.colors.bg }}>
        <ActivityIndicator color={theme.colors.primary} />
      </View>
    );
  }

  if (tokens && user) {
    return (
      <IdleLockGate onSignOut={signOut}>
        <MainTabs />
      </IdleLockGate>
    );
  }
  return <AuthStack />;
};

/**
 * Provider tree:
 *   SafeAreaProvider
 *   └─ QueryClientProvider
 *      └─ ThemeProvider
 *         └─ ErrorBoundary  ← catch render-time exception
 *            └─ ForceUpdateGate  ← block kalau backend bilang force-update
 *               └─ Boot  ← auth bootstrap + IdleLockGate(MainTabs)
 *
 * ForceUpdateGate didahulukan dari Boot karena gate ini ingin tampil
 * juga saat user belum login (kasus rilis breaking-change auth).
 */
const App: React.FC = () => (
  <SafeAreaProvider>
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <ErrorBoundary fallbackHint="HRIS gagal memuat layar utama. Silakan tutup & buka ulang aplikasi.">
          <ForceUpdateGate>
            <Boot />
          </ForceUpdateGate>
        </ErrorBoundary>
      </ThemeProvider>
    </QueryClientProvider>
  </SafeAreaProvider>
);

export default App;