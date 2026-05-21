<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * Cron harian untuk cek ulang tahun & anniversary kerja karyawan.
 *
 * Yang di-cek:
 *   1. Karyawan ulang tahun hari ini → notifikasi pribadi + ke HR
 *   2. Karyawan anniversary kerja hari ini (sudah X tahun) → notifikasi
 *      ke karyawan, atasan, HR. Highlight milestone (1/3/5/10/15/20/25 tahun).
 *
 * Schedule: jalankan setiap hari jam 08:00 (atau sesuai kebutuhan).
 *   `php spark hr:check-anniversary`
 *
 * Optional flag:
 *   `--dry-run` — tampilkan list saja, tidak kirim notif
 *   `--target=birthday|anniversary|both` — default both
 *   `--company=N` — filter company tertentu
 */
class CheckAnniversary extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:check-anniversary';
    protected $description = 'Cek karyawan yang ulang tahun atau anniversary kerja hari ini, kirim notifikasi otomatis.';

    protected $usage = 'hr:check-anniversary [options]';

    protected $options = [
        '--dry-run'   => 'Tampilkan list saja, tidak kirim notif',
        '--target'    => 'birthday | anniversary | both (default: both)',
        '--company'   => 'Filter company_id tertentu',
    ];

    public function run(array $params)
    {
        $dryRun = CLI::getOption('dry-run');
        $target = strtolower((string) (CLI::getOption('target') ?? 'both'));
        $companyId = (int) (CLI::getOption('company') ?? 0);

        if (! in_array($target, ['birthday','anniversary','both'], true)) {
            CLI::error("--target harus birthday|anniversary|both");
            return EXIT_ERROR;
        }

        $today = date('Y-m-d');
        $todayMD = date('m-d');

        $db = \Config\Database::connect();
        $builder = $db->table('employees e')
            ->select('e.*, c.name AS company_name, b.name AS branch_name, d.name AS department_name')
            ->join('companies c', 'c.id = e.company_id', 'left')
            ->join('branches b', 'b.id = e.branch_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->where('e.is_active', 1)
            ->where('e.deleted_at IS NULL');
        if ($companyId) $builder->where('e.company_id', $companyId);

        $employees = $builder->get()->getResultArray();
        CLI::write("Memproses " . count($employees) . " karyawan aktif" . ($dryRun ? ' (DRY RUN)' : ''), 'cyan');

        $birthdayCount = 0;
        $anniversaryCount = 0;

        foreach ($employees as $emp) {
            // Birthday check
            if (in_array($target, ['birthday','both'], true) && ! empty($emp['date_of_birth'])) {
                $bdMD = date('m-d', strtotime((string) $emp['date_of_birth']));
                if ($bdMD === $todayMD) {
                    $age = $this->yearDiff((string) $emp['date_of_birth'], $today);
                    $this->handleBirthday($emp, $age, $dryRun);
                    $birthdayCount++;
                }
            }

            // Anniversary check
            if (in_array($target, ['anniversary','both'], true) && ! empty($emp['join_date'])) {
                $jdMD = date('m-d', strtotime((string) $emp['join_date']));
                if ($jdMD === $todayMD) {
                    $years = $this->yearDiff((string) $emp['join_date'], $today);
                    if ($years >= 1) { // Skip yang baru join (0 tahun)
                        $this->handleAnniversary($emp, $years, $dryRun);
                        $anniversaryCount++;
                    }
                }
            }
        }

        CLI::newLine();
        CLI::write("Selesai: {$birthdayCount} ulang tahun, {$anniversaryCount} anniversary", 'green');

        if (! $dryRun) {
            Services::audit()->forModule('HR')->log(
                action: 'anniversary.check',
                entity: 'system',
                after: [
                    'date' => $today,
                    'birthday_count' => $birthdayCount,
                    'anniversary_count' => $anniversaryCount,
                ],
                description: "Daily anniversary check: {$birthdayCount} birthday, {$anniversaryCount} work anniversary",
                severity: 'NOTICE'
            );
        }

        return EXIT_SUCCESS;
    }

    private function yearDiff(string $from, string $to): int
    {
        $f = strtotime($from);
        $t = strtotime($to);
        if (! $f || ! $t || $t < $f) return 0;
        $fromY = (int) date('Y', $f);
        $toY   = (int) date('Y', $t);
        // Hari ini = anniv → kurangkan kalau belum lewat
        $fromMD = date('m-d', $f);
        $toMD   = date('m-d', $t);
        $diff = $toY - $fromY;
        if ($toMD < $fromMD) $diff--;
        return max(0, $diff);
    }

    private function handleBirthday(array $emp, int $age, bool $dryRun): void
    {
        $name = $emp['full_name'] ?? trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
        CLI::write("  🎂 {$name} ulang tahun ke-{$age} ({$emp['company_name']})", 'yellow');

        if ($dryRun) return;

        $companyId = (int) $emp['company_id'];

        // Notif untuk karyawan ybs
        if (! empty($emp['user_id'])) {
            Services::notification()->send(
                (int) $emp['user_id'], $companyId,
                'birthday.self',
                '🎉 Selamat Ulang Tahun!',
                "Tim {$emp['company_name']} mengucapkan selamat ulang tahun ke-{$age} untuk Anda. Semoga sehat, bahagia, dan sukses!",
                ['employee_id' => (int) $emp['id'], 'age' => $age],
                null, true
            );
        }

        // Notif untuk HR (role 2) — cuma 1 dari setiap company supaya tidak spam
        $hrUsers = \Config\Database::connect()->table('users')
            ->where('company_id', $companyId)
            ->where('role_id', 2)
            ->where('is_active', 1)->get()->getResultArray();
        foreach ($hrUsers as $hr) {
            Services::notification()->send(
                (int) $hr['id'], $companyId,
                'birthday.team',
                "🎂 Hari ini ulang tahun: {$name}",
                "{$name} dari {$emp['department_name']} hari ini berulang tahun ke-{$age}.",
                ['employee_id' => (int) $emp['id']],
                "/employees/{$emp['id']}", true
            );
        }
    }

    private function handleAnniversary(array $emp, int $years, bool $dryRun): void
    {
        $name = $emp['full_name'] ?? trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
        $isMilestone = in_array($years, [1, 3, 5, 10, 15, 20, 25, 30], true);
        $emoji = $isMilestone ? '🏆' : '🎊';
        $note = $isMilestone ? ' (MILESTONE)' : '';
        CLI::write("  {$emoji} {$name} anniversary {$years} tahun{$note} ({$emp['company_name']})", $isMilestone ? 'green' : 'cyan');

        if ($dryRun) return;

        $companyId = (int) $emp['company_id'];
        $title = $isMilestone
            ? "🏆 Selamat {$years} Tahun Berkarya!"
            : "🎊 Selamat anniversary {$years} tahun";
        $msg = $isMilestone
            ? "Tahun ke-{$years} adalah milestone penting. Terima kasih atas dedikasi dan kontribusi Anda untuk {$emp['company_name']}!"
            : "Sudah {$years} tahun Anda menjadi bagian dari {$emp['company_name']}. Terus semangat!";

        // Notif karyawan ybs
        if (! empty($emp['user_id'])) {
            Services::notification()->send(
                (int) $emp['user_id'], $companyId,
                $isMilestone ? 'anniversary.milestone' : 'anniversary.self',
                $title, $msg,
                ['employee_id' => (int) $emp['id'], 'years' => $years, 'milestone' => $isMilestone],
                null, true
            );
        }

        // Notif atasan langsung (kalau ada)
        if (! empty($emp['supervisor_id'])) {
            $sup = \Config\Database::connect()->table('employees')->where('id', (int) $emp['supervisor_id'])->get()->getRowArray();
            if ($sup && ! empty($sup['user_id'])) {
                Services::notification()->send(
                    (int) $sup['user_id'], $companyId,
                    'anniversary.team',
                    "{$emoji} Anniversary tim: {$name}",
                    "{$name} hari ini anniversary {$years} tahun di perusahaan.",
                    ['employee_id' => (int) $emp['id']],
                    "/employees/{$emp['id']}", true
                );
            }
        }

        // Notif HR — terutama untuk milestone
        if ($isMilestone) {
            $hrUsers = \Config\Database::connect()->table('users')
                ->where('company_id', $companyId)
                ->whereIn('role_id', [1, 2])
                ->where('is_active', 1)->get()->getResultArray();
            foreach ($hrUsers as $hr) {
                Services::notification()->send(
                    (int) $hr['id'], $companyId,
                    'anniversary.milestone_hr',
                    "🏆 Milestone {$years} tahun: {$name}",
                    "{$name} ({$emp['department_name']}) mencapai {$years} tahun masa kerja hari ini. Pertimbangkan untuk reward/penghargaan.",
                    ['employee_id' => (int) $emp['id'], 'years' => $years],
                    "/employees/{$emp['id']}", true
                );
            }
        }
    }
}