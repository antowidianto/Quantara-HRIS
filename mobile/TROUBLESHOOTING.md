# Troubleshooting — HRIS Mobile

Catatan masalah umum + solusi cepat. Update saat tim ketemu issue baru.

## Setup

### `npx react-native init` failed (network/firewall)

Solusi: pakai mirror npm
```bash
npm config set registry https://registry.npmmirror.com
npm install
```
Setelah install selesai, balik ke registry.npmjs.org.

### `pod install` errors di macOS

```
[!] Invalid `Podfile` file: 765: unexpected token at ...
```

Solusi: pastikan Ruby ≥ 3.1.0 (RN 0.76 minimum), CocoaPods ≥ 1.13:
```bash
sudo gem install cocoapods
cd ios && pod repo update && pod install
```

### Gradle build gagal — `SDK location not found`

Buat file `mobile/android/local.properties`:
```
sdk.dir=/Users/<USER>/Library/Android/sdk         # macOS
sdk.dir=C:\\Users\\<USER>\\AppData\\Local\\Android\\Sdk  # Windows
```

---

## Runtime

### `Network Error` saat clock-in di Android emulator

Android emulator akses host machine bukan via `localhost` tapi `10.0.2.2`.
Edit `.env`:
```
API_BASE_URL=http://10.0.2.2:8080/api/v1
```

iOS simulator pakai `localhost` (tidak masalah).

### Clock-in stuck "Mengambil lokasi..."

Kemungkinan:
1. Permission GPS belum di-grant. Buka Settings → Apps → HRIS → Permissions
2. Lokasi mock di emulator tidak set. Di Android Studio Emulator → ⋮ →
   Location → set lat/lng manual
3. iOS Simulator: Features → Location → Apple/Custom Location

### Offline outbox tidak replay

Cek:
- `Outbox.count()` returnnya berapa? Kalau > 0, sweep belum jalan.
- NetInfo state: `console.log(await NetInfo.fetch())` — pastikan
  `isConnected: true && isInternetReachable: true`
- Backend reachable? Coba hit `/api/v1/health` dari device

Manual sweep: tap badge "outbox pending" di Attendance screen.

### Token expired tapi user tidak logout

Refresh-on-401 single-flight di `client.ts` seharusnya handle ini.
Kalau tetap gagal:
- Cek `expires_in` di response login — backend default 15 menit
- Cek Keychain entry: di Settings → Privacy → Keychain (iOS) atau
  reset via `SecureStorage.clearAuth()` dari console
- Backend log audit: `auth.refresh.failed` event

### Biometric prompt muncul tapi sensor tidak available

```
Biometric.capability() → { available: false }
```

Penyebab:
- Device tidak punya sidik jari/Face ID enrolled di OS settings
- Flag `Biometric.isEnabled()` masih `true` dari sebelumnya. Reset:
  `Biometric.setEnabled(false)`
- Android emulator: extended controls → Fingerprint → Touch sensor

### Push notification tidak masuk

1. Token sudah register? Hit `GET /api/v1/devices` dari Postman dengan
   token user. Kalau kosong, `usePushSync` belum jalan.
2. Firebase config benar? Cek `google-services.json` ada di
   `android/app/`. iOS: `GoogleService-Info.plist` ada di Xcode project.
3. Backend env `fcm.projectId` dan `fcm.serviceAccountPath` set? Tanpa
   itu, PushNotifier mode log-only.
4. Test kirim manual dari Firebase Console → Cloud Messaging → Send to
   single token

---

## Build & Release

### Android signing error

`mobile/android/app/build.gradle` — atur signingConfig:
```gradle
android {
    signingConfigs {
        release {
            storeFile file('release.keystore')
            storePassword System.getenv('KEYSTORE_PASSWORD')
            keyAlias 'hris-mobile'
            keyPassword System.getenv('KEY_PASSWORD')
        }
    }
}
```

Generate keystore (sekali per project):
```bash
keytool -genkey -v -keystore release.keystore -alias hris-mobile -keyalg RSA -keysize 2048 -validity 10000
```

JANGAN commit keystore ke git. Simpan di password manager / secret vault.

### iOS App Store rejection — usage description missing

Pastikan semua key di `mobile/native-config/info-plist-additions.plist`
sudah di-merge ke `Info.plist`. Apple wajib semua usage descriptions
(NSCameraUsageDescription, NSLocationWhenInUseUsageDescription,
NSPhotoLibraryUsageDescription, NSFaceIDUsageDescription).

### Bundle size terlalu besar (> 30 MB)

Strategi:
1. Enable Hermes engine (sudah default RN 0.76+)
2. Enable ProGuard/R8 di release build (Android)
3. Split APK per ABI:
   ```gradle
   splits {
       abi {
           enable true
           include "armeabi-v7a", "arm64-v8a"
           universalApk false
       }
   }
   ```
4. Audit deps: `npx react-native-bundle-visualizer` untuk lihat top
   contributors

---

## Testing

### Jest test gagal — "Cannot find module 'react-native'"

Jest preset `react-native` butuh transformer khusus. Pastikan
`mobile/package.json` jest config:
```json
"transformIgnorePatterns": [
  "node_modules/(?!(react-native|@react-native|@react-navigation|@tanstack)/)"
]
```

### MMKV test gagal — "MMKV is not a constructor"

Jest setup di `mobile/jest.setup.js` punya stub MMKV class. Pastikan
test file import MMKV dari `@store/mmkv` (bukan langsung dari
`react-native-mmkv`).

### TypeScript errors di IDE setelah `git pull`

```bash
cd mobile
rm -rf node_modules
npm install
npx tsc --noEmit
```

VSCode: Cmd/Ctrl+Shift+P → "TypeScript: Restart TS Server".

---

## Backend / Mobile Integration

### Idempotency-Key replay tidak balik response yang sama

Cek backend log untuk filter `idempotency:before`. Kemungkinan:
- TTL key expired (default 24 jam) → backend re-execute
- Key hashing salah di client (tidak match SHA-256 backend)
- Method/path beda dari original → backend reject 422 dengan
  `IDEMPOTENCY_KEY_MISMATCH`

### `/openapi.json` 404

Pastikan `OpenApiController` ada di `Routes.php`:
```php
$routes->get('openapi.json', 'OpenApiController::spec');
```

di luar group `['filter' => 'jwt']` (route public).

### Multi-tenant: super admin tidak bisa akses company tertentu

Set header `X-Company-Id` di request, atau panggil
`switchCompany(companyId)` di auth store. Mobile app simpan ke
`activeCompanyId` di `client.ts` lewat `setActiveCompanyId()`.

---

## Performance

### App lemot saat scroll list panjang

- Pakai `FlatList` (sudah default), bukan ScrollView untuk list >50 item
- Set `initialNumToRender={20}` + `maxToRenderPerBatch={10}`
- Memoize `renderItem` dengan `useCallback`

Saat ini Tier 1 modules masih pakai `.map()` untuk simplicity di MVP.
Migration ke FlatList di-defer ke polish sprint kalau dataset > 100 row.

### Battery drain karena GPS

Tidak ada background location di app ini (sengaja). Foreground GPS hanya
saat user tap clock-in/out. Kalau battery drain tetap tinggi, cek device
log untuk other RN modules yang mungkin polling background.

---

Halaman ini sengaja short. Untuk deep dive arsitektur, lihat
`mobile/README.md` dan 4 sprint docs di `docs/SPRINT_MOBILE_M*.md`.