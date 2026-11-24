<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Filament\Resources\McpTokens;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Quansitech\Cmf\Mcp\Filament\McpTokenAccess;
use Quansitech\Cmf\Mcp\Filament\Resources\McpTokens\Pages\ListMcpTokens;
use Quansitech\Cmf\Mcp\Models\McpToken;
use UnitEnum;

final class McpTokenResource extends Resource
{
    protected static ?string $model = McpToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $modelLabel = 'MCP 令牌';

    protected static ?string $pluralModelLabel = 'MCP 授权';

    #[Override]
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament-shield::filament-shield.nav.group');
    }

    #[Override]
    public static function getViewAnyAuthorizationResponse(): Response
    {
        return McpTokenAccess::allowsManagement() ? Response::allow() : Response::deny();
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! McpTokenAccess::allowsManagement()) {
            return $query->whereRaw('1 = 0');
        }

        $actorId = Filament::auth()->id();
        $userColumn = $query->qualifyColumn('user_id');

        // 无跨用户（代签）授权时，只能看到自己的令牌，无需扫描全部令牌持有者。
        if (! McpTokenAccess::allowsIssuingForOthers()) {
            return $query->where($userColumn, $actorId);
        }

        $users = McpTokenAccess::userResource()::getEloquentQuery();
        $userKey = $users->getModel()->getQualifiedKeyName();
        $userAlias = $users->getModel()->getKeyName();

        // 逐条按宿主用户资源与策略复核可管理的用户；lazyById 避免深分页的偏移成本。
        $manageableOwnerIds = $users
            ->where($userKey, '!=', $actorId)
            ->whereIn($userKey, McpToken::query()->select('user_id'))
            // 游标分页要求主键排序稳定；用户资源可能自带默认排序，
            // 而 forPageAfterId 只移除主键列的排序，保留其它排序会跳过部分用户。
            ->reorder()
            ->lazyById(100, $userKey, $userAlias)
            ->filter(McpTokenAccess::allowsManagingUser(...))
            ->map(fn ($user) => $user->getKey())
            ->all();

        return $query->whereIn($userColumn, [$actorId, ...$manageableOwnerIds]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return self::configureTokenTable($table, showUser: true)
            ->headerActions([McpTokenActions::issue()]);
    }

    public static function configureTokenTable(Table $table, bool $showUser): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('名称')->searchable(),
                TextColumn::make('user.name')->label('用户')->searchable()->visible($showUser),
                TextColumn::make('issuer.name')->label('签发人')->placeholder('—')->toggleable()->visible($showUser),
                TextColumn::make('token_prefix')->label('令牌前缀'),
                TextColumn::make('ability')->label('权限')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'read' ? '只读' : '读写'),
                TextColumn::make('status')->label('状态')->badge()
                    ->state(fn (McpToken $record): string => match (true) {
                        $record->revoked_at !== null => '已撤销',
                        $record->expires_at?->isPast() === true => '已过期',
                        default => '有效',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        '已撤销' => 'danger',
                        '已过期' => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('last_used_at')->label('最近使用')->dateTime()->sortable()->placeholder('未使用'),
                TextColumn::make('expires_at')->label('到期时间')->dateTime()->sortable()->placeholder('长期有效'),
                TextColumn::make('revoked_at')->label('撤销时间')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([McpTokenActions::revoke()]);
    }

    #[Override]
    public static function getPages(): array
    {
        return ['index' => ListMcpTokens::route('/')];
    }
}
