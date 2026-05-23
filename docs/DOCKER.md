# Docker Deployment Guide

Panduan lengkap menjalankan HRIS via Docker — untuk dev maupun production.

## Stack Overview

| Layer | Image | Tujuan |
|---|---|---|
| Backend | `php:8.2-cli` (dev) / `php:8.2-fpm-alpine` (prod) | CodeIgniter 4 API |
| Frontend | `node:20-alpine` (dev) / `nginx:alpine` (prod) | Vue 3 SPA |
| Database | `mysql:8.0` | Multi-tenant data |
| Cache | `redis:7-alpine` | Session + cache |
| Mail (dev) | `mailhog/mailhog` | Catch outgoing email, inspect via web UI |

## Development Stack

### Cara start

```bash
# Dari root project
docker compose up -d
```

Service yang nyala:

| Port | Service | URL |
|---|---|---|
| 5173 | Frontend (Vite dev) | http://localhost:5173 |
| 8080 | Backend (CI4) | http://localhost:8080/api/v1 |
| 8025 | MailHog UI | http://localhost:8025 |
| 3306 | MySQL | mysql://localhost:3306 |
| 6379 | Redis | redis://localhost:6379 |
| 1025 | MailHog SMTP | smtp://localhost:1025 |

### First-time setup

Setelah `docker compose up -d`, jalankan migrasi & seeder:

```bash
docker compose exec backend php spark migrate
docker compose exec backend php spark db:seed InitialSeeder
```

Login default: `admin@hris.local` / `Admin@12345`

### Development workflow

- **Edit file di host** — backend & frontend di-mount via volume, perubahan langsung kebaca (hot reload Vite, auto-reload PHP-FPM)
- **Tail logs**: `docker compose logs -f backend frontend`
- **Shell ke container**: `docker compose exec backend sh`
- **Process email queue manual**: `docker compose exec backend php spark email:process`
- **Reset DB total**: `docker compose down -v && docker compose up -d`

### Test email lokal

Aplikasi otomatis route SMTP ke MailHog. Kirim email apa saja → cek inbox di **http://localhost:8025**. Tidak ada email beneran ke real address di mode dev.

## Production Stack

### Build images

```bash
# Backend (multi-stage: composer + php-fpm)
docker build -t hris-backend:1.0 -f backend/Dockerfile backend/

# Frontend (multi-stage: vite build + nginx)
docker build \
    --build-arg VITE_API_BASE_URL=https://api.hris.example.com \
    --build-arg VITE_USE_PROXY=false \
    -t hris-frontend:1.0 \
    -f frontend/Dockerfile frontend/
```

### Run dengan reverse proxy

Production butuh **reverse proxy nginx** di depan untuk SSL termination + routing `/api/*` ke backend dan `/*` ke frontend. Contoh `docker-compose.prod.yml`:

```yaml
services:
  backend:
    image: hris-backend:1.0
    env_file: ./backend/.env.production
    volumes:
      - hris_writable:/var/www/html/writable
    restart: unless-stopped

  frontend:
    image: hris-frontend:1.0
    restart: unless-stopped

  nginx-proxy:
    image: nginx:alpine
    ports:
      - "443:443"
      - "80:80"
    volumes:
      - ./deploy/nginx-proxy.conf:/etc/nginx/conf.d/default.conf:ro
      - ./deploy/ssl:/etc/nginx/ssl:ro
    depends_on:
      - backend
      - frontend
    restart: unless-stopped

volumes:
  hris_writable:
```

Lihat `docs/DEPLOYMENT.md` untuk contoh nginx-proxy.conf production-ready.

### Email queue worker

Untuk production, tambahkan service worker yang loop process email queue:

```yaml
  email-worker:
    image: hris-backend:1.0
    env_file: ./backend/.env.production
    volumes:
      - hris_writable:/var/www/html/writable
    restart: unless-stopped
    command: >
      sh -c "while true; do php spark email:process --batch=20; sleep 60; done"
    depends_on:
      - backend
```

Atau pakai supervisor / systemd timer di host.

### Cron jobs

Job yang harus dijadwalkan via cron host (atau k8s CronJob):

```cron
# Cleanup selfie attachments (mingguan, Sabtu jam 4 pagi)
0 4 * * 6  docker compose exec -T backend php spark hr:cleanup-attachments

# Leave year-end forfeiture (akhir tahun)
0 0 31 12 * docker compose exec -T backend php spark hr:leave-yearend

# Audit log archive (bulanan)
0 3 1 * *  docker compose exec -T backend php spark audit:archive --older-than=12months
```

## Image Sizes

Target ukuran image production (rough):

| Image | Size | Catatan |
|---|---|---|
| hris-backend | ~250 MB | php-fpm-alpine + opcache + extensions |
| hris-frontend | ~50 MB | nginx:alpine + dist files |

Untuk size lebih kecil, pertimbangkan:
- Strip `composer require --dev` dari prod build (sudah dilakukan di Dockerfile via `--no-dev`)
- Compress static asset Vite via `vite-plugin-compression` (gzip/brotli)
- Multi-arch build (`docker buildx build --platform linux/amd64,linux/arm64`)

## Security

Beberapa hal yang sudah diterapkan di image:

- **Backend**: `expose_php=Off`, `display_errors=Off`, secure session cookies, opcache validate_timestamps=0 (file system tidak di-stat per request)
- **Backend**: drop privileges ke `www-data`, run sebagai non-root
- **Frontend**: nginx tidak expose `Server` header, security headers (X-Frame-Options, X-Content-Type-Options, Referrer-Policy)
- **Healthcheck**: built-in di kedua Dockerfile
- **`.dockerignore`**: exclude `.env`, `tests/`, `scripts/`, `node_modules/` dari build context

Yang **harus dilakukan operator** sebelum deploy production:

- Set semua secret di `.env.production` (jangan commit ke repo)
- `chmod 600 .env.production` di host
- Mount `writable/` sebagai persistent volume
- Set `app.forceGlobalSecureRequests=true` setelah HTTPS aktif
- Configure proper `cors.allowedOrigins` (jangan wildcard)

## Troubleshooting

### Backend: "Unable to connect to database"

MySQL belum siap saat backend boot. `depends_on.condition: service_healthy` di docker-compose.yml seharusnya cover, tapi kalau masih: tunggu 10 detik lalu `docker compose restart backend`.

### Frontend: hot reload tidak jalan di Windows

Vite + Docker Desktop di Windows kadang butuh polling untuk file watcher. Tambah ke `frontend/vite.config.js`:

```js
export default {
  server: {
    watch: {
      usePolling: true,  // Windows + Docker Desktop fix
      interval: 1000,
    }
  }
}
```

### "Permission denied" saat write ke writable/

```bash
docker compose exec backend chown -R www-data:www-data /var/www/html/writable
docker compose exec backend chmod -R 750 /var/www/html/writable
```

### Reset total

```bash
docker compose down -v          # hapus volumes (DESTRUCTIVE)
docker compose up -d --build    # rebuild + start
docker compose exec backend php spark migrate
docker compose exec backend php spark db:seed InitialSeeder
```
