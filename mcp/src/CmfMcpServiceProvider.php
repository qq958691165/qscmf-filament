<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Quansitech\Cmf\Core\Cmf;
use Quansitech\Cmf\Mcp\Filament\Resources\McpTokens\McpTokenResource;
use Quansitech\Cmf\Mcp\Http\AuthenticateMcpToken;
use Quansitech\Cmf\Mcp\Models\McpToken;

final class CmfMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cmf-mcp.php', 'cmf-mcp');
        Cmf::registerPlugin(McpPlugin::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/cmf-mcp.php' => config_path('cmf-mcp.php')], 'cmf-config');

        $userModel = config('cmf-users.model');
        $userModel::resolveRelationUsing('mcpTokens', fn ($user) => $user->hasMany(McpToken::class, 'user_id'));

        $permissions = config('filament-shield.custom_permissions', []);
        $permissions['Manage:McpToken'] ??= '管理 MCP 授权码';
        $permissions['ManageOthers:McpToken'] ??= '管理他人 MCP 授权码';
        config(['filament-shield.custom_permissions' => $permissions]);

        $excludedResources = config('filament-shield.resources.exclude', []);
        if (! in_array(McpTokenResource::class, $excludedResources, true)) {
            config(['filament-shield.resources.exclude' => [...$excludedResources, McpTokenResource::class]]);
        }

        if (! $this->app->routesAreCached()) {
            $throttle = config('cmf-mcp.throttle', '60,1');

            Mcp::web('mcp/{panel}', McpServer::class)
                ->name('cmf-mcp.server')
                ->middleware([
                    ...($throttle ? ['throttle:'.$throttle] : []),
                    AuthenticateMcpToken::class,
                    ...config('cmf-mcp.middleware', []),
                ]);
        }
    }
}
