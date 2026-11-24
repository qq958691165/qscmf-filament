<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tests\Fixtures\Providers;

use Filament\Panel;
use Filament\PanelProvider;
use Quansitech\Cmf\Mcp\McpPlugin;

/**
 * 测试面板：只挂载 MCP 插件，供 /mcp/{panel} 路由与面板隔离校验使用。
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(McpPlugin::make());
    }
}
