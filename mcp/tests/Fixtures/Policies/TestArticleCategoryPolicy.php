<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tests\Fixtures\Policies;

use Quansitech\Cmf\Mcp\Tests\Fixtures\Models\TestArticleCategory;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Models\TestUser;

/**
 * 测试策略：按用户 mcp_abilities 判权，模拟宿主项目的 Policy。
 */
final class TestArticleCategoryPolicy
{
    public function viewAny(TestUser $user): bool
    {
        return $user->allowsMcpAbility('viewAny');
    }

    public function view(TestUser $user, TestArticleCategory $model): bool
    {
        return $user->allowsMcpAbility('view');
    }

    public function create(TestUser $user): bool
    {
        return $user->allowsMcpAbility('create');
    }

    public function update(TestUser $user, TestArticleCategory $model): bool
    {
        return $user->allowsMcpAbility('update');
    }

    public function delete(TestUser $user, TestArticleCategory $model): bool
    {
        return $user->allowsMcpAbility('delete');
    }
}
