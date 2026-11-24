<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 测试用业务模型：文章分类。
 */
class TestArticleCategory extends Model
{
    protected $table = 'article_categories';

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'secret'];

    /** @var list<string> */
    protected $hidden = ['secret'];
}
