# Demo Frontend Vue di HP (akses via IP LAN)

Panduan singkat untuk menjalankan frontend Vite + Vue agar bisa diakses dari HP yang berada di **jaringan Wi‑Fi yang sama** dengan komputer dev.

> Cocok untuk demo cepat, pengujian PWA, geolocation clock‑in, dan tampilan responsif tanpa perlu deploy.

---

## 1. Pastikan HP & PC di Wi‑Fi yang sama

HP dan komputer harus terhubung ke **SSID yang sama** dan **bukan** di "guest network" / VLAN terisolasi (banyak router/kantor memblokir komunikasi antar device di guest network).

## 2. Cek IP LAN komputer

Windows (PowerShell / CMD):

```cmd
ipconfig | findstr /i "IPv4"
```

Cari yang berformat `192.168.x.x` atau `10.x.x.x` di adapter Wi‑Fi aktif. Contoh:

```
IPv4 Address. . . . . . . . . . . : 192.168.1.7
```

> Kalau ada beberapa IP (mis. 192.168.1.7 dan 192.168.1.13), biasanya yang pertama adalah Wi‑Fi. Kalau ragu, coba satu‑satu dari HP.

## 3. Jalankan backend (CodeIgniter)

Backend tetap di `localhost:8080` di sisi PC — tidak perlu expose ke LAN, karena yang dipanggil HP adalah Vite dev server, dan Vite akan **proxy** request `/api` ke backend lewat localhost.

```cmd
cd backend
php spark serve --host=localhost --port=8080
```

> Jika mau, bisa juga `--host=0.0.0.0` lalu set `VITE_API_BASE_URL=http://<IP-PC>:8080` dan `VITE_USE_PROXY=false`. Tapi cara proxy lebih simpel dan tidak perlu CORS tambahan.

## 4. Jalankan frontend Vite

```cmd
cd frontend
npm install        # sekali aja
npm run dev
```

Output kira-kira:

```
  VITE v5.4.8  ready in 432 ms

  ➜  Local:   http://localhost:5173/
  ➜  Network: http://192.168.1.7:5173/
  ➜  Network: http://192.168.1.13:5173/
```

Catat URL `Network` — itulah yang dibuka di HP.

> `npm run dev` sudah pakai `vite --host`, jadi otomatis listen di `0.0.0.0`. Kalau perlu pin ke salah satu IP saja, edit `frontend/.env`:
>
> ```
> DEV_HOST=192.168.1.7
> DEV_PORT=5173
> ```

## 5. Allow port 5173 di Windows Firewall

Pertama kali `vite --host` dijalankan, biasanya Windows munculin prompt "Allow Node.js to communicate on…". **Centang Private network**, lalu Allow.

Kalau prompt sudah ditolak sebelumnya, tambah aturan manual:

```cmd
netsh advfirewall firewall add rule name="Vite Dev 5173" dir=in action=allow protocol=TCP localport=5173
```

(Hapus dengan `netsh advfirewall firewall delete rule name="Vite Dev 5173"` setelah selesai demo.)

## 6. Buka dari HP

Di browser HP, ketik URL `Network` tadi, mis:

```
http://192.168.1.7:5173
```

Login pakai akun demo seperti biasa. Semua API call (`/api/v1/...`) akan di‑proxy oleh Vite ke backend di PC, jadi tidak perlu konfigurasi CORS ekstra di HP.

---

## Troubleshooting

### "This site can't be reached" / timeout di HP
- Pastikan HP & PC di Wi‑Fi yang sama (bukan guest / hotspot tersembunyi).
- Coba ping dari HP pakai aplikasi network tools, atau buka `http://<IP-PC>:5173` di laptop tetangga dulu.
- Cek firewall Windows (langkah 5).
- Cek antivirus / Bitdefender / Kaspersky — sering blokir port LAN tanpa notif.

### "Blocked request. This host is not allowed."
Vite 5 punya `allowedHosts`. Sudah di‑set ke `'all'` di `vite.config.js`, jadi seharusnya tidak muncul. Kalau masih kena, restart `npm run dev`.

### API balik 404 / network error di HP padahal di laptop OK
- Pastikan `VITE_USE_PROXY=true` di `frontend/.env`.
- Pastikan backend hidup di `localhost:8080` di PC (cek `php spark serve` masih running).
- Buka DevTools remote (Chrome `chrome://inspect`) untuk lihat request HP.

### PWA / Service Worker tidak aktif via HTTP
Banyak fitur (Web Push, install prompt) butuh **HTTPS**. `http://192.168.x.x` masih jalan untuk SW di Chrome desktop tapi **bisa ditolak di Chrome Android**. Workaround:

1. Pakai `chrome://flags/#unsafely-treat-insecure-origin-as-secure` di HP, isi `http://192.168.1.7:5173`, restart Chrome.
2. Atau pakai tunneling HTTPS:

   ```cmd
   npx -y localtunnel --port 5173
   ```

   atau cloudflared:
   ```cmd
   cloudflared tunnel --url http://localhost:5173
   ```

### Geolocation tidak jalan di HP
Sama dengan SW — butuh secure context. Pakai trik flag di atas, atau tunneling HTTPS.

### Bisa buka via IP tapi reload hard error / Vite HMR
HMR via WebSocket biasanya auto‑connect ke host yang sama. Kalau muncul warning HMR, tambahkan di `vite.config.js` (sudah optional):

```js
server: {
  host: true,
  hmr: { host: '192.168.1.7' }, // ← isi IP PC
}
```

---

## Ringkasan command

```cmd
:: PC – sekali setup
cd frontend && npm install
netsh advfirewall firewall add rule name="Vite Dev 5173" dir=in action=allow protocol=TCP localport=5173

:: PC – setiap demo
cd backend  && php spark serve --host=localhost --port=8080
cd frontend && npm run dev

:: HP – buka di browser
http://<IP-LAN-PC>:5173