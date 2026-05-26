<?php
/**
 * Helper script — cari panggilan AuditLogger shorthand (logCreate, logUpdate,
 * logDelete, logApprove, logReject) yang keliru pakai named parameter
 * `severity:`. Method-method shorthand itu TIDAK menerima `$severity`,
 * hanya `log()` yang menerimanya.
 *
 * Run: php scripts/find_bad_audit_calls.php
 */

$dirs = [
    __DIR__ . '/../app/Controllers',
    __DIR__ . '/../app/Libraries',
    __DIR__ . '/../app/Commands',
];

$shorthand = ['logCreate', 'logUpdate', 'logDelete', 'logApprove', 'logReject'];

$files = [];
foreach ($dirs as $dir) {
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iter as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

$found = 0;
foreach ($files as $f) {
    $src = file_get_contents($f);
    $lines = explode("\n", $src);
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $matched = null;
        foreach ($shorthand as $name) {
            if (preg_match('/->' . $name . '\s*\(/', $lines[$i])) {
                $matched = $name;
                break;
            }
        }
        if ($matched === null) continue;

        // Collect call block (multi-line) sampai paren tertutup balance
        $block = '';
        $open = 0; $close = 0;
        for ($j = $i; $j < min($i + 15, $n); $j++) {
            $block .= $lines[$j] . "\n";
            $open  = substr_count($block, '(');
            $close = substr_count($block, ')');
            if ($open > 0 && $close >= $open) break;
        }
        if (preg_match('/\bseverity:/', $block)) {
            $found++;
            echo $f . ':' . ($i + 1) . " — {$matched}() with severity:\n";
            echo rtrim($block) . "\n---\n";
        }
    }
}

if ($found === 0) {
    echo "Tidak ada panggilan shorthand keliru. Aman.\n";
} else {
    echo "\nTotal: {$found} pelanggaran. Perbaiki dengan ->withSeverity('LEVEL') sebelum chain, "
       . "atau pakai ->log(..., severity: 'LEVEL') langsung.\n";
}