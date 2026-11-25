<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Tools;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Mcp\AgentTool;
use Quansitech\Cmf\Agent\Support\ResourceConfig;
use RuntimeException;

#[IsReadOnly]
final class DescribeCmfResource extends AgentTool
{
    protected string $name = 'describe_cmf_resource';

    protected string $description = '查看当前用户可访问的 CMF 资源、字段和可用查询条件。';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()
                ->enum(array_keys(ResourceConfig::all()))
                ->description('资源 slug；不填时返回全部可访问资源。'),
        ];
    }

    public function shouldRegister(): bool
    {
        return ResourceConfig::all() !== [] && parent::shouldRegister();
    }

    protected function run(Request $request): array
    {
        $panel = Filament::getCurrentPanel();
        $actor = Filament::auth()->user();

        if ($panel === null || $panel->getId() !== config('cmf-agent.panel') || ! $actor instanceof Authenticatable) {
            throw new RuntimeException('当前用户无法查看资源说明。');
        }

        $args = $request->validate(['resource' => 'sometimes|string']);
        $resources = ResourceConfig::accessible($actor, $panel);

        if (isset($args['resource']) && $args['resource'] !== '') {
            $definition = $resources[$args['resource']] ?? null;

            if ($definition === null) {
                throw new RuntimeException('当前用户无法查看这个资源。');
            }

            return ['resource' => self::format((string) $args['resource'], $definition)];
        }

        $items = [];

        foreach ($resources as $slug => $definition) {
            $items[] = self::format($slug, $definition);
        }

        return ['resources' => $items];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private static function format(string $slug, array $definition): array
    {
        $filters = [];

        foreach ($definition['filters'] as $name => $filter) {
            $filters[$name] = $filter;
        }

        return [
            'slug' => $slug,
            'label' => $definition['label'],
            'fields' => $definition['fields'],
            'search_fields' => $definition['search_fields'],
            'filters' => $filters,
            'sortable' => $definition['sortable'],
            'default_sort' => $definition['default_sort'],
            'per_page' => $definition['per_page'],
        ];
    }
}
