<?php
/**
 * Smoke test untuk XlsxImportService — generate template, parse balik
 * sebagai upload, validate. Tidak commit ke DB.
 *
 * Usage: cd backend && php scripts/test-xlsx-import.php
 */
use CodeIgniter\Boot;
use Config\Paths;

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('FCPATH', __DIR__ . '/../public/');
chdir(FCPATH);

require __DIR__ . '/../app/Config/Paths.php';
$paths = new Paths();
require $paths->systemDirectory . '/Boot.php';
Boot::bootSpark($paths);

$svc      = \Config\Services::xlsxImport();
$registry = \App\Libraries\Import\ImportRegistry::all();

$ok = 0;
$fail = 0;
function assertion(string $label, bool $cond, string $detail = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  ✓ {$label}\n"; $ok++; }
    else       { echo "  ✗ {$label} — {$detail}\n"; $fail++; }
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "Smoke test XlsxImportService — Migration #067\n";
echo "═══════════════════════════════════════════════════════════════\n";

$db = \Config\Database::connect();
$companyId = (int) ($db->table('companies')->limit(1)->get()->getRowArray()['id'] ?? 1);
$userId    = (int) ($db->table('users')->limit(1)->get()->getRowArray()['id'] ?? 1);
echo "Test context: company_id={$companyId}, user_id={$userId}\n\n";

$generatedFiles = [];

foreach ($registry as $slug => $spec) {
    echo "▶ Resource: {$slug} ({$spec['label']})\n";

    // Generate template
    try {
        $path = $svc->generateTemplate($spec);
        $generatedFiles[] = $path;
        assertion('Template di-generate', file_exists($path), $path);
        $size = filesize($path);
        assertion('Template > 1 KB',     $size > 1024, "size={$size}");
    } catch (\Throwable $e) {
        assertion('Template di-generate', false, $e->getMessage());
        continue;
    }

    // Preview pakai template aslinya (yang isinya sample row).
    try {
        $result = $svc->preview(
            $path,
            "test-{$slug}.xlsx",
            filesize($path),
            $spec,
            $companyId,
            $userId
        );
        assertion('Preview success',    isset($result['batch_id']));
        assertion('Total rows ≥ 1',     ($result['total'] ?? 0) >= 1, "total={$result['total']}");
        // Sample row biasanya valid, tapi unique_in_table mungkin tabrakan (file
        // ini di-upload berulang). Yang penting: parse-nya jalan, validation
        // berjalan, batch tercatat.
        echo "    → batch #{$result['batch_id']} status={$result['status']}, "
            . "valid={$result['valid']}, errors={$result['errors']}\n";

        // Cancel batch supaya tidak ngotorin DB.
        $db->table('import_batches')->where('id', $result['batch_id'])->update([
            'status'     => 'CANCELLED',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        assertion('Preview success', false, $e->getMessage());
    }
    echo "\n";
}

// Cleanup template files
foreach ($generatedFiles as $p) {
    if (file_exists($p)) @unlink($p);
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "Total: {$ok} ok, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);