<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Quansitech\Cmf\Mcp\Filament\Resources\McpTokens\McpTokenResource;

final class McpPlugin implements Plugin
{
    public const ID = 'cmf-mcp';

    public static function make(): static
    {
        return app(self::class);
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        if (self::supportsPanel($panel)) {
            $panel->resources([McpTokenResource::class]);
        }
    }

    public function boot(Panel $panel): void {}

    public static function supportsPanel(Panel $panel): bool
    {
        return in_array($panel->getId(), config('cmf-mcp.panels'), true) && ! $panel->hasTenancy();
    }
}
