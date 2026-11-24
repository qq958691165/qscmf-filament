<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Filament\Resources\McpTokens\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Support\Components\Attributes\Unexposed;
use Override;
use Quansitech\Cmf\Mcp\Filament\Resources\McpTokens\McpTokenResource;

final class ListMcpTokens extends ListRecords
{
    protected static string $resource = McpTokenResource::class;

    #[Override]
    protected function authorizeAccess(): void
    {
        McpTokenResource::authorizeViewAny();
    }

    #[Unexposed]
    public function hydrate(): void
    {
        $this->authorizeAccess();
    }
}
