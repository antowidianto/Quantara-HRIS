<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/** Verify the database is the final arbiter when two requests reserve one key. */
final class IdempotencyConcurrencyTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'hris-idem-');
        self::assertNotFalse($path);
        $this->databasePath = $path;
    }

    protected function tearDown(): void
    {
        if (isset($this->databasePath) && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
        parent::tearDown();
    }

    public function testOnlyOneReservationWinsWithinATenant(): void
    {
        $first = $this->connect();
        $second = $this->connect();
        $first->exec(
            'CREATE TABLE idempotency_keys ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, key_hash TEXT NOT NULL,'
            . 'UNIQUE(company_id, key_hash))'
        );

        $insert = 'INSERT INTO idempotency_keys (company_id, key_hash) VALUES (:company_id, :key_hash)';
        $statement = $first->prepare($insert);
        self::assertTrue($statement->execute(['company_id' => 10, 'key_hash' => 'same-request']));

        try {
            $second->prepare($insert)->execute(['company_id' => 10, 'key_hash' => 'same-request']);
            self::fail('The second reservation must lose the unique-key race.');
        } catch (PDOException $e) {
            self::assertStringContainsString('UNIQUE', strtoupper($e->getMessage()));
        }

        self::assertSame(1, (int) $first->query('SELECT COUNT(*) FROM idempotency_keys')->fetchColumn());

        // The same opaque key remains valid in another tenant.
        self::assertTrue(
            $second->prepare($insert)->execute(['company_id' => 20, 'key_hash' => 'same-request'])
        );
        self::assertSame(2, (int) $first->query('SELECT COUNT(*) FROM idempotency_keys')->fetchColumn());
    }

    private function connect(): PDO
    {
        return new PDO('sqlite:' . $this->databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
