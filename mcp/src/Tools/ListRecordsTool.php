<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Quansitech\Cmf\Mcp\Resources\ResourceRuntime;

/**
 * 分页列出记录。
 */
final class ListRecordsTool extends CmfTool
{
    protected string $name = 'list_records';

    protected string $description = '分页查询指定资源的记录，支持排序与等值过滤；排序、过滤字段限于可读字段，每条记录都会按 view 策略复核。';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()
                ->description('资源标识：在 cmf-mcp.resources 中注册的 slug。')
                ->required(),
            'page' => $schema->integer()
                ->description('页码，从 1 开始。')
                ->default(1),
            'per_page' => $schema->integer()
                ->description('每页记录数（1-100）。')
                ->default(15),
            'sort' => $schema->string()
                ->description('排序字段，必须是可读字段。'),
            'direction' => $schema->string()
                ->description('排序方向：asc（升序）或 desc（降序）。')
                ->enum(['asc', 'desc'])
                ->default('asc'),
            'filter' => $schema->object()
                ->description('按可读字段做等值过滤：标量表示等于，数组表示 IN。'),
        ];
    }

    protected function execute(Request $request, ResourceRuntime $runtime, Authenticatable $user): array
    {
        $slug = $this->resourceSlug($request);

        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 15);

        $sort = $request->get('sort');
        $sort = is_string($sort) && $sort !== '' ? $sort : null;

        $direction = strtolower((string) $request->get('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $filter = $request->get('filter', []);
        $filter = is_array($filter) ? $filter : [];

        return $runtime->listRecords($user, $slug, $page, $perPage, $sort, $direction, $filter);
    }
}
