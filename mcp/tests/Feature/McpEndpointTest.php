<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request;
use Quansitech\Cmf\Mcp\Filament\McpTokenAccess;
use Quansitech\Cmf\Mcp\Filament\Resources\McpTokens\McpTokenResource;
use Quansitech\Cmf\Mcp\Models\McpToken;
use Quansitech\Cmf\Mcp\Resources\EloquentResourceRepository;
use Quansitech\Cmf\Mcp\Resources\Exceptions\ToolException;
use Quansitech\Cmf\Mcp\Resources\ResourceAuthorizer;
use Quansitech\Cmf\Mcp\Resources\ResourceRegistry;
use Quansitech\Cmf\Mcp\Resources\ResourceRuntime;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Models\TestArticleCategory;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Models\TestUser;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Resources\OrderedTestUserResource;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Resources\TestArticleCategoryResource;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Resources\UnregisteredArticleCategoryResource;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Tools\ThrowingCmfTool;
use Quansitech\Cmf\Mcp\Tests\TestCase;
use RuntimeException;

/**
 * MCP HTTP 传输与通用工具的回归测试（testbench 自包含，不依赖宿主应用）。
 */
final class McpEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_authenticate_the_http_transport_and_can_be_revoked(): void
    {
        $user = $this->makeUser();
        $this->rpc('tools/list')->assertUnauthorized();
        $this->rpc('tools/list', token: 'invalid')->assertUnauthorized();

        $expired = McpToken::issue($user, 'Expired', 'read', now()->subMinute());
        $this->rpc('tools/list', token: $expired)->assertUnauthorized();

        $token = McpToken::issue($user, 'Client', 'read');
        $record = McpToken::query()->where('token_hash', hash('sha256', $token))->sole();
        $this->assertArrayNotHasKey('token_hash', $record->toArray());

        $this->rpc('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'cmf-test', 'version' => '1.0'],
        ], $token)->assertOk()->assertJsonPath('result.serverInfo.name', 'CMF MCP Server');

        $this->rpc('tools/list', token: $token)->assertOk()->assertJsonCount(7, 'result.tools');
        $this->assertNotNull($record->refresh()->last_used_at);

        $record->revoke();
        $this->rpc('tools/list', token: $token)->assertUnauthorized();
    }

    public function test_read_and_write_tokens_are_scoped_and_policy_checked(): void
    {
        $user = $this->makeUser('viewAny', 'view');
        $read = McpToken::issue($user, 'Read client', 'read');
        $write = McpToken::issue($user, 'Write client', 'write');

        TestArticleCategory::query()->create(['name' => 'Existing category', 'slug' => 'existing-category', 'secret' => 'top-secret']);

        $list = $this->tool('list_records', [], $read)->assertOk()->assertJsonPath('result.isError', false);
        $this->assertStringContainsString('Existing category', $list->getContent());
        // hidden 字段永远不对外返回。
        $this->assertStringNotContainsString('top-secret', $list->getContent());

        $this->tool('get_record', ['id' => TestArticleCategory::query()->sole()->id], $read)
            ->assertOk()->assertJsonPath('result.isError', false);

        $discovery = $this->tool('list_resources', [], $read)->assertOk()->assertJsonPath('result.isError', false);
        $this->assertStringContainsString('article-categories', $discovery->getContent());
        $this->assertStringContainsString('测试文章分类', $discovery->getContent());

        $description = $this->tool('describe_resource', [], $read)->assertOk()->assertJsonPath('result.isError', false);
        $this->assertStringNotContainsString('"update"', $description->getContent());

        $create = ['data' => ['name' => 'New category', 'slug' => 'new-category']];

        // 有读写令牌但缺少 create 策略：拒绝。
        $this->tool('create_record', $create, $write)->assertOk()->assertJsonPath('result.isError', true);
        $this->allow($user, 'create', 'update', 'delete');

        // 只读令牌不能写。
        $this->tool('create_record', $create, $read)->assertOk()->assertJsonPath('result.isError', true);
        $this->assertDatabaseMissing('article_categories', ['slug' => 'new-category']);

        // 非白名单/非 fillable 字段（id）被拒绝。
        $this->tool('create_record', ['data' => $create['data'] + ['id' => 42]], $write)
            ->assertOk()->assertJsonPath('result.isError', true);

        $this->tool('create_record', $create, $write)->assertOk()->assertJsonPath('result.isError', false);
        $id = TestArticleCategory::query()->where('slug', 'new-category')->sole()->id;

        $this->tool('update_record', ['id' => $id, 'data' => ['name' => 'Renamed category']], $write)
            ->assertOk()->assertJsonPath('result.isError', false);
        $this->assertDatabaseHas('article_categories', ['id' => $id, 'name' => 'Renamed category']);

        $this->tool('delete_record', ['id' => $id], $write)->assertOk()->assertJsonPath('result.isError', false);
        $this->assertDatabaseMissing('article_categories', ['id' => $id]);

        // 未知资源。
        $this->tool('list_records', ['resource' => 'unknown-resource'], $read)->assertOk()->assertJsonPath('result.isError', true);

        // 不存在的记录。
        $this->tool('get_record', ['id' => 99999], $read)->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_update_unique_field_ignores_itself_and_rejects_conflicts(): void
    {
        $user = $this->makeUser('viewAny', 'view', 'create', 'update');
        $write = McpToken::issue($user, 'Write client', 'write');
        TestArticleCategory::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = TestArticleCategory::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        // 重新提交自身未变更的 slug 不应被判为重复。
        $this->tool('update_record', ['id' => $beta->id, 'data' => ['slug' => 'beta', 'name' => 'Beta 2']], $write)
            ->assertOk()->assertJsonPath('result.isError', false);
        $this->assertDatabaseHas('article_categories', ['id' => $beta->id, 'slug' => 'beta', 'name' => 'Beta 2']);

        // 与其他记录冲突的 slug 应被拒绝。
        $this->tool('update_record', ['id' => $beta->id, 'data' => ['slug' => 'alpha']], $write)
            ->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_list_records_aborts_after_scan_cap(): void
    {
        $user = $this->makeUser('viewAny', 'view');
        $read = McpToken::issue($user, 'Read client', 'read');
        for ($i = 1; $i <= 5; $i++) {
            TestArticleCategory::query()->create(['name' => "Cat {$i}", 'slug' => "cat-{$i}"]);
        }

        config(['cmf-mcp.max_scan_rows' => 2]);
        $this->tool('list_records', ['per_page' => 10], $read)->assertOk()->assertJsonPath('result.isError', true);

        config(['cmf-mcp.max_scan_rows' => 5000]);
        $this->tool('list_records', ['per_page' => 10], $read)->assertOk()->assertJsonPath('result.isError', false);
    }

    public function test_list_records_filter_and_sort_are_whitelisted(): void
    {
        $user = $this->makeUser('viewAny', 'view');
        $read = McpToken::issue($user, 'Read client', 'read');
        TestArticleCategory::query()->create(['name' => 'Alpha', 'slug' => 'alpha', 'secret' => 'hidden-a']);
        TestArticleCategory::query()->create(['name' => 'Beta', 'slug' => 'beta', 'secret' => 'hidden-b']);

        $filtered = $this->tool('list_records', ['filter' => ['name' => 'Alpha']], $read)
            ->assertOk()->assertJsonPath('result.isError', false);
        $this->assertStringContainsString('Alpha', $filtered->getContent());
        $this->assertStringNotContainsString('Beta', $filtered->getContent());

        $inList = $this->tool('list_records', ['filter' => ['name' => ['Alpha', 'Beta']]], $read)
            ->assertOk()->assertJsonPath('result.isError', false);
        $this->assertStringContainsString('Alpha', $inList->getContent());
        $this->assertStringContainsString('Beta', $inList->getContent());

        // hidden 字段不可作为过滤/排序字段。
        $this->tool('list_records', ['filter' => ['secret' => 'hidden-a']], $read)->assertOk()->assertJsonPath('result.isError', true);
        $this->tool('list_records', ['sort' => 'secret'], $read)->assertOk()->assertJsonPath('result.isError', true);

        $sorted = $this->tool('list_records', ['sort' => 'name', 'direction' => 'desc'], $read)
            ->assertOk()->assertJsonPath('result.isError', false);
        $this->assertStringContainsString('Beta', $sorted->getContent());
    }

    public function test_read_token_cannot_update_or_delete(): void
    {
        $user = $this->makeUser('viewAny', 'view', 'create', 'update', 'delete');
        $read = McpToken::issue($user, 'Read client', 'read');
        $record = TestArticleCategory::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);

        $this->tool('update_record', ['id' => $record->id, 'data' => ['name' => 'Hacked']], $read)
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->tool('delete_record', ['id' => $record->id], $read)
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->assertDatabaseHas('article_categories', ['id' => $record->id, 'name' => 'Alpha']);
    }

    public function test_resource_must_be_registered_on_current_panel(): void
    {
        $user = $this->makeUser('viewAny', 'view');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user, 'web');
        TestArticleCategory::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);

        $base = [
            'model' => TestArticleCategory::class,
            'read' => ['id', 'name'],
            'operations' => ['viewAny', 'view'],
        ];

        config(['cmf-mcp.resources' => ['article-categories' => $base + ['resource' => TestArticleCategoryResource::class]]]);
        $result = $this->runtime()->listRecords($user, 'article-categories');
        $this->assertSame('Alpha', $result['records'][0]['name']);

        // 未注册到当前面板的同模型 Resource 不能通过 MCP 访问。
        config(['cmf-mcp.resources' => ['article-categories' => $base + ['resource' => UnregisteredArticleCategoryResource::class]]]);
        $this->expectException(ToolException::class);
        $this->runtime()->listRecords($user, 'article-categories');
    }

    public function test_cross_user_issuance_requires_permission_and_records_issuer(): void
    {
        $boss = $this->makeUserWithEmail('owner-boss@example.test');
        $clerk = $this->makeUserWithEmail('clerk@example.test');
        $target = $this->makeUserWithEmail('target@example.test');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Gate::define('Manage:McpToken', static fn (TestUser $user): bool => true);
        Gate::define('ManageOthers:McpToken', static fn (TestUser $user): bool => $user->email === 'owner-boss@example.test');

        $this->actingAs($clerk, 'web');
        $this->assertFalse(McpTokenAccess::allowsManagingUser($target));

        $this->actingAs($boss, 'web');
        $this->assertTrue(McpTokenAccess::allowsManagingUser($target));

        $plain = McpToken::issue($target, 'Delegated', 'read', null, $boss);
        $record = McpToken::query()->where('token_hash', hash('sha256', $plain))->sole();
        $this->assertSame($boss->getKey(), $record->issued_by);
        $this->assertTrue($record->issuer->is($boss));
    }

    public function test_token_list_handles_multiple_candidate_chunks(): void
    {
        $actor = $this->makeUserWithEmail('actor@example.test');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Gate::define('Manage:McpToken', static fn (TestUser $user): bool => true);
        Gate::define('ManageOthers:McpToken', static fn (TestUser $user): bool => true);
        $this->actingAs($actor, 'web');

        // 超过 100 名令牌持有人，触发 lazyById 的多批次路径（游标别名回归）。
        for ($i = 1; $i <= 120; $i++) {
            $owner = TestUser::query()->create([
                'name' => "Owner {$i}",
                'email' => "owner{$i}@example.test",
                'password' => 'secret',
                'mcp_abilities' => [],
            ]);
            McpToken::issue($owner, "Token {$i}", 'read');
        }

        $this->assertSame(120, McpTokenResource::getEloquentQuery()->count());
    }

    public function test_token_list_is_stable_when_user_resource_has_default_ordering(): void
    {
        $actor = $this->makeUserWithEmail('ordered-actor@example.test');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Gate::define('Manage:McpToken', static fn (TestUser $user): bool => true);
        Gate::define('ManageOthers:McpToken', static fn (TestUser $user): bool => true);
        $this->actingAs($actor, 'web');
        config(['cmf-users.resource' => OrderedTestUserResource::class]);

        // 名称与 id 反向排列，模拟用户资源默认按 name 排序；若游标保留该排序会漏人。
        for ($i = 1; $i <= 250; $i++) {
            $owner = TestUser::query()->create([
                'name' => sprintf('%03d', 1000 - $i),
                'email' => "ordered-owner{$i}@example.test",
                'password' => 'secret',
                'mcp_abilities' => [],
            ]);
            McpToken::issue($owner, "Ordered token {$i}", 'read');
        }

        $this->assertSame(250, McpTokenResource::getEloquentQuery()->count());
    }

    public function test_wrapped_query_exception_is_sanitized_before_logging(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user, 'web');

        $binding = 'BINDING-SECRET-123';
        $queryException = new QueryException(
            'mysql',
            'select * from orders where customer_phone = ?',
            [$binding],
            new RuntimeException('PDO failure'),
        );
        $wrapped = new RuntimeException('Repository failed', 0, $queryException);

        Log::spy();
        (new ThrowingCmfTool($wrapped))->handle(new Request);

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context = []) use ($binding): bool {
            $logged = $context['exception'] ?? null;

            $this->assertStringContainsString('Database query failed with SQLSTATE', $message);
            $this->assertStringNotContainsString($binding, $message);
            $this->assertInstanceOf(RuntimeException::class, $logged);
            $this->assertNull($logged->getPrevious());

            return true;
        });
    }

    private function runtime(): ResourceRuntime
    {
        return new ResourceRuntime(new ResourceRegistry, new EloquentResourceRepository, new ResourceAuthorizer);
    }

    private function makeUserWithEmail(string $email, string ...$abilities): TestUser
    {
        return TestUser::query()->create([
            'name' => 'MCP Test User',
            'email' => $email,
            'password' => 'secret',
            'mcp_abilities' => array_values($abilities),
        ]);
    }

    private function makeUser(string ...$abilities): TestUser
    {
        return TestUser::query()->create([
            'name' => 'MCP Test User',
            'email' => 'mcp-test@example.test',
            'password' => 'secret',
            'mcp_abilities' => array_values($abilities),
        ]);
    }

    private function allow(TestUser $user, string ...$abilities): void
    {
        $user->forceFill([
            'mcp_abilities' => array_values(array_unique([...($user->mcp_abilities ?? []), ...$abilities])),
        ])->save();
    }

    private function tool(string $name, array $arguments, string $token): TestResponse
    {
        return $this->rpc('tools/call', [
            'name' => $name,
            'arguments' => $arguments + ['resource' => 'article-categories'],
        ], $token);
    }

    private function rpc(string $method, array $params = [], ?string $token = null): TestResponse
    {
        $headers = ['Accept' => 'application/json, text/event-stream'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->postJson('/mcp/admin', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ], $headers);
    }
}
