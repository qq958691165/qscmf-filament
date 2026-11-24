# QS CMF MCP 模块

`quansitech/cmf-module-mcp` 为 CMF 的 Filament 面板提供 Streamable HTTP MCP 服务。每个授权码绑定一个后台用户，可设为只读或读写，并支持有效期和撤销。请求按该用户的身份执行，受应用的 Laravel Policy 和资源查询范围限制。

## 安装

需要 PHP 8.5+、Filament 5，以及兼容 `laravel/mcp` 1.2 的 Laravel 版本。模块依赖 CMF Core 和 Users。

```bash
composer require quansitech/cmf-module-mcp
php artisan vendor:publish --provider='Quansitech\Cmf\Mcp\CmfMcpServiceProvider'
php artisan migrate
php artisan shield:generate --all --option=permissions --panel=admin --no-interaction
```

新项目安装 CMF 时也可通过 `php artisan cmf:install` 发布配置并处理迁移。

默认启用非租户 `admin` 面板，端点为 `/mcp/admin`。在 `config/cmf-mcp.php` 的 `panels` 中配置启用的面板 ID。`middleware` 可追加限流等应用中间件。配置了 `resource` 的资源只有在其 Filament Resource 已注册到当前面板时才可访问，从而在多面板宿主中保持面板隔离。`max_scan_rows`（默认 5000）限制 `list_records` 单次扫描行数，超过会提示收窄筛选条件。`user_search_limit`（默认 50，设为 0 表示不限制）控制后台签发令牌时用户搜索返回的条数，50 只是建议值，非强制。多租户面板需要单独的租户绑定机制，目前不提供该入口。

## 配置资源

`resources` 默认为空，各启用面板共享这份配置。应用通过配置明确导出资源、返回字段和写入字段。示例中的模型和 Resource 由应用实现。

```php
'resources' => [
    'articles' => [
        'model' => \App\Models\Article::class,
        'resource' => \App\Filament\Resources\Articles\ArticleResource::class,
        'description' => '新闻文章，支持增删改查。',
        'read' => ['id', 'title', 'status', 'published_at'],
        'write' => [
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:draft,published'],
        ],
        'operations' => ['viewAny', 'view', 'create', 'update', 'delete'],
    ],
],
```

- `model` 必填。配置 `resource` 后，基于该 Filament Resource 的 `getEloquentQuery()` 查询，并使用它的显示名称。将对应的 Resource 注册到需要开放的面板。
- `description` 可选，为资源提供一句中文说明，会出现在 `list_resources` 与 `describe_resource` 的结果里；省略时为空字符串。
- `read` 是对外返回的字段白名单。省略 `operations` 时，配置了 `read` 的资源默认只开放 `viewAny` 和 `view`。列表也会逐条检查 `view`。`describe_resource` 列出的 `update` 和 `delete` 仍须通过目标记录的 Policy。
- 创建和更新只接受 `write` 中通过校验且可批量赋值的字段。更新为部分更新，只对本次提交的字段做校验；若字段间存在跨字段依赖规则（如 `required_with`、`same`、`confirmed`），请在同一次 `data` 中一并提交相关字段。更新时唯一性规则会自动忽略当前记录本身，重新提交未变更的唯一字段不会被判为重复。目前仅自动处理 `unique:表,列` 与 `unique:表,列,排除值,主键列` 两种形式；带附加 `where` 条件或自定义主键的规则需在自定义 Repository 中处理。需要关联更新、上传、领域服务或复杂校验的资源，可以通过实现 `Resources\Contracts\ResourceRepository` 定制读写流程。实现时保留资源查询范围、字段限制及业务约束。
- 可配置实现 `Resources\Contracts\ResourceScope` 的 `scope`，进一步限制每位用户可访问的记录。配置应用 Policy 后，再给角色分配相应权限。

| 工具 | 参数或用途 |
| --- | --- |
| `list_resources` | 查看当前账号可发现的资源 |
| `describe_resource` | `resource`，查看字段和可用操作 |
| `list_records` | `resource`，可选 `page`、`per_page`、`sort`、`direction`、`filter` |
| `get_record` | `resource`、`id` |
| `create_record` | `resource`、`data` |
| `update_record` | `resource`、`id`、`data` |
| `delete_record` | `resource`、`id` |

## 签发和撤销授权码

给管理者授予 `Manage:McpToken`（管理自己的授权码）。若允许为**其他用户**代签或管理授权码，还需单独授予 `ManageOthers:McpToken`（代签等同冒充，需显式授权），目标用户同时须在当前用户资源中可见；代签的授权码会记录签发人（`issued_by`）以便审计。如需从 Shield 角色页面分配这些自定义权限，将 `shield_resource.tabs.custom_permissions` 设为 `true`。管理员可在「MCP 授权」或用户编辑页的 MCP 授权栏目创建和撤销授权码。明文只在创建时显示一次，请立即复制到客户端。列表仅显示前缀及使用状态。需要更换授权码时，撤销旧码并重新签发。

## 配置客户端

把示例中的 `<网站域名>` 换成实际可访问的域名。生产环境通过 HTTPS 提供端点，客户端需能访问该地址。

### Claude Code

```bash
claude mcp add --transport http cmf-mcp https://<网站域名>/mcp/admin \
  --header 'Authorization: Bearer <授权码>'
```

### WorkBuddy

在 MCP 配置中合并以下连接。支持使用配置文件的版本可编辑 `~/.workbuddy/mcp.json`。

```json
{
  "mcpServers": {
    "cmf-mcp": {
      "type": "http",
      "url": "https://<网站域名>/mcp/admin",
      "headers": {"Authorization": "Bearer <授权码>"}
    }
  }
}
```

### 豆包工作

新增自定义 MCP 连接器，名称填 `CMF`，传输类型选 Streamable HTTP，填写端点 URL `https://<网站域名>/mcp/admin`，并添加自定义请求头 `Authorization`，值为 `Bearer <授权码>`。保存后可先请求列出有权查看的资源。

三个客户端都可以先用只读码验证 `list_resources` 与 `list_records`。如需让客户端写入数据，请向对应用户签发读写码并授予具体资源权限。

## Nginx 配置

MCP 走 Streamable HTTP，响应可能是流式的（SSE）。除常规 Laravel 站点配置外，需要给 `/mcp/` 关闭缓冲并放宽超时，否则客户端可能收不到流式分块或长连接被提前断开。

```nginx
server {
    listen 443 ssl http2;
    server_name <网站域名>;

    # ...

    location ~ \.php$ {
        # ...

        # 流式响应：关闭缓冲并放宽读超时
        fastcgi_buffering off;
        fastcgi_read_timeout 300s;
    }

    # ...
}
```

说明：

- 上面的 `fastcgi_buffering off;` 与 `fastcgi_read_timeout` 放在 PHP location，对整站生效；若只想对 `/mcp/` 生效，可把 `location ^~ /mcp/` 单独写成直接 `fastcgi_pass` 到 PHP-FPM 并设置这些参数。
- 反向代理/负载均衡后再接本机时，确保 Laravel 信任代理（`TrustProxies`），以便正确生成 `https` 链接。

## 审计

MCP 的写操作（create / update / delete）走 Eloquent 模型事件，接入 `owen-it/laravel-auditing`：
业务模型 `use OwenIt\Auditing\Auditable` 并实现 `OwenIt\Auditing\Contracts\Auditable` 后，
MCP 写入会在 `audits` 表生成 `created` / `updated` / `deleted` 记录。`user_id` 为授权码所属的后台用户，
`url` 为 `/mcp/{panel}`，便于在「审计日志」中区分来自 MCP 的变更。
