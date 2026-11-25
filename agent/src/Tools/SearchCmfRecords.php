<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Tools;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Mcp\AgentTool;
use Quansitech\Cmf\Agent\Support\ResourceConfig;
use RuntimeException;
use Throwable;

#[IsReadOnly]
final class SearchCmfRecords extends AgentTool
{
    protected string $name = 'search_cmf_records';

    protected string $description = '按关键词、筛选条件、排序和分页查询 CMF 资源记录。';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()
                ->enum(array_keys(ResourceConfig::all()))
                ->required()
                ->description('资源 slug，可先用 describe_cmf_resource 查看可用值。'),
            'keyword' => $schema->string()
                ->description('在资源配置的 search_fields 中做模糊匹配。'),
            'filters' => $schema->object()
                ->description('筛选条件：键为资源配置的筛选名，值为字符串或数字。'),
            'sort' => $schema->string()
                ->description('排序字段；必须是资源配置的 sortable 之一。'),
            'direction' => $schema->string()
                ->enum(['asc', 'desc'])
                ->description('排序方向，默认 desc。'),
            'page' => $schema->integer()->min(1)->description('页码，默认 1。'),
            'per_page' => $schema->integer()->min(1)->max(100)->description('每页条数，默认取资源配置。'),
        ];
    }

    public function shouldRegister(): bool
    {
        return ResourceConfig::all() !== [] && parent::shouldRegister();
    }

    protected function run(Request $request): array
    {
        try {
            return $this->query($request);
        } catch (QueryException) {
            Log::warning('CMF Agent database query failed.', ['tool' => $this->name()]);

            throw new RuntimeException('查询失败，请检查资源访问权限或缩小查询范围。');
        }
    }

    private function query(Request $request): array
    {
        $panel = Filament::getCurrentPanel();
        $actor = Filament::auth()->user();

        if ($panel === null || $panel->getId() !== config('cmf-agent.panel') || ! $actor instanceof Authenticatable) {
            throw new RuntimeException('当前用户无法查询这个资源。');
        }

        $args = $request->validate([
            'resource' => 'required|string',
            'keyword' => 'sometimes|nullable|string|max:100',
            'filters' => 'sometimes|nullable|array',
            'sort' => 'sometimes|nullable|string|max:64',
            'direction' => 'sometimes|nullable|in:asc,desc',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $definition = ResourceConfig::get((string) $args['resource']);
        $gate = Gate::forUser($actor);

        if (($definition['resource'] !== null && ! in_array($definition['resource'], $panel->getResources(), true)) || ! $gate->allows('viewAny', $definition['model'])) {
            throw new RuntimeException('当前用户无法查询这个资源。');
        }

        $query = $definition['resource'] !== null
            ? $definition['resource']::getEloquentQuery()
            : $definition['model']::query();

        $query->reorder();
        $this->applyKeyword($query, $definition, (string) ($args['keyword'] ?? ''));
        $this->applyFilters($query, $definition, (array) ($args['filters'] ?? []));

        [$sort, $direction] = $this->sort($definition, $args);
        $query->orderBy($sort, $direction);
        $query->orderBy($query->getModel()->getQualifiedKeyName(), 'desc');

        $perPage = max(1, min(100, (int) ($args['per_page'] ?? $definition['per_page'])));
        $page = max(1, (int) ($args['page'] ?? 1));
        $skip = ($page - 1) * $perPage;
        $scanLimit = max(1, min(PHP_INT_MAX - 1, (int) config('cmf-agent.max_scan_rows', 5000)));
        $query->limit(min($query->getQuery()->limit ?? PHP_INT_MAX, $scanLimit + 1));
        $allowedFields = array_flip($definition['fields']);
        $records = [];
        $scanned = 0;
        $skipped = 0;

        try {
            foreach ($query->cursor() as $record) {
                if (++$scanned > $scanLimit) {
                    throw new RuntimeException('查询超出扫描上限，请增加筛选条件。');
                }

                if (! $gate->allows('view', $record)) {
                    continue;
                }

                if ($skipped < $skip) {
                    $skipped++;

                    continue;
                }

                $records[] = array_intersect_key($record->attributesToArray(), $allowedFields);

                if (count($records) > $perPage) {
                    break;
                }
            }
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RuntimeException('查询失败，请检查资源访问权限或缩小查询范围。');
        }

        return [
            'resource' => $args['resource'],
            'page' => $page,
            'per_page' => $perPage,
            'sort' => $sort,
            'direction' => $direction,
            'records' => array_slice($records, 0, $perPage),
            'has_more' => count($records) > $perPage,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function applyKeyword(Builder $query, array $definition, string $keyword): void
    {
        if ($keyword === '') {
            return;
        }

        if ($definition['search_fields'] === []) {
            throw new RuntimeException('这个资源不支持关键词搜索。');
        }

        $query->where(function (Builder $query) use ($definition, $keyword): void {
            foreach ($definition['search_fields'] as $field) {
                $query->orWhere($field, 'like', '%'.$keyword.'%');
            }
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $definition, array $filters): void
    {
        foreach ($filters as $name => $value) {
            if (! is_string($name) || ! isset($definition['filters'][$name])) {
                throw new RuntimeException('不支持的筛选条件：'.$name);
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_scalar($value)) {
                throw new RuntimeException('筛选条件必须是字符串或数字。');
            }

            $filter = $definition['filters'][$name];
            $operator = $filter['operator'];
            $query->where($filter['column'], $operator, $operator === 'like' ? '%'.$value.'%' : $value);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $args
     * @return array{0: string, 1: string}
     */
    private function sort(array $definition, array $args): array
    {
        $sort = isset($args['sort']) && $args['sort'] !== '' ? (string) $args['sort'] : $definition['default_sort'][0];

        if (! in_array($sort, $definition['sortable'], true)) {
            throw new RuntimeException('不支持的排序字段：'.$sort);
        }

        $direction = isset($args['direction']) && $args['direction'] !== ''
            ? (string) $args['direction']
            : ($sort === $definition['default_sort'][0] ? $definition['default_sort'][1] : 'desc');

        return [$sort, $direction];
    }
}
