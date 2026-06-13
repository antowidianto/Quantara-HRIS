# Tutorial 05 — Build iOS & Publish ke App Store

Cara build app HRIS Mobile untuk iPhone/iPad dan publish ke Apple
App Store. Tutorial ini fokus ke alur yang berbeda dari Android.

> **PENTING:** Build iOS **WAJIB pakai macOS**. Tidak bisa di Windows
> atau Linux native. Alternative: cloud Mac (MacInCloud, MacStadium)
> atau service CI/CD (Codemagic, Bitrise, EAS Build).

> Prasyarat:
> - macOS 13 (Ventura) atau lebih baru
> - Xcode 15+ dari App Store
> - Akun Apple Developer ($99/tahun)
> - Sudah baca [Tutorial 01 — Setup](TUTORIAL_MOBILE_01_SETUP.md)

---

## 1. Setup macOS untuk RN

```bash
# Homebrew (kalau belum)
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Node 18 LTS
brew install node@18

# Watchman (file watcher untuk Metro)
brew install watchman

# CocoaPods (manage iOS dependency)
sudo gem install cocoapods

# Verifikasi
node -v       # v18.x
pod --version # 1.14.x atau lebih
```

### Install Xcode

1. App Store → search "Xcode" → Install (~10 GB)
2. Buka Xcode satu kali untuk terima license
3. Install Command Line Tools:
   ```bash
   xcode-select --install
   sudo xcode-select -s /Applications/Xcode.app/Contents/Developer
   ```
4. Xcode → Settings → Components → Download "iOS 17 Simulator"

### Akun Apple Developer

1. Daftar di <https://developer.apple.com> ($99/tahun)
2. Pilih Individual atau Organization (organization butuh D-U-N-S Number)
3. Verifikasi + bayar, tunggu approve 1-3 hari

> D-U-N-S Number gratis di <https://www.dnb.com/duns-number/get-a-duns.html>.
> Untuk Indonesia 2-4 minggu kalau gratis.

---

## 2. Generate folder iOS native

Folder `mobile/ios/` belum ada di repo, harus di-generate sekali:

```bash
cd mobile
bash scripts/init-native.sh
```

Atau manual:
```bash
cd mobile
npx --yes @react-native-community/cli@latest init HrisMobileTmp \
  --version 0.76.3 --skip-install --skip-git-init \
  --directory _tmp/scaffold
cp -R _tmp/scaffold/ios ios
rm -rf _tmp
```

Struktur:
```
mobile/ios/
├── HrisMobile/                ← target app utama
├── HrisMobileTests/
├── HrisMobile.xcodeproj/
├── HrisMobile.xcworkspace/    ← BUKA INI (BUKAN xcodeproj)
├── Podfile
└── Podfile.lock
```

---

## 3. Install CocoaPods dependency

```bash
cd mobile/ios
pod install
```

Tunggu 5-10 menit pertama kali. Setelah selesai, **selalu buka workspace
(.xcworkspace), bukan project (.xcodeproj)** karena Pods di-link via
workspace.

### Troubleshooting `pod install`

```bash
# Update Ruby ≥ 3.1.0 kalau error
brew install rbenv ruby-build
rbenv install 3.1.4
rbenv global 3.1.4
gem install cocoapods

# Update spec
pod repo update
pod install --repo-update

# Clean reinstall
rm -rf Pods Podfile.lock
pod install
```

---

## 4. Konfigurasi Bundle Identifier + .env

### 4.1 Edit `.env`

```env
# Untuk simulator iOS
API_BASE_URL=http://localhost:8080/api/v1

# Untuk device fisik di WiFi yang sama
# API_BASE_URL=http://192.168.1.5:8080/api/v1
```

### 4.2 Set Bundle Identifier

Bundle ID iOS = applicationId Android = ID unik global. Format reverse
domain: `com.yourcompany.hrismobile`.

1. Buka `mobile/ios/HrisMobile.xcworkspace` di Xcode
2. Sidebar → klik project root "HrisMobile"
3. Tab **Signing & Capabilities**
4. **Bundle Identifier** → ganti ke `com.quantara.hrismobile` (atau
   yang sesuai perusahaan)
5. **Team** → pilih akun Apple Developer

> Bundle ID **unik global**. Cek availability di tools online sebelum
> commit. Sekali publish, **tidak bisa ganti**.

---

## 5. Run di iOS Simulator (dev mode)

Terminal 1:
```bash
cd mobile && npm start
```

Terminal 2:
```bash
cd mobile && npm run ios
```

Default launch di iPhone 15. Untuk pilih device:
```bash
npm run ios -- --simulator="iPhone 15 Pro"
```

Pertama kali: 10-15 menit. Selanjutnya: ~30 detik.

Hot reload: edit kode → save → simulator auto-refresh.

---

## 6. Run di iPhone fisik

1. Sambungkan iPhone via USB → Trust this computer
2. Xcode → project → Signing & Capabilities → centang "Automatically
   manage signing" → pilih Team
3. Pilih iPhone target di toolbar Xcode → klik tombol Play
4. Pertama kali, di iPhone:
   - Settings → General → VPN & Device Management
   - Klik nama developer → Trust
5. Sekarang app bisa dilaunch

Atau pakai CLI:
```bash
npm run ios -- --device "iPhone Saya"
```

---

## 7. Setup permission di Info.plist

iOS strict — wajib jelaskan kenapa app butuh permission. Edit
`mobile/ios/HrisMobile/Info.plist`, tambahkan **sebelum** `</dict>`:

```xml
<key>NSCameraUsageDescription</key>
<string>HRIS Mobile butuh akses kamera untuk foto profile dan reimbursement.</string>

<key>NSPhotoLibraryUsageDescription</key>
<string>HRIS Mobile butuh akses galeri untuk upload foto profile.</string>

<key>NSLocationWhenInUseUsageDescription</key>
<string>HRIS Mobile pakai lokasi GPS untuk verifikasi clock-in/out.</string>

<key>NSFaceIDUsageDescription</key>
<string>HRIS Mobile pakai Face ID untuk login cepat.</string>

<key>NSContactsUsageDescription</key>
<string>HRIS Mobile pakai kontak untuk fitur emergency contact.</string>
```

> **Tanpa key ini → App Store reject (kode 4.5.4).**

Snippet referensi: `mobile/native-config/info-plist-additions.plist`.

---

## 8. Build Production (Archive)

### 8.1 Set version + build number

Xcode → project → tab "General":
- **Version** (CFBundleShortVersionString) — `1.0.0`, format semver
- **Build** (CFBundleVersion) — `1`, **harus monoton naik per Version**

Aturan App Store:
- Version (`1.0.0`) bisa di-reuse antar build
- Build (`1`, `2`, `3`) HARUS monoton naik. Sekali upload `1.0.0 (5)`,
  upload berikutnya tidak bisa `1.0.0 (5)` lagi — harus `(6)`.

### 8.2 Test release build dulu

Edit Scheme → Build Configuration: Release → run di simulator. Cek
fitur core jalan, console.log tidak bocor.

Balikkan ke Debug setelah test.

### 8.3 Archive

1. Toolbar Xcode: pilih device target = **"Any iOS Device (arm64)"**
   (BUKAN simulator)
2. Menu → Product → **Archive**
3. Tunggu compile (5-15 menit)
4. Jendela "Organizer" terbuka dengan archive baru

### 8.4 Validate archive

Sebelum upload:
1. Organizer → klik archive → klik **Validate App**
2. Distribution method: **App Store Connect**
3. Provisioning profile: **Automatic**
4. Klik Next → Validate

Kalau lulus, lanjut. Kalau warning, fix dulu (biasanya signing/cert).

---

## 9. Upload ke App Store Connect

### 9.1 Buat app di App Store Connect

1. Buka <https://appstoreconnect.apple.com>
2. **My Apps** → klik **+** → **New App**
3. Isi:
   - Platform: **iOS**
   - Name: `HRIS Mobile`
   - Primary language: Indonesian
   - Bundle ID: pilih yang barusan dibuat
   - SKU: ID internal mis. `HRIS-MOBILE-001` (bebas, unique per akun)
   - User Access: Full Access
4. Create

### 9.2 Upload archive dari Xcode

1. Organizer → archive → **Distribute App**
2. **App Store Connect** → Upload
3. Default options OK
4. Manage signing automatically
5. Klik Upload, tunggu 5-10 menit

Setelah upload, archive masuk ke App Store Connect → Build tab. Wait
~10-30 menit untuk Apple processing.

### 9.3 Lengkapi metadata App Store

App → tab "App Information":
- **Name** — `HRIS Mobile`
- **Subtitle** (max 30 char) — `Absensi, cuti, slip gaji`
- **Category** — Primary: `Business`, Secondary: `Productivity`
- **Privacy Policy URL** — wajib (lihat
  [Tutorial 04 section 4](TUTORIAL_MOBILE_04_PLAY_STORE.md#4-privacy-policy-wajib))

App → tab "Pricing and Availability":
- Price: Free (atau set tier kalau berbayar)
- Availability: pilih negara target (minimum Indonesia)

App → version "1.0 Prepare for Submission":
- **Description** — mirip Play Store full description
- **Keywords** (max 100 char, comma-separated) — mis.
  `HR,payroll,attendance,cuti,karyawan,gaji,absensi,kantor`
- **Support URL** — `https://your-company.com/support`
- **Marketing URL** — opsional
- **Promotional Text** — opsional, 170 char tampil di top App Store

### 9.4 Screenshots & icon

Wajib screenshot untuk minimal 2 ukuran device:

| Device | Resolution | Min |
|--------|-----------|-----|
| iPhone 6.7" (15 Pro Max) | 1290 × 2796 | wajib |
| iPhone 6.5" (11 Pro Max) | 1242 × 2688 | wajib |
| iPhone 5.5" (8 Plus) | 1242 × 2208 | wajib (untuk app lama) |
| iPad Pro 12.9" | 2048 × 2732 | wajib jika support iPad |

Cara ambil:
- Simulator → Cmd+S (auto save ke Desktop)
- Atau pakai tools generator screenshot Apple

App Icon di App Store: handled otomatis dari Xcode asset catalog
(`Images.xcassets/AppIcon.appiconset`). Pastikan ada icon 1024×1024
PNG no alpha channel.

### 9.5 App Privacy (Data Safety equivalent)

App → tab "App Privacy":
- Klik "Get Started"
- Untuk setiap data type yang app collect, jelaskan:
  - Data type (Email, Location, Photos, dll)
  - Purpose (App Functionality, Analytics)
  - Linked to identity? (Yes/No)
  - Used for tracking? (No untuk HRIS internal)

Mirip Data Safety form Play Store. Lihat referensi di
[Tutorial 04 section 5.8](TUTORIAL_MOBILE_04_PLAY_STORE.md#58-data-safety-data-safety-form--paling-lama).

### 9.6 Build & test info

Pilih build yang barusan upload:
- App → version → **Build** section → klik "+ Build" → pilih build
  yang sudah di-process

App Review Information:
- Sign-in info: kasih credential test user (mirip Play Store)
- Notes: jelaskan flow login, requirement device, dll
- Contact: nama + phone + email reviewer bisa kontak

---

## 10. TestFlight (beta testing iOS)

App Store Connect → tab **TestFlight**.

### 10.1 Internal testing

Untuk tim internal (max 100 tester per app, semuanya harus punya akun
Apple Developer Team Member). Tidak butuh review Apple → instant.

1. Build masuk ke "iOS" tab di TestFlight
2. Klik build → kasih Test Information (what to test, build notes)
3. Add internal testers via "Internal Group"
4. Tester install via app TestFlight di iPhone

### 10.2 External testing

Untuk tester di luar tim (max 10,000). **Perlu review Apple sekali**
(biasanya 24 jam, lebih cepat dari production review).

1. TestFlight → Add External Testers
2. Buat group, masukkan email tester (bisa public link juga)
3. Submit for Beta App Review
4. Setelah approve, tester dapat email + install via TestFlight app

> External TestFlight expire 90 hari. Setelah itu user harus install
> versi production atau update build TestFlight baru.

---

## 11. Submit untuk Production review

Setelah TestFlight smooth 1-2 minggu:

1. App Store Connect → version → cek semua field "Done"
2. Klik **Submit for Review** di pojok kanan atas
3. Jawab beberapa pertanyaan:
   - Export Compliance: pakai HTTPS standar → **No** (untuk encryption
     custom)
   - Content Rights: app tidak include third-party content → No
   - Advertising Identifier (IDFA): untuk HRIS pure, **No**

### 11.1 Wait for review

- First submission: **24-48 jam** (sangat bervariasi, kadang 5 hari)
- Update: 24 jam biasanya
- Hotfix: bisa "Expedited Review" sekali per app, untuk emergency

Status di App Store Connect:
- **Waiting for Review** → Apple belum lihat
- **In Review** → reviewer sedang test
- **Pending Developer Release** → lulus, tinggal release manual
- **Ready for Sale** → live di App Store

### 11.2 Phased Release (recommended)

Sama dengan staged rollout Play Store:
- App Store Connect → version → Phased Release for Automatic Updates
- Default: 7 hari, naik bertahap (1% → 100%)

Bisa pause kalau ditemukan crash kritis.

---

## 12. Update versi berikutnya

1. Bump Version + Build di Xcode → General tab
2. Archive → Validate → Upload
3. App Store Connect → "+ Version" → buat version baru (mis. `1.0.1`)
4. Pilih build yang barusan upload
5. Tulis "What's New in This Version" (release notes)
6. Submit for Review

---

## 13. Common reject reasons App Store

### 13.1 Guideline 5.1.1 — Privacy
**Reject:** "App collects data without explanation"
**Fix:** Lengkapi App Privacy section. Pastikan privacy policy URL
explanatory.

### 13.2 Guideline 4.0 — Design (HIG)
**Reject:** "Design tidak match Apple Human Interface Guidelines"
**Fix:** Audit UI vs HIG. Ganti tap target < 44pt, dll.

### 13.3 Guideline 2.1 — App Completeness
**Reject:** "App crashes on launch" / "Login broken"
**Fix:** Test ulang di device fisik. Reviewer pakai akun yang kamu
kasih — pastikan akun aktif.

### 13.4 Guideline 4.5.4 — Push Notification
**Reject:** "Push notification untuk tujuan iklan"
**Fix:** HRIS notif legitimate (approval, reminder). Jelaskan di
review notes.

### 13.5 Guideline 5.1.5 — Location
**Reject:** "App pakai location tapi tidak essential"
**Fix:** Jelaskan di review notes — clock-in butuh GPS verification.

Reply via App Store Connect → Resolution Center. Biasanya reviewer
respond 24 jam setelah reply.

---

## 14. CI build iOS (Codemagic / Bitrise / EAS)

Build iOS di local Mac OK untuk dev. Untuk team distributed atau CI,
pakai cloud service:

### 14.1 Codemagic (recommended untuk RN)
- 500 menit build/bulan gratis
- Setup: connect GitHub repo, set workflow YAML
- Auto-build + deploy ke TestFlight saat push tag
- Detail: <https://codemagic.io/start/>

### 14.2 GitHub Actions dengan macOS runner
```yaml
name: iOS Build

on:
  push:
    tags: ['mobile-v*']

jobs:
  build-ios:
    runs-on: macos-14
    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: 18
          cache: npm
          cache-dependency-path: mobile/package-lock.json

      - name: Install JS deps
        run: cd mobile && npm ci

      - name: Pod install
        run: cd mobile/ios && pod install

      - name: Import certificates
        env:
          APPLE_CERT: ${{ secrets.APPLE_CERTIFICATE_BASE64 }}
          CERT_PASSWORD: ${{ secrets.CERT_PASSWORD }}
        run: |
          echo $APPLE_CERT | base64 -d > cert.p12
          security create-keychain -p "" build.keychain
          security import cert.p12 -k build.keychain -P $CERT_PASSWORD -T /usr/bin/codesign

      - name: Build & Archive
        run: |
          cd mobile/ios
          xcodebuild -workspace HrisMobile.xcworkspace \
            -scheme HrisMobile \
            -configuration Release \
            -archivePath build/HrisMobile.xcarchive archive

      - name: Export IPA
        run: |
          xcodebuild -exportArchive \
            -archivePath build/HrisMobile.xcarchive \
            -exportOptionsPlist ExportOptions.plist \
            -exportPath build/

      - uses: actions/upload-artifact@v4
        with:
          name: hris-mobile-ipa
          path: mobile/ios/build/*.ipa
```

GitHub macOS runner $0.08/menit untuk private repo (gratis untuk
public). Build iOS rata-rata 15-25 menit.

---

## 15. Cek list final sebelum submit

- [ ] Bundle Identifier unik & match App Store Connect
- [ ] Version + Build number naik
- [ ] All Info.plist usage descriptions ada
- [ ] App Privacy form di-fill complete
- [ ] Privacy Policy URL aktif + comprehensive
- [ ] Screenshots minimal 2 device size (6.7", 6.5")
- [ ] App icon 1024×1024 ada di asset catalog
- [ ] Keywords + description engaging
- [ ] Test login dengan akun reviewer di iPhone fisik
- [ ] No console.log di release build
- [ ] TestFlight beta sudah test 3-7 hari minimum
- [ ] Crash rate < 0.1% di TestFlight
- [ ] Backup signing certificate + provisioning profile

---

## 16. Resource tambahan

- App Store Review Guidelines: <https://developer.apple.com/app-store/review/guidelines/>
- Human Interface Guidelines: <https://developer.apple.com/design/human-interface-guidelines/>
- App Store Connect help: <https://help.apple.com/app-store-connect/>
- TestFlight: <https://developer.apple.com/testflight/>

---

## 17. Lanjut

- Build error / runtime error →
  [Tutorial 06 — Troubleshooting](TUTORIAL_MOBILE_06_TROUBLESHOOTING.md)
- Lihat tutorial lain di [Index](TUTORIAL_MOBILE_INDEX.md)