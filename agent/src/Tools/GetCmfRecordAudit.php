<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Tools;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Mcp\AgentTool;
use Quansitech\Cmf\Agent\Support\ResourceConfig;
use RuntimeException;
use Throwable;

#[IsReadOnly]
final class GetCmfRecordAudit extends AgentTool
{
    protected string $name = 'get_cmf_record_audit';

    protected string $description = '查询一条 CMF 记录的审计历史，查看谁在何时修改了哪些字段。';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()
                ->enum(array_keys(ResourceConfig::all()))
                ->required()
                ->description('资源 slug。'),
            'id' => $schema->string()
                ->required()
                ->description('记录 ID。'),
            'limit' => $schema->integer()
                ->min(1)
                ->max(100)
                ->description('最多返回多少条，默认 50。'),
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

            throw new RuntimeException('无法查询这条记录的审计历史。');
        }
    }

    private function query(Request $request): array
    {
        $panel = Filament::getCurrentPanel();
        $actor = Filament::auth()->user();

        if ($panel === null || $panel->getId() !== config('cmf-agent.panel') || ! $actor instanceof Authenticatable) {
            throw new RuntimeException('当前用户无法查看这个资源的审计记录。');
        }

        $args = $request->validate([
            'resource' => 'required|string',
            'id' => 'required',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $definition = ResourceConfig::get((string) $args['resource']);
        $gate = Gate::forUser($actor);

        if (! $gate->allows('ViewAny:Audit')
            || ($definition['resource'] !== null && ! in_array($definition['resource'], $panel->getResources(), true))
            || ! $gate->allows('viewAny', $definition['model'])) {
            throw new RuntimeException('当前用户无法查看这个资源的审计记录。');
        }

        $query = $definition['resource'] !== null
            ? $definition['resource']::getEloquentQuery()
            : $definition['model']::query();

        $record = $query->find($args['id']);

        if (! $record instanceof Model || ! $gate->allows('view', $record) || ! $gate->allows('audit', $record)) {
            throw new RuntimeException('当前用户无法查看这条记录。');
        }

        $table = (string) config('audit.drivers.database.table', 'audits');

        if (! Schema::hasTable($table)) {
            return [
                'resource' => $args['resource'],
                'id' => $record->getKey(),
                'audits' => [],
                'has_more' => false,
            ];
        }

        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));
        $rows = DB::table($table)
            ->where('auditable_type', $record->getMorphClass())
            ->where('auditable_id', $record->getKey())
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $allowedFields = array_flip($definition['fields']);
        $audits = [];

        foreach ($rows as $row) {
            $audits[] = [
                'id' => $row->id,
                'event' => $row->event,
                'user' => $this->user($row),
                'created_at' => $row->created_at,
                'old' => array_intersect_key($this->decode($row->old_values ?? null), $allowedFields),
                'new' => array_intersect_key($this->decode($row->new_values ?? null), $allowedFields),
            ];
        }

        return [
            'resource' => $args['resource'],
            'id' => $record->getKey(),
            'audits' => array_slice($audits, 0, $limit),
            'has_more' => count($rows) > $limit,
        ];
    }

    /** @return array<string, mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{id: mixed, name?: string}
     */
    private function user(object $row): array
    {
        $userId = $row->user_id ?? null;
        $userType = $row->user_type ?? null;
        $user = ['id' => $userId];

        if ($userId === null || ! is_string($userType) || ! class_exists($userType) || ! is_subclass_of($userType, Model::class)) {
            return $user;
        }

        try {
            $model = $userType::query()->find($userId);

            if ($model instanceof Model && is_string($model->getAttribute('name'))) {
                $user['name'] = $model->getAttribute('name');
            }
        } catch (Throwable) {
            // 审计用户已被删除或模型不可用时只返回 user_id。
        }

        return $user;
    }
}
