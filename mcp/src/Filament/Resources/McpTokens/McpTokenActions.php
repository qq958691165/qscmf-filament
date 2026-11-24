<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Filament\Resources\McpTokens;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Js;
use Quansitech\Cmf\Mcp\Filament\McpTokenAccess;
use Quansitech\Cmf\Mcp\Models\McpToken;

final class McpTokenActions
{
    public static function issue(?Model $owner = null): Action
    {
        $fields = [
            TextInput::make('name')->label('名称')->required()->maxLength(100),
            Select::make('ability')
                ->label('权限')
                ->options(['read' => '只读', 'write' => '读写'])
                ->default('read')
                ->required()
                ->in(['read', 'write']),
            DateTimePicker::make('expires_at')
                ->label('到期时间')
                ->helperText('留空则长期有效。')
                ->seconds(false)
                ->minDate(fn () => now())
                ->rules(['after:now']),
        ];

        if ($owner === null) {
            array_unshift($fields, Select::make('user_id')
                ->label('用户')
                ->default(fn () => Filament::auth()->id())
                ->required()
                ->searchable()
                ->getSearchResultsUsing(self::searchUsers(...))
                ->getOptionLabelUsing(function (mixed $value): ?string {
                    $user = self::findUser($value);

                    return $user === null ? null : self::userLabel($user);
                }));
        }

        return Action::make('issueMcpToken')
            ->label('签发 MCP 令牌')
            ->icon(Heroicon::OutlinedKey)
            ->modalSubmitActionLabel('签发')
            ->authorize(fn (): bool => $owner === null
                ? McpTokenAccess::allowsManagement()
                : McpTokenAccess::allowsManagingUser($owner))
            ->schema($fields)
            ->action(function (array $data, Action $action) use ($owner): void {
                $user = $owner ?? self::findUser($data['user_id'] ?? null);
                abort_unless($user !== null && McpTokenAccess::allowsManagingUser($user), 403);

                $issuer = Filament::auth()->user();

                $token = McpToken::issue(
                    $user,
                    $data['name'],
                    $data['ability'],
                    filled($data['expires_at'] ?? null) ? CarbonImmutable::parse($data['expires_at']) : null,
                    $issuer instanceof Model ? $issuer : null,
                );

                self::showPlaintext($action, $token);
            });
    }

    public static function revoke(): Action
    {
        return Action::make('revokeMcpToken')
            ->label('撤销')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading('撤销 MCP 令牌')
            ->modalDescription('撤销后，此令牌将立即失效。')
            ->modalSubmitActionLabel('撤销')
            ->requiresConfirmation()
            ->authorize(fn (McpToken $record): bool => self::canRevoke($record))
            ->action(function (McpToken $record): void {
                abort_unless(self::canRevoke($record), 403);

                $record->revoke();
                Notification::make()->title('已撤销 MCP 令牌')->success()->send();
            });
    }

    private static function canRevoke(McpToken $record): bool
    {
        return $record->revoked_at === null
            && $record->user instanceof Model
            && McpTokenAccess::allowsManagingUser($record->user);
    }

    private static function searchUsers(string $search): array
    {
        if (! McpTokenAccess::allowsManagement()) {
            return [];
        }

        $query = McpTokenAccess::userResource()::getEloquentQuery();
        $name = $query->qualifyColumn('name');
        $email = $query->qualifyColumn('email');

        $query
            ->where(fn (Builder $query): Builder => $query
                ->whereLike($name, "%{$search}%")
                ->orWhereLike($email, "%{$search}%"))
            ->orderBy($name);

        // 建议值，非强制；0 表示不限制。
        $limit = (int) config('cmf-mcp.user_search_limit', 50);
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query
            ->get()
            ->filter(McpTokenAccess::allowsManagingUser(...))
            ->mapWithKeys(fn (Model $user): array => [$user->getKey() => self::userLabel($user)])
            ->all();
    }

    private static function findUser(mixed $key): ?Model
    {
        if ((! is_int($key) && ! is_string($key)) || ! McpTokenAccess::allowsManagement()) {
            return null;
        }

        $resource = McpTokenAccess::userResource();
        $model = $resource::getModel();
        $actor = Filament::auth()->user();
        if ($actor instanceof $model && (string) $actor->getKey() === (string) $key) {
            return $actor;
        }

        $user = $resource::getEloquentQuery()->find($key);

        return $user !== null && McpTokenAccess::allowsManagingUser($user) ? $user : null;
    }

    private static function userLabel(Model $user): string
    {
        return "{$user->name} ({$user->email})";
    }

    private static function showPlaintext(Action $action, string $token): void
    {
        $plaintext = Js::from($token);

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $endpoint = Js::from(url('/mcp/'.$panelId));

        // Send the secret only in the issuing request's browser effect.
        $action->getLivewire()->js(<<<JS
            (() => {
                const secret = {$plaintext};
                const endpoint = {$endpoint};
                const NL = String.fromCharCode(10);

                const dialog = document.createElement('dialog');
                dialog.setAttribute('aria-label', 'MCP 令牌已签发');
                dialog.style.cssText = 'position:fixed;inset:0;margin:auto;width:min(44rem,calc(100vw - 2rem));max-height:calc(100vh - 2rem);overflow:auto;padding:1.5rem;border:1px solid;border-radius:0.75rem;background:Canvas;color:CanvasText;';

                const title = document.createElement('h2');
                title.textContent = 'MCP 令牌已签发';
                title.style.fontWeight = 'bold';

                const description = document.createElement('p');
                description.textContent = '请立即复制并妥善保存。关闭后将无法再次查看完整令牌。下方为接入说明，可照此配置 MCP 客户端。';

                const token = document.createElement('input');
                token.value = secret;
                token.readOnly = true;
                token.autocomplete = 'off';
                token.spellcheck = false;
                token.setAttribute('aria-label', '完整 MCP 令牌');
                token.style.cssText = 'display:block;width:100%;margin:1rem 0;padding:0.5rem;border:1px solid;border-radius:0.25rem;font-family:monospace;';

                const help = document.createElement('div');
                help.style.cssText = 'margin:1rem 0;font-size:0.85rem;line-height:1.5;border-top:1px solid rgba(128,128,128,.35);padding-top:0.75rem;';

                const helpTitle = document.createElement('p');
                helpTitle.textContent = '接入说明';
                helpTitle.style.cssText = 'font-weight:600;margin:0 0 0.5rem;';
                help.append(helpTitle);

                const block = (labelText, codeText) => {
                    const wrap = document.createElement('div');
                    wrap.style.margin = '0 0 0.6rem';
                    const label = document.createElement('div');
                    label.textContent = labelText;
                    label.style.cssText = 'font-weight:600;margin-bottom:0.25rem;';
                    const pre = document.createElement('pre');
                    pre.textContent = codeText;
                    pre.style.cssText = 'white-space:pre-wrap;word-break:break-all;background:rgba(128,128,128,.12);padding:0.5rem;border-radius:0.35rem;font-family:monospace;font-size:0.8rem;margin:0;';
                    wrap.append(label, pre);
                    return wrap;
                };

                const bearer = 'Authorization: Bearer ' + secret;
                const claude = 'claude mcp add --transport http cmf-mcp ' + endpoint + ' --header "Authorization: Bearer ' + secret + '"';
                const workbuddy = [
                    '{',
                    '  "mcpServers": {',
                    '    "cmf-mcp": {',
                    '      "type": "http",',
                    '      "url": "' + endpoint + '",',
                    '      "headers": { "Authorization": "Bearer ' + secret + '" }',
                    '    }',
                    '  }',
                    '}',
                ].join(NL);
                const doubao = '传输类型：HTTP（Streamable）' + NL + 'URL：' + endpoint + NL + '自定义 Header：' + bearer;

                help.append(
                    block('服务器地址（Streamable HTTP 端点）', endpoint),
                    block('请求头', bearer),
                    block('Claude Code', claude),
                    block('WorkBuddy（~/.workbuddy/mcp.json）', workbuddy),
                    block('豆包工作 / 其他客户端', doubao),
                );

                const note = document.createElement('p');
                note.textContent = '说明：服务器地址按当前站点生成；若通过域名、反向代理或端口访问，请换成客户端实际可访问的地址。令牌仅对签发账号有效，权限（只读/读写）以签发时所选为准，请勿泄露。';
                note.style.cssText = 'margin:0.5rem 0 0;opacity:0.8;';
                help.append(note);

                const footer = document.createElement('form');
                footer.method = 'dialog';
                const close = document.createElement('button');
                close.textContent = '关闭';
                close.className = 'fi-btn';
                footer.append(close);

                dialog.append(title, description, token, help, footer);
                dialog.addEventListener('close', () => dialog.remove(), { once: true });
                document.body.append(dialog);
                dialog.showModal();
                token.select();
            })()
            JS);
    }
}
