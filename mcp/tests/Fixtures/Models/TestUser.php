<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tests\Fixtures\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * 测试用户：可直接登录 Filament 面板；mcp_abilities 为授予的 MCP 策略能力。
 */
class TestUser extends Authenticatable implements FilamentUser
{
    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'mcp_abilities'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['mcp_abilities' => 'array'];
    }

    /**
     * 是否具备某项 MCP 策略能力（viewAny/view/create/update/delete）。
     */
    public function allowsMcpAbility(string $ability): bool
    {
        return in_array($ability, $this->mcp_abilities ?? [], true);
    }
}
