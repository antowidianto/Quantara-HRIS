<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration #076 — Database-driven sidebar menu.
 *
 * Sebelumnya menu sidebar di-hardcode di `frontend/src/layouts/AppLayout.vue`
 * sebagai static array. Tabel `menus` ini memindahkan source-of-truth ke
 * database supaya:
 *
 *   1. HR / admin bisa atur urutan, label, ikon tanpa rebuild frontend.
 *   2. Menu yang relevan bisa dikelompokkan jadi parent dropdown supaya
 *      sidebar lebih rapi (sebelumnya seksi "HR" punya 30+ item flat).
 *   3. Per-tenant override mungkin di kemudian hari (company_id NULL =
 *      default global; non-null = override khusus untuk satu company).
 *
 * Struktur:
 *
 *   - `parent_id` self-reference: bikin tree tak terbatas. Konvensi
 *     pemakaian saat ini cuma 3 level:
 *
 *         SECTION (parent_id = NULL, type = SECTION)
 *           ├── ITEM       (type = ITEM, route_path != null)
 *           └── PARENT     (type = PARENT, route_path null)
 *                 └── ITEM (type = ITEM, route_path != null)
 *
 *     Renderer di frontend memilih komponen sesuai `type`:
 *       - SECTION → header label di sidebar (uppercase tracking)
 *       - PARENT  → tombol collapse + chevron, render children kalau open
 *       - ITEM    → RouterLink biasa.
 *
 *   - `permission` slug: difilter di backend pakai daftar perm user.
 *     Null = always visible (mis. menu "Saya" yang self-service).
 *
 *   - `super_admin_only`: untuk menu khusus super admin (mis. Companies).
 *
 *   - `icon`: nama komponen Heroicons outline (mis. "UsersIcon").
 *     Frontend punya whitelist mapping nama → komponen di
 *     `frontend/src/components/menu/iconMap.js`. Ikon tidak dikenal
 *     fallback ke ikon default (Squares2X2Icon).
 *
 *   - `translation_key`: opsional; kalau di-set frontend pakai i18n key
 *     ini sebagai label (mis. "nav.items.employees"). Kalau kosong,
 *     pakai kolom `label` apa adanya. Ini mempertahankan dukungan i18n
 *     sambil membolehkan label custom dari admin.
 *
 *   - `sort_order`: angka kecil naik. Default 0; admin reorder via API.
 *
 * Idempotent — aman dijalankan ulang. Seed cuma jalan kalau tabel masih
 * kosong (count = 0) supaya tidak menimpa edit user.
 */
class CreateMenus extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('menus')) {
            $forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'company_id' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => true,
                    'comment'  => 'NULL = default global; non-null = override per company.',
                ],
                'parent_id' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => true,
                    'comment'  => 'Self-reference. NULL = top-level (SECTION).',
                ],
                'type' => [
                    'type'       => 'ENUM',
                    'constraint' => ['SECTION', 'PARENT', 'ITEM'],
                    'default'    => 'ITEM',
                    'comment'    => 'SECTION = header grup, PARENT = dropdown, ITEM = link biasa.',
                ],
                'code' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'comment'    => 'Slug unik per company, mis. "hr.employees".',
                ],
                'label' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 150,
                    'comment'    => 'Label tampilan default (kalau translation_key kosong).',
                ],
                'translation_key' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 150,
                    'null'       => true,
                    'comment'    => 'Key i18n di frontend (mis. nav.items.employees).',
                ],
                'icon' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 80,
                    'null'       => true,
                    'comment'    => 'Nama icon Heroicons (mis. UsersIcon).',
                ],
                'route_path' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                    'comment'    => 'Path Vue Router. Null untuk SECTION & PARENT.',
                ],
                'permission' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'null'       => true,
                    'comment'    => 'Permission slug. NULL = always visible.',
                ],
                'super_admin_only' => [
                    'type'     => 'TINYINT',
                    'unsigned' => true,
                    'default'  => 0,
                ],
                'sort_order' => [
                    'type'    => 'INT',
                    'default' => 0,
                ],
                'is_active' => [
                    'type'     => 'TINYINT',
                    'unsigned' => true,
                    'default'  => 1,
                ],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
                'deleted_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addKey('parent_id');
            $forge->addKey(['company_id', 'parent_id', 'sort_order']);
            $forge->addKey('permission');
            $forge->createTable('menus');
        }

        // ─── Seed permissions: menu.view & menu.manage ──────────────
        $this->seedPermissions([
            ['slug' => 'menu.view',   'name' => 'View Menu',
             'group' => 'admin',      'description' => 'Melihat daftar menu sidebar (mostly internal use).'],
            ['slug' => 'menu.manage', 'name' => 'Manage Menu',
             'group' => 'admin',      'description' => 'Atur menu sidebar (CRUD + reorder).'],
        ]);

        // ─── Seed default menu tree (skip kalau sudah ada data) ─────
        $existing = (int) $db->table('menus')
            ->where('company_id IS NULL')
            ->countAllResults();
        if ($existing === 0) {
            $this->seedDefaultMenus();
        }
    }

    public function down(): void
    {
        $db    = $this->db;
        $forge = $this->forge;
        if ($db->tableExists('menus')) {
            try { $forge->dropTable('menus', true); } catch (\Throwable $e) {}
        }
    }

    /**
     * Insert default menu tree global (company_id = NULL).
     * Struktur ini mereplikasi menu yang sebelumnya hardcoded di
     * AppLayout.vue, dengan beberapa item dikelompokkan ke parent
     * dropdown supaya sidebar lebih rapi.
     */
    private function seedDefaultMenus(): void
    {
        $now = date('Y-m-d H:i:s');
        $tree = $this->defaultMenuTree();

        // Walk tree DFS, insert level-by-level supaya parent_id resolved.
        $insert = function (array $nodes, ?int $parentId, int $depth) use (&$insert, $now) {
            $sort = 0;
            foreach ($nodes as $node) {
                $row = [
                    'company_id'       => null,
                    'parent_id'        => $parentId,
                    'type'             => $node['type'] ?? 'ITEM',
                    'code'             => $node['code'],
                    'label'            => $node['label'],
                    'translation_key'  => $node['translation_key'] ?? null,
                    'icon'             => $node['icon'] ?? null,
                    'route_path'       => $node['route_path'] ?? null,
                    'permission'       => $node['permission'] ?? null,
                    'super_admin_only' => ! empty($node['super_admin_only']) ? 1 : 0,
                    'sort_order'       => $sort,
                    'is_active'        => 1,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
                $this->db->table('menus')->insert($row);
                $newId = (int) $this->db->insertID();
                $sort += 10;

                if (! empty($node['children'])) {
                    $insert($node['children'], $newId, $depth + 1);
                }
            }
        };

        $insert($tree, null, 0);
    }

    /**
     * Default menu tree — dipisah supaya gampang di-review/edit.
     */
    private function defaultMenuTree(): array
    {
        return [
            // ─── Beranda ──────────────────────────────────────────
            [
                'type' => 'SECTION', 'code' => 'home', 'label' => 'Beranda', 'translation_key' => 'nav.home',
                'children' => [
                    ['code' => 'dashboard', 'label' => 'Dashboard', 'translation_key' => 'nav.items.dashboard',
                     'icon' => 'HomeIcon', 'route_path' => '/dashboard'],
                ],
            ],

            // ─── Saya (self-service) ─────────────────────────────
            [
                'type' => 'SECTION', 'code' => 'my', 'label' => 'Saya', 'translation_key' => 'nav.my',
                'children' => [
                    [
                        'type' => 'PARENT', 'code' => 'my.attendance', 'label' => 'Absensi & WFH',
                        'icon' => 'ClockIcon',
                        'children' => [
                            ['code' => 'my.attendance.list', 'label' => 'Absensi Saya',
                             'translation_key' => 'nav.items.my_attendance', 'icon' => 'ClockIcon',
                             'route_path' => '/attendance/my'],
                            ['code' => 'my.attendance.clock', 'label' => 'Clock In/Out',
                             'translation_key' => 'nav.items.clock_in_out', 'icon' => 'ClockIcon',
                             'route_path' => '/attendance/clock'],
                            ['code' => 'my.attendance.changes', 'label' => 'Pengajuan Absensi',
                             'translation_key' => 'nav.items.attendance_request',
                             'icon' => 'ClipboardDocumentListIcon',
                             'route_path' => '/attendance/my-changes'],
                            ['code' => 'my.attendance.wfh', 'label' => 'Pengajuan WFH',
                             'icon' => 'HomeModernIcon', 'route_path' => '/attendance/wfh'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'my.leave_overtime', 'label' => 'Cuti & Lembur',
                        'icon' => 'CalendarDaysIcon',
                        'children' => [
                            ['code' => 'my.leaves', 'label' => 'Cuti Saya',
                             'translation_key' => 'nav.items.my_leaves', 'icon' => 'CalendarDaysIcon',
                             'route_path' => '/leaves'],
                            ['code' => 'my.leave_balance', 'label' => 'Saldo Cuti',
                             'translation_key' => 'nav.items.leave_balance', 'icon' => 'CalendarDaysIcon',
                             'route_path' => '/leaves/balance'],
                            ['code' => 'my.overtimes', 'label' => 'Lembur Saya',
                             'translation_key' => 'nav.items.my_overtimes', 'icon' => 'ClockIcon',
                             'route_path' => '/overtimes'],
                            ['code' => 'my.overtime_plan', 'label' => 'Rencana Lembur',
                             'icon' => 'ClockIcon', 'route_path' => '/overtimes/plan'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'my.payroll', 'label' => 'Payroll Saya',
                        'icon' => 'CurrencyDollarIcon',
                        'children' => [
                            ['code' => 'my.payslips', 'label' => 'Slip Gaji Saya',
                             'translation_key' => 'nav.items.my_payslips', 'icon' => 'CurrencyDollarIcon',
                             'route_path' => '/payslips/my'],
                            ['code' => 'my.bonuses', 'label' => 'Riwayat Bonus',
                             'icon' => 'BanknotesIcon', 'route_path' => '/bonuses/my'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'my.approvals', 'label' => 'Approval',
                        'icon' => 'ClipboardDocumentListIcon',
                        'children' => [
                            ['code' => 'my.approvals.inbox', 'label' => 'Inbox Approval',
                             'translation_key' => 'nav.items.approval_inbox',
                             'icon' => 'ClipboardDocumentListIcon', 'route_path' => '/approvals/inbox'],
                            ['code' => 'my.approvals.delegations', 'label' => 'Delegasi Approval',
                             'translation_key' => 'nav.items.approval_delegations',
                             'icon' => 'ShieldCheckIcon', 'route_path' => '/approvals/delegations'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'my.performance', 'label' => 'Performance',
                        'icon' => 'ChartBarIcon',
                        'children' => [
                            ['code' => 'my.performance.goal', 'label' => 'Goal & Review Saya',
                             'translation_key' => 'nav.items.my_performance', 'icon' => 'ChartBarIcon',
                             'route_path' => '/performance/my'],
                            ['code' => 'my.performance.peer_review', 'label' => 'Peer Review',
                             'icon' => 'UserGroupIcon', 'route_path' => '/performance/peer-review'],
                            ['code' => 'my.performance.pulse', 'label' => 'Pulse Survey',
                             'icon' => 'HeartIcon', 'route_path' => '/hr/pulse-survey'],
                        ],
                    ],
                    ['code' => 'my.talent_marketplace', 'label' => 'Talent Marketplace',
                     'icon' => 'SparklesIcon', 'route_path' => '/hr/talent-marketplace'],
                    ['code' => 'my.referral', 'label' => 'Referral Program',
                     'icon' => 'TrophyIcon', 'route_path' => '/recruitment/referral'],
                    ['code' => 'my.transfer', 'label' => 'Mutasi Saya',
                     'icon' => 'ArrowsRightLeftIcon', 'route_path' => '/hr/my-transfers'],
                    ['code' => 'my.resignation', 'label' => 'Pengajuan Resignation',
                     'translation_key' => 'nav.items.my_resignation',
                     'icon' => 'ArrowLeftStartOnRectangleIcon', 'route_path' => '/hr/my-resignation'],
                ],
            ],

            // ─── HR (manager / admin) ─────────────────────────────
            [
                'type' => 'SECTION', 'code' => 'hr', 'label' => 'HR', 'translation_key' => 'nav.hr',
                'children' => [
                    [
                        'type' => 'PARENT', 'code' => 'hr.employees', 'label' => 'Karyawan',
                        'icon' => 'UsersIcon',
                        'children' => [
                            ['code' => 'hr.employees.list', 'label' => 'Karyawan',
                             'translation_key' => 'nav.items.employees', 'icon' => 'UsersIcon',
                             'route_path' => '/employees', 'permission' => 'employee.view'],
                            ['code' => 'hr.employees.contracts', 'label' => 'Kontrak Karyawan',
                             'translation_key' => 'nav.items.employee_contracts',
                             'icon' => 'ClipboardDocumentListIcon',
                             'route_path' => '/employees/contracts', 'permission' => 'employee.view'],
                            ['code' => 'hr.probation', 'label' => 'Probation Tracker',
                             'icon' => 'ExclamationTriangleIcon', 'route_path' => '/hr/probation',
                             'permission' => 'employee.update'],
                            ['code' => 'hr.idp', 'label' => 'Development Plan (IDP)',
                             'icon' => 'ChartBarIcon', 'route_path' => '/hr/idp',
                             'permission' => 'idp.view'],
                            ['code' => 'hr.assets', 'label' => 'Aset Perusahaan',
                             'translation_key' => 'nav.items.assets', 'icon' => 'BuildingOffice2Icon',
                             'route_path' => '/hr/assets', 'permission' => 'asset.view'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'hr.attendance', 'label' => 'Absensi & Roster',
                        'icon' => 'ClockIcon',
                        'children' => [
                            ['code' => 'hr.attendance.list', 'label' => 'Absensi',
                             'translation_key' => 'nav.items.attendance', 'icon' => 'ClockIcon',
                             'route_path' => '/attendance', 'permission' => 'attendance.view_all'],
                            ['code' => 'hr.roster', 'label' => 'Roster Shift',
                             'translation_key' => 'nav.items.shift_roster', 'icon' => 'CalendarDaysIcon',
                             'route_path' => '/roster', 'permission' => 'roster.view'],
                            ['code' => 'hr.change_requests', 'label' => 'Pengajuan Perubahan',
                             'translation_key' => 'nav.items.change_requests',
                             'icon' => 'ClipboardDocumentListIcon',
                             'route_path' => '/attendance/change-requests/approvals',
                             'permission' => 'attendance.approve_change'],
                            ['code' => 'hr.anomalies', 'label' => 'Anomali Absensi',
                             'translation_key' => 'nav.items.anomalies', 'icon' => 'ShieldCheckIcon',
                             'route_path' => '/attendance/anomalies',
                             'permission' => 'attendance.review_anomaly'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'hr.leave_overtime', 'label' => 'Cuti, Lembur & Klaim',
                        'icon' => 'CalendarDaysIcon',
                        'children' => [
                            ['code' => 'hr.leaves', 'label' => 'Cuti',
                             'translation_key' => 'nav.items.leaves', 'icon' => 'CalendarDaysIcon',
                             'route_path' => '/leaves?status=PENDING', 'permission' => 'leave.view'],
                            ['code' => 'hr.team_calendar', 'label' => 'Kalender Cuti Tim',
                             'translation_key' => 'nav.items.team_calendar',
                             'icon' => 'CalendarDaysIcon', 'route_path' => '/leaves/team-calendar',
                             'permission' => 'leave.team_calendar'],
                            ['code' => 'hr.overtimes', 'label' => 'Lembur',
                             'translation_key' => 'nav.items.overtimes', 'icon' => 'ClockIcon',
                             'route_path' => '/overtimes?status=PENDING', 'permission' => 'overtime.view'],
                            ['code' => 'hr.reimbursements', 'label' => 'Reimbursement',
                             'translation_key' => 'nav.items.reimbursements', 'icon' => 'BanknotesIcon',
                             'route_path' => '/reimbursements', 'permission' => 'reimbursement.view'],
                            ['code' => 'hr.loans', 'label' => 'Kasbon/Pinjaman',
                             'translation_key' => 'nav.items.loans', 'icon' => 'BanknotesIcon',
                             'route_path' => '/loans', 'permission' => 'loan.view'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'hr.lifecycle', 'label' => 'Lifecycle Karyawan',
                        'icon' => 'ArrowsRightLeftIcon',
                        'children' => [
                            ['code' => 'hr.onboarding', 'label' => 'Onboarding / Offboarding',
                             'translation_key' => 'nav.items.onboarding',
                             'icon' => 'ClipboardDocumentListIcon', 'route_path' => '/hr/onboarding',
                             'permission' => 'onboarding.view'],
                            ['code' => 'hr.disciplinary', 'label' => 'Surat Peringatan',
                             'translation_key' => 'nav.items.disciplinary',
                             'icon' => 'ClipboardDocumentListIcon', 'route_path' => '/hr/disciplinary',
                             'permission' => 'disciplinary.view'],
                            ['code' => 'hr.jic', 'label' => 'Promosi / Demosi (JIC)',
                             'icon' => 'ClipboardDocumentListIcon', 'route_path' => '/hr/jic',
                             'permission' => 'jic.view'],
                            ['code' => 'hr.transfers', 'label' => 'Mutasi Karyawan',
                             'icon' => 'ArrowsRightLeftIcon', 'route_path' => '/hr/transfers',
                             'permission' => 'employee_transfer.view'],
                            ['code' => 'hr.severance', 'label' => 'Pesangon',
                             'translation_key' => 'nav.items.severance', 'icon' => 'CurrencyDollarIcon',
                             'route_path' => '/hr/severance', 'permission' => 'severance.view'],
                            ['code' => 'hr.terminations', 'label' => 'Termination (PHK)',
                             'icon' => 'NoSymbolIcon', 'route_path' => '/hr/terminations',
                             'permission' => 'termination.view'],
                            ['code' => 'hr.resignations', 'label' => 'Resignations',
                             'translation_key' => 'nav.items.resignations',
                             'icon' => 'ArrowLeftStartOnRectangleIcon', 'route_path' => '/hr/resignations',
                             'permission' => 'resignation.view'],
                            ['code' => 'hr.exit_interviews', 'label' => 'Exit Interviews',
                             'icon' => 'ChatBubbleLeftRightIcon', 'route_path' => '/hr/exit-interviews',
                             'permission' => 'exit_interview.view'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'hr.recruitment', 'label' => 'Rekrutmen',
                        'icon' => 'UsersIcon',
                        'children' => [
                            ['code' => 'hr.mpp', 'label' => 'Manpower Planning (MPP)',
                             'icon' => 'ClipboardDocumentListIcon', 'route_path' => '/recruitment/mpp',
                             'permission' => 'mpp.view'],
                            ['code' => 'hr.recruitment.jobs', 'label' => 'Recruitment',
                             'translation_key' => 'nav.items.recruitment', 'icon' => 'UsersIcon',
                             'route_path' => '/recruitment/jobs', 'permission' => 'recruitment.view'],
                            ['code' => 'hr.recruitment.career', 'label' => 'Career Portal',
                             'icon' => 'UsersIcon', 'route_path' => '/recruitment/career-settings',
                             'permission' => 'careers.manage'],
                            ['code' => 'hr.recruitment.tests', 'label' => 'Online Tests',
                             'icon' => 'ClipboardDocumentListIcon',
                             'route_path' => '/recruitment/online-tests',
                             'permission' => 'online_tests.view'],
                            ['code' => 'hr.recruitment.bank', 'label' => 'Question Bank',
                             'icon' => 'ClipboardDocumentListIcon',
                             'route_path' => '/recruitment/online-test-bank',
                             'permission' => 'online_tests.manage'],
                            ['code' => 'hr.recruitment.assignments', 'label' => 'Test Assignments',
                             'icon' => 'ClipboardDocumentListIcon',
                             'route_path' => '/recruitment/online-test-assignments',
                             'permission' => 'online_tests.view'],
                            ['code' => 'hr.recruitment.funnel', 'label' => 'Recruitment Funnel',
                             'icon' => 'FunnelIcon', 'route_path' => '/recruitment/funnel',
                             'permission' => 'recruitment.view'],
                            ['code' => 'hr.recruitment.scoring', 'label' => 'Candidate Scoring',
                             'icon' => 'ChartBarIcon', 'route_path' => '/recruitment/candidate-scoring',
                             'permission' => 'recruitment.manage'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'hr.training', 'label' => 'Training & Sertifikasi',
                        'icon' => 'AcademicCapIcon',
                        'children' => [
                            ['code' => 'hr.training.list', 'label' => 'Training',
                             'translation_key' => 'nav.items.training',
                             'icon' => 'ClipboardDocumentListIcon', 'route_path' => '/training',
                             'permission' => 'training.view'],
                            ['code' => 'hr.training.certs', 'label' => 'Sertifikat',
                             'translation_key' => 'nav.items.certificates',
                             'icon' => 'ClipboardDocumentListIcon', 'route_path' => '/certificates',
                             'permission' => 'certificate.view'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'hr.performance', 'label' => 'Performance',
                        'icon' => 'ChartBarIcon',
                        'children' => [
                            ['code' => 'hr.performance.list', 'label' => 'Performance / OKR',
                             'translation_key' => 'nav.items.performance', 'icon' => 'ChartBarIcon',
                             'route_path' => '/performance', 'permission' => 'performance.view'],
                            ['code' => 'hr.performance.tree', 'label' => 'Goals Cascading',
                             'icon' => 'ShareIcon', 'route_path' => '/performance/goals-tree',
                             'permission' => 'performance.view'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'hr.talent', 'label' => 'Talent & Analytics',
                        'icon' => 'AcademicCapIcon',
                        'children' => [
                            ['code' => 'hr.turnover', 'label' => 'Turnover Analytics',
                             'icon' => 'UserMinusIcon', 'route_path' => '/hr/turnover-analytics',
                             'permission' => 'turnover.view'],
                            ['code' => 'hr.competency', 'label' => 'Competency Library',
                             'icon' => 'AcademicCapIcon', 'route_path' => '/hr/competencies',
                             'permission' => 'employee.update'],
                            ['code' => 'hr.succession', 'label' => 'Succession Planning',
                             'icon' => 'KeyIcon', 'route_path' => '/hr/succession',
                             'permission' => 'employee.update'],
                        ],
                    ],
                    ['code' => 'hr.helpdesk', 'label' => 'HR Helpdesk',
                     'translation_key' => 'nav.items.helpdesk',
                     'icon' => 'ClipboardDocumentListIcon', 'route_path' => '/helpdesk/tickets',
                     'permission' => 'helpdesk.view'],
                    ['code' => 'hr.travel', 'label' => 'Perjalanan Dinas',
                     'translation_key' => 'nav.items.business_trips',
                     'icon' => 'ClipboardDocumentListIcon', 'route_path' => '/travel',
                     'permission' => 'business_trip.view'],
                ],
            ],

            // ─── Payroll ─────────────────────────────────────────
            [
                'type' => 'SECTION', 'code' => 'payroll', 'label' => 'Payroll', 'translation_key' => 'nav.payroll',
                'children' => [
                    ['code' => 'payroll.periods', 'label' => 'Periode Payroll',
                     'translation_key' => 'nav.items.payroll_periods', 'icon' => 'CurrencyDollarIcon',
                     'route_path' => '/payroll/periods', 'permission' => 'payroll.view'],
                    ['code' => 'payroll.payslips', 'label' => 'Slip Gaji',
                     'translation_key' => 'nav.items.payslips', 'icon' => 'ClipboardDocumentListIcon',
                     'route_path' => '/payslips', 'permission' => 'payslip.view_all'],
                    [
                        'type' => 'PARENT', 'code' => 'payroll.bonus', 'label' => 'Bonus Engine',
                        'icon' => 'BanknotesIcon',
                        'children' => [
                            ['code' => 'payroll.bonus.schemes', 'label' => 'Bonus Schemes',
                             'icon' => 'BanknotesIcon', 'route_path' => '/payroll/bonus/schemes',
                             'permission' => 'bonus.manage'],
                            ['code' => 'payroll.bonus.runs', 'label' => 'Bonus Runs',
                             'icon' => 'BanknotesIcon', 'route_path' => '/payroll/bonus/runs',
                             'permission' => 'bonus.view'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'payroll.planning', 'label' => 'Planning & Simulasi',
                        'icon' => 'CalculatorIcon',
                        'children' => [
                            ['code' => 'payroll.thr', 'label' => 'THR Planning',
                             'icon' => 'GiftIcon', 'route_path' => '/payroll/thr-planning',
                             'permission' => 'payroll.view'],
                            ['code' => 'payroll.simulate', 'label' => 'Simulasi Payroll',
                             'icon' => 'CalculatorIcon', 'route_path' => '/payroll/simulate',
                             'permission' => 'payroll.simulate'],
                        ],
                    ],
                ],
            ],

            // ─── Master Data ─────────────────────────────────────
            [
                'type' => 'SECTION', 'code' => 'master', 'label' => 'Master Data',
                'translation_key' => 'nav.master_data',
                'children' => [
                    [
                        'type' => 'PARENT', 'code' => 'master.org', 'label' => 'Organisasi',
                        'icon' => 'BuildingOffice2Icon',
                        'children' => [
                            ['code' => 'master.org_structure', 'label' => 'Struktur Organisasi',
                             'translation_key' => 'nav.items.org_structure',
                             'icon' => 'BuildingOffice2Icon', 'route_path' => '/master/org-structure',
                             'permission' => 'department.view'],
                            ['code' => 'master.org_roles', 'label' => 'Master Jabatan Kunci',
                             'icon' => 'ShieldCheckIcon', 'route_path' => '/master/organization-roles',
                             'permission' => 'org_structure.view'],
                            ['code' => 'master.branches', 'label' => 'Cabang',
                             'translation_key' => 'nav.items.branches',
                             'icon' => 'BuildingOffice2Icon', 'route_path' => '/master/branches',
                             'permission' => 'branch.view'],
                            ['code' => 'master.work_locations', 'label' => 'Lokasi Kerja',
                             'translation_key' => 'nav.items.work_locations',
                             'icon' => 'BuildingOffice2Icon', 'route_path' => '/master/work-locations',
                             'permission' => 'branch.view'],
                            ['code' => 'master.departments', 'label' => 'Departemen',
                             'translation_key' => 'nav.items.departments',
                             'icon' => 'BuildingOffice2Icon', 'route_path' => '/master/departments',
                             'permission' => 'department.view'],
                            ['code' => 'master.positions', 'label' => 'Jabatan',
                             'translation_key' => 'nav.items.positions',
                             'icon' => 'BuildingOffice2Icon', 'route_path' => '/master/positions',
                             'permission' => 'position.view'],
                            ['code' => 'master.job_titles', 'label' => 'Master Jabatan',
                             'icon' => 'BuildingOffice2Icon', 'route_path' => '/master/job-titles',
                             'permission' => 'job_title.view'],
                            ['code' => 'master.job_grades', 'label' => 'Job Grade',
                             'translation_key' => 'nav.items.job_grades',
                             'icon' => 'BuildingOffice2Icon', 'route_path' => '/master/job-grades',
                             'permission' => 'position.view'],
                            ['code' => 'master.cost_centers', 'label' => 'Cost Center',
                             'icon' => 'CurrencyDollarIcon', 'route_path' => '/master/cost-centers',
                             'permission' => 'cost_center.view'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'master.recruitment', 'label' => 'Rekrutmen',
                        'icon' => 'ClipboardDocumentListIcon',
                        'children' => [
                            ['code' => 'master.pipelines', 'label' => 'Pipeline Rekrutmen',
                             'icon' => 'ClipboardDocumentListIcon',
                             'route_path' => '/master/recruitment-pipelines',
                             'permission' => 'recruitment_pipeline.view'],
                            ['code' => 'master.test_categories', 'label' => 'Jenis Test Online',
                             'icon' => 'AcademicCapIcon',
                             'route_path' => '/master/online-test-categories',
                             'permission' => 'online_tests.view'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'master.shift', 'label' => 'Shift',
                        'icon' => 'ClockIcon',
                        'children' => [
                            ['code' => 'master.shifts', 'label' => 'Shift Kerja',
                             'translation_key' => 'nav.items.shifts',
                             'icon' => 'ClockIcon', 'route_path' => '/master/shifts',
                             'permission' => 'shift.view'],
                            ['code' => 'master.shift_assignments', 'label' => 'Aturan Shift',
                             'translation_key' => 'nav.items.shift_assignments',
                             'icon' => 'ClockIcon', 'route_path' => '/master/shift-assignments',
                             'permission' => 'shift.view'],
                            ['code' => 'master.shift_patterns', 'label' => 'Pola Shift',
                             'translation_key' => 'nav.items.shift_patterns',
                             'icon' => 'CalendarDaysIcon', 'route_path' => '/master/shift-patterns',
                             'permission' => 'shift_pattern.view'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'master.finance', 'label' => 'Cuti & Payroll',
                        'icon' => 'CurrencyDollarIcon',
                        'children' => [
                            ['code' => 'master.leave_types', 'label' => 'Tipe Cuti',
                             'translation_key' => 'nav.items.leave_types',
                             'icon' => 'CalendarDaysIcon', 'route_path' => '/master/leave-types',
                             'permission' => 'leave.manage_types'],
                            ['code' => 'master.payroll_components', 'label' => 'Komponen Gaji',
                             'translation_key' => 'nav.items.payroll_components',
                             'icon' => 'CurrencyDollarIcon', 'route_path' => '/master/payroll-components',
                             'permission' => 'payroll.manage'],
                            ['code' => 'master.allowances', 'label' => 'Tunjangan / Mapping',
                             'translation_key' => 'nav.items.allowances',
                             'icon' => 'BanknotesIcon', 'route_path' => '/master/allowances',
                             'permission' => 'payroll.manage'],
                        ],
                    ],
                    [
                        'type' => 'PARENT', 'code' => 'master.policy', 'label' => 'Policy & Template',
                        'icon' => 'ClipboardDocumentListIcon',
                        'children' => [
                            ['code' => 'master.regulations', 'label' => 'Peraturan Perusahaan',
                             'translation_key' => 'nav.items.regulations',
                             'icon' => 'ClipboardDocumentListIcon', 'route_path' => '/master/regulations',
                             'permission' => 'settings.manage'],
                            ['code' => 'master.contract_templates', 'label' => 'Template Kontrak',
                             'translation_key' => 'nav.items.contract_templates',
                             'icon' => 'ClipboardDocumentListIcon',
                             'route_path' => '/master/contract-templates',
                             'permission' => 'settings.manage'],
                            ['code' => 'master.approval_chains', 'label' => 'Konfigurasi Approval',
                             'translation_key' => 'nav.items.approval_chains',
                             'icon' => 'ShieldCheckIcon', 'route_path' => '/master/approval-chains',
                             'permission' => 'settings.manage'],
                            ['code' => 'master.task_templates', 'label' => 'Template Onboarding',
                             'translation_key' => 'nav.items.task_templates',
                             'icon' => 'ClipboardDocumentListIcon',
                             'route_path' => '/master/task-templates',
                             'permission' => 'onboarding.manage'],
                        ],
                    ],
                ],
            ],

            // ─── Reports ─────────────────────────────────────────
            [
                'type' => 'SECTION', 'code' => 'reports', 'label' => 'Laporan', 'translation_key' => 'nav.reports',
                'children' => [
                    ['code' => 'reports.main', 'label' => 'Laporan',
                     'translation_key' => 'nav.items.reports_main', 'icon' => 'ChartBarIcon',
                     'route_path' => '/reports', 'permission' => 'report.view'],
                    ['code' => 'reports.compliance', 'label' => 'Compliance Reports',
                     'icon' => 'DocumentChartBarIcon', 'route_path' => '/hr/compliance-reports',
                     'permission' => 'compliance.report.view'],
                ],
            ],

            // ─── Intelligence (Horizon 4) ────────────────────────
            [
                'type' => 'SECTION', 'code' => 'intel', 'label' => 'Intelligence',
                'children' => [
                    ['code' => 'intel.copilot', 'label' => 'AI HR Copilot',
                     'icon' => 'SparklesIcon', 'route_path' => '/hr/ai-copilot',
                     'permission' => 'ai.use'],
                    ['code' => 'intel.pulse_manage', 'label' => 'Pulse Survey Manage',
                     'icon' => 'HeartIcon', 'route_path' => '/hr/pulse-survey?tab=manage',
                     'permission' => 'pulse_survey.manage'],
                    ['code' => 'intel.workforce', 'label' => 'Workforce Scenarios',
                     'icon' => 'PresentationChartLineIcon',
                     'route_path' => '/hr/workforce-scenarios',
                     'permission' => 'workforce.simulate'],
                    ['code' => 'intel.compensation', 'label' => 'Compensation Intel',
                     'icon' => 'ScaleIcon', 'route_path' => '/hr/compensation-intel',
                     'permission' => 'compensation.analyze'],
                ],
            ],

            // ─── Admin ───────────────────────────────────────────
            [
                'type' => 'SECTION', 'code' => 'admin', 'label' => 'Admin', 'translation_key' => 'nav.admin',
                'children' => [
                    ['code' => 'admin.users', 'label' => 'Pengguna',
                     'translation_key' => 'nav.items.users', 'icon' => 'UsersIcon',
                     'route_path' => '/admin/users', 'permission' => 'user.manage'],
                    ['code' => 'admin.roles', 'label' => 'Role',
                     'translation_key' => 'nav.items.roles', 'icon' => 'ShieldCheckIcon',
                     'route_path' => '/admin/roles', 'permission' => 'role.manage'],
                    ['code' => 'admin.audit', 'label' => 'Audit Log',
                     'translation_key' => 'nav.items.audit_logs',
                     'icon' => 'ClipboardDocumentListIcon', 'route_path' => '/admin/audit-logs',
                     'permission' => 'audit.view'],
                    ['code' => 'admin.companies', 'label' => 'Perusahaan',
                     'translation_key' => 'nav.items.companies', 'icon' => 'BuildingOffice2Icon',
                     'route_path' => '/admin/companies', 'super_admin_only' => true],
                    [
                        'type' => 'PARENT', 'code' => 'admin.settings', 'label' => 'Pengaturan',
                        'icon' => 'Cog6ToothIcon',
                        'children' => [
                            ['code' => 'admin.settings.app', 'label' => 'Pengaturan',
                             'translation_key' => 'nav.items.settings', 'icon' => 'Cog6ToothIcon',
                             'route_path' => '/admin/settings', 'permission' => 'settings.manage'],
                            ['code' => 'admin.settings.email', 'label' => 'Email Settings',
                             'icon' => 'Cog6ToothIcon', 'route_path' => '/admin/email-settings',
                             'permission' => 'settings.manage'],
                            ['code' => 'admin.settings.whatsapp', 'label' => 'WhatsApp Settings',
                             'icon' => 'ChatBubbleLeftEllipsisIcon',
                             'route_path' => '/admin/whatsapp-settings',
                             'permission' => 'settings.manage'],
                            ['code' => 'admin.settings.menu', 'label' => 'Menu Manager',
                             'icon' => 'Bars3Icon', 'route_path' => '/admin/menus',
                             'permission' => 'menu.manage'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Tambah permissions baru + auto-grant ke role super_admin (id=1) dan
     * hr_admin (id=2). Sama pola dengan migration #064.
     */
    private function seedPermissions(array $permissions): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($permissions as $p) {
            $exists = $this->db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists === 0) {
                $p['created_at'] = $now;
                $this->db->table('permissions')->insert($p);
            }
            $perm = $this->db->table('permissions')->where('slug', $p['slug'])->get()->getRowArray();
            if (! $perm) continue;
            foreach ([1, 2] as $roleId) {
                $hasPerm = $this->db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($hasPerm > 0) continue;
                $this->db->table('role_permissions')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => (int) $perm['id'],
                    'created_at'    => $now,
                ]);
            }
        }
    }
}