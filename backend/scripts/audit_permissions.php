<?php
/**
 * audit_permissions.php — scan kode sumber untuk semua permission slug
 * yang dipakai di `hasPermission(...)` / `requirePermission(...)` / dll,
 * lalu bandingkan dengan slug yang di-seed di migration & InitialSeeder.
 *
 * Tujuan: cari permission yang dipakai di runtime tapi BELUM ter-seed,
 * sehingga tidak akan muncul di modal Role super admin.
 *
 * Usage:
 *   cd backend && php scripts/audit_permissions.php
 *
 * Output:
 *   ┌── 1. Slug yang dipakai di code tapi BELUM ada di seeder/migration
 *   ├── 2. Slug yang ter-seed tapi tidak terpakai (bisa dipakai untuk cleanup)
 *   └── 3. Coverage stats per group
 */

$root = __DIR__ . '/..';
chdir($root);

// ─── 1. Cari slug yang dipakai di code ─────────────────────────────
$used = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('app'));
$patterns = [
    '/hasPermission\([\'"]([a-z0-9_.]+)[\'"]\)/',
    '/requirePermission\([\'"]([a-z0-9_.]+)[\'"]\)/',
    '/->can\([\'"]([a-z0-9_.]+)[\'"]\)/',
    '/[\'"]perm[\'"]\s*=>\s*[\'"]([a-z0-9_.]+)[\'"]/',
];
foreach ($rii as $f) {
    if ($f->getExtension() !== 'php') continue;
    $content = file_get_contents($f->getPathname());
    foreach ($patterns as $rx) {
        if (preg_match_all($rx, $content, $m)) {
            foreach ($m[1] as $slug) {
                $used[$slug] = $used[$slug] ?? [];
                $used[$slug][] = str_replace('\\', '/', $f->getPathname());
            }
        }
    }
}

// Frontend juga (item.perm di sidebar / view *.vue)
$frontendDir = '../frontend/src';
if (is_dir($frontendDir)) {
    $rii2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($frontendDir));
    foreach ($rii2 as $f) {
        if (! in_array($f->getExtension(), ['vue', 'js', 'ts'], true)) continue;
        $content = file_get_contents($f->getPathname());
        // perm: 'cost_center.view' atau can('xxx')
        $rxs = [
            "/perm:\s*['\"]([a-z0-9_.]+)['\"]/",
            "/\\bcan\\([\\s]*['\"]([a-z0-9_.]+)['\"][\\s]*\\)/",
            "/hasPermission\\([\\s]*['\"]([a-z0-9_.]+)['\"][\\s]*\\)/",
        ];
        foreach ($rxs as $rx) {
            if (preg_match_all($rx, $content, $m)) {
                foreach ($m[1] as $slug) {
                    if (! preg_match('/[a-z]\.[a-z]/', $slug)) continue; // must look like 'a.b'
                    $used[$slug] = $used[$slug] ?? [];
                    $used[$slug][] = str_replace('\\', '/', $f->getPathname());
                }
            }
        }
    }
}

// ─── 2. Cari slug yang DI-SEED (migration up + seeder) ────────────
$seeded = [];
$files = [];
$rii3 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('app/Database'));
foreach ($rii3 as $f) {
    if ($f->getExtension() === 'php') $files[] = $f->getPathname();
}
foreach ($files as $fp) {
    $content = file_get_contents($fp);
    // Pattern: ['slug' => 'xxx', ...]
    if (preg_match_all('/[\'"]slug[\'"]\s*=>\s*[\'"]([a-z0-9_.]+)[\'"]/', $content, $m)) {
        foreach ($m[1] as $slug) {
            // Skip role slug (super_admin, hr_admin, dsb) — yang bukan permission.
            // Permission slug pasti ada titik. Role slug tidak punya titik.
            if (! str_contains($slug, '.')) continue;
            $seeded[$slug] = $seeded[$slug] ?? [];
            $seeded[$slug][] = str_replace('\\', '/', $fp);
        }
    }
}

// ─── 3. Diff ─────────────────────────────────────────────────────
$missingFromSeed = array_diff(array_keys($used), array_keys($seeded));
$unusedSeed      = array_diff(array_keys($seeded), array_keys($used));
sort($missingFromSeed);
sort($unusedSeed);

$line = str_repeat('═', 70);
echo "{$line}\n";
echo "  PERMISSION AUDIT — Code vs Seed\n";
echo "{$line}\n\n";

echo "Total slug dipakai di code     : " . count($used) . "\n";
echo "Total slug ter-seed            : " . count($seeded) . "\n";
echo "Selisih (dipakai tapi tdk seed): " . count($missingFromSeed) . "\n";
echo "Selisih (seed tapi tdk dipakai): " . count($unusedSeed) . "\n\n";

// ──────────────────────────────────────────────────────────────────
// Section 1: Missing from seed (yang harus ditindak — utama)
// ──────────────────────────────────────────────────────────────────
echo "{$line}\n";
echo "  [1/3] PERMISSION DIPAKAI DI CODE TAPI BELUM TER-SEED\n";
echo "{$line}\n";
if (empty($missingFromSeed)) {
    echo "  ✓ Tidak ada gap — semua permission di code sudah ter-seed.\n\n";
} else {
    echo "Slug-slug ini akan TIDAK MUNCUL di modal Role super admin\n";
    echo "kecuali migration baru menambahkannya ke tabel `permissions`.\n\n";
    foreach ($missingFromSeed as $slug) {
        echo "  • {$slug}\n";
        $usedAt = array_unique($used[$slug] ?? []);
        foreach (array_slice($usedAt, 0, 3) as $f) {
            echo "      └─ {$f}\n";
        }
        if (count($usedAt) > 3) {
            echo "      └─ ... +" . (count($usedAt) - 3) . " file lain\n";
        }
    }
    echo "\n";
}

// ──────────────────────────────────────────────────────────────────
// Section 2: Unused seed (informational, bukan bug)
// ──────────────────────────────────────────────────────────────────
echo "{$line}\n";
echo "  [2/3] PERMISSION TER-SEED TAPI TIDAK DIPAKAI (informational)\n";
echo "{$line}\n";
if (empty($unusedSeed)) {
    echo "  ✓ Semua slug yang ter-seed terpakai di code.\n\n";
} else {
    echo "Bukan bug — bisa jadi feature di-rencanakan tapi belum implement,\n";
    echo "atau sudah dihapus tapi seed masih ada (cleanup candidate).\n\n";
    foreach ($unusedSeed as $slug) {
        echo "  • {$slug}\n";
    }
    echo "\n";
}

// ──────────────────────────────────────────────────────────────────
// Section 3: Stats per group
// ──────────────────────────────────────────────────────────────────
echo "{$line}\n";
echo "  [3/3] STATS PER GROUP\n";
echo "{$line}\n";
$byGroup = [];
foreach (array_keys($used) as $slug) {
    [$g, ] = array_pad(explode('.', $slug, 2), 2, '');
    $byGroup[$g] = $byGroup[$g] ?? ['used' => 0, 'seeded' => 0];
    $byGroup[$g]['used']++;
}
foreach (array_keys($seeded) as $slug) {
    [$g, ] = array_pad(explode('.', $slug, 2), 2, '');
    $byGroup[$g] = $byGroup[$g] ?? ['used' => 0, 'seeded' => 0];
    $byGroup[$g]['seeded']++;
}
ksort($byGroup);
printf("  %-30s %10s %10s %10s\n", 'GROUP', 'USED', 'SEEDED', 'GAP');
foreach ($byGroup as $g => $cnt) {
    $gap = $cnt['used'] - $cnt['seeded'];
    $marker = $gap > 0 ? '⚠' : '';
    printf("  %-30s %10d %10d %10d %s\n", $g, $cnt['used'], $cnt['seeded'], $gap, $marker);
}
echo "\n";

echo "{$line}\n";
if (! empty($missingFromSeed)) {
    echo "  ⚠ Ada " . count($missingFromSeed) . " permission yang perlu di-seed.\n";
    echo "    Buat migration baru untuk insert slug-slug di atas + grant ke role default.\n";
} else {
    echo "  ✓ Audit clean — semua permission ter-track.\n";
}
echo "{$line}\n";

exit($missingFromSeed === [] ? 0 : 1);
