# Tutorial Mobile HRIS — Index

Kumpulan tutorial lengkap untuk mengoperasikan, build, dan rilis aplikasi
mobile HRIS (React Native 0.76, TypeScript, target Android + iOS).

Tutorial ini ditulis untuk developer/operator yang baru pertama kali
menyentuh repo ini. Semua perintah sudah di-verify di Windows 10 +
Node 18 + JDK 17 + Android SDK 34.

> Status build (verifikasi terakhir 11 Juni 2026):
> - `npx tsc --noEmit` → 0 error
> - `npx jest` → 23/23 lulus (5 suites)
> - `gradlew :app:assembleDebug` → BUILD SUCCESSFUL
> - APK debug + release sudah ter-generate di `mobile/android/app/build/outputs/apk/`

---

## Urutan baca yang disarankan

| # | Tutorial | Kapan dibaca |
|---|----------|--------------|
| 01 | [Setup Environment](TUTORIAL_MOBILE_01_SETUP.md) | Pertama kali setup PC/laptop developer |
| 02 | [Dev Workflow & Run di Device](TUTORIAL_MOBILE_02_DEV_WORKFLOW.md) | Mau ngoding harian (Metro, hot reload, debugging) |
| 03 | [Build APK (Debug + Release)](TUTORIAL_MOBILE_03_BUILD_APK.md) | Mau bikin file `.apk` untuk install manual |
| 04 | [Publish ke Google Play Store](TUTORIAL_MOBILE_04_PLAY_STORE.md) | Mau rilis ke jutaan user via Play Store |
| 05 | [Build iOS (macOS only)](TUTORIAL_MOBILE_05_BUILD_IOS.md) | Mau rilis ke App Store |
| 06 | [Troubleshooting Lengkap](TUTORIAL_MOBILE_06_TROUBLESHOOTING.md) | Saat ada error build / runtime |

Tutorial 01 adalah prasyarat untuk semua tutorial lainnya. Tutorial 02
prasyarat untuk 03/04. Tutorial 05 berdiri sendiri dan butuh macOS.

---

## Quick reference — perintah paling sering dipakai

Semua dijalankan dari folder `mobile/`:

```bat
:: 1. Sekali setelah clone repo / pull update besar
npm ci

:: 2. Cek kode sehat (TypeScript + Jest)
npx tsc --noEmit
npm test

:: 3. Run di emulator/HP yang tersambung (dev mode, hot reload)
npm run android

:: 4. Build APK debug (langsung jalan di HP, tidak perlu keystore)
cd android
gradlew.bat :app:assembleDebug
:: Output: android\app\build\outputs\apk\debug\app-debug.apk

:: 5. Build APK release (signed, untuk distribusi)
cd android
gradlew.bat :app:assembleRelease
:: Output: android\app\build\outputs\apk\release\app-release.apk

:: 6. Build AAB untuk Play Store
cd android
gradlew.bat :app:bundleRelease
:: Output: android\app\build\outputs\bundle\release\app-release.aab

:: 7. Install APK ke HP yang tersambung via USB
adb install -r android\app\build\outputs\apk\debug\app-debug.apk
```

---

## Struktur folder mobile

```
mobile/
├── android/                  Native code Android (Gradle, Kotlin)
│   ├── app/
│   │   ├── build/outputs/apk/    APK output ada di sini
│   │   ├── debug.keystore        Keystore default (auto-generated)
│   │   └── release.keystore      Keystore production (HARUS dibuat sendiri)
│   ├── build.gradle
│   ├── gradle.properties
│   ├── gradlew.bat               Wrapper Gradle (Windows)
│   ├── local.properties          Path Android SDK lokal (jangan commit)
│   └── settings.gradle
├── ios/                      Native code iOS (Xcode, Swift) — macOS only
├── native-config/            Snippet permission untuk merge ke Android/iOS
├── scripts/                  Helper script
│   ├── build-android.cmd     Wrapper build APK/AAB
│   └── init-native.sh        Generate android/ + ios/ dari scaffold
├── src/                      Source TypeScript app
│   ├── api/                  Axios client + interceptor + outbox
│   ├── components/           Komponen UI reusable
│   ├── features/             Feature module (auth, attendance, leave, dst.)
│   ├── i18n/                 Translation (id, en)
│   ├── lib/                  Helper umum
│   ├── store/                Zustand store + MMKV adapter
│   └── theme/                Design system + theme provider
├── .env.example              Template environment
├── App.tsx                   Root component
├── index.js                  Entry point RN
├── package.json
├── README.md                 Overview teknis app
├── TROUBLESHOOTING.md        Catatan masalah umum (lama)
└── tsconfig.json
```

---

## Prinsip yang dipegang

1. **Kode harus selalu compile.** Sebelum push, jalankan `npx tsc --noEmit`
   dan `npm test`. Build APK setelah keduanya hijau.
2. **Keystore production tidak boleh di-commit.** Simpan di password
   manager + backup terenkripsi. Kalau hilang, app di Play Store tidak
   bisa di-update lagi (harus app baru).
3. **`.env` tidak di-commit.** Setiap developer copy dari `.env.example`
   dan isi `API_BASE_URL` sesuai kebutuhan masing-masing (localhost vs
   staging vs production).
4. **APK debug ≠ APK release.** Debug pakai keystore default, ukurannya
   ~130 MB karena include semua arsitektur + Hermes debug. Release pakai
   keystore production, ukurannya ~60 MB karena dioptimasi.
5. **Play Store tidak terima APK.** Sejak Agustus 2021, Play Store hanya
   menerima format AAB (Android App Bundle). APK hanya untuk distribusi
   internal/sideload.

---

## Roadmap mobile

Detail per sprint ada di file `SPRINT_MOBILE_M*.md` di folder ini.
Status modul ada di `mobile/README.md`.

Tier 1 MVP (sudah scaffold): Auth, Attendance, Leave, Approvals,
Payslip, Profile, Device push registration.

Tier 2 (M3+): Overtime, Reimbursement (foto+OCR), Business trip,
Helpdesk, Settings dalam (2FA, password, notif prefs), forgot password,
PDF download.

---

## Bantuan

- Slack: `#hris-mobile`
- Issue tracker: GitHub Issues label `mobile`
- Email tim: lihat `docs/CONTACT.md` (kalau ada) atau `CONTRIBUTING.md`
  di root repo

Kalau menemukan error baru yang belum ada di
[TUTORIAL_MOBILE_06_TROUBLESHOOTING.md](TUTORIAL_MOBILE_06_TROUBLESHOOTING.md),
silakan PR untuk update dokumentasi.