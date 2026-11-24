<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Resources;

use InvalidArgumentException;
use Throwable;

/**
 * 资源注册表：把 cmf-mcp.resources 配置解析为 ResourceDefinition 集合。
 *
 * 采用惰性构建并按 slug 缓存。单个资源配置有误时仅记录日志并跳过该条，
 * 不影响其它资源，也不会把内部异常细节返回给调用方。
 */
final class ResourceRegistry
{
    /**
     * @var array<string, ResourceDefinition>|null
     */
    private ?array $definitions = null;

    /**
     * @return array<string, ResourceDefinition>
     */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];
        $configured = config('cmf-mcp.resources', []);

        if (! is_array($configured)) {
            report(new InvalidArgumentException('cmf-mcp.resources must be an array.'));

            return $this->definitions = [];
        }

        foreach ($configured as $slug => $config) {
            if (! is_string($slug) || ! is_array($config)) {
                report(new InvalidArgumentException('Invalid cmf-mcp resource entry; expected slug => array.'));

                continue;
            }

            try {
                $definitions[$slug] = ResourceDefinition::fromConfig($slug, $config);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $this->definitions = $definitions;
    }

    public function find(string $slug): ?ResourceDefinition
    {
        return $this->all()[$slug] ?? null;
    }

    public function has(string $slug): bool
    {
        return $this->find($slug) !== null;
    }
}
