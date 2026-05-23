# Architecture Decision Records (ADR)

Catatan keputusan arsitektur yang signifikan untuk HRIS. Format mengikuti [Michael Nygard's template](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions).

## Daftar

| # | Judul | Status | Tanggal |
|---|---|---|---|
| [0001](0001-codeigniter-vs-laravel.md) | Backend Framework: CodeIgniter 4 | Accepted | 2024 |
| [0002](0002-jwt-vs-session-auth.md) | Authentication: JWT (bukan session) | Accepted | 2024 |
| [0003](0003-sqlite-dev-mysql-prod.md) | Database: SQLite untuk dev, MySQL untuk production | Accepted | 2024 |
| [0004](0004-additive-only-migrations.md) | Database Migrations: additive-only (no rename/drop) | Accepted | 2025 |

## Cara nambah ADR baru

1. Salin template di bawah ke file `docs/adr/{NNNN}-{kebab-title}.md`
2. Increment NNNN dari ADR terakhir
3. Update tabel di file ini
4. PR untuk review tim

## Template

```markdown
# {NNNN}. {Judul Singkat}

**Status**: Proposed | Accepted | Deprecated | Superseded by ADR-XXXX
**Tanggal**: YYYY-MM-DD

## Konteks

Apa masalah / situasi yang memicu keputusan ini?

## Keputusan

Keputusan apa yang diambil? Pilihan jelas dan eksplisit.

## Alternatif yang Dipertimbangkan

Apa pilihan lain yang sudah dievaluasi? Kenapa tidak dipilih?

## Konsekuensi

### Positif
- Manfaat / improvement

### Negatif
- Tradeoff / batasan / utang teknis

### Mitigasi
- Bagaimana cara handle konsekuensi negatif