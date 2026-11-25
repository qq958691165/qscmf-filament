<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Tests\Fixtures\Policies;

use Quansitech\Cmf\Agent\Tests\Fixtures\Models\TestArticle;
use Quansitech\Cmf\Agent\Tests\Fixtures\Models\TestUser;

class TestArticlePolicy
{
    public function viewAny(TestUser $user): bool
    {
        return in_array('ViewAny:Article', $user->abilities ?? [], true);
    }

    public function view(TestUser $user, TestArticle $article): bool
    {
        return in_array('View:Article', $user->abilities ?? [], true) && $article->secret !== 'hidden';
    }

    public function update(TestUser $user, TestArticle $article): bool
    {
        return in_array('Update:Article', $user->abilities ?? [], true) && $article->status === 'draft';
    }
}
