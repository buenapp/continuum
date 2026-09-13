<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\McpServer;
use Continuum\TaskTools;
use Continuum\LockTools;
use Continuum\Bridge\NullMilestoneSyncAdapter;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

/**
 * MRTR elicitation (2026-07-28): confirmation challenges on tools/call,
 * capability gating, and the retry merge.
 */
class ElicitationTest extends TestCase {

    private function server(FakeCouch $couch, ?FakeRespClient $resp = null): McpServer {
        $storage = new ContinuumStorage(
            new ValKeyStore($resp ?? new FakeRespClient([])), $couch, new FakeArcade()
        );
        $server = new McpServer('test', '0.0.0');
        $server->register(new TaskTools($storage, new NullMilestoneSyncAdapter()));
        $server->register(new LockTools($storage));
        return $server;
    }

    private function callTool(McpServer $server, string $name, array $arguments, bool $elicitation = true, array $inputResponses = []): array {
        $params = ['name' => $name, 'arguments' => $arguments];
        $caps = $elicitation ? ['elicitation' => new \stdClass()] : [];
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => $caps,
        ];
        if ($inputResponses !== []) {
            $params['inputResponses'] = $inputResponses;
        }
        return $server->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params]);
    }

    public function testHeldTaskClaimElicitsWhenClientSupportsIt(): void {
        $server = $this->server(new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '3-c', 'status' => 'claimed', 'owner' => 'alice']],
        ]));
        $result = $this->callTool($server, 'task_claim', ['taskId' => 'T-1'])['result'];
        $this->assertSame('input_required', $result['resultType']);
        $request = $result['inputRequests']['confirm'];
        $this->assertSame('elicitation/create', $request['method']);
        $this->assertSame('form', $request['params']['mode']);
        $this->assertStringContainsString('alice', $request['params']['message']);
        $this->assertSame(['approve'], $request['params']['requestedSchema']['required']);
    }

    public function testElicitationDeniedForClientsWithoutCapability(): void {
        $server = $this->server(new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '3-c', 'status' => 'claimed', 'owner' => 'alice']],
        ]));
        $result = $this->callTool($server, 'task_claim', ['taskId' => 'T-1'], elicitation: false)['result'];
        $this->assertSame('complete', $result['resultType']);
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString("with the 'confirm' argument", $result['content'][0]['text']);
    }

    public function testElicitationDeniedForLegacyEra(): void {
        $server = $this->server(new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '3-c', 'status' => 'claimed', 'owner' => 'alice']],
        ]));
        $response = $server->handleRequest([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'task_claim', 'arguments' => ['taskId' => 'T-1']],
        ]);
        $this->assertTrue($response['result']['isError']);
    }

    public function testRetryWithAcceptCompletesTheSteal(): void {
        $server = $this->server(new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '3-c', 'status' => 'claimed', 'owner' => 'alice', 'title' => 'T']],
            ['code' => 201, 'body' => ['id' => 'T-1', 'rev' => '4-d']],
            ['code' => 200, 'body' => ['uuids' => ['ev1']]],
            ['code' => 201, 'body' => ['id' => 'ev1', 'rev' => '1-a']],
        ]));
        $result = $this->callTool($server, 'task_claim', ['taskId' => 'T-1'], inputResponses: [
            'confirm' => ['action' => 'accept', 'content' => ['approve' => true]],
        ])['result'];
        $this->assertSame('complete', $result['resultType']);
        $this->assertArrayNotHasKey('isError', $result);
        $this->assertSame('test-agent', $result['structuredContent']['owner'] ?? null);
    }

    public function testRetryWithDeclineFailsWithoutMutation(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '3-c', 'status' => 'claimed', 'owner' => 'alice']],
        ]);
        $result = $this->callTool($this->server($couch), 'task_claim', ['taskId' => 'T-1'], inputResponses: [
            'confirm' => ['action' => 'decline', 'content' => []],
        ])['result'];
        // a declined confirmation is a tool-level failure shown to the agent
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('steal declined', $result['content'][0]['text']);
        $this->assertCount(1, $couch->calls);
    }

    public function testLockReleaseForeignElicitsForce(): void {
        $resp = new FakeRespClient([0, 'bob', 5000]); // EVAL not-owner, GET owner, PTTL
        $result = $this->callTool($this->server(new FakeCouch([]), $resp), 'advisory_lock_release', ['name' => 'deploy'])['result'];
        $request = $result['inputRequests']['confirm'];
        $this->assertSame('elicitation/create', $request['method']);
        $this->assertStringContainsString('bob', $request['params']['message']);
    }

    public function testLockForceReleaseOnApprove(): void {
        $resp = new FakeRespClient([0, 'bob', 5000, 1]); // + DEL
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['uuids' => ['ev9']]],
            ['code' => 201, 'body' => ['id' => 'ev9', 'rev' => '1-z']],
        ]);
        $result = $this->callTool($this->server($couch, $resp), 'advisory_lock_release', ['name' => 'deploy'], inputResponses: [
            'confirm' => ['action' => 'accept', 'content' => ['approve' => true]],
        ])['result'];
        $payload = $result['structuredContent'] ?? [];
        $this->assertTrue($payload['released']);
        $this->assertTrue($payload['forced']);
        $this->assertStringContainsString('Force-released', $result['content'][0]['text']);
        $this->assertSame('advisory_lock_force_release', $couch->calls[1]['data']['type']);
        $this->assertSame('bob', $couch->calls[1]['data']['data']['from']);
    }

    public function testLockReleaseDeclineKeepsLock(): void {
        $resp = new FakeRespClient([0, 'bob', 5000]);
        $result = $this->callTool($this->server(new FakeCouch([]), $resp), 'advisory_lock_release', ['name' => 'deploy'], inputResponses: [
            'confirm' => ['action' => 'accept', 'content' => ['approve' => false]],
        ])['result'];
        $payload = $result['structuredContent'] ?? [];
        $this->assertFalse($payload['released']);
        $this->assertTrue($payload['declined']);
        $this->assertStringContainsString('declined', $result['content'][0]['text']);
        $this->assertCount(3, $resp->calls); // EVAL, GET, PTTL — no DEL
    }

    public function testDirectArgumentEscapeHatchWithoutElicitation(): void {
        // Non-MRTR clients pass the answer object as an argument directly.
        $server = $this->server(new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '3-c', 'status' => 'blocked', 'owner' => 'alice', 'title' => 'T']],
            ['code' => 201, 'body' => ['id' => 'T-1', 'rev' => '4-d']],
            ['code' => 200, 'body' => ['uuids' => ['ev4']]],
            ['code' => 201, 'body' => ['id' => 'ev4', 'rev' => '1-q']],
        ]));
        $result = $this->callTool($server, 'task_claim', [
            'taskId' => 'T-1',
            'confirm' => ['action' => 'accept', 'content' => ['approve' => true]],
        ], elicitation: false)['result'];
        $this->assertSame('complete', $result['resultType']);
        $this->assertArrayNotHasKey('isError', $result);
    }
}
