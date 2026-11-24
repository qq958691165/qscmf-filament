<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Quansitech\Cmf\Mcp\Resources\ResourceRuntime;

/**
 * 更新记录（需要 write 令牌 + update Policy，支持部分字段更新）。
 */
final class UpdateRecordTool extends CmfTool
{
    protected string $name = 'update_record';

    protected string $description = '更新一条记录，需要读写令牌和更新权限；支持部分更新，仅校验并修改提交的字段。';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()
                ->description('资源标识：在 cmf-mcp.resources 中注册的 slug。')
                ->required(),
            'id' => $schema->union(['string', 'integer'])
                ->description('记录主键值。')
                ->required(),
            'data' => $schema->object()
                ->description('可写字段到值的映射；只修改提交的字段。')
                ->required(),
        ];
    }

    protected function execute(Request $request, ResourceRuntime $runtime, Authenticatable $user): array
    {
        $slug = $this->resourceSlug($request);
        $key = $this->recordKey($request);
        $attributes = $this->dataAttributes($request);

        return [
            'resource' => $slug,
            'record' => $runtime->updateRecord($user, $slug, $key, $attributes),
        ];
    }
}
