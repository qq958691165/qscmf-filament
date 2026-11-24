<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Resources\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\Mcp\Resources\ResourceDefinition;

/**
 * 资源数据访问契约。
 *
 * 默认实现为 EloquentResourceRepository；如需自定义写逻辑（审计、领域服务等），
 * 可在 cmf-mcp.resources.<slug>.repository 指定实现类。运行时负责已授权的记录
 * 查找、事务与保存后作用域复核；实现只需在给定模型上完成写入，且不得在提交前
 * 执行外部副作用。
 */
interface ResourceRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(ResourceDefinition $definition, Authenticatable $user, array $attributes): Model;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ResourceDefinition $definition, Authenticatable $user, Model $record, array $attributes): Model;

    public function delete(ResourceDefinition $definition, Authenticatable $user, Model $record): void;
}
