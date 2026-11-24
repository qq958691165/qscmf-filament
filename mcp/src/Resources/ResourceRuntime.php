<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Resources;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Unique;
use Quansitech\Cmf\Mcp\Resources\Contracts\ResourceRepository;
use Quansitech\Cmf\Mcp\Resources\Exceptions\ToolException;

final class ResourceRuntime
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly EloquentResourceRepository $defaultRepository,
        private readonly ResourceAuthorizer $authorizer,
    ) {}

    public function listResources(Authenticatable $user): array
    {
        $resources = [];

        foreach ($this->registry->all() as $definition) {
            $description = $this->describeForUser($user, $definition);

            if ($description !== null) {
                $resources[] = $description;
            }
        }

        return $resources;
    }

    public function describe(Authenticatable $user, string $slug): array
    {
        return $this->describeForUser($user, $this->definition($slug))
            ?? throw new ToolException('Resource not found.');
    }

    private function describeForUser(Authenticatable $user, ResourceDefinition $definition): ?array
    {
        if (! $this->authorizer->canAccess($user, $definition)) {
            return null;
        }

        $operations = [];
        $writeToken = $this->authorizer->hasWriteToken();
        $list = $definition->canViewAny() && $definition->canView()
            && $this->authorizer->allows($user, $definition, 'viewAny');
        $create = $writeToken && $definition->canCreate() && $this->authorizer->allows($user, $definition, 'create');

        if (! $list && ! $create) {
            return null;
        }

        if ($list) {
            $operations[] = 'viewAny';
            $operations[] = 'view';
        }

        if ($create) {
            $operations[] = 'create';
        }

        if ($writeToken && $definition->canUpdate()) {
            $operations[] = 'update';
        }

        if ($writeToken && $definition->canDelete()) {
            $operations[] = 'delete';
        }

        $model = $definition->newModel();
        $writeFields = [];

        if ($create || in_array('update', $operations, true)) {
            $writeFields = array_values(array_diff(array_keys($definition->writeRules), $model->getHidden()));
            $writeFields = array_values(array_filter($writeFields, $model->isFillable(...)));
        }

        return [
            'slug' => $definition->slug,
            'label' => $definition->label(),
            'plural_label' => $definition->pluralLabel(),
            'description' => $definition->description ?? '',
            'operations' => $operations,
            'read_fields' => $list ? $definition->readableFields($model) : [],
            'write_fields' => $writeFields,
        ];
    }

    public function listRecords(
        Authenticatable $user,
        string $slug,
        int $page = 1,
        int $perPage = 15,
        ?string $sort = null,
        string $direction = 'asc',
        array $filter = [],
    ): array {
        $definition = $this->definition($slug);

        if (! $definition->canViewAny() || ! $definition->canView()
            || ! $this->authorizer->allows($user, $definition, 'viewAny')
            || ! $this->authorizer->canAccess($user, $definition)) {
            throw new ToolException('You are not allowed to perform this action.');
        }

        $query = $definition->baseQuery($user);
        $model = $query->getModel();
        $readableFields = $definition->readableFields($model);
        $queryableFields = [];

        if ($filter !== [] || $sort !== null) {
            $columns = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
            $queryableFields = array_values(array_intersect($readableFields, $columns));
        }

        foreach ($filter as $field => $value) {
            if (! is_string($field) || ! in_array($field, $queryableFields, true)) {
                throw new ToolException('Unknown filter field.');
            }

            $column = $model->qualifyColumn($field);

            if (is_array($value)) {
                if (! array_is_list($value) || count($value) > 100 || array_filter($value, static fn ($item): bool => ! is_scalar($item)) !== []) {
                    throw new ToolException('A filter list must contain at most 100 scalar values.');
                }

                $query->whereIn($column, $value);
            } else {
                if (! is_scalar($value)) {
                    throw new ToolException('A filter value must be scalar or a list of scalars.');
                }

                $query->where($column, $value);
            }
        }

        if ($sort !== null) {
            if (! in_array($sort, $queryableFields, true) || ! in_array($direction, ['asc', 'desc'], true)) {
                throw new ToolException('Invalid sort field or direction.');
            }

            $query->reorder($model->qualifyColumn($sort), $direction);
        }

        $query->orderBy($model->getQualifiedKeyName());
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $authorizedPage = 1;
        $authorizedCount = 0;
        $records = [];
        $hasMore = false;
        $maxScan = max(1, (int) config('cmf-mcp.max_scan_rows', 5000));
        $scanned = 0;

        // Count only authorized rows so page metadata cannot reveal denied records.
        foreach ($query->lazy(100) as $record) {
            // 逐行授权过滤的成本随扫描行数增长，超过上限即中止，避免大表全表扫描。
            if (++$scanned > $maxScan) {
                throw new ToolException('The query matches too many records to paginate; add filters or narrow the range.');
            }

            if (! $this->authorizer->allows($user, $definition, 'view', $record)) {
                continue;
            }

            if ($authorizedPage < $page) {
                if (++$authorizedCount === $perPage) {
                    $authorizedPage++;
                    $authorizedCount = 0;
                }

                continue;
            }

            if (count($records) === $perPage) {
                $hasMore = true;
                break;
            }

            $records[] = $definition->serialize($record);
        }

        return [
            'records' => $records,
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'has_more' => $hasMore],
        ];
    }

    public function getRecord(Authenticatable $user, string $slug, string|int $key): array
    {
        $definition = $this->definition($slug);

        if (! $definition->canView() || ! $this->authorizer->canAccess($user, $definition)) {
            throw new ToolException('Record not found.');
        }

        return $definition->serialize($this->authorizedRecord($user, $definition, 'view', $definition->baseQuery($user), $key));
    }

    public function createRecord(Authenticatable $user, string $slug, array $attributes): array
    {
        $this->authorizer->ensureWriteToken();
        $definition = $this->definition($slug);

        if (! $definition->canCreate() || ! $this->authorizer->canAccess($user, $definition)
            || ! $this->authorizer->allows($user, $definition, 'create')) {
            throw new ToolException('You are not allowed to perform this action.');
        }

        return $definition->newModel()->getConnection()->transaction(function () use ($user, $definition, $attributes): array {
            $attributes = $this->prepareAttributes($definition, $definition->newModel(), $attributes, true);
            $record = $this->repository($definition)->create($definition, $user, $attributes);

            return $this->writeResult($user, $definition, $record);
        });
    }

    public function updateRecord(Authenticatable $user, string $slug, string|int $key, array $attributes): array
    {
        $this->authorizer->ensureWriteToken();
        $definition = $this->definition($slug);

        if (! $definition->canUpdate() || ! $this->authorizer->canAccess($user, $definition)) {
            throw new ToolException('Record not found.');
        }

        return $definition->newModel()->getConnection()->transaction(function () use ($user, $definition, $key, $attributes): array {
            $record = $this->authorizedRecord($user, $definition, 'update', $definition->baseQuery($user)->lockForUpdate(), $key);
            $attributes = $this->prepareAttributes($definition, $record, $attributes, false);
            $record = $this->repository($definition)->update($definition, $user, $record, $attributes);

            return $this->writeResult($user, $definition, $record);
        });
    }

    public function deleteRecord(Authenticatable $user, string $slug, string|int $key): array
    {
        $this->authorizer->ensureWriteToken();
        $definition = $this->definition($slug);

        if (! $definition->canDelete() || ! $this->authorizer->canAccess($user, $definition)) {
            throw new ToolException('Record not found.');
        }

        return $definition->newModel()->getConnection()->transaction(function () use ($user, $definition, $key): array {
            $record = $this->authorizedRecord($user, $definition, 'delete', $definition->baseQuery($user)->lockForUpdate(), $key);
            $this->repository($definition)->delete($definition, $user, $record);

            return ['deleted' => true, 'id' => $key];
        });
    }

    private function definition(string $slug): ResourceDefinition
    {
        return $this->registry->find($slug) ?? throw new ToolException('Resource not found.');
    }

    private function authorizedRecord(Authenticatable $user, ResourceDefinition $definition, string $ability, Builder $query, string|int $key): Model
    {
        $record = $this->find($query, $key);

        if ($record === null || ! $this->authorizer->allows($user, $definition, $ability, $record)) {
            throw new ToolException('Record not found.');
        }

        return $record;
    }

    private function find(Builder $query, string|int $key): ?Model
    {
        $model = $query->getModel();

        if (in_array($model->getKeyType(), ['int', 'integer'], true) && ! preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', (string) $key)) {
            return null;
        }

        $record = $query->where($model->getQualifiedKeyName(), $key)->first();

        return $record !== null && (string) $record->getKey() === (string) $key ? $record : null;
    }

    private function writeResult(Authenticatable $user, ResourceDefinition $definition, Model $record): array
    {
        $key = $record->getKey();
        $saved = (is_int($key) || is_string($key)) ? $this->find($definition->baseQuery($user), $key) : null;

        if ($saved === null) {
            throw new ToolException('The record is outside the resource scope.');
        }

        if (! $definition->canView() || ! $this->authorizer->allows($user, $definition, 'view', $saved)) {
            return [];
        }

        return $definition->serialize($saved);
    }

    private function repository(ResourceDefinition $definition): ResourceRepository
    {
        return $definition->repositoryClass === null ? $this->defaultRepository : app($definition->repositoryClass);
    }

    private function prepareAttributes(ResourceDefinition $definition, Model $model, array $attributes, bool $creating): array
    {
        foreach (array_keys($attributes) as $field) {
            if (! is_string($field) || ! $definition->isWritable($field) || ! $model->isFillable($field)) {
                throw new ToolException('The data contains an unknown or non-writable field.');
            }
        }

        $rules = $creating ? $definition->writeRules : array_intersect_key($definition->writeRules, $attributes);

        // 更新已存在记录时，唯一性规则需忽略当前记录本身，否则重新提交未变更的
        // 唯一字段（如 slug/sku/order_no）会被误判为重复。
        if (! $creating && $model->exists) {
            foreach ($rules as $field => $rule) {
                $rules[$field] = $this->withUniqueIgnore($rule, $model);
            }
        }

        return array_intersect_key(Validator::make($attributes, $rules)->validate(), $attributes);
    }

    /**
     * 为单个字段的规则补上“忽略当前记录主键”，兼容字符串（竖线分隔）与数组两种写法。
     */
    private function withUniqueIgnore(mixed $rule, Model $model): mixed
    {
        $key = $model->getKey();
        $keyName = $model->getKeyName();

        if ($key === null) {
            return $rule;
        }

        if (is_array($rule)) {
            return array_map(function (mixed $item) use ($key, $keyName): mixed {
                if ($item instanceof Unique) {
                    return $item->ignore($key, $keyName);
                }

                return is_string($item) ? $this->appendIgnoreToRule($item, $key, $keyName) : $item;
            }, $rule);
        }

        if ($rule instanceof Unique) {
            return $rule->ignore($key, $keyName);
        }

        return is_string($rule) ? $this->appendIgnoreToRule($rule, $key, $keyName) : $rule;
    }

    private function appendIgnoreToRule(string $rule, int|string $key, string $keyName): string
    {
        $segments = explode('|', $rule);

        foreach ($segments as $index => $segment) {
            if (! str_starts_with($segment, 'unique:')) {
                continue;
            }

            $parts = explode(',', substr($segment, 7));

            // 兼容 unique:table,column 与 unique:table,column,except,idColumn 两种形式。
            if (count($parts) === 2) {
                $segments[$index] = $segment.','.$key.','.$keyName;
            } elseif (count($parts) === 4) {
                $parts[2] = (string) $key;
                $parts[3] = $keyName;
                $segments[$index] = 'unique:'.implode(',', $parts);
            }
        }

        return implode('|', $segments);
    }
}
