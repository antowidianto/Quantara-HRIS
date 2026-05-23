# 0001. Backend Framework: CodeIgniter 4 (bukan Laravel)

**Status**: Accepted
**Tanggal**: 2024-01

## Konteks

HRIS multi-tenant ini target deploy di lingkungan korporasi Indonesia yang **bervariasi** dari segi infrastruktur:
- Server shared hosting tradisional (cPanel) yang banyak dipakai SMB Indonesia
- VPS basic (1-2 vCPU, 2GB RAM)
- Container modern (Kubernetes, ECS) untuk customer enterprise

Persyaratan utama:
- **Footprint kecil** — kalau deploy di shared hosting, framework harus jalan tanpa banyak setup overhead
- **Performance baseline tinggi** — request per second tinggi tanpa harus pakai opcache + queue + cache layer kompleks
- **Tim familiar PHP** — onboarding developer baru tidak butuh pelatihan ekstensif
- **Mature ecosystem** — library untuk JWT, PHPMailer, dompdf, phpoffice/phpspreadsheet harus tersedia
- **Vendor lock-in minimal** — tidak terikat ke ecosystem service spesifik (mis. Laravel Forge, Vapor)

## Keputusan

Pakai **CodeIgniter 4 (PHP 8.1+)** sebagai backend framework.

## Alternatif yang Dipertimbangkan

### Laravel
- ✅ Ecosystem terkaya (Eloquent ORM, Sanctum, Horizon, Vapor, Forge)
- ✅ Mayoritas tutorial PHP modern pakai Laravel
- ❌ Bootstrap overhead lebih besar (~50ms baseline vs ~5ms CI4) — signifikan untuk shared hosting
- ❌ Memory footprint lebih besar (~30MB vs ~10MB)
- ❌ Banyak feature opinionated yang tidak kepakai untuk API-only backend (Blade, Livewire)
- ❌ Bergantung ke service Laravel-specific (Sanctum, Horizon) yang push ke arah platform mereka

### Symfony
- ✅ Sangat fleksibel, modular, enterprise-ready
- ❌ Boilerplate banyak — config, services.yaml, routing.yaml, DI
- ❌ Learning curve tinggi untuk junior developer
- ❌ Footprint paling besar dari opsi yang dipertimbangkan

### Slim Framework / Lumen
- ✅ Lightweight (mirip CI4)
- ❌ Ekosistem terbatas — banyak library standar (validation, ORM, query builder) harus install terpisah
- ❌ Lumen sudah deprecated; Slim 4 community lebih kecil

### CodeIgniter 4
- ✅ Footprint kecil (~10MB memory, ~5ms bootstrap)
- ✅ Built-in: Validation, Query Builder, Migration, Routing, Filter (middleware), Email, Pagination, Cache abstraction
- ✅ Documentation jelas dan stabil
- ✅ Support PHP modern (typed properties, enum, readonly)
- ✅ Komunitas Indonesia signifikan (banyak tutorial bahasa Indonesia)
- ❌ Ekosistem package lebih kecil dari Laravel (tidak ada equivalent Sanctum, Horizon)
- ❌ ORM nya (Entity + Model) tidak sekuat Eloquent

## Konsekuensi

### Positif
- Bisa di-deploy di shared hosting cPanel tanpa modifikasi (setup `php-fpm` standar cukup)
- Cold start cepat → pengguna tidak nunggu lama untuk request pertama
- Memory hemat → cocok untuk customer dengan budget VPS kecil
- Tidak vendor-locked → migrasi ke framework lain (kalau perlu) lebih murah karena business logic terpisah di `app/Libraries/`

### Negatif
- Tidak ada equivalent Eloquent — query lebih raw (Query Builder), perlu hati-hati supaya tidak SQL injection
- Refresh token + 2FA + RBAC harus implement sendiri (Laravel ada Sanctum + Spatie/permission)
- Ecosystem package terbatas → beberapa feature (mis. ImageOptimizer, full-text search) butuh integrasi manual

### Mitigasi
- Business logic disimpan di `app/Libraries/` (PayrollService, BpjsCalculator, dll), bukan di Controller — supaya gampang test + portable
- Pakai PHPStan level 0 untuk catch type error yang kalau di Laravel akan di-catch Eloquent runtime
- Encrypt sensitive field manual via `App\Libraries\Encryption` — equivalent Laravel `casts.encrypted`
- JWT pakai `firebase/php-jwt`; 2FA pakai `robthree/twofactorauth` — third-party tapi well-maintained
- Migration pattern additive-only (lihat ADR-0004) untuk hindari breaking change

### Catatan Update
Per Sprint 9 (2026), CI4 4.5.x sudah sangat matang. Sprint 1-9 menambahkan 39 migrations + 30+ libraries + 65+ controllers tanpa hit limitasi framework signifikan. Keputusan terbukti tepat untuk profile customer dan team kami.