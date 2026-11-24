<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Quansitech\Cmf\Mcp\Resources\ResourceRuntime;

/**
 * 读取单条记录。
 */
final class GetRecordTool extends CmfTool
{
    protected string $name = 'get_record';

    protected string $description = '按主键读取单条记录，仅返回可读字段，并要求对该记录具备 view 权限。';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()
                ->description('资源标识：在 cmf-mcp.resources 中注册的 slug。')
                ->required(),
            'id' => $schema->union(['string', 'integer'])
                ->description('记录主键值。')
                ->required(),
        ];
    }

    protected function execute(Request $request, ResourceRuntime $runtime, Authenticatable $user): array
    {
        $slug = $this->resourceSlug($request);
        $key = $this->recordKey($request);

        return [
            'resource' => $slug,
            'record' => $runtime->getRecord($user, $slug, $key),
        ];
    }
}
