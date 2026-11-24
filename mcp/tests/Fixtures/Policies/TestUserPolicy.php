<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tests\Fixtures\Policies;

use Quansitech\Cmf\Mcp\Tests\Fixtures\Models\TestUser;

/**
 * 测试用用户策略：允许查看，供用户资源的可见性判断使用。
 */
final class TestUserPolicy
{
    public function viewAny(TestUser $user): bool
    {
        return true;
    }

    public function view(TestUser $user, TestUser $model): bool
    {
        return true;
    }

    public function update(TestUser $user, TestUser $model): bool
    {
        return true;
    }
}
