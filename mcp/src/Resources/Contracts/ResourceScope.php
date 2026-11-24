<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Resources\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * 资源数据作用域约束。
 *
 * 配置在 cmf-mcp.resources.<slug>.scope 的类必须实现本接口。
 * 它会在 Filament Resource 的 getEloquentQuery()（若配置）之上追加
 * 强制过滤条件，确保 MCP 端点无法绕过业务/数据范围限制，即使超级管理员
 * 通过 Gate::before 放行权限，也必须受该作用域约束。
 */
interface ResourceScope
{
    /**
     * 对传入的嵌套查询追加 WHERE 条件并返回同一个查询。
     *
     * 运行时会把该约束包在独立的嵌套分组中，因此本方法不应依赖或改写
     * 调用方已存在的其它条件。
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function apply(Builder $query, Authenticatable $user): Builder;
}
