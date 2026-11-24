<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Resources;

use Filament\Resources\Resource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Quansitech\Cmf\Mcp\Resources\Contracts\ResourceRepository;
use Quansitech\Cmf\Mcp\Resources\Contracts\ResourceScope;

/**
 * 单个 MCP 资源的不可变定义（由 cmf-mcp.resources 配置构建）。
 *
 * 字段与操作白名单在此集中校验，运行时只信任本对象，不再读取原始配置，
 * 从而保证未知字段、未启用的操作无法被绕过。
 */
final class ResourceDefinition
{
    /** 允许的操作名。 */
    public const OPERATIONS = ['viewAny', 'view', 'create', 'update', 'delete'];

    /**
     * @param  class-string<Model>  $modelClass
     * @param  class-string<resource>|null  $resourceClass
     * @param  list<string>  $readFields
     * @param  array<string, array<int, mixed>|string>  $writeRules
     * @param  list<string>  $operations
     * @param  class-string<ResourceScope>|null  $scopeClass
     * @param  class-string<ResourceRepository>|null  $repositoryClass
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $modelClass,
        public readonly ?string $resourceClass,
        public readonly array $readFields,
        public readonly array $writeRules,
        public readonly array $operations,
        public readonly ?string $scopeClass,
        public readonly ?string $repositoryClass,
        public readonly ?string $description = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $slug, array $config): self
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/', $slug)) {
            throw new InvalidArgumentException('Resource slug must contain only letters, numbers, hyphens, or underscores.');
        }

        $model = $config['model'] ?? null;

        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException("Resource [{$slug}] must define a valid Eloquent model class.");
        }

        $resource = $config['resource'] ?? null;

        if ($resource !== null) {
            if (! is_string($resource) || ! class_exists($resource)
                || ! is_subclass_of($resource, Resource::class)
                || $resource::getModel() !== $model) {
                throw new InvalidArgumentException("Resource [{$slug}] must reference a Filament Resource for the configured model.");
            }
        }

        $read = self::stringList($config['read'] ?? [], "Resource [{$slug}] read");
        $write = self::writeRules($config['write'] ?? [], $slug);
        $operations = self::operations($config['operations'] ?? null, $read, $slug);

        $scope = $config['scope'] ?? null;

        if ($scope !== null && (! is_string($scope) || ! class_exists($scope) || ! is_subclass_of($scope, ResourceScope::class))) {
            throw new InvalidArgumentException("Resource [{$slug}] scope must implement ".ResourceScope::class.'.');
        }

        $repository = $config['repository'] ?? null;

        if ($repository !== null && (! is_string($repository) || ! class_exists($repository) || ! is_subclass_of($repository, ResourceRepository::class))) {
            throw new InvalidArgumentException("Resource [{$slug}] repository must implement ".ResourceRepository::class.'.');
        }

        $description = $config['description'] ?? null;

        if ($description !== null && ! is_string($description)) {
            throw new InvalidArgumentException("Resource [{$slug}] description must be a string.");
        }

        $description = is_string($description) ? trim($description) : null;

        return new self($slug, $model, $resource, $read, $write, $operations, $scope, $repository, $description !== null && $description !== '' ? $description : null);
    }

    public function newModel(): Model
    {
        return new ($this->modelClass)();
    }

    /**
     * 构建已应用作用域的基础查询。
     *
     * Filament Resource::getEloquentQuery()（若配置）作为基础查询，其 WHERE 条件会被
     * 归入一个嵌套分组；随后 ResourceScope 以同样方式追加 AND 约束，避免调用方
     * 传入的过滤/排序通过 OR 绕过资源作用域。
     *
     * @return Builder<*>
     */
    public function baseQuery(Authenticatable $user): Builder
    {
        $query = $this->resourceClass !== null
            ? $this->resourceClass::getEloquentQuery()
            : $this->modelClass::query();

        $base = $query->getQuery();

        if ($base->wheres !== []) {
            $nested = $base->forNestedWhere();
            $nested->wheres = $base->wheres;
            $nested->setBindings($base->getRawBindings()['where'], 'where');
            $base->wheres = [];
            $base->setBindings([], 'where');
            $base->addNestedWhereQuery($nested);
        }

        if ($this->scopeClass !== null) {
            $query->where(function (Builder $constraints) use ($user): Builder {
                return app($this->scopeClass)->apply($constraints, $user);
            });
        }

        return $query;
    }

    public function hasRead(): bool
    {
        return $this->readFields !== [];
    }

    public function hasWrite(): bool
    {
        return $this->writeRules !== [];
    }

    public function canViewAny(): bool
    {
        return in_array('viewAny', $this->operations, true) && $this->hasRead();
    }

    public function canView(): bool
    {
        return in_array('view', $this->operations, true) && $this->hasRead();
    }

    public function canCreate(): bool
    {
        return in_array('create', $this->operations, true) && $this->hasWrite();
    }

    public function canUpdate(): bool
    {
        return in_array('update', $this->operations, true) && $this->hasWrite();
    }

    public function canDelete(): bool
    {
        return in_array('delete', $this->operations, true);
    }

    public function isReadable(string $field): bool
    {
        return in_array($field, $this->readFields, true);
    }

    public function isWritable(string $field): bool
    {
        return array_key_exists($field, $this->writeRules);
    }

    /**
     * 当前模型实例上实际可读取的字段（叠加 hidden/visible）。
     *
     * @return list<string>
     */
    public function readableFields(Model $record): array
    {
        $fields = array_diff($this->readFields, $record->getHidden());
        $visible = $record->getVisible();

        return array_values($visible === [] ? $fields : array_intersect($fields, $visible));
    }

    /**
     * 按读取白名单序列化记录，绝不输出白名单外的字段（含隐藏/敏感字段）。
     *
     * @return array<string, mixed>
     */
    public function serialize(Model $record): array
    {
        $data = [];

        foreach ($this->readableFields($record) as $field) {
            if ($record->hasAttribute($field)) {
                $data[$field] = $record->getAttributeValue($field);
            }
        }

        return $data;
    }

    public function label(): string
    {
        return $this->resourceClass !== null
            ? $this->resourceClass::getModelLabel()
            : class_basename($this->modelClass);
    }

    public function pluralLabel(): string
    {
        return $this->resourceClass !== null
            ? $this->resourceClass::getPluralModelLabel()
            : class_basename($this->modelClass);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, string $label): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("{$label} must be an array of field names.");
        }

        $fields = [];

        foreach ($value as $field) {
            if (! is_string($field) || ! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $field)) {
                throw new InvalidArgumentException("{$label} must only contain simple field names.");
            }

            $fields[] = $field;
        }

        return array_values(array_unique($fields));
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    private static function writeRules(mixed $value, string $slug): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("Resource [{$slug}] write must be a map of field => validation rules.");
        }

        $rules = [];

        foreach ($value as $field => $fieldRules) {
            if (! is_string($field) || ! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $field)) {
                throw new InvalidArgumentException("Resource [{$slug}] write field names must be simple field names.");
            }

            if (! is_string($fieldRules) && ! is_array($fieldRules)) {
                throw new InvalidArgumentException("Resource [{$slug}] write rules for [{$field}] must be a string or array.");
            }

            $rules[$field] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @param  list<string>  $read
     * @return list<string>
     */
    private static function operations(mixed $value, array $read, string $slug): array
    {
        if ($value === null) {
            return $read === [] ? [] : ['viewAny', 'view'];
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("Resource [{$slug}] operations must be a list.");
        }

        $operations = [];

        foreach ($value as $operation) {
            if (! is_string($operation) || ! in_array($operation, self::OPERATIONS, true)) {
                throw new InvalidArgumentException("Resource [{$slug}] has an unknown operation.");
            }

            $operations[] = $operation;
        }

        return array_values(array_unique($operations));
    }
}
