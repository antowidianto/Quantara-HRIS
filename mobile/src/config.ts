import Config from 'react-native-config';

/**
 * Typed wrapper untuk react-native-config supaya tidak banyak `as string`
 * tersebar di codebase. Default fallback aman untuk dev tanpa .env.
 */
export const env = {
  apiBaseUrl: Config.API_BASE_URL ?? 'http://10.0.2.2:8080/api/v1', // Android emulator host
  appVersion: Config.APP_VERSION ?? '0.1.0',
  appBuild:   Config.APP_BUILD ?? '1',
  sentryDsn:  Config.SENTRY_DSN ?? '',
  defaultGeofenceMeters: Number(Config.DEFAULT_GEOFENCE_METERS ?? '200'),
};