<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class TestArticle extends Model
{
    protected $table = 'test_articles';

    protected $fillable = ['title', 'status', 'secret'];

    protected $hidden = ['secret'];
}
