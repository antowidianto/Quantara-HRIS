<?php

namespace Tests\Unit;

use App\Libraries\Recruitment\OnboardingPlanService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk OnboardingPlanService — fokus ke pure helpers
 * (gender matching, planned-asset validation, gender normalization)
 * yang tidak butuh DB.
 *
 * Method DB-aware (syncMppPlannedAssets, applyPlanningToEmployee, dll)
 * butuh CodeIgniter database connection — tidak di-test di sini supaya
 * tests bisa jalan cepat di CI tanpa DB. Coverage untuk mereka via
 * integration test atau manual QA terpisah.
 *
 * Run: vendor/bin/phpunit --filter OnboardingPlanServiceTest
 */
class OnboardingPlanServiceTest extends TestCase
{
    // ─── genderMatchesLeaveType() ────────────────────────────────────

    /**
     * Core requirement: cuti dengan restriction 'F' (mis. cuti melahirkan,
     * cuti haid) HANYA di-seed untuk karyawan F. Gender M jangan dapat,
     * unset jangan dapat. Ini yang user minta: "saldo cuti disesuaikan
     * dengan jenis kelamin yang di isi dari form pendaftaran kandidat".
     */
    public function testFemaleEmployeeMatchesFemaleLeaveType(): void
    {
        $this->assertTrue(OnboardingPlanService::genderMatchesLeaveType('F', 'F'));
    }

    public function testMaleEmployeeDoesNotMatchFemaleLeaveType(): void
    {
        $this->assertFalse(OnboardingPlanService::genderMatchesLeaveType('M', 'F'));
    }

    public function testFemaleEmployeeDoesNotMatchMaleLeaveType(): void
    {
        // Mis. cuti paternity (cuti ayah ikut istri melahirkan) hanya M
        $this->assertFalse(OnboardingPlanService::genderMatchesLeaveType('F', 'M'));
    }

    public function testAnyGenderMatchesAllLeaveType(): void
    {
        $this->assertTrue(OnboardingPlanService::genderMatchesLeaveType('M', 'ALL'));
        $this->assertTrue(OnboardingPlanService::genderMatchesLeaveType('F', 'ALL'));
        $this->assertTrue(OnboardingPlanService::genderMatchesLeaveType(null, 'ALL'));
    }

    public function testNullRestrictionTreatedAsAll(): void
    {
        // Backward compat: row leave_types lama tanpa gender_restriction
        // tidak boleh tiba-tiba menolak semua karyawan.
        $this->assertTrue(OnboardingPlanService::genderMatchesLeaveType('M', null));
        $this->assertTrue(OnboardingPlanService::genderMatchesLeaveType('F', null));
        $this->assertTrue(OnboardingPlanService::genderMatchesLeaveType(null, null));
    }

    public function testEmptyStringRestrictionTreatedAsAll(): void
    {
        // Skema seed kadang kosong vs null; harus di-handle sama.
        $this->assertTrue(OnboardingPlanService::genderMatchesLeaveType('M', ''));
        $this->assertTrue(OnboardingPlanService::genderMatchesLeaveType('F', ''));
    }

    public function testEmptyOrNullEmployeeGenderBlockedFromGenderSpecific(): void
    {
        // Karyawan yang gender-nya belum di-isi tidak boleh dapat cuti
        // gender-specific — mencegah salah issue (mis. salah hitung saldo
        // melahirkan untuk yang sebenarnya laki-laki tapi gender belum diset).
        $this->assertFalse(OnboardingPlanService::genderMatchesLeaveType(null, 'F'));
        $this->assertFalse(OnboardingPlanService::genderMatchesLeaveType(null, 'M'));
        $this->assertFalse(OnboardingPlanService::genderMatchesLeaveType('', 'F'));
        $this->assertFalse(OnboardingPlanService::genderMatchesLeaveType('', 'M'));
    }

    public function testGenderRestrictionCaseInsensitive(): void
    {
        // Defensive: kalau seed pakai lowercase 'f', tetap bisa match.
        $this->assertTrue(OnboardingPlanService::genderMatchesLeaveType('f', 'f'));
        $this->assertTrue(OnboardingPlanService::genderMatchesLeaveType('F', 'f'));
        $this->assertTrue(OnboardingPlanService::genderMatchesLeaveType('m', 'M'));
    }

    // ─── normalizeGenderForEmployee() ────────────────────────────────

    public function testNormalizeGenderPassesMandF(): void
    {
        $this->assertSame('M', OnboardingPlanService::normalizeGenderForEmployee('M'));
        $this->assertSame('F', OnboardingPlanService::normalizeGenderForEmployee('F'));
    }

    public function testNormalizeGenderUppercasesLowercase(): void
    {
        $this->assertSame('M', OnboardingPlanService::normalizeGenderForEmployee('m'));
        $this->assertSame('F', OnboardingPlanService::normalizeGenderForEmployee('f'));
    }

    public function testNormalizeGenderRejectsOtherCandidateValues(): void
    {
        // Skema candidates punya 'OTHER' & 'PREFER_NOT_TO_SAY' — tabel
        // employees hanya M/F. Mapping konservatif: HR isi manual.
        $this->assertNull(OnboardingPlanService::normalizeGenderForEmployee('OTHER'));
        $this->assertNull(OnboardingPlanService::normalizeGenderForEmployee('PREFER_NOT_TO_SAY'));
        $this->assertNull(OnboardingPlanService::normalizeGenderForEmployee(''));
        $this->assertNull(OnboardingPlanService::normalizeGenderForEmployee(null));
        $this->assertNull(OnboardingPlanService::normalizeGenderForEmployee('X'));
    }

    // ─── validatePlannedAssetRow() ───────────────────────────────────

    public function testValidatePlannedAssetValidRowReturnsEmptyErrors(): void
    {
        $errors = OnboardingPlanService::validatePlannedAssetRow([
            'category' => 'LAPTOP',
            'name'     => 'Laptop developer 16GB RAM',
        ]);
        $this->assertSame([], $errors);
    }

    public function testValidatePlannedAssetCategoryRequired(): void
    {
        $errors = OnboardingPlanService::validatePlannedAssetRow([
            'category' => '',
            'name'     => 'Sesuatu',
        ]);
        $this->assertContains('category wajib diisi', $errors);
    }

    public function testValidatePlannedAssetCategoryMustBeKnown(): void
    {
        $errors = OnboardingPlanService::validatePlannedAssetRow([
            'category' => 'PESAWAT',
            'name'     => 'Avanza',
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("'PESAWAT' tidak valid", $errors[0]);
    }

    public function testValidatePlannedAssetCategoryCaseInsensitive(): void
    {
        // Frontend kadang kirim lowercase
        $errors = OnboardingPlanService::validatePlannedAssetRow([
            'category' => 'laptop',
            'name'     => 'Laptop developer',
        ]);
        $this->assertSame([], $errors);
    }

    public function testValidatePlannedAssetNameMinLength(): void
    {
        $errors = OnboardingPlanService::validatePlannedAssetRow([
            'category' => 'LAPTOP',
            'name'     => 'A',
        ]);
        $this->assertContains('name minimal 2 karakter', $errors);
    }

    public function testValidatePlannedAssetNameTrimmedBeforeLengthCheck(): void
    {
        // Nama "  " (cuma spasi) harus di-treat seperti kosong
        $errors = OnboardingPlanService::validatePlannedAssetRow([
            'category' => 'LAPTOP',
            'name'     => '   ',
        ]);
        $this->assertContains('name minimal 2 karakter', $errors);
    }

    public function testValidatePlannedAssetNameMaxLength(): void
    {
        $errors = OnboardingPlanService::validatePlannedAssetRow([
            'category' => 'LAPTOP',
            'name'     => str_repeat('A', 201),
        ]);
        $this->assertContains('name maksimal 200 karakter', $errors);
    }

    public function testValidatePlannedAssetNameAtMaxLengthIsValid(): void
    {
        $errors = OnboardingPlanService::validatePlannedAssetRow([
            'category' => 'LAPTOP',
            'name'     => str_repeat('A', 200),
        ]);
        $this->assertSame([], $errors);
    }

    public function testValidatePlannedAssetAllStandardCategoriesAccepted(): void
    {
        foreach (OnboardingPlanService::ASSET_CATEGORIES as $cat) {
            $errors = OnboardingPlanService::validatePlannedAssetRow([
                'category' => $cat,
                'name'     => "Item kategori {$cat}",
            ]);
            $this->assertSame([], $errors,
                "Category {$cat} should be valid (defined in ASSET_CATEGORIES)");
        }
    }

    public function testValidatePlannedAssetMissingNameTreatedAsEmpty(): void
    {
        $errors = OnboardingPlanService::validatePlannedAssetRow([
            'category' => 'LAPTOP',
            // name absent
        ]);
        $this->assertContains('name minimal 2 karakter', $errors);
    }

    public function testValidatePlannedAssetCollectsMultipleErrors(): void
    {
        // Baris benar-benar invalid harus return semua error sekaligus,
        // bukan stop pada error pertama.
        $errors = OnboardingPlanService::validatePlannedAssetRow([
            'category' => 'INVALID_CAT',
            'name'     => 'X',
        ]);
        $this->assertCount(2, $errors);
    }

    // ─── ASSET_CATEGORIES sanity ─────────────────────────────────────

    public function testAssetCategoriesContainsExpectedSet(): void
    {
        $expected = [
            'LAPTOP', 'DESKTOP', 'PHONE', 'VEHICLE', 'FURNITURE',
            'TOOL', 'ID_CARD', 'ACCESS_CARD', 'OTHER',
        ];
        foreach ($expected as $cat) {
            $this->assertContains($cat, OnboardingPlanService::ASSET_CATEGORIES,
                "ASSET_CATEGORIES harus include {$cat}");
        }
    }
}