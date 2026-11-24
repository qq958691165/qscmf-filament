<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Models\TestUser;

/**
 * 测试用用户资源：供跨用户令牌列表与代签权限校验使用。
 */
final class TestUserResource extends Resource
{
    protected static ?string $model = TestUser::class;

    public static function getPages(): array
    {
        return [];
    }
}
