import Geolocation from 'react-native-geolocation-service';
import { PermissionsAndroid, Platform } from 'react-native';

/**
 * Geo helper untuk attendance.
 *
 * - Request permission location (foreground only — kita tidak butuh
 *   background location karena clock-in adalah trigger user-initiated).
 * - Capture posisi sekali (highAccuracy + 10s timeout).
 * - Hitung jarak Haversine untuk validasi geofence di UI sebelum kirim
 *   (UX: "Anda 350m dari kantor, masih boleh clock-in?").
 *
 * Backend tetap validasi server-side; client check ini cuma supaya user
 * tahu lebih dulu bila keliru lokasi.
 */

export interface GeoPosition {
  latitude: number;
  longitude: number;
  accuracy: number;
  timestamp: number;
  isMocked: boolean;
}

export const requestLocationPermission = async (): Promise<boolean> => {
  if (Platform.OS === 'android') {
    const perm = PermissionsAndroid.PERMISSIONS.ACCESS_FINE_LOCATION;
    if (!perm) return false;
    const result = await PermissionsAndroid.request(perm, {
      title: 'Izin Lokasi',
      message: 'HRIS perlu lokasi GPS untuk validasi clock-in/out.',
      buttonPositive: 'OK',
    });
    return result === PermissionsAndroid.RESULTS.GRANTED;
  }
  // iOS: minta lewat react-native-permissions di App init (lihat App.tsx).
  // Geolocation di iOS akan throw kalau belum di-grant di Info.plist.
  return true;
};

export const getCurrentPosition = (): Promise<GeoPosition> =>
  new Promise((resolve, reject) => {
    Geolocation.getCurrentPosition(
      (pos) => {
        if (pos.mocked) {
          reject(new Error('Aplikasi Fake GPS terdeteksi. Harap matikan Fake GPS untuk melakukan absen.'));
          return;
        }
        resolve({
          latitude: pos.coords.latitude,
          longitude: pos.coords.longitude,
          accuracy: pos.coords.accuracy ?? 0,
          timestamp: pos.timestamp,
          isMocked: !!pos.mocked,
        });
      },
      (err) => reject(new Error(err.message || 'Gagal mengambil lokasi')),
      { enableHighAccuracy: true, timeout: 12_000, maximumAge: 5_000 },
    );
  });

/** Haversine distance in meters between two coords. */
export const distanceMeters = (
  a: { latitude: number; longitude: number },
  b: { latitude: number; longitude: number },
): number => {
  const R = 6371000; // meter
  const toRad = (n: number) => (n * Math.PI) / 180;
  const dLat = toRad(b.latitude - a.latitude);
  const dLng = toRad(b.longitude - a.longitude);
  const lat1 = toRad(a.latitude);
  const lat2 = toRad(b.latitude);
  const h =
    Math.sin(dLat / 2) ** 2 +
    Math.sin(dLng / 2) ** 2 * Math.cos(lat1) * Math.cos(lat2);
  return 2 * R * Math.asin(Math.sqrt(h));
};