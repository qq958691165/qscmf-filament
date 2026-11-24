<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Quansitech\Cmf\Core\CmfCoreServiceProvider;
use Quansitech\Cmf\Mcp\CmfMcpServiceProvider;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Models\TestArticleCategory;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Models\TestUser;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Policies\TestArticleCategoryPolicy;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Policies\TestUserPolicy;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Providers\AdminPanelProvider;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Resources\TestUserResource;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            // 注意顺序：filament/support 会 bind() 覆盖 Livewire 的 DataStore，
            // Livewire 后注册可让 registerMechanisms 的 instance() 绑定优先生效。
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            TablesServiceProvider::class,
            NotificationsServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            LivewireServiceProvider::class,
            McpServiceProvider::class,
            CmfCoreServiceProvider::class,
            AdminPanelProvider::class,
            CmfMcpServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', TestUser::class);
        $app['config']->set('cmf-users.model', TestUser::class);
        $app['config']->set('cmf-users.resource', TestUserResource::class);

        $app['config']->set('cmf-mcp.panels', ['admin']);
        $app['config']->set('cmf-mcp.middleware', []);
        // 测试内放开限流，避免用例里密集请求触发 429。
        $app['config']->set('cmf-mcp.throttle', null);
        $app['config']->set('cmf-mcp.max_scan_rows', 5000);
        $app['config']->set('cmf-mcp.resources', [
            'article-categories' => [
                'model' => TestArticleCategory::class,
                'description' => '测试文章分类',
                'read' => ['id', 'name', 'slug', 'secret'],
                'write' => [
                    'name' => 'required|string|max:100',
                    'slug' => 'required|string|max:100|unique:article_categories,slug',
                ],
                'operations' => ['viewAny', 'view', 'create', 'update', 'delete'],
            ],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::policy(TestArticleCategory::class, TestArticleCategoryPolicy::class);
        Gate::policy(TestUser::class, TestUserPolicy::class);
    }
}
