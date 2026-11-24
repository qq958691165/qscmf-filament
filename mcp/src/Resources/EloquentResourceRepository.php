<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Resources;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\Mcp\Resources\Contracts\ResourceRepository;
use Quansitech\Cmf\Mcp\Resources\Exceptions\ToolException;

final class EloquentResourceRepository implements ResourceRepository
{
    public function create(ResourceDefinition $definition, Authenticatable $user, array $attributes): Model
    {
        $record = $definition->newModel();

        return $this->update($definition, $user, $record, $attributes);
    }

    public function update(ResourceDefinition $definition, Authenticatable $user, Model $record, array $attributes): Model
    {
        if (! $record->fill($attributes)->save()) {
            throw new ToolException('The record could not be saved.');
        }

        return $record;
    }

    public function delete(ResourceDefinition $definition, Authenticatable $user, Model $record): void
    {
        if ($record->delete() !== true) {
            throw new ToolException('The record could not be deleted.');
        }
    }
}
