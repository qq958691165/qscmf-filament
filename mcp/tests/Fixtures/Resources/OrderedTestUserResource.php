<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Models\TestUser;

/**
 * 带默认排序的测试用户资源，用于验证令牌列表的游标分页不受其它排序干扰。
 */
final class OrderedTestUserResource extends Resource
{
    protected static ?string $model = TestUser::class;

    public static function getPages(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderBy('name');
    }
}
