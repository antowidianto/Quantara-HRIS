<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ApprovalDelegationBusinessFlowTest extends TestCase
{
    public function testDelegateResolutionIsTenantScopedAndSupportsLifecycleModules(): void
    {
        $resolver = file_get_contents(APPPATH . 'Libraries/Approval/ApprovalDelegationResolver.php');
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Approval/ApprovalDelegationController.php');

        self::assertIsString($resolver);
        self::assertIsString($controller);
        self::assertStringContainsString("->where('company_id', \$companyId)", $resolver);
        self::assertStringContainsString('getActiveDelegate($expectedUserId, $companyId, $module)', $resolver);
        foreach (['MPP', 'RESIGNATION', 'TRANSFER', 'JIC'] as $module) {
            self::assertStringContainsString("'{$module}'", $controller);
        }
    }

    public function testEligibleDelegateQueryAliasesTheUsersTableInsteadOfUsingAliasAsTableName(): void
    {
        $model = file_get_contents(APPPATH . 'Models/Identity/UserModel.php');

        self::assertIsString($model);
        self::assertStringContainsString("->table('users u')", $model);
        self::assertStringNotContainsString("->builder('u')", $model);
    }
}
