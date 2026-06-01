<?php
/**
 * Smoke test untuk endpoint bulk-reorder org-structure setelah perbaikan
 * "Department #X tidak ditemukan dalam scope perusahaan".
 *
 * Test scenarios (fokus pada deteksi STALE_TREE_STATE):
 *  1. dept yang soft-deleted masuk request → 409 dgn diagnostics reason=DELETED
 *  2. dept dari company lain masuk request → 409 dgn diagnostics reason=CROSS_TENANT
 *  3. parent_id yang tidak ada → 409 dgn diagnostics reason=NOT_FOUND
 *  4. moves kosong → 422 NO_MOVES
 *
 * Catatan: jalur happy-path (move sukses) sudah ter-cover oleh test
 * test-org-structure-flow.php yang melalui service layer langsung.
 * Test harness CLI ini tidak mensimulasikan request HTTP utuh sehingga
 * tidak diandalkan untuk verifikasi flow normal.
 */

use CodeIgniter\Boot;
use Config\Paths;
use Config\Database;
use Config\Services;

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('FCPATH', __DIR__ . '/../public/');
chdir(FCPATH);

require __DIR__ . '/../app/Config/Paths.php';
$paths = new Paths();
require $paths->systemDirectory . '/Boot.php';
Boot::bootSpark($paths);

$db = Database::connect();
$pass = 0; $fail = 0;
function check($label, $cond, $extra = null) {
    global $pass, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $pass++; }
    else       {
        echo "  [FAIL] {$label}\n";
        if ($extra !== null) echo "         " . (is_string($extra) ? $extra : json_encode($extra)) . "\n";
        $fail++;
    }
}

echo "=== Bulk Reorder Stale-State Detection Test ===\n\n";

// ─── Setup: pakai 2 company dan 1 dept dari masing-masing ──────────
$companies = $db->table('companies')->where('is_active', 1)->orderBy('id','ASC')->limit(2)->get()->getResultArray();
if (count($companies) < 2) {
    echo "Skip: butuh minimal 2 company aktif untuk test cross-tenant\n"; exit(0);
}
$cidA = (int) $companies[0]['id'];
$cidB = (int) $companies[1]['id'];

$deptA = $db->table('departments')->where('company_id', $cidA)->where('deleted_at IS NULL')
    ->where('parent_id IS NULL')->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$deptA2 = $db->table('departments')->where('company_id', $cidA)->where('deleted_at IS NULL')
    ->where('id !=', (int) $deptA['id'])->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$deptB = $db->table('departments')->where('company_id', $cidB)->where('deleted_at IS NULL')
    ->orderBy('id','ASC')->limit(1)->get()->getRowArray();

if (! $deptA || ! $deptA2 || ! $deptB) { echo "Skip: butuh dept di kedua company\n"; exit(0); }

echo "Setup: companyA=#{$cidA}, deptA=#{$deptA['id']} ({$deptA['name']}), deptA2=#{$deptA2['id']} ({$deptA2['name']})\n";
echo "       companyB=#{$cidB}, deptB=#{$deptB['id']} ({$deptB['name']})\n\n";

$_SERVER['REQUEST_METHOD'] = 'POST';

/**
 * Panggil bulkReorder controller method dengan body JSON tertentu.
 * Set ulang tenant di tiap call agar konsisten.
 */
function callBulkReorder(array $moves, int $companyId = 1): array {
    Services::tenant()->setCompanyId($companyId);
    Services::tenant()->setUserId(1);
    Services::tenant()->setRoleId(2);

    $payload = json_encode(['moves' => $moves]);
    $req = Services::request(null, false);
    $req->setBody($payload);
    $req->setHeader('Content-Type', 'application/json');

    $ctrl = new \App\Controllers\Api\V1\Master\OrgStructureController();
    $resp = Services::response(null, false);
    $resp->setStatusCode(200);
    $ctrl->initController($req, $resp, Services::logger());
    $resp = $ctrl->bulkReorder();
    return [
        'status' => $resp->getStatusCode(),
        'data'   => json_decode($resp->getBody(), true),
    ];
}

// ─── Test 1: dept yang soft-deleted ─────────────────────────────
echo "1. Include dept yang soft-deleted → 409 STALE_TREE_STATE\n";
$deletedRow = $db->table('departments')->where('deleted_at IS NOT NULL')
    ->where('company_id', $cidA)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
if ($deletedRow) {
    $res = callBulkReorder([
        ['department_id' => (int) $deletedRow['id'], 'new_parent_id' => (int) $deptA['id']],
    ], $cidA);
    check('HTTP 409', $res['status'] === 409, $res);
    check('code = STALE_TREE_STATE', ($res['data']['code'] ?? null) === 'STALE_TREE_STATE');
    check('diagnostics ada', isset($res['data']['errors']['diagnostics'][$deletedRow['id']]));
    check('reason DELETED', ($res['data']['errors']['diagnostics'][$deletedRow['id']]['reason'] ?? null) === 'DELETED');
} else {
    echo "  Skip: tidak ada soft-deleted dept di company A\n";
}

// ─── Test 2: include dept dari company lain ────────────────────
echo "\n2. Include dept dari company lain → 409 STALE_TREE_STATE\n";
$res = callBulkReorder([
    ['department_id' => (int) $deptB['id'], 'new_parent_id' => null],
], $cidA);
check('HTTP 409', $res['status'] === 409, $res);
check('code = STALE_TREE_STATE', ($res['data']['code'] ?? null) === 'STALE_TREE_STATE');
check('diagnostics include cross-tenant', ($res['data']['errors']['diagnostics'][(int) $deptB['id']]['reason'] ?? null) === 'CROSS_TENANT');

// ─── Test 3: parent yang tidak ada ─────────────────────────────
echo "\n3. parent_id tidak exist → 409 STALE_TREE_STATE\n";
$res = callBulkReorder([
    ['department_id' => (int) $deptA2['id'], 'new_parent_id' => 999999],
], $cidA);
check('HTTP 409', $res['status'] === 409, $res);
check('code = STALE_TREE_STATE', ($res['data']['code'] ?? null) === 'STALE_TREE_STATE');
check('reason NOT_FOUND', ($res['data']['errors']['diagnostics'][999999]['reason'] ?? null) === 'NOT_FOUND');

// ─── Test 4: empty moves ───────────────────────────────────────
echo "\n4. moves kosong → 422 NO_MOVES\n";
$res = callBulkReorder([], $cidA);
check('HTTP 422', $res['status'] === 422);
check('code = NO_MOVES', ($res['data']['code'] ?? null) === 'NO_MOVES');

// ─── Test 5: regression — root department (parent_id = NULL) bisa di-reorder ──
//
// Bug sebelumnya: `isset($current[$id])` salah kembalikan false untuk root
// department karena value-nya NULL (parent_id IS NULL). Akibatnya semua
// move untuk root selalu dianggap STALE_TREE_STATE walaupun datanya sehat.
// Test ini memastikan happy-path move root → root lain berhasil 200.
echo "\n5. Reorder root → root (regression null-parent_id)\n";

// Snapshot parent_id awal supaya kita bisa restore di akhir
$origParentA  = $deptA['parent_id']  !== null ? (int) $deptA['parent_id']  : null;
$origParentA2 = $deptA2['parent_id'] !== null ? (int) $deptA2['parent_id'] : null;

// Pastikan deptA bukan descendant deptA2 (kalau iya, swap)
$res = callBulkReorder([
    ['department_id' => (int) $deptA2['id'], 'new_parent_id' => (int) $deptA['id']],
], $cidA);
check('HTTP 200', $res['status'] === 200, $res);
check('status success', ($res['data']['status'] ?? null) === 'success');
check('applied_count >= 1', ($res['data']['data']['applied_count'] ?? 0) >= 1);

// Verifikasi parent_id di DB benar-benar berubah
$updated = $db->table('departments')->where('id', $deptA2['id'])->get()->getRowArray();
check('parent_id tersimpan di DB',
    (int) ($updated['parent_id'] ?? 0) === (int) $deptA['id'],
    'expected ' . $deptA['id'] . ', got ' . ($updated['parent_id'] ?? 'NULL'));

// Restore: kembalikan deptA2 ke parent semula supaya idempoten
$res = callBulkReorder([
    ['department_id' => (int) $deptA2['id'], 'new_parent_id' => $origParentA2],
], $cidA);
check('Restore HTTP 200', $res['status'] === 200, $res);

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
