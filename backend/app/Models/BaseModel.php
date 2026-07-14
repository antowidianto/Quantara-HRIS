<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\Services;

/**
 * BaseModel — Sprint B / A1
 *
 * Foundation untuk semua HRIS Model. Mengaktifkan pattern yang hilang
 * sebelumnya (Sprint B refactor):
 *
 *   1. **Multi-tenant scope otomatis** — query helper `forTenant()` akan
 *      auto-apply WHERE company_id = X dari TenantContext, atau lewati
 *      kalau super_admin.
 *
 *   2. **Encryption casts** — field di `$encryptedFields` di-decrypt
 *      otomatis di hasil read (`afterFind`) dan di-encrypt di insert/update.
 *      Caller controller tidak perlu manage `nik_encrypted` vs `nik`
 *      manual lagi.
 *
 *   3. **Soft delete + timestamps** — sudah default di CI4 Model;
 *      di-enforce konsisten di subclass.
 *
 *   4. **Helper aliasing** — `query()` mengembalikan QueryBuilder yang
 *      sudah ber-scope tenant, jadi controller masih bisa pakai builder
 *      style untuk JOIN kompleks tanpa kehilangan tenant safety.
 *
 * Convention:
 *   - Subclass set `protected string $table`, `$allowedFields`,
 *     `$encryptedFields`, dan optional `$casts`.
 *   - Subclass yang multi-tenant set `protected bool $multiTenant = true`
 *     (default true). Set false untuk tabel non-tenant (mis. companies,
 *     audit_logs untuk super admin global view).
 *
 * @phpstan-consistent-constructor
 */
abstract class BaseModel extends Model
{
    /**
     * Apakah tabel ini scoped per company.
     * Set `false` di subclass untuk tabel global (companies, roles, dll).
     */
    protected bool $multiTenant = true;

    /**
     * Daftar field yang di-encrypt at-rest. Format: kolom plaintext
     * (mis. 'nik') akan di-encrypt ke kolom `<name>_encrypted` (mis.
     * 'nik_encrypted'). Subclass boleh override mapping kalau pakai
     * kolom non-konvensi.
     *
     * @var array<int,string>
     */
    protected array $encryptedFields = [];

    /**
     * Nama kolom company_id (override kalau beda dari konvensi).
     */
    protected string $companyColumn = 'company_id';

    protected $useTimestamps = true;
    protected $useSoftDeletes = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    /**
     * Return query builder dengan tenant scope sudah di-apply.
     * Pakai untuk query custom (JOIN, GROUP BY) yang tidak cocok di
     * standard `find*()` Model.
     *
     * Untuk super admin tanpa company aktif, scope di-skip otomatis.
     */
    public function scoped(): \CodeIgniter\Database\BaseBuilder
    {
        $b = $this->builder();
        if ($this->multiTenant) {
            $tenant = Services::tenant();
            $companyId = $tenant->getCompanyId();
            // Super admin is global only when no company has explicitly been
            // selected. Once X-Company-Id resolves an active company, use the
            // same tenant boundary as every other role.
            if (! ($tenant->isSuperAdmin() && $companyId === 0)) {
                $b->where("{$this->table}.{$this->companyColumn}", $companyId);
            }
        }
        return $b;
    }

    /**
     * Find by ID + auto tenant scope.
     * Return null kalau tidak ditemukan ATAU bukan milik tenant aktif.
     */
    public function findScoped(int $id): ?array
    {
        $b = $this->scoped();
        if ($this->useSoftDeletes) {
            $b->where("{$this->table}.{$this->deletedField} IS NULL");
        }
        $row = $b->where("{$this->table}.{$this->primaryKey}", $id)
            ->get()->getRowArray();
        return $row ? $this->afterFindHook($row) : null;
    }

    /**
     * Insert dengan auto-encrypt + auto-tenant.
     * Field `company_id` otomatis di-set dari TenantContext kalau belum ada.
     */
    public function createScoped(array $data): int|false
    {
        // Auto-encrypt sensitive fields
        $data = $this->encryptIncoming($data);

        // Auto-set company_id dari tenant kalau multiTenant & belum di-set
        if ($this->multiTenant && empty($data[$this->companyColumn])) {
            $tenant = Services::tenant();
            $cid = $tenant->getCompanyId();
            if ($cid > 0) {
                $data[$this->companyColumn] = $cid;
            }
        }

        // Insert dan return ID
        $id = $this->insert($data, true);
        return $id === false ? false : (int) $id;
    }

    /**
     * Update dengan auto-encrypt + tenant guard.
     * Return false kalau row bukan milik tenant aktif (defense in depth
     * walaupun controller sudah cek).
     */
    public function updateScoped(int $id, array $data): bool
    {
        // Pastikan row exist & milik tenant ini
        if (! $this->findScoped($id)) {
            return false;
        }
        $data = $this->encryptIncoming($data);

        // Jangan biarkan caller update company_id (anti tenant-jump)
        unset($data[$this->companyColumn]);

        return (bool) $this->update($id, $data);
    }

    /**
     * Soft delete dengan tenant guard.
     */
    public function deleteScoped(int $id): bool
    {
        if (! $this->findScoped($id)) {
            return false;
        }
        return (bool) $this->delete($id);
    }

    /**
     * Decrypt sensitive fields di hasil find.
     * CI4 Model otomatis call `afterFind` callback kalau di-set; kita
     * pakai method pos sendiri supaya bisa di-call manual juga.
     */
    public function afterFindHook(array $row): array
    {
        $enc = Services::encryption();
        foreach ($this->encryptedFields as $field) {
            $col = $field . '_encrypted';
            if (isset($row[$col]) && $row[$col] !== null && $row[$col] !== '') {
                try {
                    $row[$field] = $enc->decrypt($row[$col]);
                } catch (\Throwable $e) {
                    // Decrypt gagal (key rotated, data corrupt) — log & set null
                    log_message('warning', sprintf(
                        '%s::afterFindHook decrypt failed for field=%s row_id=%s: %s',
                        static::class, $field, $row[$this->primaryKey] ?? '?', $e->getMessage(),
                    ));
                    $row[$field] = null;
                }
            }
        }
        return $row;
    }

    /**
     * Encrypt incoming sensitive fields sebelum insert/update.
     * Field plaintext di payload (mis. 'nik') di-encrypt ke <name>_encrypted
     * dan plaintext di-strip supaya tidak ke-stored.
     */
    protected function encryptIncoming(array $data): array
    {
        if (empty($this->encryptedFields)) {
            return $data;
        }
        $enc = Services::encryption();
        foreach ($this->encryptedFields as $field) {
            if (array_key_exists($field, $data)) {
                $plain = $data[$field];
                unset($data[$field]);
                if ($plain === null || $plain === '') {
                    $data[$field . '_encrypted'] = null;
                    continue;
                }
                $data[$field . '_encrypted'] = $enc->encrypt((string) $plain);
            }
        }
        return $data;
    }
}
