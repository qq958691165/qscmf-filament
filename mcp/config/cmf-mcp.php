<?php

declare(strict_types=1);

return [
    // 启用的 Filament 面板 ID。仅支持非多租户面板；多租户面板暂不开放。
    'panels' => ['admin'],

    /*
    |--------------------------------------------------------------------------
    | 资源配置示例
    |--------------------------------------------------------------------------
    |
    | 键名（slug）=> 资源定义。下面的示例默认注释掉，仅作写法参考；
    | 按需取消注释，并替换成你自己项目中的模型与 Filament Resource。
    |
    | - model       必填。Eloquent 模型类。
    | - resource    可选。Filament Resource 类；配置后沿用它的 getEloquentQuery()
    |               作为查询范围，并使用它的 getModelLabel()/getPluralModelLabel()
    |               作为 label/plural_label。推荐把该 Resource 注册到对应面板。
    | - description 可选。资源的中文说明，会出现在 list_resources 和
    |               describe_resource 的结果里；省略时为空字符串。
    | - read        对外返回的字段白名单（只读字段）。
    | - write       字段 => 校验规则（字符串或数组）；仅接受其中通过校验且
    |               模型 isFillable() 的字段。需要复杂逻辑时可配 repository。
    | - operations  允许的操作：viewAny、view、create、update、delete。
    |               省略时，配置了 read 的资源默认只开放 viewAny 和 view。
    | - scope       可选。实现 Quansitech\Cmf\Mcp\Resources\Contracts\ResourceScope
    |               的类，用于按当前用户限制可访问记录。
    | - repository  可选。实现 Quansitech\Cmf\Mcp\Resources\Contracts\ResourceRepository
    |               的类，用于定制读写流程（如关联写入、上传、领域服务）。
    |
    */
    'resources' => [
        // 'articles' => [
        //     'model' => \App\Models\Article::class,
        //     'resource' => \App\Filament\Resources\Articles\ArticleResource::class,
        //     'description' => '新闻文章：分类、标题、摘要、正文、封面、发布状态与推荐位。',
        //     'read' => ['id', 'category_id', 'title', 'slug', 'summary', 'content', 'status', 'published_at'],
        //     'write' => [
        //         'category_id' => 'nullable|integer|exists:article_categories,id',
        //         'title' => 'required|string|max:255',
        //         'slug' => 'required|string|max:255|unique:articles,slug',
        //         'summary' => 'nullable|string|max:500',
        //         'status' => 'in:draft,published',
        //         'published_at' => 'nullable|date',
        //     ],
        //     'operations' => ['viewAny', 'view', 'create', 'update', 'delete'],
        // ],
    ],

    // 追加到 MCP 路由的应用中间件。模块内已默认带 Bearer 鉴权与限流。
    'middleware' => [],

    // 限流：每分钟请求数,分钟窗口。默认 60,1（每分钟 60 次）。
    'throttle' => '60,1',

    // list_records 单次最多扫描的记录行数，防止在大表上逐行做授权过滤时全表扫描。
    // 超过上限会返回错误，提示收窄筛选条件。默认 5000。
    'max_scan_rows' => (int) env('CMF_MCP_MAX_SCAN_ROWS', 5000),
];
