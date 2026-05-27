<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * SeedAll — runner yang menjalankan semua seeder dalam urutan benar.
 *
 *   php spark db:seed SeedAll
 *
 * Equivalent dengan:
 *   php spark db:seed InitialSeeder
 *   php spark db:seed IndonesianMastersSeeder
 *   php spark db:seed DemoDataSeeder
 */
class SeedAll extends Seeder
{
    public function run(): void
    {
        echo "\n=== HRIS Master Seeder ===\n\n";

        echo "▶ InitialSeeder (permissions, roles, default admin, PTKP/TER, demo company)...\n";
        $this->call('InitialSeeder');

        echo "\n▶ IndonesianMastersSeeder (UMP/UMK 2026, libur nasional)...\n";
        $this->call('IndonesianMastersSeeder');

        echo "\n▶ DemoDataSeeder (15 karyawan, attendance, cuti, lembur, payroll)...\n";
        $this->call('DemoDataSeeder');

        echo "\n▶ ApprovalAndContractSeeder (work location, approval chain, peraturan, template kontrak)...\n";
        $this->call('ApprovalAndContractSeeder');

        echo "\n▶ RosterSeeder (shift pabrik, pola 5-2 / 4-2 / 3 shift)...\n";
        $this->call('RosterSeeder');

        echo "\n▶ DisciplinaryCategoriesSeeder (15 kategori SP umum)...\n";
        $this->call('DisciplinaryCategoriesSeeder');

        echo "\n▶ TaskTemplatesSeeder (template Onboarding/Offboarding default)...\n";
        $this->call('TaskTemplatesSeeder');

        echo "\n=== Selesai. ===\n";
        echo "Login default:\n";
        echo "  - Super admin:  admin@hris.local      / Admin@12345\n";
        echo "  - HR Admin:     budi.santoso@hris.local / Demo@12345\n";
        echo "  - Manager:      rina.wati@hris.local    / Demo@12345\n";
        echo "  - Employee:     siti.aminah@hris.local  / Demo@12345\n";
        echo "  - Finance:      andi.pratama@hris.local / Demo@12345\n\n";

        echo "ALTERNATIF (production-realistic Indonesian PT structure):\n";
        echo "  php spark db:seed IndonesianStakeholderSeeder\n";
        echo "  → Reset penuh + 68 stakeholder lengkap (Komisaris → Junior Staff)\n";
        echo "  → Default password: Quantara@2026 (must_change=1)\n\n";
    }
}
