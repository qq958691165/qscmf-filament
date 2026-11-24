<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Quansitech\Cmf\Mcp\Resources\ResourceRuntime;

/**
 * 列出当前端点暴露的资源。
 */
final class ListResourcesTool extends CmfTool
{
    protected string $name = 'list_resources';

    protected string $description = '列出当前 MCP 端点可访问的数据资源，以及每个资源支持的操作和可读字段。';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function execute(Request $request, ResourceRuntime $runtime, Authenticatable $user): array
    {
        return [
            'resources' => $runtime->listResources($user),
        ];
    }
}
