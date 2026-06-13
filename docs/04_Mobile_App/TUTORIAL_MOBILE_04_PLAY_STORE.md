# Tutorial 04 — Publish ke Google Play Store

Panduan lengkap dari nol sampai app HRIS Mobile live di Google Play
Store. Total estimasi: 1-2 minggu (sebagian besar nunggu review Google).

> Prasyarat:
> - Sudah selesai [Tutorial 01-03](TUTORIAL_MOBILE_INDEX.md)
> - Punya keystore production (cek
>   [Tutorial 03 section 3.1](TUTORIAL_MOBILE_03_BUILD_APK.md#31-generate-keystore-production-sekali-per-project-jangan-diulang))
> - APK release sudah test berhasil install + jalan di HP

---

## 1. Akun Google Play Console — sekali bayar $25

### 1.1 Buat akun

1. Buka <https://play.google.com/console>
2. Login pakai akun Google (recommended pakai email perusahaan, bukan
   personal — supaya bisa di-handover kalau ganti owner)
3. Pilih tipe akun:
   - **Personal** — untuk freelancer / startup kecil. Nama developer =
     nama pribadi
   - **Organization** — untuk perusahaan. Nama developer = nama PT
     (recommended). Butuh dokumen verifikasi (NPWP/SK perusahaan)
4. Bayar $25 (~Rp 400rb) — sekali seumur hidup, **bukan langganan**
5. Verifikasi identitas:
   - **Personal**: KTP/Paspor, foto diri pegang KTP
   - **Organization**: SK Kemenkumham, NPWP, surat pengangkatan direktur
6. Tunggu approve dari Google (1-3 hari kerja, kadang sampai 1 minggu)

### 1.2 Tambah developer/team (opsional)

Setelah akun aktif: Settings → Users and permissions → Invite new user.
Role yang sering dipakai:
- **Admin** — full access (1-2 orang max)
- **Release manager** — bisa upload + publish, tidak bisa edit billing
- **Developer** — bisa upload AAB, tapi publish butuh approval
- **Viewer** — read-only

---

## 2. Buat App di Play Console

1. Login ke Play Console
2. Klik "Create app"
3. Isi form:
   - **App name** — `HRIS Mobile` atau nama produk (50 char max). Bisa
     diubah nanti.
   - **Default language** — `Indonesian (Indonesia) – id-ID` atau
     `English (United States)`
   - **App or game** — App
   - **Free or paid** — Free (HRIS B2B biasanya free, monetize via
     subscription backend)
   - **Declarations**:
     - Centang "Developer Program Policies"
     - Centang "US export laws"
4. Klik **Create app**

App muncul di dashboard dengan status "Draft".

---

## 3. Lengkapi Store Listing (informasi app)

Sidebar kiri: **Grow → Store presence → Main store listing**.

### 3.1 App details

| Field | Contoh isi |
|-------|------------|
| App name | `HRIS Mobile - Quantara` |
| Short description (80 char) | `Aplikasi HR untuk absensi, cuti, slip gaji, dan approval — semua di HP.` |
| Full description (4000 char) | Lihat template di bawah |

Template full description (sesuaikan):

```
HRIS Mobile membantu karyawan dan HR mengelola pekerjaan administratif
tanpa perlu duduk di depan PC. Cocok untuk perusahaan dengan tim
hybrid, lapangan, atau remote.

FITUR UTAMA:
✓ Clock-in / Clock-out dengan GPS verification
✓ Pengajuan cuti dengan approval workflow
✓ Lihat slip gaji + komponen pendapatan/potongan
✓ Approval request dari atasan (cuti, lembur, reimbursement)
✓ Profil karyawan & dokumen pribadi
✓ Push notification untuk reminder & approval

KEAMANAN:
- Login dengan biometrik (sidik jari/Face ID)
- Token JWT dengan auto-refresh
- Data tersimpan terenkripsi di device
- Compliance dengan UU PDP Indonesia

OFFLINE READY:
Clock-in saat tidak ada sinyal? Tidak masalah. Data tersimpan lokal
dan auto-sync saat koneksi kembali.

UNTUK SIAPA:
Aplikasi ini hanya bisa digunakan oleh karyawan perusahaan yang sudah
berlangganan HRIS Quantara. Hubungi HR Anda untuk akses login.

DUKUNGAN:
support@your-company.com
www.your-company.com/hris
```

### 3.2 Graphics — wajib semua

| Asset | Spesifikasi | Jumlah |
|-------|-------------|--------|
| **App icon** | 512×512 PNG, 32-bit, max 1MB | 1 |
| **Feature graphic** | 1024×500 PNG/JPG | 1 |
| **Phone screenshots** | 9:16 atau 16:9, min 320 px, max 3840 px | min 2, max 8 |
| **7-inch tablet screenshots** | (opsional) | 0-8 |
| **10-inch tablet screenshots** | (opsional) | 0-8 |

Tools recommended:
- **App icon**: Figma / Adobe Illustrator → export 512×512
- **Feature graphic**: Canva (template "Google Play Feature Graphic")
- **Screenshots**: ambil dari device pakai `adb`:
  ```bat
  adb exec-out screencap -p > screenshot1.png
  ```
  Atau pakai emulator → built-in screenshot (Ctrl+S).
- **Frame screenshot** (opsional, tampak lebih profesional): pakai
  <https://screenshots.pro/> atau <https://www.appstorescreenshot.com/>

> **Tip:** screenshot pertama paling penting (paling kelihatan di list
> Play Store). Tampilkan fitur paling menarik (mis. dashboard
> attendance dengan map).

### 3.3 Categorization

- **App category**: `Business` atau `Productivity`
- **Tags** (max 5): pilih relevan, mis. `HR`, `Payroll`, `Time tracking`,
  `Employee`, `Attendance`

### 3.4 Contact details

- Email: support email yang aktif
- Phone (opsional): nomor support
- Website: marketing site / landing page

### 3.5 External marketing (opsional)

- "Allow Google to use my app's icon and graphics for promotion" —
  centang kalau OK

Save semua tab. Status berubah dari "Add" jadi "Done" satu per satu.

---

## 4. Privacy Policy (WAJIB)

Play Store sekarang strict — **harus ada privacy policy yang publik**
sebelum app bisa publish, terutama kalau app collect data
(login, location, dll).

### 4.1 Buat privacy policy

Pilihan:
- **Generator gratis**: <https://app-privacy-policy-generator.firebaseapp.com/>
- **Template open source**: cari "privacy policy template Indonesia"
- **Bayar lawyer** (untuk perusahaan besar)

Minimum yang harus dijelaskan:
1. Data apa yang dikumpulkan (email, lokasi, kontak, dll)
2. Untuk apa data tersebut dipakai
3. Apakah data dishare ke third party (FCM, Sentry, dll)
4. Hak user (akses, hapus, ekspor data)
5. Cara contact developer

### 4.2 Hosting privacy policy

URL harus public + permanen + HTTPS. Pilihan:
- **Domain perusahaan**: `https://your-company.com/hris/privacy`
- **GitHub Pages gratis**: push file `.md` ke repo public, enable Pages
- **Notion publik**: tidak recommended, kadang Google reject

Contoh struktur:
```markdown
# Kebijakan Privasi HRIS Mobile
Tanggal efektif: 11 Juni 2026

## Data yang kami kumpulkan
- Email & nama (untuk login)
- Lokasi GPS (saat clock-in/out)
- Foto profile (opsional)
- Device info (untuk push notification)

## Penggunaan data
Data hanya dipakai untuk fungsi aplikasi (autentikasi, attendance,
notifikasi). Data tidak dijual ke pihak ketiga.

## Berbagi data
- Firebase Cloud Messaging (Google) untuk push notif
- Sentry untuk crash report (anonim)

## Hak Anda
Hubungi support@your-company.com untuk:
- Akses data Anda
- Menghapus akun
- Ekspor data

## Kontak
PT Quantara, Jl. ..., Jakarta. support@your-company.com
```

### 4.3 Set di Play Console

App content → Privacy policy → Privacy policy URL → masukkan link →
Save.

---

## 5. App content declarations (form wajib)

Sidebar: **Policy → App content**. Ada ~10 section yang harus diisi.
Kalau salah satu kosong, app tidak bisa publish.

### 5.1 Privacy policy
Sudah di section 4.

### 5.2 App access
- "Are all of your app's functions available without restrictions?"
- Pilih: **All or some functionality is restricted**
  → karena app HRIS butuh login dari perusahaan
- Tambah credential test untuk Google reviewer:
  - Username: `reviewer@your-company.com`
  - Password: `<password test>`
  - Note: "Login dengan akun ini untuk akses semua fitur. Akun ini
    berisi data dummy."

> Akun ini **wajib** kalau app butuh login. Tanpa ini, reviewer
> tidak bisa test → app reject.

### 5.3 Ads
- "Does your app contain ads?" → **No** (kecuali memang ada)

### 5.4 Content rating

Klik "Start questionnaire". Pilih:
- Email kontak
- Category: **Reference, News, or Educational** (atau "Utility,
  Productivity, Communication, or Other")

Lalu jawab kuesioner:
- Apakah app berisi konten kekerasan? → No
- Konten seksual? → No
- Bahasa kasar? → No
- ...

Hasil rating biasanya **PEGI 3 / Everyone** untuk app HR.

### 5.5 Target audience and content
- Target age: pilih **18 and over** (HRIS bukan untuk anak-anak)
- "Does your app appeal to children?" → No

### 5.6 News apps
- "Is this a news app?" → No

### 5.7 COVID-19 contact tracing
- → No

### 5.8 Data safety (Data Safety form — paling lama)

Jelaskan SEMUA data yang app collect/share. Untuk HRIS biasanya:

| Data type | Collected | Shared | Required | Purpose |
|-----------|-----------|--------|----------|---------|
| Email address | ✅ | ❌ | Required | Account management |
| Name | ✅ | ❌ | Required | Account management |
| User photos | ✅ (kalau ada profile foto) | ❌ | Optional | App functionality |
| Approximate location | ✅ | ❌ | Required | App functionality (attendance) |
| Precise location | ✅ | ❌ | Required | App functionality (attendance) |
| Phone number | ✅ (kalau ada) | ❌ | Optional | Account management |
| Device or other IDs | ✅ | ✅ Firebase | Required | App functionality (push notif) |
| App interactions | ✅ | ❌ | Required | Analytics |
| Crash logs | ✅ | ✅ Sentry | Required | App functionality |

Untuk setiap data: jelaskan
- Apakah dienkripsi saat transit? (Yes — HTTPS)
- Apakah user bisa request delete? (Yes — kontak support)

### 5.9 Government apps
- → No

### 5.10 Financial features
- → No (kecuali app ada e-wallet/payment)

### 5.11 Health
- → No

---

## 6. Set up app signing oleh Play Console (recommended)

Play Console punya fitur **Play App Signing** — Google manage upload
key + signing key. Lebih aman karena kalau key kamu hilang, masih bisa
recover.

### 6.1 Pilih opsi signing

Sidebar: **Release → Setup → App signing**.

Pilihan saat upload AAB pertama:
1. **Use Google-generated key** (recommended) — Google generate
   signing key, kamu hanya pegang upload key. Kalau upload key hilang,
   bisa di-reset.
2. **Upload your own key** — kamu pegang signing key sendiri (yang
   tadi `release.keystore`). Hilang = game over.

Untuk first publish, ikut yang nomor 1. Saat upload AAB pertama,
Google otomatis enroll.

### 6.2 Eksport upload certificate

Setelah enroll, Play Console kasih:
- **App signing key certificate (SHA-1, SHA-256)** — untuk Firebase /
  Google Maps API config
- **Upload key certificate** — yang dipakai di local

Catat SHA-1/SHA-256 ini. Akan dipakai di section 9 (Firebase setup).

---

## 7. Build AAB (Android App Bundle)

Play Store **tidak terima APK lagi sejak Aug 2021**. Harus AAB.

### 7.1 Build

```bat
cd mobile\android
gradlew.bat clean
gradlew.bat :app:bundleRelease
```

Wait sampai `BUILD SUCCESSFUL`. Output:
```
mobile\android\app\build\outputs\bundle\release\app-release.aab
```

Ukuran ~30-50 MB. Smaller dari APK karena Play Store yang nanti split
per device.

### 7.2 Verifikasi AAB

```bat
:: Inspeksi metadata
keytool -list -printcert -jarfile mobile\android\app\build\outputs\bundle\release\app-release.aab

:: Verifikasi versionCode + versionName
:: cek di mobile\android\app\build.gradle defaultConfig
```

### 7.3 Test AAB di device (opsional tapi recommended)

AAB bukan format yang bisa langsung install. Generate APK dari AAB
pakai `bundletool`:

```bat
:: Download bundletool dari https://github.com/google/bundletool/releases
java -jar bundletool.jar build-apks ^
  --bundle=app-release.aab ^
  --output=app-release.apks ^
  --ks=release.keystore ^
  --ks-key-alias=hris-mobile

java -jar bundletool.jar install-apks --apks=app-release.apks
```

Test install + run. Kalau OK, lanjut upload.

---

## 8. Upload AAB & buat release

### 8.1 Pilih track

Play Console punya 4 track release:

| Track | Untuk siapa | Perlu review Google? |
|-------|-------------|----------------------|
| **Internal testing** | Tim internal (max 100 user) | Tidak (instant) |
| **Closed testing** | Tester terbatas via email/group (~beta tester) | Iya (1-3 hari) |
| **Open testing** | Public beta — siapa saja yang join link | Iya (1-3 hari) |
| **Production** | Live di Play Store buat semua user | Iya (1-7 hari) |

**Workflow recommended:**
1. Internal testing dulu — test sama tim selama 1-2 hari
2. Closed testing — distribusi ke 5-10 power user / staff HR
3. Production — setelah confirm tidak ada bug major

### 8.2 Upload AAB ke Internal testing

1. Sidebar: **Release → Testing → Internal testing**
2. Klik "Create new release"
3. Drag-drop atau klik "Upload" → pilih `app-release.aab`
4. Wait sampai upload + processing selesai (~2 menit)

### 8.3 Tulis release notes

Field "Release notes" — bahasa Indonesia + Inggris (kalau target multi
bahasa):

```html
<id-ID>
🎉 HRIS Mobile v1.0.0 — Rilis Pertama!
- Login dengan email + biometrik
- Clock-in/out dengan GPS
- Pengajuan cuti
- Lihat slip gaji
- Approval workflow
</id-ID>

<en-US>
🎉 HRIS Mobile v1.0.0 — First Release!
- Email + biometric login
- GPS-verified clock-in/out
- Leave request workflow
- Payslip viewer
- Manager approval flow
</en-US>
```

### 8.4 Tambah tester

Internal testing → Testers tab → Create email list → masukkan email
tester (max 100). Save.

Klik "Copy link" untuk dapat opt-in URL. Kirim ke tester.

Tester:
1. Buka link di HP yang sama dengan email yang di-add
2. Tap "Become a tester"
3. Open Play Store → search "HRIS Mobile" → install (sekarang available)
4. Update auto saat ada AAB baru

### 8.5 Roll out release

Klik **Review release** → cek semua warning hilang → **Start rollout
to Internal testing**.

Status berubah jadi "Available for testing". Internal testing langsung
live.

---

## 9. Setup Firebase (untuk push notification)

Kalau app butuh push notif (FCM):

### 9.1 Buat project Firebase

1. <https://console.firebase.google.com> → Add project → kasih nama
   "HRIS Mobile"
2. Skip Google Analytics atau enable (gratis)

### 9.2 Add Android app ke Firebase

- Package name: `com.hrismobile` (harus match `applicationId` di
  `build.gradle`)
- Nickname: `HRIS Mobile Android`
- Debug signing certificate SHA-1: ambil dari
  `keytool -list -v -keystore debug.keystore` (password `android`)
- Release SHA-1: copy dari Play Console (App signing → "Release SHA-1")

### 9.3 Download `google-services.json`

Letakkan di `mobile/android/app/google-services.json`.

> **Jangan commit ke Git** kalau project public. Kalau private repo OK.

### 9.4 Update build.gradle

`mobile/android/build.gradle`:
```gradle
dependencies {
    classpath("com.google.gms:google-services:4.4.0")
}
```

`mobile/android/app/build.gradle` baris paling bawah:
```gradle
apply plugin: 'com.google.gms.google-services'
```

### 9.5 Install dependency RN

```bat
cd mobile
npm install @react-native-firebase/app @react-native-firebase/messaging
```

Rebuild app. Test push notif:
- Firebase Console → Cloud Messaging → "Send your first message"
- Target: device dengan FCM token (cek di app log)

---

## 10. Promote dari testing ke production

Setelah testing OK 1-2 minggu:

1. Sidebar: **Release → Production**
2. "Create new release"
3. **Reuse AAB dari Internal testing**: scroll ke bawah, klik
   "Promote release" dari panel Internal testing
4. Update release notes (boleh sama atau lebih ringkas)
5. **Countries / regions**: pilih negara target. "Indonesia" minimum,
   tambah negara lain kalau perlu.
6. Klik "Review release" → cek warning → klik "Start rollout to
   production"

### 10.1 Staged rollout (recommended untuk production)

Default 100% rollout. Untuk safer:
- Day 1-2: 5% rollout (kalau ada bug, hanya 5% user kena)
- Day 3-4: 20%
- Day 5-7: 50%
- Day 8+: 100%

Kalau ditemukan crash via Crashlytics, **halt rollout** sebelum 100%.

### 10.2 Wait for review

Google review production AAB:
- First submission: 3-7 hari (bisa 14 hari kalau ramai)
- Update: biasanya 1-3 hari
- Hotfix urgent: bisa request expedited review

Status di Play Console: "Pending publication" → "Published".

App live di:
```
https://play.google.com/store/apps/details?id=com.hrismobile
```

---

## 11. Update release berikutnya

Tiap update:

1. Naikkan `versionCode` + `versionName` di
   `mobile/android/app/build.gradle`
2. Build AAB baru: `gradlew.bat :app:bundleRelease`
3. Play Console → Release → Production → Create new release
4. Upload AAB baru
5. Tulis "What's new" (akan tampil di Play Store update screen)
6. Review + rollout

> **Tip:** keep changelog di repo (`mobile/CHANGELOG.md`) — copy-paste
> ke release notes biar konsisten.

---

## 12. Monitoring after publish

Sidebar Play Console:
- **Statistics** — install, uninstall, ANRs (App Not Responding)
- **Vitals** — crash rate, performance
- **Reviews** — rating + feedback user. Reply ke review penting!
- **Reports** → Reach and devices — share device + Android version

Set up alerting:
- Settings → Alerting → enable email untuk:
  - Crash rate > X%
  - Bad rating < 3 stars
  - Policy violation

---

## 13. Reject & cara handle

Kalau Google reject app, alasan umum + solusi:

### 13.1 Privacy Policy issue
**Reject:** "Privacy policy is missing or incomplete"
**Fix:** Lengkapi privacy policy section 4. Pastikan URL berfungsi
+ jelaskan SEMUA data di Data Safety form.

### 13.2 Permissions misuse
**Reject:** "App requests permissions not used"
**Fix:** Audit permissions di `AndroidManifest.xml`. Hapus yang tidak
dipakai (mis. CALL_PHONE kalau tidak ada fitur call).

### 13.3 Background location
**Reject:** "Use of ACCESS_BACKGROUND_LOCATION violates policy"
**Fix:** HRIS hanya butuh foreground location untuk clock-in. Hapus
`ACCESS_BACKGROUND_LOCATION` dari manifest.

### 13.4 Test account tidak work
**Reject:** "Reviewer cannot login"
**Fix:** Update credential di App content → App access. Pastikan
akun masih aktif.

### 13.5 Target API level outdated
**Reject:** "Update target SDK to API 34 (Android 14)"
**Fix:** Update `targetSdkVersion` di `mobile/android/build.gradle`
ke 34. Build ulang.

Reply via Play Console → Policy → status. Biasanya appeal di-respond
dalam 2-3 hari.

---

## 14. Cek list final sebelum publish ke production

- [ ] AAB sudah test di internal testing track minimal 3 hari
- [ ] Tidak ada crash di Crashlytics
- [ ] Privacy Policy URL aktif + complete
- [ ] App content declarations lengkap (semua tab "Done")
- [ ] Test account untuk reviewer aktif
- [ ] Screenshot 4-8 yang menarik
- [ ] Feature graphic 1024×500 ada
- [ ] App icon 512×512 ada
- [ ] Release notes Indonesian + English
- [ ] Target API 34+ (Android 14)
- [ ] versionCode tidak duplikat dengan versi sebelumnya
- [ ] Backup keystore sudah aman
- [ ] Tim aware kalau review takes 3-7 hari

---

## 15. Resource tambahan

- Play Console help: <https://support.google.com/googleplay/android-developer>
- Policy center: <https://play.google.com/about/developer-content-policy/>
- Best practices store listing: <https://developer.android.com/distribute/best-practices/launch>
- Android version distribution: <https://apilevels.com/>

---

## 16. Lanjut

- Mau publish iOS juga →
  [Tutorial 05 — Build iOS](TUTORIAL_MOBILE_05_BUILD_IOS.md)
- Build error → [Tutorial 06](TUTORIAL_MOBILE_06_TROUBLESHOOTING.md)