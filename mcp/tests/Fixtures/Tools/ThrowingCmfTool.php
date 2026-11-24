<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tests\Fixtures\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Mcp\Request;
use Quansitech\Cmf\Mcp\Resources\ResourceRuntime;
use Quansitech\Cmf\Mcp\Tools\CmfTool;
use Throwable;

/**
 * 测试用工具：执行时抛出指定异常，用于验证 CmfTool 的异常脱敏。
 */
final class ThrowingCmfTool extends CmfTool
{
    public function __construct(private readonly Throwable $exception) {}

    protected function execute(Request $request, ResourceRuntime $runtime, Authenticatable $user): array
    {
        throw $this->exception;
    }
}
