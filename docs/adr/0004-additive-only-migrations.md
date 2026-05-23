# 0004. Database Migrations: additive-only (no rename/drop columns existing)

**Status**: Accepted
**Tanggal**: 2025-01

## Konteks

HRIS sudah deploy ke beberapa customer production. Tiap rilis baru bisa membawa schema change yang potensial breaking:
- Rename kolom (mis. `employees.npwp` → `employees.tax_id`)
- Drop kolom unused (mis. `users.legacy_token`)
- Change column type (mis. `salary` dari INT jadi DECIMAL)
- Drop tabel (mis. modul lama yang dihapus)

Kalau migration breaking, customer yang upgrade dari versi lama akan **kehilangan data** atau **migration gagal di tengah** (left DB di state inconsistent). Recovery sulit kalau backup tidak fresh.

Tambahan, kami punya **35+ migrations** dengan beberapa cross-reference (FK, JSON breakdown), dan project masih aktif develop dengan banyak deferred items di roadmap. Schema change drastis bakal bikin migration log nyaring sulit di-bisect kalau ada bug nanti.

## Keputusan

**Migration pakai pola additive-only**:

✅ **Boleh**:
- `addColumn` — tambah kolom baru (dengan default value supaya backfill aman)
- `createTable` — tabel baru
- `addKey` / `ALTER TABLE ADD INDEX` — index baru
- Update data via UPDATE (idempotent, tidak menghapus kolom)
- Tambah enum value baru di constraint (additive)

❌ **Tidak boleh** (kecuali ada strong justification + DBA review):
- `dropColumn` — kolom dihapus akan kehilangan data
- `renameColumn` — break query di code yang masih reference nama lama (selama window deployment)
- `modifyColumn` change type — bisa lossy (truncation, precision loss)
- `dropTable` — kalau ada FK ke tabel lain, cascade behavior tidak terprediksi
- Hapus enum value — kalau ada row dengan value tsb, error

Setiap migration harus **idempotent** — cek `tableExists()`, `fieldExists()`, atau `INFORMATION_SCHEMA` sebelum apply. Kalau migration sudah pernah jalan (manual atau lewat tool external), re-run harus no-op.

## Alternatif yang Dipertimbangkan

### Migration breaking dengan migration_log + rollback support
- ✅ Schema clean (tidak akumulasi kolom unused)
- ✅ Bisa "fix mistake" di rilis berikutnya
- ❌ Rollback complicated kalau ada FK + data dependent
- ❌ Customer multi-tenant yang upgrade beda waktu bisa stuck di state berbeda
- ❌ Test rollback butuh effort signifikan (sering di-skip)

### Schema-as-code (Doctrine ORM-style entity migrations)
- ✅ Entity definition jadi source of truth, schema auto-generated
- ❌ CodeIgniter tidak punya equivalent — perlu replace dengan library ketiga
- ❌ Auto-generated migration sering perlu manual edit untuk handle edge case (FK drop order, data backfill)

### Liquibase / Flyway
- ✅ Mature, support multi-DB, bisa rollback
- ❌ Operational overhead tambahan (CLI tool eksternal, butuh JVM untuk Liquibase)
- ❌ Tidak pakai CI4 Migration system → developer butuh learn 2 tools

## Konsekuensi

### Positif
- **Zero-downtime upgrades** untuk customer production — migration tidak akan rusak data existing
- **Forward-compatible code** — code lama yang reference kolom yang nantinya "deprecated" tetap jalan (kolom masih ada, hanya tidak dipakai)
- **Auditability** — migration log linear, tidak ada "renamed/dropped" event yang bikin susah trace history
- **Multi-tenant safety** — customer yang stuck di versi lama upgrade ke versi baru tanpa risk data loss

### Negatif
- **Schema clutter** — kolom deprecated tetap ada, lama-lama tabel jadi "wide"
- **Naming inconsistency** — kalau awal salah nama kolom, harus pakai nama baru sambil keep kolom lama (mis. `birth_date` + `bod` untuk 2 modul yang spawn berbeda waktu)
- **Disk usage marginal increase** — kolom unused tetap allocated
- **Developer cognitive load** — perlu tahu kolom mana yang "active" vs "deprecated"

### Mitigasi
- Comment di migration jelasin kenapa kolom dipertahankan walau tidak dipakai
- Naming convention konsisten dari awal (review code di PR)
- Index unused kolom di-drop kalau performance jadi issue (DROP INDEX OK, additive-only refer ke kolom & data)
- Roadmap khusus untuk **schema cleanup release** (mis. v2.0.0) — sebelum itu, kumpulkan list deprecated → drop dalam satu rilis besar dengan migration plan + downtime window
- Migration terbaru selalu ditulis dengan idempotent pattern (cek `fieldExists` dulu) — lihat #036 sampai #040

### Contoh nyata

**Boleh** — Sprint 4 menambah kolom `bpjs_kes_class` di `employees`:
```php
if (! $this->db->fieldExists('bpjs_kes_class', 'employees')) {
    $this->forge->addColumn('employees', [
        'bpjs_kes_class' => ['type' => 'TINYINT', 'default' => 1],
    ]);
}
```

**Tidak boleh** — rename `employees.npwp` jadi `tax_id`:
```php
// JANGAN — break query di EmployeeController, PayrollService, export PPh21
$this->forge->modifyColumn('employees', [
    'npwp' => ['name' => 'tax_id', 'type' => 'VARCHAR', 'constraint' => 20],
]);
```

**Workaround kalau mau rename**:
1. Migration #X: tambah kolom `tax_id`, copy data dari `npwp` (additive)
2. Update code untuk pakai `tax_id` (multi-version transition: write ke keduanya, read prefer `tax_id` fallback `npwp`)
3. Setelah semua customer upgrade ke versi yang pakai `tax_id`: migration #X+N drop `npwp` (planned breaking change dengan migration plan)

### Catatan praktis

Sprint 1-9 (migrations #001 sampai #040) ketat ikuti pattern ini, dengan beberapa pengecualian terbatas yang sudah dicatat di migration comment:
- `modifyColumn` enum constraint: dipakai untuk **menambah** enum value (additive), bukan menghapus
- `ALTER TABLE` raw SQL: dipakai untuk index addition (#040), bukan column manipulation