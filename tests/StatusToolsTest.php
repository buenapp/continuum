<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\StatusTools;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class StatusToolsTest extends TestCase {

    private function tools(FakeRespClient $resp, ?FakeCouch $couch = null): StatusTools {
        return new StatusTools(new ContinuumStorage(
            new ValKeyStore($resp),
            $couch ?? new FakeCouch([]),
            new FakeArcade()
        ));
    }

    public function testRegisters(): void {
        $registry = new ToolRegistry();
        $registry->register($this->tools(new FakeRespClient([])));
        $this->assertTrue($registry->hasTool('board_status'));
    }

    public function testAggregatesAllLayers(): void {
        $resp = new FakeRespClient([
            ['alice'],                                                        // SMEMBERS agents
            ['heartbeat', (string)(time() - 42), 'working_on', 'parser', 'capabilities', '["php"]'], // HGETALL alice
            1,                                                                // LLEN queue:proj
            ['0', ['continuum:lock:build']],                                  // SCAN
            'alice',                                                          // GET lock:build
            30000,                                                            // PTTL
        ]);
        $couch = new FakeCouch([
            // tasks _all_docs
            ['code' => 200, 'body' => ['rows' => [
                (object)['id' => 'T-1', 'doc' => (object)['title' => 'Fix', 'scope' => 'proj', 'status' => 'claimed', 'owner' => 'alice', 'priority' => 1]],
                (object)['id' => 'T-2', 'doc' => (object)['title' => 'Shipped', 'scope' => 'proj', 'status' => 'done']],
            ]]],
            // boards _all_docs
            ['code' => 200, 'body' => ['rows' => [
                (object)['id' => 'proj/roadmap', 'doc' => (object)['value' => 'x']],
                (object)['id' => 'global/motto', 'doc' => (object)['value' => 'y']],
            ]]],
            // events _all_docs (via EventTools inside board_status)
            ['code' => 200, 'body' => ['rows' => [
                (object)['id' => 'E1', 'doc' => (object)['agent' => 'alice', 'type' => 'task_claim', 'ts' => '2026-09-13T01:00:00.000Z', 'data' => []]],
            ]]],
        ]);
        $result = $this->tools($resp, $couch)->board_status();
        $status = $result->getStructuredContent();

        $this->assertCount(1, $status['agents']);
        $this->assertSame('alice', $status['agents'][0]['agent']);
        $this->assertSame('parser', $status['agents'][0]['working_on']);
        $this->assertSame(['php'], $status['agents'][0]['capabilities']);
        $this->assertSame(42, $status['agents'][0]['last_seen_s_ago']);

        $this->assertCount(1, $status['open_tasks']); // done task excluded
        $this->assertSame('T-1', $status['open_tasks'][0]['task']);

        $this->assertSame('alice', $status['locks']['build']['owner']);
        $this->assertSame(['proj' => 1, 'global' => 1], $status['boards']);
        $this->assertSame(['proj' => 1], $status['queues']);
        $this->assertSame('E1', $status['recent_events'][0]['id']);

        // dual format: human-first text block with title-leading task lines
        $text = $result->toArray()['content'][0]['text'];
        $this->assertStringContainsString('## Open tasks (1)', $text);
        $this->assertStringContainsString('- Fix [claimed, p1, @alice] (T-1)', $text);
        $this->assertStringContainsString('- build — alice (30000ms TTL)', $text);
    }

    public function testEmptyBoardIsGraceful(): void {
        $resp = new FakeRespClient([
            [],                        // SMEMBERS agents
            ['0', []],                 // SCAN (no locks)
        ]);
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['rows' => []]],
            ['code' => 200, 'body' => ['rows' => []]],
            ['code' => 200, 'body' => ['rows' => []]],
        ]);
        $status = $this->tools($resp, $couch)->board_status()->getStructuredContent();
        $this->assertSame([], $status['agents']);
        $this->assertSame([], $status['open_tasks']);
        $this->assertSame([], $status['locks']);
        $this->assertSame([], $status['queues']);
    }
}
