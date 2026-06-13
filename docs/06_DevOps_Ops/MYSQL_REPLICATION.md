# Panduan Setup MySQL Replication (Master-Slave)

Dokumen ini menjelaskan langkah-langkah untuk melakukan setup replikasi database (Master-Slave / Primary-Replica) pada database MySQL 8 untuk mendukung skalabilitas beban kerja baca-tulis (Read/Write splitting) pada aplikasi HRIS Quantara.

---

## 1. Topologi & Desain
Dalam arsitektur ini, seluruh query tulis (INSERT, UPDATE, DELETE) dikirim ke server **Master (Primary)**, sedangkan query baca (SELECT) diarahkan ke server **Replica (Secondary)**. Hal ini secara signifikan mengurangi beban kerja I/O pada database utama.

```
       [Aplikasi HRIS (Backend)]
        /                     \
 (Write Queries)         (Read Queries)
      /                         \
     v                           v
 [MySQL Master] ----------> [MySQL Replica]
 (Read/Write)   Replication  (Read-Only)
```

---

## 2. Setup Server Master (Primary)

### Langkah A: Konfigurasi `my.cnf` (atau `my.ini`)
Buka file konfigurasi MySQL Master Anda dan tambahkan parameter berikut di bawah blok `[mysqld]`:

```ini
[mysqld]
# Identifier unik untuk server master
server-id = 1

# Aktifkan binary log (wajib untuk replikasi)
log-bin = mysql-bin

# Tentukan database yang akan direplikasi
binlog-do-db = hris_db

# Metode sinkronisasi binary log
sync_binlog = 1
```

### Langkah B: Restart MySQL Service
Restart service database Anda untuk menerapkan konfigurasi:
```bash
sudo systemctl restart mysql
```

### Langkah C: Buat User Replikasi
Buka terminal MySQL Master dan jalankan perintah berikut untuk membuat user yang akan digunakan oleh Replica untuk menyalin log:

```sql
CREATE USER 'replica_user'@'%' IDENTIFIED WITH mysql_native_password BY 'SecureReplicaPassword123';
GRANT REPLICATION SLAVE ON *.* TO 'replica_user'@'%';
FLUSH PRIVILEGES;
```

### Langkah D: Dapatkan Status Master
Jalankan perintah berikut untuk melihat posisi log saat ini:
```sql
SHOW MASTER STATUS;
```
Catat nilai kolom **File** (contoh: `mysql-bin.000001`) dan **Position** (contoh: `154`). Nilai ini akan digunakan saat konfigurasi Replica.

---

## 3. Setup Server Replica (Secondary)

### Langkah A: Konfigurasi `my.cnf`
Buka file konfigurasi MySQL Replica Anda dan tambahkan parameter berikut di bawah blok `[mysqld]`:

```ini
[mysqld]
# Identifier unik (harus berbeda dari master)
server-id = 2

# Aktifkan logging perubahan yang didapat dari master
relay-log = mysql-relay-bin

# Buat database replica berstatus read-only untuk mencegah penulisan tidak disengaja
read_only = ON
```

### Langkah B: Restart MySQL Service
Restart service database Anda:
```bash
sudo systemctl restart mysql
```

### Langkah C: Konfigurasikan Koneksi ke Master
Buka terminal MySQL Replica dan jalankan perintah berikut (sesuaikan IP master, nama file log, dan posisi log dari langkah sebelumnya):

```sql
CHANGE REPLICATION SOURCE TO
SOURCE_HOST='IP_ADDRESS_MASTER',
SOURCE_USER='replica_user',
SOURCE_PASSWORD='SecureReplicaPassword123',
SOURCE_LOG_FILE='mysql-bin.000001',
SOURCE_LOG_POS=154;
```

### Langkah D: Mulai Replikasi
Jalankan perintah untuk memulai proses replikasi:
```sql
START REPLICA;
```

### Langkah E: Verifikasi Status Replikasi
Periksa status replikasi pada Replica:
```sql
SHOW REPLICA STATUS\G
```
Pastikan kolom berikut menunjukkan nilai:
* `Replica_IO_Running: Yes`
* `Replica_SQL_Running: Yes`
* `Seconds_Behind_Source: 0` (menunjukkan replica sinkron tanpa delay)

---

## 4. Konfigurasi di CodeIgniter 4
Aplikasi HRIS Quantara sudah dikonfigurasi untuk mendukung grup koneksi replica. 

Buka file konfigurasi `.env` Anda dan isi kredensial untuk server default (Master) dan server replica:

```env
# Server Master (Untuk Write Queries)
database.default.hostname = IP_ADDRESS_MASTER
database.default.database = hris_db
database.default.username = hris_user
database.default.password = MasterPassword123

# Server Replica (Untuk Read Queries)
database.replica.hostname = IP_ADDRESS_REPLICA
database.replica.database = hris_db
database.replica.username = hris_user
database.replica.password = ReplicaPassword123
```

Secara otomatis, CodeIgniter 4 akan mengarahkan query data transaksi baca ke database replica melalui service/model layer yang memanggil koneksi `replica` untuk data lookup.
