import DeviceInfo from 'react-native-device-info';
import { Platform } from 'react-native';
import { api } from '@api/client';

/**
 * Register device ke backend untuk push notification.
 *
 * Untuk MVP scaffold, kita TIDAK include react-native-firebase langsung
 * karena Firebase native config (google-services.json + GoogleService-Info.plist)
 * butuh pengaturan project-level di Android/iOS yang melebihi scope scaffold.
 *
 * Pola yang dipakai:
 *   1. Caller (App.tsx setelah login) panggil `registerDeviceToken(fcmToken)`
 *      dengan token yang sudah di-fetch dari Firebase Messaging.
 *   2. Helper ini lengkapi metadata (device id, model, OS, app version)
 *      lalu POST ke /devices/register.
 *
 * Untuk instructions integrasi Firebase lengkap, lihat docs/MOBILE_APP_PLAN.md
 * + react-native-firebase docs (https://rnfirebase.io/messaging/usage).
 */

export const registerDeviceToken = async (fcmToken: string): Promise<void> => {
  if (!fcmToken) return;
  await api.post('/devices/register', {
    platform: Platform.OS === 'ios' ? 'IOS' : 'ANDROID',
    token: fcmToken,
    device_id: await DeviceInfo.getUniqueId(),
    device_model: DeviceInfo.getModel(),
    os_version: DeviceInfo.getSystemVersion(),
    app_version: DeviceInfo.getVersion(),
    locale: 'id-ID',
    timezone: 'Asia/Jakarta',
  });
};

export const unregisterDeviceToken = async (fcmToken: string): Promise<void> => {
  if (!fcmToken) return;
  try {
    await api.post('/devices/unregister', { token: fcmToken });
  } catch {
    // best-effort
  }
};