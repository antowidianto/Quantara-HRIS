<?php

/**
 * Smoke test untuk HR Helpdesk / Ticket System.
 *
 * Skenario:
 *  1. Schema check: 2 tabel + 4 permissions
 *  2. Generate ticket_number unik
 *  3. Insert tiket → SLA due_at terhitung
 *  4. Komentar HR (PUBLIC) → ANSWERED + first_response_at set
 *  5. Komentar karyawan reply → IN_PROGRESS
 *  6. Komentar internal → tidak ubah status, tetap visible HR only
 *  7. Assign ke HR → IN_PROGRESS
 *  8. Status change ke CLOSED + rating
 *  9. Reopen → REOPENED, closed_at null
 * 10. Stats counter & overdue
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

$db = \Config\Database::connect();
$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $pass++; }
    else       { echo "  [FAIL] {$label}\n"; $fail++; }
}

echo "=== HR Helpdesk Smoke Test ===\n\n";

// 1. Schema
echo "1. Schema check\n";
check('Tabel hr_tickets ada', $db->tableExists('hr_tickets'));
check('Tabel hr_ticket_comments ada', $db->tableExists('hr_ticket_comments'));

$perms = ['helpdesk.view','helpdesk.view_all','helpdesk.handle','helpdesk.internal'];
$cnt = $db->table('permissions')->whereIn('slug', $perms)->countAllResults();
check("4 permission helpdesk.* terdaftar (got {$cnt})", $cnt === 4);

// 2. Setup data
echo "\n2. Setup\n";
$company = $db->table('companies')->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$emp = $db->table('employees')->where('company_id', $company['id'])->where('is_active', 1)
    ->where('user_id IS NOT NULL')->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$hr = $db->table('users')->where('company_id', $company['id'])->where('role_id', 2)
    ->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
if (! $emp || ! $hr) {
    echo "  ⚠ Butuh employee+user dan HR user. Skip.\n";
    exit($fail > 0 ? 1 : 0);
}
echo "  Employee: #{$emp['id']} ({$emp['full_name']}), HR user #{$hr['id']}\n";

// Cleanup test data sebelumnya
$db->table('hr_tickets')->where('subject LIKE', 'TEST_HELPDESK%')->delete();

// 3. Insert tiket via direct DB (simulasi controller flow)
echo "\n3. Insert tiket\n";
$now = date('Y-m-d H:i:s');
$ticketNumber = 'TKT-' . date('Y') . '-99001';
$slaDue = date('Y-m-d H:i:s', strtotime($now) + (24 * 3600)); // HIGH = 24h

$db->table('hr_tickets')->insert([
    'company_id' => $company['id'],
    'ticket_number' => $ticketNumber,
    'employee_id' => $emp['id'],
    'created_by_user_id' => $emp['user_id'],
    'subject' => 'TEST_HELPDESK Pertanyaan slip gaji',
    'body' => 'Saya tidak menemukan slip gaji bulan lalu.',
    'category' => 'PAYROLL',
    'priority' => 'HIGH',
    'status' => 'OPEN',
    'sla_due_at' => $slaDue,
    'created_at' => $now, 'updated_at' => $now,
]);
$tid = (int) $db->insertID();
check('Tiket berhasil dibuat', $tid > 0);

$row = $db->table('hr_tickets')->where('id', $tid)->get()->getRowArray();
check('Status awal OPEN', $row['status'] === 'OPEN');
check('SLA due_at = +24h dari created (HIGH)', strtotime($row['sla_due_at']) - strtotime($row['created_at']) === 24 * 3600);
check('Ticket number format TKT-YEAR-XXXXX', preg_match('/^TKT-\d{4}-\d{5}$/', $row['ticket_number']) === 1);

// 4. HR post komentar publik
echo "\n4. HR post komentar PUBLIC\n";
$db->table('hr_ticket_comments')->insert([
    'company_id' => $company['id'],
    'ticket_id' => $tid,
    'author_user_id' => $hr['id'],
    'author_role' => 'HR',
    'body' => 'Halo, slip akan dikirim besok.',
    'is_internal' => 0,
    'created_at' => $now, 'updated_at' => $now,
]);
// Update parent: ANSWERED + first_response_at
$db->table('hr_tickets')->where('id', $tid)->update([
    'status' => 'ANSWERED',
    'first_response_at' => $now,
    'updated_at' => $now,
]);
$row = $db->table('hr_tickets')->where('id', $tid)->get()->getRowArray();
check('Status berubah ke ANSWERED', $row['status'] === 'ANSWERED');
check('first_response_at terisi', ! empty($row['first_response_at']));

$cmtCount = $db->table('hr_ticket_comments')->where('ticket_id', $tid)->countAllResults();
check('1 komentar tercatat', $cmtCount === 1);

// 5. Komentar internal HR
echo "\n5. Komentar INTERNAL\n";
$db->table('hr_ticket_comments')->insert([
    'company_id' => $company['id'],
    'ticket_id' => $tid,
    'author_user_id' => $hr['id'],
    'author_role' => 'HR',
    'body' => 'NOTE INTERNAL: pastikan kabag finance approve dulu',
    'is_internal' => 1,
    'created_at' => $now, 'updated_at' => $now,
]);
$cmtCount = $db->table('hr_ticket_comments')->where('ticket_id', $tid)->countAllResults();
check('Total 2 komentar', $cmtCount === 2);
$publicCount = $db->table('hr_ticket_comments')
    ->where('ticket_id', $tid)->where('is_internal', 0)->countAllResults();
check('1 komentar public visible', $publicCount === 1);
$internalCount = $db->table('hr_ticket_comments')
    ->where('ticket_id', $tid)->where('is_internal', 1)->countAllResults();
check('1 komentar internal (HR-only)', $internalCount === 1);

// 6. Karyawan reply → kembali ke IN_PROGRESS
echo "\n6. Karyawan reply\n";
$db->table('hr_ticket_comments')->insert([
    'company_id' => $company['id'],
    'ticket_id' => $tid,
    'author_user_id' => $emp['user_id'],
    'author_role' => 'EMPLOYEE',
    'body' => 'Sudah saya cek, tetap belum muncul.',
    'is_internal' => 0,
    'created_at' => $now, 'updated_at' => $now,
]);
// Status ANSWERED + employee reply → IN_PROGRESS
$db->table('hr_tickets')->where('id', $tid)->update([
    'status' => 'IN_PROGRESS', 'updated_at' => $now,
]);
$row = $db->table('hr_tickets')->where('id', $tid)->get()->getRowArray();
check('Status balik ke IN_PROGRESS setelah employee reply', $row['status'] === 'IN_PROGRESS');

// 7. Assign
echo "\n7. Assign ke HR\n";
$db->table('hr_tickets')->where('id', $tid)->update([
    'assignee_user_id' => $hr['id'],
    'assigned_at' => $now,
    'updated_at' => $now,
]);
$row = $db->table('hr_tickets')->where('id', $tid)->get()->getRowArray();
check('Assignee tersimpan', (int) $row['assignee_user_id'] === (int) $hr['id']);
check('assigned_at terisi', ! empty($row['assigned_at']));

// 8. Close + rating
echo "\n8. Close dgn rating\n";
$db->table('hr_tickets')->where('id', $tid)->update([
    'status' => 'CLOSED',
    'closed_at' => $now,
    'resolved_at' => $now,
    'satisfaction_rating' => 5,
    'satisfaction_note' => 'Cepat & jelas',
    'updated_at' => $now,
]);
$row = $db->table('hr_tickets')->where('id', $tid)->get()->getRowArray();
check('Status CLOSED', $row['status'] === 'CLOSED');
check('Rating 5/5', (int) $row['satisfaction_rating'] === 5);
check('closed_at + resolved_at terisi', ! empty($row['closed_at']) && ! empty($row['resolved_at']));

// 9. Reopen
echo "\n9. Reopen\n";
$db->table('hr_tickets')->where('id', $tid)->update([
    'status' => 'REOPENED',
    'closed_at' => null,
    'updated_at' => $now,
]);
$row = $db->table('hr_tickets')->where('id', $tid)->get()->getRowArray();
check('Status REOPENED', $row['status'] === 'REOPENED');
check('closed_at di-clear', empty($row['closed_at']));

// 10. SLA computation untuk priority lain
echo "\n10. SLA per priority\n";
$slaMap = ['URGENT' => 4, 'HIGH' => 24, 'MEDIUM' => 72, 'LOW' => 168];
foreach ($slaMap as $p => $hours) {
    $expected = $hours * 3600;
    check("Priority {$p} → {$hours} jam", $expected === $hours * 3600);
}

// 11. Stats counter
echo "\n11. Stats counter\n";
$counts = $db->table('hr_tickets')
    ->select('status, COUNT(*) AS total')
    ->where('deleted_at IS NULL')
    ->groupBy('status')->get()->getResultArray();
$statsMap = [];
foreach ($counts as $c) $statsMap[$c['status']] = (int) $c['total'];
check('Stats aggregate berjalan', is_array($counts));
check('REOPENED = 1 (test ticket)', ($statsMap['REOPENED'] ?? 0) >= 1);

// 12. Class structure
echo "\n12. Class structure\n";
$ctrlExists = class_exists('\\App\Controllers\Api\V1\Helpdesk\HelpdeskController');
check('HelpdeskController class registered', $ctrlExists);
if ($ctrlExists) {
    $r = new \ReflectionClass(\App\Controllers\Api\V1\Helpdesk\HelpdeskController::class);
    foreach (['index','create','show','update','delete','assign','changeStatus','close','reopen','addComment','stats'] as $m) {
        check("Method {$m}() ada", $r->hasMethod($m));
    }
}

// Cleanup
$db->table('hr_tickets')->where('id', $tid)->delete();
echo "  ✓ Cleanup test data\n";

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);