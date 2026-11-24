<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Resources\Exceptions;

use RuntimeException;

/**
 * 可安全返回给 MCP 调用方的领域异常。
 *
 * 消息会原样出现在工具的错误结果中，因此绝不能包含敏感数据（密钥、密码、
 * 记录内容等）。未预期的异常由工具基类统一捕获并返回通用提示，避免泄露。
 */
final class ToolException extends RuntimeException {}
