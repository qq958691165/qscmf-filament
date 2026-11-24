<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Http;

use Closure;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Quansitech\Cmf\Mcp\McpPlugin;
use Quansitech\Cmf\Mcp\Models\McpToken;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $panelId = $request->route('panel');
        abort_unless(in_array($panelId, config('cmf-mcp.panels', []), true), 404);
        $panel = Filament::getPanels()[$panelId] ?? null;
        abort_unless($panel?->hasPlugin(McpPlugin::ID) && ! $panel->hasTenancy(), 404);

        $plain = $request->bearerToken();
        $request->headers->remove('Authorization');
        $request->server->remove('HTTP_AUTHORIZATION');
        $request->server->remove('REDIRECT_HTTP_AUTHORIZATION');
        $token = is_string($plain) && preg_match('/\Acmfmcp_[a-f0-9]{64}\z/', $plain)
            ? McpToken::query()
                ->with('user')
                ->where('token_hash', hash('sha256', $plain))
                ->whereNull('revoked_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->first()
            : null;

        if (! $token || ! in_array($token->ability, ['read', 'write'], true) || ! $token->user instanceof FilamentUser) {
            return response()->json(['message' => 'Invalid MCP access token.'], 401);
        }

        $user = $token->user;
        if (! $user->canAccessPanel($panel)) {
            // 与“面板不存在”统一为 404，避免持有效令牌者据此枚举已启用面板。
            return response()->json(['message' => 'Not found.'], 404);
        }

        $previousPanel = Filament::getCurrentPanel();
        $previousGuard = Auth::getDefaultDriver();
        $guard = Auth::guard($panel->getAuthGuard());
        $previousUser = $guard->hasUser() ? $guard->user() : null;

        try {
            Filament::setCurrentPanel($panel);
            Auth::shouldUse($panel->getAuthGuard());
            $guard->setUser($user);
            $request->attributes->set(McpToken::class, $token);
            $token->forceFill(['last_used_at' => now()])->save();

            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        } finally {
            $request->attributes->remove(McpToken::class);
            if ($previousUser) {
                $guard->setUser($previousUser);
            } else {
                $guard->forgetUser();
            }
            Auth::shouldUse($previousGuard);
            Filament::setCurrentPanel($previousPanel);
        }
    }
}
