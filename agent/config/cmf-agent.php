<?php

return [
    'panel' => 'admin',
    'name' => 'CMF 助手',
    'driver' => env('CMF_AGENT_DRIVER', 'sync'),

    // 使用 Packstub 自带的 /mcp 端点与 Agent access 令牌页。
    'enable_packstub_mcp' => true,
    'agent_access' => true,

    'max_scan_rows' => 5000,

    /*
     * read_resources 按 slug 配置一个可读资源：
     *
     * 'articles' => [
     *     'label' => '文章',
     *     'model' => App\Models\Article::class,
     *     'resource' => App\Filament\Resources\Articles\ArticleResource::class, // 可选，须与 model 一致且在当前面板
     *     'fields' => ['id', 'title', 'status', 'summary'],
     *     'search_fields' => ['title', 'summary'],
     *     'filters' => [
     *         'status' => ['column' => 'status', 'operator' => '='],
     *         'created_from' => ['column' => 'created_at', 'operator' => '>='],
     *     ],
     *     'sortable' => ['id', 'created_at', 'title'],
     *     'default_sort' => ['created_at', 'desc'],
     *     'per_page' => 20,
     * ],
     *
     * fields 是输出白名单；search_fields 只能是 fields 的子集；filters 的列和操作符
     * 都由配置决定，工具参数不能传入任意表名或列名。
     */
    'read_resources' => [],

    // 宿主定义的 Packstub AgentTool 子类。写工具在聊天中需要人工批准。
    'tools' => [],
];
