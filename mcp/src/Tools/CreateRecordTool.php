<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Quansitech\Cmf\Mcp\Resources\ResourceRuntime;

/**
 * 创建记录（需要 write 令牌 + create Policy）。
 */
final class CreateRecordTool extends CmfTool
{
    protected string $name = 'create_record';

    protected string $description = '创建一条记录，需要读写令牌和创建权限；仅接受可写字段并做校验。';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()
                ->description('资源标识：在 cmf-mcp.resources 中注册的 slug。')
                ->required(),
            'data' => $schema->object()
                ->description('可写字段到值的映射。')
                ->required(),
        ];
    }

    protected function execute(Request $request, ResourceRuntime $runtime, Authenticatable $user): array
    {
        $slug = $this->resourceSlug($request);
        $attributes = $this->dataAttributes($request);

        return [
            'resource' => $slug,
            'record' => $runtime->createRecord($user, $slug, $attributes),
        ];
    }
}
