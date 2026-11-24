<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Resources;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Quansitech\Cmf\Mcp\Models\McpToken;
use Quansitech\Cmf\Mcp\Resources\Exceptions\ToolException;

final class ResourceAuthorizer
{
    public function canAccess(Authenticatable $user, ResourceDefinition $definition): bool
    {
        if (Gate::forUser($user)->getPolicyFor($definition->modelClass) === null) {
            return false;
        }

        if ($definition->resourceClass === null) {
            return true;
        }

        // 装配了 Filament Resource 时，要求该 Resource 确实注册在当前面板上，
        // 避免多面板宿主里调用未在本面板注册的资源，形成面板隔离缺口。
        return $this->resourceOnCurrentPanel($definition->resourceClass)
            && $definition->resourceClass::canAccess();
    }

    private function resourceOnCurrentPanel(string $resourceClass): bool
    {
        $panel = Filament::getCurrentPanel();

        return $panel !== null && in_array($resourceClass, $panel->getResources(), true);
    }

    public function allows(Authenticatable $user, ResourceDefinition $definition, string $ability, ?Model $record = null): bool
    {
        $gate = Gate::forUser($user);
        $policy = $gate->getPolicyFor($definition->modelClass);

        if ($policy === null || ! is_callable([$policy, $ability]) || ! $gate->allows($ability, $record ?? $definition->modelClass)) {
            return false;
        }

        if ($definition->resourceClass === null) {
            return true;
        }

        if (! $this->resourceOnCurrentPanel($definition->resourceClass)) {
            return false;
        }

        return match ($ability) {
            'viewAny' => $definition->resourceClass::canViewAny(),
            'view' => $definition->resourceClass::canView($record),
            'create' => $definition->resourceClass::canCreate(),
            'update' => $definition->resourceClass::canEdit($record),
            'delete' => $definition->resourceClass::canDelete($record),
            default => false,
        };
    }

    public function hasWriteToken(): bool
    {
        $token = request()->attributes->get(McpToken::class);

        return $token instanceof McpToken && $token->ability === 'write';
    }

    public function ensureWriteToken(): void
    {
        if (! $this->hasWriteToken()) {
            throw new ToolException('A write-scoped token is required for this operation.');
        }
    }
}
