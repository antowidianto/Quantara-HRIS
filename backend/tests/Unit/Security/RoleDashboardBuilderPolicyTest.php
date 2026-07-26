<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Libraries\Admin\RoleDashboardService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class RoleDashboardBuilderPolicyTest extends CIUnitTestCase
{
    public function testBuilderRoutesAreSuperAdminOnlyAndMutationsAreIdempotent(): void
    {
        $routes = service('routes');

        foreach ([
            ['GET', 'api/v1/admin/dashboard-builder/catalog', ['jwt', 'superadmin']],
            ['GET', 'api/v1/admin/dashboard-builder/templates', ['jwt', 'superadmin']],
            ['POST', 'api/v1/admin/dashboard-builder/preview', ['jwt', 'superadmin']],
            ['POST', 'api/v1/admin/dashboard-builder/templates', ['jwt', 'superadmin', 'idempotency:required']],
            ['PUT', 'api/v1/admin/dashboard-builder/templates/([0-9]+)', ['jwt', 'superadmin', 'idempotency:required']],
            ['POST', 'api/v1/admin/dashboard-builder/templates/([0-9]+)/publish', ['jwt', 'superadmin', 'idempotency:required']],
            ['DELETE', 'api/v1/admin/dashboard-builder/templates/([0-9]+)', ['jwt', 'superadmin', 'idempotency:required']],
        ] as [$verb, $path, $expected]) {
            self::assertSame($expected, $routes->getRoutesOptions($path, $verb)['filter'] ?? [], "{$verb} {$path}");
        }

        self::assertSame(
            ['jwt', 'ownership:account'],
            $routes->getRoutesOptions('api/v1/dashboard-config/me', 'GET')['filter'] ?? [],
        );
    }

    public function testCatalogUsesOnlyCodeOwnedMetricsWithPermissionMetadata(): void
    {
        $catalog = (new RoleDashboardService())->catalog();
        self::assertNotEmpty($catalog);

        foreach ($catalog as $metric) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_.-]+$/', $metric['key']);
            self::assertContains(
                $metric['type'],
                ['stat', 'progress', 'bar', 'donut', 'pie', 'line', 'area', 'links'],
            );
            self::assertContains($metric['default_width'], ['quarter', 'half', 'full']);
            self::assertArrayHasKey('required_permission', $metric);
            self::assertArrayNotHasKey('sql', $metric);
            self::assertArrayNotHasKey('url', $metric);
        }
    }

    public function testControllerKeepsDefenseInDepthSuperAdminCheck(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 3) . '/app/Controllers/Api/V1/Admin/DashboardBuilderController.php'
        );
        self::assertStringContainsString('ensureSuperAdmin()', $controller);
        self::assertStringContainsString('SUPER_ADMIN_ONLY', $controller);
    }

    public function testDraftVersionPublishAndEffectiveResolutionWorkTogether(): void
    {
        $db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'foreignKeys' => false, 'DBDebug' => true,
        ], false);
        $db->query('CREATE TABLE roles (id INTEGER PRIMARY KEY, slug VARCHAR(80))');
        $db->query('CREATE TABLE companies (id INTEGER PRIMARY KEY, name VARCHAR(150))');
        $db->query('CREATE TABLE dashboard_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NULL, target_role_slug VARCHAR(80),
            name VARCHAR(150), description TEXT NULL, status VARCHAR(20), layout_json TEXT, version INTEGER,
            published_at DATETIME NULL, published_by INTEGER NULL, created_by INTEGER, updated_by INTEGER,
            created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL
        )');
        $db->query('CREATE TABLE dashboard_template_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, template_id INTEGER, version INTEGER, snapshot_json TEXT,
            change_type VARCHAR(30), created_by INTEGER, created_at DATETIME NULL
        )');
        $db->table('roles')->insert(['id' => 2, 'slug' => 'hr_admin']);
        $db->table('companies')->insert(['id' => 7, 'name' => 'Tenant Test']);
        $service = new RoleDashboardService($db);

        $draft = $service->create([
            'name' => 'Dashboard Test',
            'target_role_slug' => 'hr_admin',
            'company_id' => null,
            'widgets' => [[
                'metric_key' => 'headcount.active',
                'width' => 'quarter',
                'color' => 'blue',
            ]],
        ], 1);
        self::assertSame('DRAFT', $draft['status']);

        $updated = $service->update($draft['id'], [
            'name' => $draft['name'],
            'target_role_slug' => 'hr_admin',
            'company_id' => null,
            'widgets' => $draft['widgets'],
        ], 1);
        self::assertSame(2, $updated['version']);

        $published = $service->publish($draft['id'], 1);
        self::assertSame('PUBLISHED', $published['status']);
        $effective = $service->effective(7, 'hr_admin', true);
        self::assertSame($draft['id'], $effective['id']);
        self::assertSame('headcount.active', $effective['widgets'][0]['metric_key']);

        $this->expectException(\InvalidArgumentException::class);
        $service->update($draft['id'], [
            'name' => 'Perubahan Langsung',
            'target_role_slug' => 'hr_admin',
            'widgets' => $draft['widgets'],
        ], 1);
    }
}
