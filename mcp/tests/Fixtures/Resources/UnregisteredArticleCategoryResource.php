<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Models\TestArticleCategory;

/**
 * 未注册到测试面板的文章分类资源，用于验证面板隔离。
 */
final class UnregisteredArticleCategoryResource extends Resource
{
    protected static ?string $model = TestArticleCategory::class;

    public static function getPages(): array
    {
        return [];
    }
}
