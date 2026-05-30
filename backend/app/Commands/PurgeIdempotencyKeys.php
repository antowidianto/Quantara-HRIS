<?php

namespace App\Commands;

use App\Models\IdempotencyKeyModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * PurgeIdempotencyKeys — Mobile Prep / MOB-3
 *
 * Cron daily harus run:
 *
 *   0 3 * * * cd /path/to/backend && php spark idempotency:purge
 *
 * Hapus row idempotency_keys yang sudah expired (default TTL 24 jam).
 * Tabel growth-rate kira-kira (POST critical/hari × user × 1.x retry buffer);
 * untuk 5K karyawan ~25K row/hari, perlu purge supaya storage tidak ledak.
 */
class PurgeIdempotencyKeys extends BaseCommand
{
    protected $group       = 'Mobile';
    protected $name        = 'idempotency:purge';
    protected $description = 'Hapus idempotency keys yang sudah expired (TTL 24h default)';
    protected $usage       = 'idempotency:purge';

    public function run(array $params): void
    {
        $start = microtime(true);
        $model = new IdempotencyKeyModel();
        $deleted = $model->purgeExpired();
        $elapsed = round((microtime(true) - $start) * 1000);

        CLI::write(sprintf('Purged %d expired idempotency keys in %dms', $deleted, $elapsed),
            $deleted > 0 ? 'green' : 'yellow');

        log_message('info', "PurgeIdempotencyKeys: deleted={$deleted} elapsed_ms={$elapsed}");
    }
}