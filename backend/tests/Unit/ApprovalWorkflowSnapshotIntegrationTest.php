<?php

namespace Tests\Unit;

use App\Libraries\Approval\ApprovalEngine;
use CodeIgniter\Test\CIUnitTestCase;

final class ApprovalWorkflowSnapshotIntegrationTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $tables = ['job_postings', 'approval_delegations', 'approval_chain_steps', 'users', 'employees'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        $db->query('CREATE TABLE db_employees (id INTEGER PRIMARY KEY, company_id INTEGER, user_id INTEGER, department_id INTEGER, branch_id INTEGER, job_grade_id INTEGER, supervisor_id INTEGER, manager_id INTEGER)');
        $db->query('CREATE TABLE db_users (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, role_id INTEGER, is_active INTEGER, name TEXT)');
        $db->query('CREATE TABLE db_approval_chain_steps (id INTEGER PRIMARY KEY, company_id INTEGER, chain_id INTEGER, step_order INTEGER, name TEXT, approver_type TEXT, approver_role_id INTEGER, approver_user_id INTEGER, approver_employee_id INTEGER, condition_field TEXT, condition_op TEXT, condition_value REAL, condition_type TEXT, condition_value_json TEXT, allow_skip_if_self INTEGER)');
        $db->query('CREATE TABLE db_approval_delegations (id INTEGER PRIMARY KEY, company_id INTEGER, user_id INTEGER, delegate_to_user_id INTEGER, module TEXT, is_active INTEGER, date_from TEXT, date_to TEXT)');
        $db->query('CREATE TABLE db_job_postings (id INTEGER PRIMARY KEY, company_id INTEGER, hiring_manager_id INTEGER, deleted_at TEXT)');
        $db->table('employees')->insert(['id' => 1001, 'company_id' => 1001, 'user_id' => 1001]);
        $db->table('users')->insert(['id' => 2001, 'company_id' => 1001, 'employee_id' => null, 'role_id' => 5, 'is_active' => 1, 'name' => 'Finance A']);
        $db->table('users')->insert(['id' => 2002, 'company_id' => 1001, 'employee_id' => null, 'role_id' => 5, 'is_active' => 1, 'name' => 'Finance B']);
        $db->table('users')->insert(['id' => 2003, 'company_id' => 1001, 'employee_id' => null, 'role_id' => 3, 'is_active' => 1, 'name' => 'Hiring Manager']);
        $db->table('job_postings')->insert(['id' => 6001, 'company_id' => 1001, 'hiring_manager_id' => 2003]);
        $db->table('approval_chain_steps')->insert([
            'id' => 3001, 'company_id' => 1001, 'chain_id' => 4001,
            'step_order' => 1, 'name' => 'Finance approval', 'approver_type' => 'SPECIFIC_USER',
            'approver_user_id' => 2001, 'allow_skip_if_self' => 0,
        ]);
    }

    public function testExpectedApproverDoesNotChangeWhenPolicyIsEdited(): void
    {
        $engine = new ApprovalEngine();
        $build = new \ReflectionMethod($engine, 'buildWorkflowSnapshot');
        $snapshot = $build->invoke($engine,
            ['id' => 4001, 'code' => 'FIN', 'name' => 'Finance', 'fallback_strategy' => 'BLOCK'],
            ['company_id' => 1001, 'module' => 'REIMBURSEMENT', 'employee_id' => 1001],
            ['amount' => 5_000_000],
        );
        $this->assertSame([2001], $snapshot['steps'][0]['expected_approver_user_ids']);

        \Config\Database::connect()->table('approval_chain_steps')->where('id', 3001)
            ->update(['approver_user_id' => 2002]);
        $resolve = new \ReflectionMethod($engine, 'resolveCurrentApprovers');
        $resolved = $resolve->invoke($engine, [
            'company_id' => 1001, 'module' => 'REIMBURSEMENT', 'employee_id' => 1001,
            'chain_id' => 4001, 'current_step_id' => 3001,
            'workflow_snapshot' => json_encode($snapshot),
        ]);

        $this->assertSame([2001], $resolved);
        $this->assertNotContains(2002, $resolved);
    }

    public function testConditionResultIsFrozenAtSubmission(): void
    {
        $db = \Config\Database::connect();
        $db->table('approval_chain_steps')->where('id', 3001)->update([
            'condition_field' => 'amount', 'condition_op' => '>=', 'condition_value' => 10_000_000,
        ]);
        $engine = new ApprovalEngine();
        $build = new \ReflectionMethod($engine, 'buildWorkflowSnapshot');
        $snapshot = $build->invoke($engine,
            ['id' => 4001, 'code' => 'FIN', 'name' => 'Finance', 'fallback_strategy' => 'BLOCK'],
            ['company_id' => 1001, 'module' => 'REIMBURSEMENT', 'employee_id' => 1001],
            ['amount' => 5_000_000],
        );
        $this->assertFalse($snapshot['steps'][0]['condition_matched']);
        $this->assertSame([], $snapshot['steps'][0]['expected_approver_user_ids']);
    }

    public function testDelegationRemainsEffectiveDatedNotFrozenIntoPolicy(): void
    {
        $db = \Config\Database::connect();
        $db->table('approval_delegations')->insert([
            'id' => 5001, 'company_id' => 1001, 'user_id' => 2001,
            'delegate_to_user_id' => 2002, 'module' => 'REIMBURSEMENT', 'is_active' => 1,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d', strtotime('+1 day')),
        ]);
        $engine = new ApprovalEngine();
        $snapshot = (new \ReflectionMethod($engine, 'buildWorkflowSnapshot'))->invoke($engine,
            ['id' => 4001, 'code' => 'FIN', 'name' => 'Finance', 'fallback_strategy' => 'BLOCK'],
            ['company_id' => 1001, 'module' => 'REIMBURSEMENT', 'employee_id' => 1001],
            ['amount' => 5_000_000],
        );
        $this->assertSame([2001], $snapshot['steps'][0]['expected_approver_user_ids']);

        $request = [
            'company_id' => 1001, 'module' => 'REIMBURSEMENT', 'employee_id' => 1001,
            'chain_id' => 4001, 'current_step_id' => 3001,
            'workflow_snapshot' => json_encode($snapshot),
        ];
        $resolve = new \ReflectionMethod($engine, 'resolveCurrentApprovers');
        $this->assertSame([2001, 2002], $resolve->invoke($engine, $request));

        $db->table('approval_delegations')->where('id', 5001)
            ->update(['date_to' => date('Y-m-d', strtotime('-1 day'))]);
        $this->assertSame([2001], $resolve->invoke($engine, $request));
    }

    public function testHiringManagerIsResolvedFromSnapshottedJobPostingMetadata(): void
    {
        $db = \Config\Database::connect();
        $db->table('approval_chain_steps')->where('id', 3001)->update([
            'approver_type' => 'HIRING_MANAGER', 'approver_user_id' => null,
        ]);
        $snapshot = (new \ReflectionMethod(new ApprovalEngine(), 'buildWorkflowSnapshot'))->invoke(
            new ApprovalEngine(),
            ['id' => 4001, 'code' => 'OFFER', 'name' => 'Offer', 'fallback_strategy' => 'BLOCK'],
            ['company_id' => 1001, 'module' => 'CANDIDATE_OFFER', 'employee_id' => 1001],
            ['job_posting_id' => 6001, 'amount' => 8_000_000],
        );
        $this->assertSame([2003], $snapshot['steps'][0]['expected_approver_user_ids']);

        $db->table('job_postings')->where('id', 6001)->update(['hiring_manager_id' => 2002]);
        $this->assertSame([2003], $snapshot['steps'][0]['expected_approver_user_ids']);
    }

    protected function tearDown(): void
    {
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        parent::tearDown();
    }
}
