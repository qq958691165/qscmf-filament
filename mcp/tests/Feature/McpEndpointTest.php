<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Mcp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Quansitech\Cmf\Mcp\Models\McpToken;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Models\TestArticleCategory;
use Quansitech\Cmf\Mcp\Tests\Fixtures\Models\TestUser;
use Quansitech\Cmf\Mcp\Tests\TestCase;

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
