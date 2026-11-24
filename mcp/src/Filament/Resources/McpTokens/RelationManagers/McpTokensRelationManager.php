<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Filament\Resources\McpTokens\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Override;
use Quansitech\Cmf\Mcp\Filament\McpTokenAccess;
use Quansitech\Cmf\Mcp\Filament\Resources\McpTokens\McpTokenActions;
use Quansitech\Cmf\Mcp\Filament\Resources\McpTokens\McpTokenResource;

final class McpTokensRelationManager extends RelationManager
{
    protected static string $relationship = 'mcpTokens';

    protected static ?string $title = 'MCP 授权';

    #[Override]
    #[Unexposed]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return McpTokenAccess::allowsManagingUser($ownerRecord);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return McpTokenResource::configureTokenTable($table, showUser: false)
            ->headerActions([McpTokenActions::issue($this->getOwnerRecord())]);
    }
}
