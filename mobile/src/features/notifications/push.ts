import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';

import { registerDeviceToken, unregisterDeviceToken } from '@features/devices/register';
import { useAuthStore } from '@store/auth';
import { mmkv } from '@store/mmkv';

/**
 * Push notification orchestration — Sprint M2.
 *
 * Karena `@react-native-firebase/messaging` butuh native config
 * (google-services.json + GoogleService-Info.plist) yang belum jadi di
 * scaffold, modul ini di-design supaya bisa hidup di 2 mode:
 *
 *   1. **Stub mode (sekarang)**: caller tetap bisa import & wire ke
 *      Boot flow tanpa crash. Tidak ada native side-effect; hanya cache
 *      stub token dari MMKV (kalau ada).
 *
 *   2. **Production mode (M2 follow-up)**: developer mobile mengganti
 *      `getFcmToken()` dengan call ke `messaging().getToken()` dari
 *      Firebase, dan memasang listener `messaging().onMessage()` +
 *      `setBackgroundMessageHandler()` di entrypoint app.
 *
 * Pola hook `usePushSync()` di bawah dipakai di App.tsx Boot flow saat
 * user logged-in: auto-register token, dan saat sign-out, auto-unregister
 * (best-effort).
 */

const FCM_TOKEN_KEY = 'fcm.token';

/**
 * Stub: di production, ganti body dengan:
 *   const messaging = require('@react-native-firebase/messaging').default;
 *   await messaging().requestPermission();
 *   return await messaging().getToken();
 */
export const getFcmToken = async (): Promise<string | null> => {
  const cached = mmkv.getString(FCM_TOKEN_KEY);
  return cached ?? null;
};

/**
 * Hook auto-register device token saat user login.
 * Dipakai di App.tsx setelah user object terisi.
 */
export const usePushSync = (): void => {
  const user = useAuthStore((s) => s.user);
  const queryClient = useQueryClient();

  useEffect(() => {
    if (!user) return;
    void (async () => {
      try {
        const token = await getFcmToken();
        if (!token) return;
        await registerDeviceToken(token);
        // Saat ada notif baru, push handler nanti akan invalidate query.
        queryClient.invalidateQueries({ queryKey: ['notifications.unread'] });
      } catch (e) {
        // Best-effort; tidak blok user
        console.warn('[push] register failed', e);
      }
    })();

    return () => {
      // Saat user berubah (logout), unregister token lama.
      void (async () => {
        try {
          const token = await getFcmToken();
          if (token) await unregisterDeviceToken(token);
        } catch {
          // ignore
        }
      })();
    };
  }, [user, queryClient]);
};

/**
 * Saat developer mobile siap integrasi penuh, panggil ini sekali di
 * entrypoint (sebelum AppRegistry.registerComponent):
 *
 *   import messaging from '@react-native-firebase/messaging';
 *   messaging().setBackgroundMessageHandler(handleBackgroundPush);
 *
 * Untuk MVP scaffold, dummy handler ini cukup placeholder.
 */
export const handleBackgroundPush = async (
  remoteMessage: { data?: Record<string, string>; notification?: { title?: string; body?: string } },
): Promise<void> => {
  // Tergantung payload data di backend PushNotifier:
  //   { type: 'leave', id: '123' }  → user tap → buka leave detail
  //   { type: 'approval', id: '45' } → buka approval inbox
  // Routing concrete di-implement saat deep link layer dipasang (M3).
  console.log('[push] background message', remoteMessage);
};