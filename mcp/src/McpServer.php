<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp;

use Laravel\Mcp\Server;
use Quansitech\Cmf\Mcp\Tools\CreateRecordTool;
use Quansitech\Cmf\Mcp\Tools\DeleteRecordTool;
use Quansitech\Cmf\Mcp\Tools\DescribeResourceTool;
use Quansitech\Cmf\Mcp\Tools\GetRecordTool;
use Quansitech\Cmf\Mcp\Tools\ListRecordsTool;
use Quansitech\Cmf\Mcp\Tools\ListResourcesTool;
use Quansitech\Cmf\Mcp\Tools\UpdateRecordTool;

/**
 * CMF 通用 MCP 服务器。
 *
 * 仅注册七个通用工具，资源范围完全由配置 allowlist（cmf-mcp.resources）驱动，
 * 不含任何特定业务假设。通过 Laravel MCP 的 Mcp::web() 挂载。
 */
final class McpServer extends Server
{
    protected string $name = 'CMF MCP Server';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        本服务器对外提供一份按白名单开放的应用数据资源。

        使用流程：先调用 list_resources 查看可用资源，再用 describe_resource
        获取字段与可用操作。读取用 list_records / get_record；在权限允许时，
        可用 create_record / update_record / delete_record 写入。

        所有访问都受当前登录用户的策略（Policy）与已配置的资源范围限制；
        写操作需要读写范围的授权码。
    MARKDOWN;

    /**
     * @var array<int, class-string>
     */
    protected array $tools = [
        ListResourcesTool::class,
        DescribeResourceTool::class,
        ListRecordsTool::class,
        GetRecordTool::class,
        CreateRecordTool::class,
        UpdateRecordTool::class,
        DeleteRecordTool::class,
    ];
}
