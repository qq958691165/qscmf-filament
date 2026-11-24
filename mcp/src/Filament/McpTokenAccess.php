<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Filament;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Quansitech\Cmf\Mcp\McpPlugin;
use Quansitech\Cmf\Users\Filament\Resources\Users\UserResource;

final class McpTokenAccess
{
    public static function supportsCurrentPanel(): bool
    {
        $panel = Filament::getCurrentPanel();

        return $panel !== null
            && ! $panel->hasTenancy()
            && in_array($panel->getId(), config('cmf-mcp.panels', []), true)
            && ($panel->getPlugins()[McpPlugin::ID] ?? null) instanceof McpPlugin;
    }

    public static function allowsManagement(): bool
    {
        if (! self::supportsCurrentPanel()) {
            return false;
        }

        // 以当前面板 guard 的用户显式判权，避免面板使用独立 guard 时
        // 默认 guard 的 Gate 与 Filament 当前用户不一致而误判。
        $actor = Filament::auth()->user();

        return $actor instanceof Authenticatable
            && Gate::forUser($actor)->allows('Manage:McpToken');
    }

    /**
     * 是否允许为「其他用户」签发/管理令牌。
     *
     * 与 Manage:McpToken（管理自己的令牌）区分开：代他人签发等同冒充，
     * 需要一项独立的显式授权，避免仅仅因为能编辑用户就被动获得代签能力。
     */
    public static function allowsIssuingForOthers(): bool
    {
        if (! self::allowsManagement()) {
            return false;
        }

        $actor = Filament::auth()->user();

        return $actor instanceof Authenticatable
            && Gate::forUser($actor)->allows('ManageOthers:McpToken');
    }

    public static function allowsManagingUser(Model $user): bool
    {
        if (! self::allowsManagement()) {
            return false;
        }

        $actor = Filament::auth()->user();

        if (! $actor instanceof Authenticatable) {
            return false;
        }

        if ($actor instanceof Model && $actor->is($user)) {
            return true;
        }

        // 跨用户（代签）：需要独立授权，且目标用户在当前用户资源中可见，
        // 不再复用 Update:User，从而与“改密码/改角色”解耦。
        return self::allowsIssuingForOthers() && self::userResource()::canView($user);
    }

    /** @return class-string<UserResource> */
    public static function userResource(): string
    {
        return config('cmf-users.resource', UserResource::class);
    }
}
