<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Recruitment\RecruitmentStageService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class RecruitmentStageServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect([
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => '',
            'foreignKeys' => true,
            'DBDebug' => true,
        ], false);
        $this->createSchema();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        parent::tearDown();
    }

    public function testStageTransitionIsAtomicAndTracksTenantHistory(): void
    {
        $service = new RecruitmentStageService($this->connection);
        $stageId = $service->moveStage(1, 1, 'SCREENING', [
            'created_by' => 91,
            'feedback' => 'CV verified',
        ]);

        self::assertGreaterThan(0, $stageId);
        self::assertSame('SCREENING', $this->candidateStage(1));
        $stages = $this->connection->table('candidate_stages')->where('candidate_id', 1)->orderBy('id')->get()->getResultArray();
        self::assertSame('PASSED', $stages[0]['result']);
        self::assertSame('SCREENING', $stages[1]['stage']);
        self::assertSame('PENDING', $stages[1]['result']);
        self::assertSame(1, (int) $stages[1]['company_id']);

        $history = $this->connection->table('candidate_stage_history')->where('candidate_id', 1)->orderBy('id')->get()->getResultArray();
        self::assertNotNull($history[0]['exited_at']);
        self::assertSame('SCREENING', $history[1]['stage']);
        self::assertSame('CV verified', $history[1]['note']);
    }

    public function testCrossTenantTransitionFailsAndRejectIsCompanyScoped(): void
    {
        $service = new RecruitmentStageService($this->connection);
        try {
            $service->moveStage(2, 1, 'SCREENING');
            self::fail('Cross-tenant candidate transition must fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Candidate not found', $exception->getMessage());
        }
        self::assertSame('NEW', $this->candidateStage(2));

        $service->reject(1, 1, 'Tidak memenuhi kualifikasi', 91);
        self::assertSame('REJECTED', $this->candidateStage(1));
        self::assertSame('NEW', $this->candidateStage(2));
        $rejected = $this->connection->table('candidate_stages')
            ->where('candidate_id', 1)->where('stage', 'REJECTED')->get()->getRowArray();
        self::assertSame(1, (int) $rejected['company_id']);
        self::assertSame('Tidak memenuhi kualifikasi', $rejected['feedback']);
    }

    private function candidateStage(int $candidateId): string
    {
        $row = $this->connection->table('candidates')->where('id', $candidateId)->get()->getRowArray();
        return (string) $row['current_stage'];
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE candidates (
            id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, job_posting_id INTEGER NOT NULL,
            current_stage TEXT NOT NULL, rejection_reason TEXT NULL, deleted_at TEXT NULL, updated_at TEXT NULL
        )');
        $this->connection->query('CREATE TABLE candidate_stages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, candidate_id INTEGER NOT NULL,
            stage TEXT NOT NULL, result TEXT NOT NULL, scheduled_at TEXT NULL, interviewer_id INTEGER NULL,
            feedback TEXT NULL, rating INTEGER NULL, created_by INTEGER NULL, completed_at TEXT NULL,
            created_at TEXT NULL, updated_at TEXT NULL
        )');
        $this->connection->query('CREATE TABLE candidate_stage_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, candidate_id INTEGER NOT NULL,
            stage TEXT NOT NULL, entered_at TEXT NOT NULL, exited_at TEXT NULL, duration_hours REAL NULL,
            by_user_id INTEGER NULL, note TEXT NULL, created_at TEXT NULL
        )');
    }

    private function seed(): void
    {
        $now = '2026-07-15 08:00:00';
        foreach ([[1, 1], [2, 2]] as [$id, $companyId]) {
            $this->connection->table('candidates')->insert([
                'id' => $id, 'company_id' => $companyId, 'job_posting_id' => $id,
                'current_stage' => 'NEW', 'updated_at' => $now,
            ]);
            $this->connection->table('candidate_stages')->insert([
                'company_id' => $companyId, 'candidate_id' => $id, 'stage' => 'NEW',
                'result' => 'PENDING', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->connection->table('candidate_stage_history')->insert([
                'company_id' => $companyId, 'candidate_id' => $id, 'stage' => 'NEW',
                'entered_at' => $now, 'created_at' => $now,
            ]);
        }
    }
}
