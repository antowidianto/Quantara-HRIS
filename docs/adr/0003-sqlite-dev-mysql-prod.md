# 0003. Database: SQLite untuk dev, MySQL untuk production

**Status**: Accepted
**Tanggal**: 2024-01

## Konteks

Project butuh database yang:
- **Cepat di-setup di dev** — onboarding developer baru tidak perlu install MySQL/MariaDB server
- **Production-ready untuk multi-tenant** — handle ribuan karyawan × puluhan company × juta-an records
- **Familiar untuk admin sysadmin Indonesia** — dukungan komunitas + tutorial bahasa Indonesia
- **Compatible dengan ORM/Query Builder CI4** — supaya code yang sama jalan di kedua env

## Keputusan

**Dual-database strategy**:
- **Development**: SQLite 3 (file: `backend/writable/hris.sqlite`) — default di `.env.example`
- **Production / staging**: MySQL 8 atau MariaDB 10.6+

CodeIgniter Query Builder + Migration system di-tulis untuk kompatibel dengan keduanya. Driver di-switch via `database.default.DBDriver` di `.env`:
```ini
# Dev (default)
database.default.DBDriver = SQLite3
database.default.database = hris.sqlite

# Prod
database.default.DBDriver = MySQLi
database.default.database = hris_db
```

Migration ditulis dengan abstraksi Forge (`addField`, `addKey`, `createTable`); raw SQL hanya dipakai untuk fitur MySQL-specific (mis. composite index lewat `ALTER TABLE` di migration #040, di-skip kalau driver bukan MySQL).

## Alternatif yang Dipertimbangkan

### MySQL untuk dev juga
- ✅ Konsisten antara dev + prod (no behavioral drift)
- ❌ Setup lambat untuk developer baru (install MySQL/MariaDB, buat user, buat DB, atur permission)
- ❌ Resource extra (MySQL server jalan terus walau idle)
- ❌ Test isolation — test di-jalankan di DB shared, perlu cleanup hati-hati

### Docker MySQL untuk dev
- ✅ Setup cepat (kalau Docker sudah ada)
- ✅ Ephemeral — `docker-compose down -v` bersihin total
- ❌ Bukan semua developer punya Docker Desktop (terutama Mac M1/M2 awal punya issue)
- ❌ Resource overhead (MySQL container ~300MB RAM idle)

Sprint 9 menambahkan `docker-compose.yml` dengan MySQL untuk yang preferensinya kontainer-based dev — keduanya sekarang valid.

### PostgreSQL
- ✅ Lebih advanced (JSON columns native, partial index, window functions)
- ❌ Customer korporasi Indonesia mayoritas familiar MySQL/MariaDB, bukan PostgreSQL
- ❌ Hosting lokal (cPanel) jarang support Postgres out-of-box

### MongoDB
- ❌ Tidak cocok untuk relational + ACID transactions yang HRIS butuh (payroll, BPJS perlu konsistensi tinggi)

## Konsekuensi

### Positif
- **Onboarding ~5 menit** — `composer install && php spark migrate && php spark serve` cukup
- **Test cepat** — SQLite in-memory atau file → fresh DB per test run
- **Code portable** — sama Query Builder jalan di kedua env, hanya migration sesekali butuh driver-aware logic
- **Production well-supported** — MySQL 8 mature, banyak hosting Indonesia, mudah backup/restore

### Negatif
- **Behavioral drift** — beberapa edge case beda antara SQLite & MySQL:
  - SQLite tidak strict type enforcement (string ke INT field bisa nyangkut)
  - SQLite locking model lebih sederhana (single writer) — tidak tepat untuk concurrent test
  - SQLite tidak support DROP COLUMN tanpa rebuild table (FK CASCADE migration butuh raw SQL berbeda)
  - ENUM constraint di SQLite di-implement sebagai CHECK
- **Composite index** dari migration #040 di-skip di SQLite (tidak signifikan karena dataset dev kecil)
- **FULLTEXT search** (kalau nanti dipakai) hanya di MySQL — perlu fallback LIKE di SQLite

### Mitigasi
- CI pipeline di GitHub Actions test di kedua: PHPUnit pakai SQLite (cepat), tapi migration test bisa di-test ke MySQL lewat docker-compose
- Migration ditulis dengan check `getPlatform()` saat butuh raw SQL (lihat #040 `AddCompositeIndexes`)
- Production deployment harus pakai MySQL/MariaDB — di `docs/DEPLOYMENT.md` sudah eksplisit, bukan SQLite
- Smoke test scripts di `backend/scripts/test-*.php` lebih banyak yang test pakai MySQL (run terhadap dev MySQL kalau available)
- Setting `selfie_retention_days` & enum-like field divalidasi di Validation rules backend, bukan hanya rely ke DB constraint

### Catatan operasional
- Migration #040 (composite indexes) di-skip di SQLite dengan log info — bukan error. Saat deploy ke MySQL pertama kali, migration akan run dan apply indexes.
- Untuk customer yang upgrade dari SQLite (proof-of-concept) ke MySQL, pakai tool seperti `pgloader` atau export-import via SQL dump. Tidak ada migration helper otomatis di codebase ini (deferred).