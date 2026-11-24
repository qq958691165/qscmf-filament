<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Quansitech\Cmf\Mcp\Resources\ResourceRuntime;

/**
 * 描述单个资源的字段与可用操作。
 */
final class DescribeResourceTool extends CmfTool
{
    protected string $name = 'describe_resource';

    protected string $description = '查看指定资源的详细说明：可用操作、可读字段与可写字段。';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()
                ->description('资源标识：在 cmf-mcp.resources 中注册的 slug。')
                ->required(),
        ];
    }

    protected function execute(Request $request, ResourceRuntime $runtime, Authenticatable $user): array
    {
        $slug = $this->resourceSlug($request);

        return [
            'resource' => $runtime->describe($user, $slug),
        ];
    }
}
