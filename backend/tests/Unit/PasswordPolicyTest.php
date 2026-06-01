<?php

namespace Tests\Unit;

use App\Libraries\Auth\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PasswordPolicyTest — verifikasi aturan password (R1-R4) tanpa DB.
 *
 * R5/R6 (history & rotation) butuh DB → di-cover di integration test.
 *
 * Run: vendor/bin/phpunit --filter PasswordPolicyTest
 */
class PasswordPolicyTest extends TestCase
{
    private PasswordPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PasswordPolicy();
    }

    // ─── R1 panjang ──────────────────────────────────────────────────

    public function testTooShortFailsLength(): void
    {
        $errors = $this->policy->validate('Aa1!aa');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('minimal 10 karakter', $errors[0]);
    }

    public function testTooLongFailsLength(): void
    {
        $errors = $this->policy->validate(str_repeat('A1a!', 60));  // 240 chars
        $this->assertNotEmpty($errors);
        $found = false;
        foreach ($errors as $e) {
            if (str_contains($e, 'maks 200')) $found = true;
        }
        $this->assertTrue($found, 'Expected max length error');
    }

    public function testValidPasswordReturnsNoErrors(): void
    {
        // 14 char, semua kategori
        $errors = $this->policy->validate('Sangat$Aman123!');
        $this->assertSame([], $errors);
    }

    // ─── R2 kompleksitas ─────────────────────────────────────────────

    public function testNoUppercaseFails(): void
    {
        $errors = $this->policy->validate('semua_kecil_123!');
        $this->assertContains('Password harus mengandung minimal satu huruf besar (A-Z).', $errors);
    }

    public function testNoLowercaseFails(): void
    {
        $errors = $this->policy->validate('SEMUA_BESAR_123!');
        $this->assertContains('Password harus mengandung minimal satu huruf kecil (a-z).', $errors);
    }

    public function testNoDigitFails(): void
    {
        $errors = $this->policy->validate('PasswordKuat!');  // no digit
        $this->assertContains('Password harus mengandung minimal satu angka (0-9).', $errors);
    }

    public function testNoSymbolFails(): void
    {
        $errors = $this->policy->validate('PasswordKuat123');  // no symbol
        $this->assertContains('Password harus mengandung minimal satu simbol (!@#$ dst).', $errors);
    }

    public function testShortPasswordSkipsComplexityChecks(): void
    {
        // Kalau panjang < min, jangan duplicate complexity errors
        $errors = $this->policy->validate('abc');
        // Hanya 1 error tentang panjang, bukan 4 tentang kompleksitas juga
        $lengthErrors = array_filter($errors, fn($e) => str_contains($e, 'minimal 10'));
        $complexityErrors = array_filter($errors, fn($e) => str_contains($e, 'mengandung'));
        $this->assertNotEmpty($lengthErrors);
        $this->assertEmpty($complexityErrors);
    }

    // ─── R4 common password ──────────────────────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function commonPasswordsProvider(): array
    {
        return [
            'lowercase common'  => ['password123'],
            'mixed case common' => ['Password123'],
            'leet variant'      => ['p@ssw0rd'],
            'app-specific'      => ['hris123'],
            'indonesian word'   => ['indonesia'],
        ];
    }

    #[DataProvider('commonPasswordsProvider')]
    public function testCommonPasswordIsRejected(string $password): void
    {
        $this->assertTrue($this->policy->isCommon($password),
            "Password '{$password}' harus terdeteksi sebagai common");
    }

    public function testUniquePasswordNotCommon(): void
    {
        $this->assertFalse($this->policy->isCommon('K3ndalK4tEunik$'));
    }

    // ─── R3 user-specific check ──────────────────────────────────────

    public function testPasswordContainingEmailLocalPartFails(): void
    {
        $user = ['email' => 'budi.santoso@hris.local', 'name' => 'Budi Santoso'];
        $errors = $this->policy->validateAgainstUser('Budi.santoso2024!', $user);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('email', strtolower($errors[0]));
    }

    public function testPasswordContainingNameFails(): void
    {
        $user = ['email' => 'b@hris.local', 'name' => 'Budi Santoso'];
        $errors = $this->policy->validateAgainstUser('Santoso$Kuat99', $user);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('nama', strtolower($errors[0]));
    }

    public function testShortNamePartIsIgnored(): void
    {
        // Kata "Ir." 2 huruf → tidak boleh false-positive
        $user = ['email' => 'ir@hris.local', 'name' => 'Ir Tono'];
        $errors = $this->policy->validateAgainstUser('Pirate$Kuat99!', $user);
        $this->assertSame([], $errors);
    }

    public function testEmptyUserDataReturnsNoErrors(): void
    {
        $errors = $this->policy->validateAgainstUser('AnyPass$Kuat99', []);
        $this->assertSame([], $errors);
    }

    // ─── R6 forced rotation ──────────────────────────────────────────

    public function testMustRotateDisabledByDefault(): void
    {
        $this->assertFalse($this->policy->mustRotate([
            'password_changed_at' => date('Y-m-d H:i:s', strtotime('-2 years')),
        ]));  // maxAge default 0 → never rotate
    }

    public function testMustRotateAfterMaxAge(): void
    {
        $this->assertTrue($this->policy->mustRotate([
            'password_changed_at' => date('Y-m-d H:i:s', strtotime('-100 days')),
        ], 90));
    }

    public function testMustRotateBeforeMaxAge(): void
    {
        $this->assertFalse($this->policy->mustRotate([
            'password_changed_at' => date('Y-m-d H:i:s', strtotime('-30 days')),
        ], 90));
    }

    public function testMustRotateForUserNeverChangedPassword(): void
    {
        $this->assertTrue($this->policy->mustRotate([
            'password_changed_at' => null,
        ], 90));
    }
}