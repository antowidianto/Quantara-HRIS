<?php

namespace App\Commands;

use App\Libraries\AuditLogger;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * VerifyAuditChain — Sprint A / B6 (audit log immutability).
 *
 * Iterasi seluruh tabel `audit_logs` per chain (chain_id), recompute hash
 * setiap row, dan compare dengan kolom entry_hash. Kalau ada mismatch,
 * artinya:
 *   - Row di-edit setelah insert (DBA/admin tampering), ATAU
 *   - Row di-delete (chain putus), ATAU
 *   - Row di-insert oleh path yang melewati AuditLogger (bug app).
 *
 * Pakai:
 *   php spark audit:verify-chain                    # verify semua chain
 *   php spark audit:verify-chain --chain c1-202605  # verify 1 chain saja
 *   php spark audit:verify-chain --since 2026-01-01 # filter window
 *
 * Exit code:
 *   0 = semua chain valid
 *   1 = ada mismatch
 *   2 = error fatal (DB connection, dll)
 */
class VerifyAuditChain extends BaseCommand
{
    protected $group       = 'HRIS';
    protected $name        = 'audit:verify-chain';
    protected $description = 'Verify integrity hash chain di audit_logs (Sprint A / B6).';
    protected $usage       = 'audit:verify-chain [options]';
    protected $arguments   = [];
    protected $options     = [
        '--chain' => 'Hanya verify chain_id tertentu (mis. c1-202605)',
        '--since' => 'Filter row created_at >= tanggal ini (Y-m-d)',
        '--limit' => 'Batas row per chain untuk smoke check (default: tanpa limit)',
        '--quiet' => 'Tidak print detail row valid, hanya summary + mismatch',
    ];

    public function run(array $params): int
    {
        try {
            $db = \Config\Database::connect();
        } catch (\Throwable $e) {
            CLI::error('Gagal connect DB: ' . $e->getMessage());
            return 2;
        }

        // Cek skema dulu
        if (! $db->fieldExists('entry_hash', 'audit_logs')) {
            CLI::error('Kolom entry_hash belum ada. Jalankan: php spark migrate (migration #072).');
            return 2;
        }

        $chainOpt = (string) (CLI::getOption('chain') ?: '');
        $sinceOpt = (string) (CLI::getOption('since') ?: '');
        $limit    = (int) (CLI::getOption('limit') ?: 0);
        $quiet    = (bool) CLI::getOption('quiet');

        // Daftar chain yang akan di-verify
        $b = $db->table('audit_logs')
            ->select('chain_id')
            ->where('chain_id IS NOT NULL')
            ->groupBy('chain_id')
            ->orderBy('chain_id', 'ASC');
        if ($chainOpt !== '') {
            $b->where('chain_id', $chainOpt);
        }
        if ($sinceOpt !== '') {
            $b->where('created_at >=', $sinceOpt . ' 00:00:00');
        }
        $chains = array_map(static fn ($r) => $r['chain_id'], $b->get()->getResultArray());

        if (empty($chains)) {
            CLI::write('Tidak ada chain ditemukan dengan filter yang diberikan.', 'yellow');
            return 0;
        }

        $totalRows = 0;
        $totalMismatch = 0;
        $brokenChains = [];

        foreach ($chains as $chain) {
            [$rowsChecked, $mismatches] = $this->verifyChain(
                $db, $chain, $sinceOpt, $limit, $quiet
            );
            $totalRows += $rowsChecked;
            $totalMismatch += count($mismatches);
            if ($mismatches) {
                $brokenChains[$chain] = $mismatches;
            }
        }

        // ─── Summary ───────────────────────────────────────────────
        CLI::newLine();
        CLI::write(str_repeat('═', 60), 'green');
        CLI::write(sprintf(
            'Total: %d chain, %d rows verified, %d mismatch',
            count($chains), $totalRows, $totalMismatch,
        ));

        if ($totalMismatch === 0) {
            CLI::write('✔ Semua chain valid.', 'green');
            return 0;
        }

        CLI::write('✖ Mismatch ditemukan:', 'red');
        foreach ($brokenChains as $chain => $rows) {
            CLI::write("  Chain: {$chain}", 'red');
            foreach (array_slice($rows, 0, 5) as $r) {
                CLI::write("    - row id={$r['id']} expected={$r['expected']} actual={$r['actual']}");
            }
            if (count($rows) > 5) {
                CLI::write('    ... dan ' . (count($rows) - 5) . ' baris lain');
            }
        }
        return 1;
    }

    /**
     * Verify satu chain. Return [rowsChecked, mismatches[]].
     */
    private function verifyChain($db, string $chain, string $since, int $limit, bool $quiet): array
    {
        $b = $db->table('audit_logs')
            ->where('chain_id', $chain)
            ->orderBy('id', 'ASC');
        if ($since !== '') {
            $b->where('created_at >=', $since . ' 00:00:00');
        }
        if ($limit > 0) {
            $b->limit($limit);
        }
        $rows = $b->get()->getResultArray();

        if (empty($rows)) {
            return [0, []];
        }

        if (! $quiet) {
            CLI::write("Chain {$chain}: " . count($rows) . ' row(s)…', 'yellow');
        }

        $previousHash = null;
        $mismatches = [];
        foreach ($rows as $r) {
            $expected = AuditLogger::recomputeHash($r, $previousHash);
            $actual   = (string) ($r['entry_hash'] ?? '');
            if ($expected !== $actual) {
                $mismatches[] = [
                    'id'       => (int) $r['id'],
                    'expected' => substr($expected, 0, 12) . '…',
                    'actual'   => $actual === '' ? '(empty)' : substr($actual, 0, 12) . '…',
                ];
            }
            $previousHash = $actual; // pakai actual supaya error 1 row tidak kaskade
        }

        if (! $quiet && empty($mismatches)) {
            CLI::write("  ✔ {$chain} valid", 'green');
        }
        return [count($rows), $mismatches];
    }
}