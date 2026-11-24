<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class McpToken extends Model
{
    protected $table = 'cmf_mcp_tokens';

    protected $hidden = ['token_hash'];

    public static function issue(
        Model $user,
        string $name,
        string $ability,
        ?DateTimeInterface $expiresAt = null,
        ?Model $issuer = null,
    ): string {
        if (! in_array($ability, ['read', 'write'], true)) {
            throw new InvalidArgumentException('Invalid MCP token ability.');
        }

        $token = 'cmfmcp_'.bin2hex(random_bytes(32));

        $record = new static;
        $record->user()->associate($user);

        $attributes = [
            'name' => $name,
            'ability' => $ability,
            'token_hash' => hash('sha256', $token),
            'token_prefix' => substr($token, 0, 16),
            'expires_at' => $expiresAt,
        ];

        // 记录签发人：代他人签发时用于审计（actor → target）。
        if ($issuer !== null) {
            $attributes['issued_by'] = $issuer->getKey();
        }

        $record->forceFill($attributes)->save();

        return $token;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('cmf-users.model'), 'user_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(config('cmf-users.model'), 'issued_by');
    }

    public function revoke(): void
    {
        static::query()->whereKey($this->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->refresh();
    }

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
