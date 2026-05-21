# Deployment Guide

Production-ready deployment for HRIS, with hardened security defaults.

## Prerequisites

- **Server**: Ubuntu 22.04 LTS or newer (any Linux works)
- **PHP**: 8.1+ with extensions: `mysqli`, `intl`, `mbstring`, `json`, `openssl`, `gd`, `zip`, `xml`, `curl`
- **MySQL**: 8.x (or MariaDB 10.6+)
- **Node.js**: 18+ (for building frontend)
- **Web server**: Nginx (recommended) or Apache 2.4+
- **Composer**: 2.x
- **SSL certificate** (Let's Encrypt recommended)

## 1. Database Setup

```sql
CREATE DATABASE hris_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hris_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON hris_db.* TO 'hris_user'@'localhost';
FLUSH PRIVILEGES;
```

## 2. Backend Deployment

```bash
cd /var/www/hris
git clone <repo> .
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
chmod -R 750 writable/
chown -R www-data:www-data writable/
```

Edit `.env`:

```ini
CI_ENVIRONMENT = production
app.baseURL = 'https://hris.yourcompany.id/'
app.forceGlobalSecureRequests = true

# Generate strong secrets
jwt.secret = <run: php -r "echo bin2hex(random_bytes(64));">
jwt.refreshSecret = <run again: different value>
encryption.key = base64:<run: openssl rand -base64 32>

# Database
database.default.hostname = localhost
database.default.database = hris_db
database.default.username = hris_user
database.default.password = STRONG_PASSWORD_HERE

# CORS — only allow your frontend domain
cors.allowedOrigins = https://hris.yourcompany.id

# SMTP for notifications
mail.host = smtp.your-provider.com
mail.username = ...
mail.password = ...
```

Run migrations & seed:

```bash
php spark migrate
php spark db:seed InitialSeeder
```

The seeder will print the default super admin credentials. **Change the password immediately on first login.**

## 3. Frontend Build

```bash
cd /var/www/hris/frontend
npm ci
cp .env.example .env
# Edit .env: set VITE_API_BASE_URL=https://hris.yourcompany.id (no /api), VITE_USE_PROXY=false
npm run build
```

The built files are in `frontend/dist/`.

## 4. Nginx Configuration

`/etc/nginx/sites-available/hris.conf`:

```nginx
# Frontend
server {
    listen 443 ssl http2;
    server_name hris.yourcompany.id;
    root /var/www/hris/frontend/dist;
    index index.html;

    ssl_certificate /etc/letsencrypt/live/hris.yourcompany.id/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/hris.yourcompany.id/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:ECDHE-RSA-AES128-GCM-SHA256;

    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "geolocation=(self), microphone=(), camera=(self)" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self' https://hris.yourcompany.id;" always;

    # SPA fallback
    location / {
        try_files $uri $uri/ /index.html;
    }

    # API proxy to backend
    location /api/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Cache static assets
    location ~* \.(js|css|png|jpg|jpeg|gif|svg|woff|woff2|ttf|ico)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Gzip
    gzip on;
    gzip_vary on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;
}

# Redirect HTTP → HTTPS
server {
    listen 80;
    server_name hris.yourcompany.id;
    return 301 https://$server_name$request_uri;
}

# Backend (CodeIgniter)
server {
    listen 127.0.0.1:8080;
    server_name _;
    root /var/www/hris/backend/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 60;
    }

    # Block access to sensitive files
    location ~ /\.(?!well-known) { deny all; }
    location ~ /\.env { deny all; }
}
```

Enable & reload:

```bash
sudo ln -s /etc/nginx/sites-available/hris.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## 5. Email Queue Worker (Cron)

Add to crontab `crontab -e -u www-data`:

```cron
# Process queued emails every minute
* * * * * cd /var/www/hris/backend && php spark email:process --batch=20 >> /var/log/hris/email.log 2>&1
```

## 6. PHP-FPM Hardening

Edit `/etc/php/8.1/fpm/php.ini`:

```ini
expose_php = Off
display_errors = Off
log_errors = On
error_log = /var/log/php/error.log

upload_max_filesize = 5M
post_max_size = 8M
max_execution_time = 60
max_input_vars = 2000

session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
session.use_strict_mode = 1
```

Restart: `sudo systemctl restart php8.1-fpm`

## 7. MySQL Hardening

```sql
-- Run mysql_secure_installation if not already done
-- Limit connections to localhost only (in /etc/mysql/mysql.conf.d/mysqld.cnf)
-- bind-address = 127.0.0.1

-- Enable binary log for point-in-time recovery
-- log_bin = /var/log/mysql/mysql-bin.log
-- expire_logs_days = 14
```

Schedule daily backups:

```cron
0 2 * * * mysqldump --single-transaction --quick --lock-tables=false hris_db | gzip > /var/backups/hris/hris_$(date +\%Y\%m\%d).sql.gz
```

## 8. Firewall

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

## 9. Fail2ban (optional but recommended)

Add a filter for repeated 401 responses to deter brute-force.

## 10. Post-deployment Checklist

- [ ] HTTPS enforced with valid certificate
- [ ] Default super admin password changed
- [ ] JWT secrets and encryption key set to strong values (not the defaults!)
- [ ] CORS allowed origins restricted to production domain
- [ ] Database user has minimum required privileges
- [ ] `writable/` permissions are 750 owned by `www-data`
- [ ] `.env` is not world-readable (`chmod 600 .env`)
- [ ] Email queue cron is running
- [ ] Backups configured and tested
- [ ] PHP `expose_php=Off`, `display_errors=Off`
- [ ] `app.forceGlobalSecureRequests = true`
- [ ] Test login, payroll calculation, PDF generation
- [ ] Configure SMTP and test sending email

## Maintenance Commands

```bash
# Run DB migrations
cd /var/www/hris/backend && php spark migrate

# Process emails immediately
php spark email:process --batch=50

# Clear cache
rm -rf writable/cache/*

# Tail logs
tail -f writable/logs/log-$(date +%Y-%m-%d).log
```

## Scaling Notes

- For high traffic, switch session/cache to Redis
- Use a queue worker (e.g. supervisor + a long-running PHP process) instead of cron for emails
- Run multiple PHP-FPM worker pools per CPU
- Use a CDN (Cloudflare) in front of Nginx
- Replicate MySQL to a read replica for reporting queries