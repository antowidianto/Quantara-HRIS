# Migrasi SQLite → MySQL (Laragon)

Dokumen ini panduan lengkap migrasi backend HRIS dari SQLite (default
development) ke MySQL 8.x via Laragon. Semua kode aplikasi sudah dirancang
cross-DB (CI4 Forge), jadi yang berubah hanya `.env` + step pemindahan
data.

## 1. Prasyarat

- **Laragon 8+** terinstall di `C:\laragon`
  ([download resmi](https://laragon.org/download)). Sudah include:
  - MySQL 8.4.x di `C:\laragon\bin\mysql\mysql-8.4.3-winx64\`
  - Apache (opsional — kita pakai PHP CLI dari project sendiri)
  - HeidiSQL (GUI client)
- **PHP 8.2+** dengan ekstensi `mysqli`, `pdo_mysql`, `sqlite3`, `pdo_sqlite`
- Repo HRIS sudah di-clone, dependencies ter-install (`composer install`,
  `npm install`).

> **Tips install lewat winget**:
> ```cmd
> winget install --id LeNgocKhoa.Laragon --silent --accept-source-agreements --accept-package-agreements
> ```

## 2. Start MySQL

1. Buka Laragon (`C:\laragon\laragon.exe`). Klik tombol **Start All**.
   Tray icon akan menampilkan "Started" dengan port `MySQL: 3306`.
2. Verifikasi koneksi:
   ```cmd
   C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -uroot -e "SELECT VERSION();"
   ```
   Harus print versi 8.x. Default credential Laragon: **user `root`,
   password kosong**, bind ke `127.0.0.1:3306`.

## 3. Buat Database

```cmd
C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -uroot -e ^
  "CREATE DATABASE IF NOT EXISTS hris_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Atau via HeidiSQL (Laragon → "Database"):
```sql
CREATE DATABASE hris_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## 4. Update `.env`

`backend/.env` sudah di-set ke MySQL secara default sekarang:

```ini
database.default.hostname = 127.0.0.1
database.default.database = hris_db
database.default.username = root
database.default.password = ''
database.default.DBDriver = MySQLi
database.default.DBPrefix = ''
database.default.port = 3306
database.default.charset = utf8mb4
database.default.DBCollat = utf8mb4_unicode_ci
database.default.foreignKeys = true
database.default.DBDebug = true
```

Kalau MySQL Anda pakai password (production), set
`database.default.password = 'xxx'`.

## 5. Pilih Strategi Migrasi

### Opsi A — Fresh Start (RECOMMENDED untuk dev)

Paling cepat & bersih. Buang seluruh data SQLite, generate ulang dari
seeder bawaan.

```bash
cd backend
php spark migrate                                    # bikin schema
php spark db:seed App\\Database\\Seeds\\SeedAll      # seed lengkap
```

`SeedAll` mencakup:
- Permissions, roles, user admin
- Companies + branches + departments demo
- Employees demo (~30 orang) lengkap dengan kontrak, attendance, leave
- Master data Indonesia (provinsi, kota, BPJS, PTKP, PPh21, dll)
- Approval chains, contract templates, task templates
- Career portal sample
- Performance cycle demo
- dst.

### Opsi B — Migrasi Data Existing dari SQLite

Kalau Anda sudah punya data berharga di `writable/hris.sqlite` yang
ingin dipertahankan:

```bash
cd backend

# 1) Backup SQLite dulu
copy writable\hris.sqlite writable\hris.sqlite.bak

# 2) Pastikan .env sudah pointing ke MySQL (lihat step 4)

# 3) Build schema MySQL kosong dulu
php spark migrate

# 4) Preview apa saja yang akan dipindah (tidak menulis apapun)
php scripts/migrate-sqlite-to-mysql.php --dry-run

# 5) Eksekusi migrasi data
php scripts/migrate-sqlite-to-mysql.php

# 6) Kalau ada error, ulang dengan --truncate (kosongkan tabel MySQL dulu)
php scripts/migrate-sqlite-to-mysql.php --truncate
```

Script `migrate-sqlite-to-mysql.php` akan:
- Membuka SQLite via PDO langsung (tidak butuh CI4)
- Untuk setiap tabel, copy semua row ke MySQL via `INSERT IGNORE`
- Reset `AUTO_INCREMENT` ke `MAX(id)+1`
- Sementara `SET FOREIGN_KEY_CHECKS=0` selama proses (di-restore di akhir)

Tabel diproses dalam urutan dependency: `companies → roles → users →
branches → departments → employees → ... → audit_logs`. Lihat array
`$tableOrder` di file untuk daftar lengkap.

## 6. Verifikasi

```bash
cd backend

# Cek total row per tabel
php scripts/show-counts.php

# Cek skema employees
php scripts/show-employee-schema.php

# Smoke test seluruh flow utama
php scripts/test-attendance-flow.php
php scripts/test-leave-balance-flow.php
php scripts/test-payroll-export.php
php scripts/test-employee-transfer-flow.php
php scripts/test-jic-flow.php
# … dst (lihat backend/scripts/ untuk daftar)

# Unit tests
vendor\bin\phpunit
```

Login ke aplikasi:
- **Admin**: `admin@example.com` / `Password123`
- Frontend: http://localhost:5173 (jalankan `npm run dev` di folder
  `frontend/`)
- Backend API: http://localhost:8080 (jalankan `php spark serve` di
  folder `backend/`)

## 7. Yang Berubah di Code

Tidak ada perubahan kode aplikasi yang substantial. Semua migrasi CI4
Forge sudah cross-DB compatible. Yang perlu diperhatikan:

### Migrasi yang punya driver-specific branch

3 file migrasi punya cabang khusus untuk SQLite (`PRAGMA index_list`)
vs MySQL (`SHOW INDEX`):

- `backend/app/Database/Migrations/2024-01-01-000007_EnhanceAuditAndIndonesianMasters.php`
- `backend/app/Database/Migrations/2024-01-01-000010_EnhanceEmployeeAndApprovalAndContract.php`
- `backend/app/Database/Migrations/2024-01-01-000011_EnhanceShiftAssignmentAndRoster.php`

Branch ini auto-detect via `$db->getPlatform()` — tidak perlu disentuh.

### Utility scripts pakai SQLite langsung

Beberapa script utilitas di `backend/scripts/` (`fix-org-assignments.php`,
`regenerate-users-employees.php`, `seed-kaldu-richeese.php`,
`show-companies.php`, `show-counts.php`, `show-employee-schema.php`,
`sync-leave-types.php`, `verify-regenerate.php`, `test-attendance-flow.php`)
membuka SQLite langsung pakai class `SQLite3`. Setelah migrasi ke MySQL,
script-script ini tidak relevan lagi — gunakan PHPMyAdmin/HeidiSQL atau
buat versi MySQL kalau perlu.

Script flow-test lain (`test-leave-balance-flow.php`,
`test-employee-transfer-flow.php`, `test-jic-flow.php`, dst.) pakai CI4
DB connection — cross-DB. Aman dipakai langsung.

### File `writable/hris.sqlite` setelah migrasi

Aman dihapus / dipindahkan setelah verifikasi data MySQL ok. Saran:
simpan sebagai backup `writable/hris.sqlite.YYYYMMDD.bak` selama 1 bulan
untuk safety.

## 8. Troubleshooting

### "Can't connect to MySQL server on '127.0.0.1:3306'"

MySQL belum jalan. Buka Laragon dan klik **Start All**. Cek tray icon
Laragon: harus hijau "Started".

Manual start kalau Laragon GUI bermasalah:
```cmd
C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe ^
  --defaults-file=C:\laragon\etc\mysql\my.ini --console
```

### "Access denied for user 'root'@'localhost'"

Default Laragon punya password kosong. Kalau pernah set password sebelumnya:
```cmd
C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -uroot -p
```
masukkan password lama, lalu reset:
```sql
ALTER USER 'root'@'localhost' IDENTIFIED BY '';
FLUSH PRIVILEGES;
```

### "MySQL server has gone away" saat migrate

Migrasi yang panjang kadang putus. Naikkan timeout di
`C:\laragon\etc\mysql\my.ini`:
```ini
[mysqld]
max_allowed_packet = 256M
wait_timeout = 600
```
Restart Laragon.

### "Specified key was too long; max key length is 3072 bytes"

Hanya muncul di MySQL versi sangat lama (5.5/5.6) dengan InnoDB row format
non-DYNAMIC. MySQL 8.4 di Laragon default sudah aman. Kalau perlu, set
di `my.ini`:
```ini
[mysqld]
innodb_default_row_format=DYNAMIC
innodb_large_prefix=ON
```

### Test suite gagal karena pakai SQLite in-memory

`backend/app/Config/Database.php` punya group `tests` yang masih pakai
SQLite `:memory:`. Ini sengaja — biar PHPUnit cepat dan tidak mengotori
DB MySQL development. Kalau ingin test pakai MySQL juga, set:

```php
public array $tests = [
    'hostname'  => '127.0.0.1',
    'username'  => 'root',
    'password'  => '',
    'database'  => 'hris_test',
    'DBDriver'  => 'MySQLi',
    'DBPrefix'  => '',
    // ...
];
```

dan buat database `hris_test` terpisah.

## 9. Production

Untuk deploy ke production MySQL:

1. Buat user khusus aplikasi (jangan pakai `root`):
   ```sql
   CREATE USER 'hris_app'@'%' IDENTIFIED BY 'STRONG_PASSWORD';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX,
         REFERENCES, DROP, CREATE TEMPORARY TABLES, LOCK TABLES,
         EXECUTE, CREATE ROUTINE, ALTER ROUTINE
     ON hris_prod.* TO 'hris_app'@'%';
   FLUSH PRIVILEGES;
   ```

2. Update `.env.production`:
   ```ini
   database.default.hostname = db.internal.example.com
   database.default.database = hris_prod
   database.default.username = hris_app
   database.default.password = 'STRONG_PASSWORD'
   database.default.DBDriver = MySQLi
   database.default.DBDebug  = false
   ```

3. Aktifkan SSL kalau DB di luar VPC:
   ```ini
   database.default.encrypt = true
   ```

4. Backup harian. Sample crontab:
   ```cron
   0 2 * * * mysqldump -u hris_app -pXXX hris_prod | gzip > /backup/hris_$(date +\%F).sql.gz
   ```

5. Tune InnoDB. Untuk server 4 GB RAM:
   ```ini
   innodb_buffer_pool_size = 2G
   innodb_log_file_size    = 256M
   innodb_flush_log_at_trx_commit = 2
   ```

## 10. Rollback ke SQLite

Jika perlu kembali ke SQLite (mis. troubleshoot):

```ini
# backend/.env
database.default.hostname = localhost
database.default.database = hris.sqlite
database.default.username = ''
database.default.password = ''
database.default.DBDriver = SQLite3
```

Restore file: `copy writable\hris.sqlite.bak writable\hris.sqlite`.

Aplikasi otomatis pakai SQLite kembali — tidak ada perubahan kode yang
perlu di-revert.