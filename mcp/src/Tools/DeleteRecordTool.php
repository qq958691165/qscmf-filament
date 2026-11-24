<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Quansitech\Cmf\Mcp\Resources\ResourceRuntime;

/**
 * 删除记录（需要 write 令牌 + delete Policy）。
 */
final class DeleteRecordTool extends CmfTool
{
    protected string $name = 'delete_record';

    protected string $description = '删除一条记录，需要读写令牌和该记录的删除权限。';

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
            'result' => $runtime->deleteRecord($user, $slug, $key),
        ];
    }
}
