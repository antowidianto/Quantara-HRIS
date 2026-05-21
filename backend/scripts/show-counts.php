<?php
$dbPath = __DIR__ . '/../writable/hris.sqlite';
$db = new SQLite3($dbPath);
$tables = [
    'companies','branches','departments','job_grades','positions','shifts',
    'employees','users','attendances','leaves','overtimes','reimbursements',
    'loans','payroll_periods','regional_minimum_wages','holidays',
    'permissions','roles','role_permissions','ptkp_rates','ter_rates',
    'pph21_brackets','payroll_components','leave_types','employee_contracts',
    'shift_schedules','notifications','audit_logs',
];
echo str_repeat('─', 50) . "\n";
echo "Tabel                          Jumlah Baris\n";
echo str_repeat('─', 50) . "\n";
foreach ($tables as $t) {
    $r = @$db->querySingle("SELECT COUNT(*) FROM {$t}");
    if ($r === false) $r = 'N/A';
    printf("%-30s %s\n", $t, $r);
}
echo str_repeat('─', 50) . "\n";