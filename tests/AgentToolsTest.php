<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\AgentTools;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class AgentToolsTest extends TestCase {

    private function tools(FakeRespClient $resp, ?FakeCouch $couch = null): AgentTools {
        return new AgentTools(new ContinuumStorage(
            new ValKeyStore($resp),
            $couch ?? new FakeCouch([['code' => 200, 'body' => ['uuids' => ['e']]], ['code' => 201, 'body' => []]]),
            new FakeArcade()
        ));
    }

    public function testRegistersTools(): void {
        $registry = new ToolRegistry();
        $registry->register($this->tools(new FakeRespClient([])));
        foreach (['agent_register', 'agent_heartbeat'] as $tool) {
            $this->assertTrue($registry->hasTool($tool), "missing {$tool}");
        }
    }

    public function testFirstRegisterStampsRegisteredAtAndLogs(): void {
        $resp = new FakeRespClient([
            [],     // SMEMBERS agents (none yet)
            1,      // SADD
            3,      // HSET
        ]);
        $result = $this->tools($resp)->agent_register(['php', 'zig'], 'Devin CLI');
        $this->assertTrue($result->getStructuredContent()['registered']);
        $this->assertStringContainsString("Registered 'test-agent' (Devin CLI) with 2 capabilities", $result->toArray()['content'][0]['text']);
        $hset = $resp->calls[2];
        $this->assertSame('HSET', $hset[0]);
        $flat = array_search('registered_at', $hset, true);
        $this->assertNotFalse($flat);
        $caps = $hset[array_search('capabilities', $hset, true) + 1];
        $this->assertSame(['php', 'zig'], json_decode($caps, true));
    }

    public function testReRegisterPreservesRegisteredAtAndDoesNotLog(): void {
        $couch = new FakeCouch([]); // would throw 'script exhausted' if logged
        $resp = new FakeRespClient([
            ['test-agent'],                                        // SMEMBERS
            ['heartbeat', '1000', 'registered_at', '2026-01-01T00:00:00Z'], // HGETALL
            0,      // SADD
            2,      // HSET
        ]);
        $this->tools($resp, $couch)->agent_register(['php']);
        $hset = $resp->calls[3];
        $this->assertFalse(in_array('registered_at', $hset, true));
    }

    public function testHeartbeatSetsWorkingOnWithoutLogging(): void {
        $couch = new FakeCouch([]);
        $resp = new FakeRespClient([1, 4]); // SADD, HSET
        $result = $this->tools($resp, $couch)->agent_heartbeat('T-9');
        $this->assertSame('test-agent', $result->getStructuredContent()['agent']);
        $this->assertStringContainsString('working on: T-9', $result->toArray()['content'][0]['text']);
        $hset = $resp->calls[1];
        $this->assertSame('working_on', $hset[4]);
        $this->assertSame('T-9', $hset[5]);
    }
}
