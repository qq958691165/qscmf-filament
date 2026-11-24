<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Support\ValidationMessages;
use Quansitech\Cmf\Mcp\Resources\Exceptions\ToolException;
use Quansitech\Cmf\Mcp\Resources\ResourceRuntime;
use Throwable;

/**
 * 所有 CMF MCP 工具的基类。
 *
 * 统一处理：当前用户解析、领域异常 -> 安全错误响应、校验异常 -> 字段消息、
 * 未预期异常 -> 通用提示（并上报日志，绝不把堆栈/敏感数据返回给调用方）。
 */
abstract class CmfTool extends Tool
{
    public function handle(Request $request): Response
    {
        try {
            $user = $request->user();

            if (! $user instanceof Authenticatable) {
                throw new ToolException('Authentication required.');
            }

            return Response::json($this->execute($request, app(ResourceRuntime::class), $user));
        } catch (ToolException $exception) {
            return Response::error($exception->getMessage());
        } catch (ValidationException $exception) {
            return Response::error(ValidationMessages::from($exception));
        } catch (Throwable $exception) {
            // 数据库异常消息会内联 SQL 绑定值，上报前先脱敏，避免提交内容进入日志。
            // 自定义 Repository 可能把 QueryException 包在其它异常里，因此沿异常链查找。
            $queryException = $this->queryExceptionFrom($exception);

            report($queryException !== null
                ? new \RuntimeException('Database query failed with SQLSTATE '.$queryException->getCode().'.', 0)
                : $exception);

            return Response::error('The operation failed.');
        }
    }

    /**
     * 沿异常链查找数据库查询异常，避免异常被 Repository 包装后漏掉脱敏。
     */
    protected function queryExceptionFrom(Throwable $exception): ?QueryException
    {
        do {
            if ($exception instanceof QueryException) {
                return $exception;
            }
        } while (($exception = $exception->getPrevious()) !== null);

        return null;
    }

    /**
     * 执行工具逻辑并返回可 JSON 序列化的数据。
     *
     * @return array<string, mixed>
     */
    abstract protected function execute(Request $request, ResourceRuntime $runtime, Authenticatable $user): array;

    /**
     * 读取必填的 resource 参数（slug）。
     */
    protected function resourceSlug(Request $request): string
    {
        $validated = $request->validate([
            'resource' => ['required', 'string'],
        ]);

        return (string) $validated['resource'];
    }

    /**
     * 读取记录主键（字符串或整数）。
     */
    protected function recordKey(Request $request): string|int
    {
        $key = $request->get('id');

        if (is_int($key)) {
            return $key;
        }

        if (is_string($key) && $key !== '') {
            return $key;
        }

        if (is_float($key) && floor($key) === $key) {
            return (int) $key;
        }

        throw new ToolException('The [id] argument is required.');
    }

    /**
     * 读取写入字段映射（JSON 对象）。
     *
     * @return array<string, mixed>
     */
    protected function dataAttributes(Request $request): array
    {
        $data = $request->get('data');

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ToolException('The [data] argument must be an object of field values.');
        }

        return $data;
    }
}
