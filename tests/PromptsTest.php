<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\McpServer;
use Continuum\CoordinationPrompts;
use Continuum\TaskResources;
use Continuum\BoardResources;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class PromptsTest extends TestCase {

    private function server(FakeCouch $couch, ?FakeRespClient $resp = null, bool $memoryBridge = true): McpServer {
        $storage = new ContinuumStorage(
            new ValKeyStore($resp ?? new FakeRespClient([])), $couch, new FakeArcade()
        );
        $server = new McpServer('test', '0.0.0');
        $server->register(new CoordinationPrompts($storage, $memoryBridge));
        // ref/resource completion validates against registered templates
        $server->register(new TaskResources($storage));
        $server->register(new BoardResources($storage));
        return $server;
    }

    private function call(McpServer $server, string $method, array $params = []): array {
        return $server->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    public function testPromptsListIsSortedWithArgumentMetadata(): void {
        $result = $this->call($this->server(new FakeCouch([])), 'prompts/list')['result'];
        $names = array_column($result['prompts'], 'name');
        $this->assertSame(['claim_and_serve', 'handoff', 'milestone_sync', 'session_bootstrap'], $names);
        $handoff = $result['prompts'][1];
        $this->assertTrue($handoff['arguments'][0]['required']);
        $this->assertSame('task_id', $handoff['arguments'][0]['name']);
    }

    public function testBootstrapPromptNamesAgentAndLinksPack(): void {
        $result = $this->call($this->server(new FakeCouch([])), 'prompts/get', [
            'name' => 'session_bootstrap', 'arguments' => ['scope' => 'proj'],
        ])['result'];
        $this->assertCount(2, $result['messages']);
        $text = $result['messages'][0]['content']['text'];
        $this->assertStringContainsString("agent 'test-agent'", $text);
        $this->assertStringContainsString("scope 'proj'", $text);
        $link = $result['messages'][1]['content'];
        $this->assertSame('resource_link', $link['type']);
        $this->assertSame('continuum://context/pack/proj', $link['uri']);
    }

    public function testHandoffRequiresTaskIdAndEmbedsTitle(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => ['title' => 'Fix parser', 'status' => 'claimed']]]);
        $result = $this->call($this->server($couch), 'prompts/get', [
            'name' => 'handoff', 'arguments' => ['task_id' => 'T-9'],
        ])['result'];
        $this->assertStringContainsString('"Fix parser"', $result['messages'][0]['content']['text']);
    }

    public function testHandoffWithoutTaskIdIsInvalidParams(): void {
        $response = $this->call($this->server(new FakeCouch([])), 'prompts/get', ['name' => 'handoff']);
        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('task_id', $response['error']['message']);
    }

    public function testUnknownPromptIsInvalidParams(): void {
        $response = $this->call($this->server(new FakeCouch([])), 'prompts/get', ['name' => 'nope']);
        $this->assertSame(-32602, $response['error']['code']);
    }

    private function taskRows(): array {
        return ['rows' => [
            (object)['id' => 'T-1', 'doc' => (object)['title' => 'Old', 'status' => 'done']],
            (object)['id' => 'T-2', 'doc' => (object)['title' => 'Live', 'status' => 'pending', 'scope' => 'proj']],
        ]];
    }

    public function testScopeCompletionFromBoardsAndTasks(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['rows' => [(object)['id' => 'ops/key', 'doc' => (object)[]]]]],
            ['code' => 200, 'body' => $this->taskRows()],
        ]);
        $result = $this->call($this->server($couch), 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'session_bootstrap'],
            'argument' => ['name' => 'scope', 'value' => ''],
        ])['result'];
        $this->assertSame(['global', 'ops', 'proj'], $result['completion']['values']);
    }

    public function testTaskIdCompletionOpenFirstPrefixFiltered(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => $this->taskRows()]]);
        $result = $this->call($this->server($couch), 'completion/complete', [
            'ref' => ['type' => 'ref/resource', 'uri' => 'continuum://tasks/{id}'],
            'argument' => ['name' => 'id', 'value' => 'T-'],
        ])['result'];
        $this->assertSame(['T-2', 'T-1'], $result['completion']['values']);
    }

    public function testBoardKeyCompletionUsesContextScope(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => ['rows' => [
            (object)['id' => 'proj/roadmap', 'doc' => (object)[]],
            (object)['id' => 'ops/alerts', 'doc' => (object)[]],
            (object)['id' => 'proj/roadster', 'doc' => (object)[]],
        ]]]]);
        $result = $this->call($this->server($couch), 'completion/complete', [
            'ref' => ['type' => 'ref/resource', 'uri' => 'continuum://board/{scope}/{key}'],
            'argument' => ['name' => 'key', 'value' => 'road'],
            'context' => ['arguments' => ['scope' => 'proj']],
        ])['result'];
        $this->assertSame(['roadmap', 'roadster'], $result['completion']['values']);
    }

    public function testCompletionUnknownPromptIsInvalidParams(): void {
        $response = $this->call($this->server(new FakeCouch([])), 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'nope'],
            'argument' => ['name' => 'scope', 'value' => ''],
        ]);
        $this->assertSame(-32602, $response['error']['code']);
    }

    public function testCompletionForArgumentWithoutProviderIsEmpty(): void {
        $couch = new FakeCouch([]);
        $result = $this->call($this->server($couch), 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'milestone_sync'],
            'argument' => ['name' => 'whatever', 'value' => ''],
        ])['result'];
        $this->assertSame([], $result['completion']['values']);
        $this->assertSame(0, $result['completion']['total']);
    }

    public function testHandoffPromptMentionsPromoteOnlyWithMemoryBridge(): void {
        $mk = fn(FakeCouch $couch) => $couch;
        $withBridge = new FakeCouch([['code' => 200, 'body' => ['title' => 'T', 'status' => 'claimed']]]);
        $on = $this->server($withBridge);
        $textOn = $this->call($on, 'prompts/get', ['name' => 'handoff', 'arguments' => ['task_id' => 'T-1']])['result']['messages'][0]['content']['text'];
        $this->assertStringContainsString('promote_to_memory', $textOn);

        $withoutBridge = new FakeCouch([['code' => 200, 'body' => ['title' => 'T', 'status' => 'claimed']]]);
        $off = $this->server($withoutBridge, null, false);
        $textOff = $this->call($off, 'prompts/get', ['name' => 'handoff', 'arguments' => ['task_id' => 'T-1']])['result']['messages'][0]['content']['text'];
        $this->assertStringNotContainsString('promote_to_memory', $textOff);
        $this->assertStringContainsString('task_handoff', $textOff);

        $milestoneOff = $this->call($off, 'prompts/get', ['name' => 'milestone_sync'])['result']['messages'][0]['content']['text'];
        $this->assertStringNotContainsString('promote_to_memory', $milestoneOff);
    }

    public function testCapabilitiesAdvertisePromptsAndCompletions(): void {
        $response = $this->call($this->server(new FakeCouch([])), 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '0'],
        ]);
        $this->assertArrayHasKey('prompts', $response['result']['capabilities']);
        $this->assertArrayHasKey('completions', $response['result']['capabilities']);
    }
}
