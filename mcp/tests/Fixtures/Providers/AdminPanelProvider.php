<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tests\Fixtures\Providers;

use Filament\Panel;
use Filament\PanelProvider;
use Quansitech\Cmf\Mcp\McpPlugin;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Resources\TestArticleCategoryResource;

/**
 * 测试面板：挂载 MCP 插件与已注册的资源，供面板隔离校验使用。
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->resources([
                TestArticleCategoryResource::class,
            ])
            ->plugin(McpPlugin::make());
    }
}
