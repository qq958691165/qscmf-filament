<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Support;

use Filament\Panel;
use Filament\Resources\Resource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * 读取并校验 config('cmf-agent.read_resources') 的资源定义。
 *
 * 所有工具都只通过这里拿模型、字段白名单和可筛选条件，避免把任意表名或
 * 列名暴露为工具参数。
 */
final class ResourceConfig
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $resources = config('cmf-agent.read_resources', []);

        return is_array($resources) ? $resources : [];
    }

    /**
     * @return array{
     *     model: class-string<Model>,
     *     fields: list<string>,
     *     per_page: int,
     *     resource: class-string<resource>|null,
     *     label: string,
     *     search_fields: list<string>,
     *     filters: array<string, array{column: string, operator: string}>,
     *     sortable: list<string>,
     *     default_sort: array{0: string, 1: string}
     * }
     */
    public static function get(string $slug): array
    {
        $definition = self::all()[$slug] ?? null;

        if (! is_array($definition) || ! isset($definition['model']) || ! is_string($definition['model']) || ! is_subclass_of($definition['model'], Model::class)) {
            throw new RuntimeException('无法查询这个资源。');
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $fields = self::strings($definition['fields'] ?? []);

        if ($fields === []) {
            throw new RuntimeException('无法查询这个资源。');
        }

        $resource = $definition['resource'] ?? null;

        if ($resource !== null && (! is_string($resource) || ! is_subclass_of($resource, Resource::class) || $resource::getModel() !== $modelClass)) {
            throw new RuntimeException('无法查询这个资源。');
        }

        /** @var class-string<resource>|null $resource */
        $perPage = max(1, min(100, (int) ($definition['per_page'] ?? 20)));

        // 搜索字段只能是输出字段的子集，避免通过关键词探测不可见字段。
        $searchFields = array_values(array_intersect(self::strings($definition['search_fields'] ?? []), $fields));

        $filters = [];

        foreach ((array) ($definition['filters'] ?? []) as $name => $filter) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            if (is_string($filter)) {
                $filter = ['column' => $filter, 'operator' => '='];
            }

            if (! is_array($filter) || ! is_string($filter['column'] ?? null) || $filter['column'] === '') {
                throw new RuntimeException('资源筛选配置无效。');
            }

            $operator = (string) ($filter['operator'] ?? '=');

            if (! in_array($operator, ['=', '!=', '>', '>=', '<', '<=', 'like'], true)) {
                throw new RuntimeException('资源筛选配置无效。');
            }

            $filters[$name] = ['column' => $filter['column'], 'operator' => $operator];
        }

        $sortable = self::strings($definition['sortable'] ?? []);

        if ($sortable === []) {
            $sortable = [(new $modelClass)->getKeyName()];
        }

        $defaultSort = $definition['default_sort'] ?? [$sortable[0], 'desc'];

        if (! is_array($defaultSort) || count($defaultSort) < 2 || ! is_string($defaultSort[0]) || ! in_array((string) $defaultSort[1], ['asc', 'desc'], true)) {
            $defaultSort = [$sortable[0], 'desc'];
        }

        if (! in_array($defaultSort[0], $sortable, true)) {
            $defaultSort = [$sortable[0], 'desc'];
        }

        $label = $definition['label'] ?? (is_string($resource) ? (string) $resource::getModelLabel() : class_basename($modelClass));

        if (! is_string($label) || trim($label) === '') {
            $label = $slug;
        }

        return [
            'model' => $modelClass,
            'fields' => $fields,
            'per_page' => $perPage,
            'resource' => $resource,
            'label' => $label,
            'search_fields' => $searchFields,
            'filters' => $filters,
            'sortable' => $sortable,
            'default_sort' => [$defaultSort[0], (string) $defaultSort[1]],
        ];
    }

    /**
     * 当前用户在当前面板可查询的资源：先看 Resource 是否注册到面板，再看 viewAny 策略。
     *
     * @return array<string, array<string, mixed>>
     */
    public static function accessible(?Authenticatable $actor, ?Panel $panel): array
    {
        if ($actor === null || $panel === null) {
            return [];
        }

        $gate = Gate::forUser($actor);
        $resources = [];

        foreach (array_keys(self::all()) as $slug) {
            try {
                $definition = self::get($slug);
            } catch (RuntimeException) {
                continue;
            }

            if ($definition['resource'] !== null && ! in_array($definition['resource'], $panel->getResources(), true)) {
                continue;
            }

            if (! $gate->allows('viewAny', $definition['model'])) {
                continue;
            }

            $resources[$slug] = $definition;
        }

        return $resources;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }
}
