<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Quansitech\Cmf\Agent\AgentPlugin;
use Quansitech\Cmf\Agent\Support\DiscoveredAgentTools;
use Quansitech\Cmf\Agent\Tests\Fixtures\Models\TestArticle;
use Quansitech\Cmf\Agent\Tests\Fixtures\Models\TestUser;
use Quansitech\Cmf\Agent\Tests\Fixtures\Tools\DuplicateRegisteredTestTool;
use Quansitech\Cmf\Agent\Tests\Fixtures\Tools\RegisteredTestTool;
use Quansitech\Cmf\Agent\Tests\TestCase;
use Quansitech\Cmf\Agent\Tools\DescribeCmfResource;
use Quansitech\Cmf\Agent\Tools\GetCmfRecordAudit;
use Quansitech\Cmf\Agent\Tools\SearchCmfRecords;

final class AgentToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_records_returns_only_configured_fields_and_hides_unauthorized_records(): void
    {
        $user = $this->makeUser('ViewAny:Article', 'View:Article');
        $this->actingAs($user, 'web');

        TestArticle::query()->create(['title' => '可见文章', 'status' => 'draft', 'secret' => '不应出现']);
        TestArticle::query()->create(['title' => '受限文章', 'status' => 'draft', 'secret' => 'hidden']);

        $payload = $this->payload($this->callTool(SearchCmfRecords::class, ['resource' => 'articles']));

        $this->assertSame('articles', $payload['resource']);
        $this->assertCount(1, $payload['records']);
        $this->assertSame('可见文章', $payload['records'][0]['title']);
        $this->assertArrayNotHasKey('secret', $payload['records'][0]);
        $this->assertStringNotContainsString('受限文章', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function test_search_records_requires_view_any_permission(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user, 'web');

        $response = $this->callTool(SearchCmfRecords::class, ['resource' => 'articles']);

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('无法查询', (string) $response->content());
    }

    public function test_search_records_rejects_unknown_resource(): void
    {
        $user = $this->makeUser('ViewAny:Article', 'View:Article');
        $this->actingAs($user, 'web');

        $response = $this->callTool(SearchCmfRecords::class, ['resource' => 'unknown']);

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('无法查询', (string) $response->content());
    }

    public function test_search_records_supports_keyword_filters_sorting_and_pagination(): void
    {
        config(['cmf-agent.read_resources.articles.per_page' => 2]);

        $user = $this->makeUser('ViewAny:Article', 'View:Article');
        $this->actingAs($user, 'web');

        TestArticle::query()->create(['title' => '第一篇 财经', 'status' => 'draft']);
        TestArticle::query()->create(['title' => '第二篇 科技', 'status' => 'published']);
        TestArticle::query()->create(['title' => '第三篇 财经', 'status' => 'draft']);

        $payload = $this->payload($this->callTool(SearchCmfRecords::class, [
            'resource' => 'articles',
            'keyword' => '财经',
            'filters' => ['status' => 'draft'],
            'sort' => 'title',
            'direction' => 'asc',
            'page' => 1,
            'per_page' => 2,
        ]));

        $this->assertCount(2, $payload['records']);
        $this->assertSame('第一篇 财经', $payload['records'][0]['title']);
        $this->assertSame('第三篇 财经', $payload['records'][1]['title']);
        $this->assertFalse($payload['has_more']);
        $this->assertSame('title', $payload['sort']);
        $this->assertSame('asc', $payload['direction']);
    }

    public function test_search_records_rejects_unsupported_filter(): void
    {
        $user = $this->makeUser('ViewAny:Article', 'View:Article');
        $this->actingAs($user, 'web');

        $response = $this->callTool(SearchCmfRecords::class, [
            'resource' => 'articles',
            'filters' => ['secret' => 'x'],
        ]);

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('不支持的筛选条件', (string) $response->content());
    }

    public function test_describe_resource_lists_fields_and_filters_for_authorized_user(): void
    {
        $user = $this->makeUser('ViewAny:Article', 'View:Article');
        $this->actingAs($user, 'web');

        $payload = $this->payload($this->callTool(DescribeCmfResource::class, []));

        $this->assertCount(1, $payload['resources']);
        $this->assertSame('articles', $payload['resources'][0]['slug']);
        $this->assertSame('文章', $payload['resources'][0]['label']);
        $this->assertSame(['id', 'title', 'status'], $payload['resources'][0]['fields']);
        $this->assertSame(['title'], $payload['resources'][0]['search_fields']);
        $this->assertArrayHasKey('status', $payload['resources'][0]['filters']);
        $this->assertArrayNotHasKey('secret', $payload['resources'][0]['fields']);
    }

    public function test_describe_resource_hides_resources_without_view_any_permission(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user, 'web');

        $payload = $this->payload($this->callTool(DescribeCmfResource::class, []));

        $this->assertSame([], $payload['resources']);
    }

    public function test_audit_requires_the_separate_audit_permission(): void
    {
        Gate::define('audit', static fn (): bool => true);
        Gate::define('ViewAny:Audit', static fn (TestUser $user): bool => in_array('ViewAny:Audit', $user->abilities, true));
        $this->actingAs($this->makeUser('ViewAny:Article', 'View:Article'), 'web');
        $article = TestArticle::query()->create(['title' => '草稿', 'status' => 'draft']);

        $this->assertTrue($this->callTool(GetCmfRecordAudit::class, [
            'resource' => 'articles',
            'id' => $article->getKey(),
        ])->isError());
    }

    public function test_audit_returns_only_configured_fields(): void
    {
        Gate::define('audit', static fn (): bool => true);
        Gate::define('ViewAny:Audit', static fn (TestUser $user): bool => in_array('ViewAny:Audit', $user->abilities, true));
        $user = $this->makeUser('ViewAny:Article', 'View:Article', 'ViewAny:Audit');
        $this->actingAs($user, 'web');

        $article = TestArticle::query()->create(['title' => '旧标题', 'status' => 'draft', 'secret' => '不应出现']);

        DB::table('audits')->insert([
            'user_type' => TestUser::class,
            'user_id' => $user->getKey(),
            'event' => 'updated',
            'auditable_type' => $article->getMorphClass(),
            'auditable_id' => $article->getKey(),
            'old_values' => json_encode(['title' => '旧标题', 'secret' => '不应出现'], JSON_UNESCAPED_UNICODE),
            'new_values' => json_encode(['title' => '新标题', 'secret' => '也不应出现'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->payload($this->callTool(GetCmfRecordAudit::class, [
            'resource' => 'articles',
            'id' => $article->getKey(),
        ]));

        $this->assertCount(1, $payload['audits']);
        $this->assertSame('updated', $payload['audits'][0]['event']);
        $this->assertSame('旧标题', $payload['audits'][0]['old']['title']);
        $this->assertSame('新标题', $payload['audits'][0]['new']['title']);
        $this->assertArrayNotHasKey('secret', $payload['audits'][0]['old']);
        $this->assertArrayNotHasKey('secret', $payload['audits'][0]['new']);
        $this->assertSame($user->getKey(), $payload['audits'][0]['user']['id']);
    }

    public function test_tool_is_not_registered_without_configured_resources(): void
    {
        config(['cmf-agent.read_resources' => []]);

        $this->assertFalse(app(SearchCmfRecords::class)->shouldRegister());
        $this->assertFalse(app(DescribeCmfResource::class)->shouldRegister());
        $this->assertFalse(app(GetCmfRecordAudit::class)->shouldRegister());
    }

    public function test_tools_are_discovered_from_composer_package_metadata(): void
    {
        $installedJson = tempnam(sys_get_temp_dir(), 'composer-installed-');

        try {
            file_put_contents($installedJson, json_encode([
                'packages' => [
                    [
                        'name' => 'vendor/irrelevant-package',
                        'extra' => [
                            'cmf-agent' => [
                                'tools' => [RegisteredTestTool::class, ''],
                            ],
                        ],
                    ],
                    [
                        'name' => 'vendor/module-without-agent-tools',
                        'extra' => [],
                    ],
                ],
            ]));

            $discovered = DiscoveredAgentTools::fromInstalledJson($installedJson);
        } finally {
            unlink($installedJson);
        }

        $this->assertSame([RegisteredTestTool::class], $discovered);
    }

    public function test_invalid_discovered_tools_do_not_break_panel_tool_classes(): void
    {
        $installedJson = tempnam(sys_get_temp_dir(), 'composer-installed-');

        try {
            file_put_contents($installedJson, json_encode([
                'packages' => [
                    [
                        'name' => 'vendor/module-with-agent-tools',
                        'extra' => [
                            'cmf-agent' => [
                                'tools' => ['  '.RegisteredTestTool::class.'  ', DuplicateRegisteredTestTool::class, \stdClass::class, 'App\\MissingAgentTool'],
                            ],
                        ],
                    ],
                ],
            ]));

            DiscoveredAgentTools::flush();
            DiscoveredAgentTools::discover($installedJson);

            $tools = AgentPlugin::toolClasses();
        } finally {
            unlink($installedJson);
        }

        $this->assertContains(RegisteredTestTool::class, $tools);
        $this->assertNotContains(DuplicateRegisteredTestTool::class, $tools);
        $this->assertNotContains(\stdClass::class, $tools);
        $this->assertNotContains('App\\MissingAgentTool', $tools);
    }

    public function test_missing_installed_json_discovery_is_empty(): void
    {
        $this->assertSame([], DiscoveredAgentTools::fromInstalledJson('/missing/vendor/composer/installed.json'));
    }

    public function test_non_agent_tool_in_config_is_rejected_when_building_tool_classes(): void
    {
        config(['cmf-agent.tools' => [\stdClass::class]]);

        $this->expectException(InvalidArgumentException::class);

        AgentPlugin::toolClasses();
    }

    public function test_agent_plugin_registers_packstub_agents_on_admin_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasPlugin('cmf-agent'));
        $this->assertTrue($panel->hasPlugin('packstub-agents'));
    }

    public function test_chinese_translations_are_loaded_from_the_package(): void
    {
        $this->assertSame('新对话', __('New chat'));
        $this->assertSame('Agent 访问', __('Agent access'));
    }

    /**
     * @param  class-string  $tool
     * @param  array<string, mixed>  $arguments
     */
    private function callTool(string $tool, array $arguments): Response
    {
        return app($tool)->handle(new Request($arguments));
    }

    private function payload(Response $response): array
    {
        $this->assertFalse($response->isError(), (string) $response->content());

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function makeUser(string ...$abilities): TestUser
    {
        return TestUser::query()->create([
            'name' => 'Agent Tool Test User',
            'email' => 'agent-tool-'.str()->random(8).'@example.test',
            'password' => str()->random(32),
            'abilities' => $abilities,
        ]);
    }
}
