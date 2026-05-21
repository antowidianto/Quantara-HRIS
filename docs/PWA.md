# PWA / Mobile (Progressive Web App)

Dokumen ini menjelaskan modul **Progressive Web App** yang ditambahkan di
iterasi roadmap fase 5 item #15.

## Latar Belakang

Karyawan lapangan (sales, kurir, teknisi, pekerja shift) sering tidak punya
device kantor dan butuh akses HRIS lewat HP pribadi. Browser web biasa kurang
ideal: harus buka URL tiap kali, hilang saat refresh, dan tidak bisa offline.

PWA menjawab kebutuhan ini tanpa perlu native iOS/Android app:
- **Install ke home screen** seperti native app
- **Offline-first** untuk fitur kritis (clock-in saat tidak ada sinyal)
- **Push notifications** (foundation siap, perlu VAPID server-side)
- **App shortcuts** ke fitur sering dipakai (Clock In, Cuti, Slip Gaji, Approval)

## Apa yang Berubah

### Frontend Files

#### `frontend/public/manifest.webmanifest`
Web App Manifest dengan:
- Identitas app: name, short_name, description, theme_color (#2563eb), background_color
- Display mode `standalone` (tanpa browser chrome saat installed)
- Icons 192/512px + maskable variant (untuk Android adaptive icon)
- 4 **shortcuts** ke fitur populer:
  - Clock In/Out → `/attendance/clock`
  - Cuti Saya → `/leaves`
  - Slip Gaji → `/payslips/my`
  - Inbox Approval → `/approvals/inbox`
- Long-press app icon di home screen → muncul shortcut menu

#### `frontend/public/sw.js` (Service Worker)
Vanilla JS, tanpa dependency tambahan. Strategi:

**App shell (HTML/JS/CSS/fonts/icons)** — cache-first
- Pre-cache `/`, `/index.html`, `/manifest.webmanifest`, `/favicon.svg` saat install
- Hit cache dulu, fallback network. Saat asset baru ke-fetch, masuk cache.
- Versioned cache (`hris-shell-v1.0.0`) — bump version setiap deploy untuk invalidate.

**API GET requests** — network-first dengan fallback cache
- Coba network dulu (always fresh). Cache header `x-sw-cached-at` untuk timestamp.
- Kalau offline → fallback ke cache (max 7 hari).
- Tambah header `x-from-cache: 1` saat dari cache supaya UI bisa indicate stale data.

**API mutations (POST/PUT/DELETE/PATCH)** — bypass cache + offline queue
- Coba network. Sukses → return as-is.
- Gagal (offline) → simpan request ke IndexedDB store `hris-pending-mutations`.
- Return synthetic 202 dgn `x-sw-queued: 1` supaya UI tidak gagal total.
- Saat user kembali online → auto-flush queue (replay request) lewat:
  - Background Sync API (`hris-flush-queue` tag) di Chrome Android
  - Manual postMessage dari client (`flush-queue`)
- Notify clients via `postMessage({ type: 'queue-flushed', success, failed, remaining })`.
- 4xx error response → drop dari queue (mencegah retry infinite untuk validation error).

**Web Push** — foundation siap
- Listen `push` event → tampilkan notifikasi via `showNotification()`
- Listen `notificationclick` → buka URL target (atau focus window existing)
- VAPID public key + endpoint POST harus disuplai dari backend (TODO)

#### `frontend/src/composables/usePwa.js`
Composable Vue untuk:
- `register()` — register SW (skip di dev kecuali env VITE_PWA_DEV)
- `isOnline` — reactive ref, listen `online`/`offline` event
- `updateAvailable` — flag kalau SW baru ready
- `applyUpdate()` — reload dengan SW baru (postMessage `skip-waiting`)
- `canInstall` — beforeinstallprompt event tertangkap
- `promptInstall()` — trigger Android install dialog
- `flushQueue()` — manual replay queue
- `queueStatus` — reactive status saat queue di-flush
- Helper `subscribePush()` & `unsubscribePush()` untuk Web Push integration

#### `frontend/src/components/PwaBanner.vue`
Auto-mounted di App.vue. Menampilkan:
- **Offline indicator** — banner kuning di top saat `! isOnline`
- **Install prompt** — banner emerald di bottom-left saat `canInstall && ! dismissed`
  - Tombol "Pasang" → trigger native install dialog
  - Tombol "Nanti" → save ke localStorage supaya tidak tampil lagi
- **Update available** — banner blue di bottom-right saat SW baru ready
  - Tombol "Reload Sekarang" → apply update + reload
- **Toast notifications**:
  - Saat balik online: "Kembali online — sinkronisasi data..."
  - Saat offline: "Anda offline. Aksi akan dikirim saat online."
  - Saat queue flushed: "✓ N permintaan offline berhasil dikirim"

#### `frontend/index.html`
Tambahan meta tag PWA-required:
- `<link rel="manifest" href="/manifest.webmanifest">`
- `<meta name="apple-mobile-web-app-capable" content="yes">` (iOS Safari)
- `<meta name="apple-mobile-web-app-title" content="HRIS">`
- `<link rel="apple-touch-icon" href="/icons/icon-192.png">`

## Workflow Skenario

### Skenario 1 — Install ke Home Screen

1. User akses `https://hris.example.com` di Android Chrome.
2. Chrome detect manifest valid + SW registered → trigger `beforeinstallprompt`.
3. Banner emerald muncul: "Pasang HRIS di perangkat Anda".
4. User klik **Pasang** → muncul native install dialog Android.
5. Konfirmasi → app icon HRIS muncul di home screen.
6. Tap icon → buka standalone (tanpa Chrome address bar), pakai theme color biru.
7. Long-press icon → muncul shortcut menu: Absen / Cuti / Slip Gaji / Approval.

### Skenario 2 — Offline Clock-In di Lapangan

1. Kurir di area sinyal lemah klik tap **Clock In** di `/attendance/clock`.
2. SW intercept request → tidak ada network → simpan ke IndexedDB queue.
3. UI dapat response 202 dengan `x-sw-queued: 1` → tampil toast "Akan dikirim saat online".
4. Banner kuning di top: "Anda sedang offline...".
5. Saat sinyal kembali, `online` event fire → auto `flushQueue()`.
6. Request replay ke server → kalau OK, tercatat dengan `created_at` dari saat di-queue.
7. Toast hijau: "✓ 1 permintaan offline berhasil dikirim".
8. UI fetch data lagi → jam clock-in tampil di history.

### Skenario 3 — App Update

1. Setelah deploy versi baru, file `sw.js` ada perubahan (CACHE_VERSION baru).
2. Browser fetch `sw.js`, byte-compare dengan yang lama → ada beda → install version baru.
3. SW lama masih jadi controller, SW baru `waiting`.
4. Composable detect → `updateAvailable.value = true` → banner blue muncul.
5. User klik **Reload Sekarang** → `skip-waiting` postMessage → controller pindah → reload.
6. User dapat versi baru tanpa hard-refresh manual.

### Skenario 4 — iOS Safari (limited)

1. iOS Safari belum support beforeinstallprompt → banner install tidak muncul.
2. User tap Share → "Add to Home Screen" → install manual.
3. SW jalan setelah install (iOS 16.4+), offline cache work, push notifications terbatas.
4. iOS belum support Background Sync — replay queue trigger oleh `online` event saja.

## Verifikasi

Build frontend sukses dengan `npm run build`:

```
✓ dist/manifest.webmanifest (1.86 KB) — copied dari public/
✓ dist/sw.js (9.46 KB) — copied dari public/
✓ dist/index.html — meta tag manifest + apple-touch-icon ter-include
```

Manual testing checklist:
- [ ] Buka `npm run preview` di Chrome → DevTools → Application → Manifest valid
- [ ] Application → Service Workers → terdaftar dgn scope `/`
- [ ] Application → Storage → IndexedDB → ada `hris-pending-mutations`
- [ ] Network tab → Offline mode → coba clock-in → return 202
- [ ] Application → Cache Storage → `hris-shell-v1.0.0` + `hris-api-v1.0.0`
- [ ] Trigger Lighthouse PWA audit → score ≥ 90
- [ ] Android Chrome: tap install banner → app muncul di home screen
- [ ] Buka standalone → no browser chrome, theme color biru

## Bypass SW di Development

SW di-register hanya saat `import.meta.env.PROD === true`. Untuk testing di dev:

```bash
# .env.local
VITE_PWA_DEV=true
```

Lalu jalankan `npm run dev` — SW akan terdaftar tapi pakai source dev (jangan
deploy file ini).

## Setup Production

### 1. Generate Icon Files

Lihat `frontend/public/icons/README.md`. Butuh:
- `icon-192.png` (192×192) — Android home + apple-touch
- `icon-512.png` (512×512) — high-res
- `icon-maskable-512.png` (512×512 dengan padding 10%) — Android adaptive
- `shortcut-*.png` (96×96) — opsional untuk shortcut menu

Bisa pakai online generator: https://realfavicongenerator.net atau script
sharp/imagemagick lokal.

### 2. HTTPS Required

Service Worker hanya jalan di `https://` atau `http://localhost`. Untuk testing
production-like di IP local, pakai mkcert atau ngrok.

### 3. Web Push (Optional)

Untuk enable real push notifications:
1. Generate VAPID keypair: `npx web-push generate-vapid-keys`
2. Simpan public key di backend setting `pwa.vapid_public_key`
3. Backend endpoint `POST /push-subscriptions` untuk simpan subscription per user
4. Backend trigger push lewat `web-push` library (PHP: minishlink/web-push) saat ada notif penting
5. Frontend call `subscribePush(publicKey, '/api/v1/push-subscriptions')` setelah login

(Saat ini foundation di SW siap, tapi backend endpoint + library belum dibuat.)

## Integrasi dengan Modul Lain

- **Notifikasi**: notification system existing (in-app) tetap jalan. Push notif
  jadi delivery channel tambahan.
- **Attendance Clock**: paling diuntungkan dari offline queue. Clock-in saat
  signal jelek otomatis re-sync.
- **Leave/Overtime/Reimbursement form submit**: semua mutation lewat axios
  → otomatis kena offline queue tanpa code change.

## Roadmap Selanjutnya

- **Generate icon files** — saat ini placeholder, butuh design asset
- **Web Push end-to-end** — VAPID + subscription endpoint + sender library
- **Background sync untuk file upload** — handle multipart attachment offline
- **Periodic Background Sync** — auto-fetch data refresh tiap N jam
- **Workbox migration** — kalau cache strategy makin kompleks, pertimbangkan pakai Workbox
- **App update toast lebih halus** — saat ini banner; bisa migrate ke ToastWithAction style
- **Deeper IndexedDB caching** — cache full employee profile, payslips, untuk offline read-heavy