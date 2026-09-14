<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\AgentTools;
use Continuum\SessionContext;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class AgentToolsTest extends TestCase {

    protected function tearDown(): void {
        SessionContext::set(null);
    }

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
            ['test-agent'],                                        // SMEMBERS agents
            ['heartbeat', '1000', 'registered_at', '2026-01-01T00:00:00Z'], // HGETALL agent
            [],                                                    // SMEMBERS agent-sessions
            '1000',                                                // HGET heartbeat (lastSeen)
        ]);
        $this->tools($resp, $couch)->agent_register(['php']);
        $hset = $resp->calls[5]; // SMEMBERS, HGETALL, SMEMBERS, HGET, SADD, HSET
        $this->assertSame('HSET', $hset[0]);
        $this->assertFalse(in_array('registered_at', $hset, true));
    }

    public function testHeartbeatSetsWorkingOnWithoutLogging(): void {
        $couch = new FakeCouch([]);
        $resp = new FakeRespClient([1, 4]); // SADD, HSET
        $result = $this->tools($resp, $couch)->agent_heartbeat('T-9');
        $this->assertSame('test-agent', $result->getStructuredContent()['agent']);
        $this->assertNull($result->getStructuredContent()['session']);
        $this->assertStringContainsString('working on: T-9', $result->toArray()['content'][0]['text']);
        $hset = $resp->calls[1];
        $this->assertSame('working_on', $hset[4]);
        $this->assertSame('T-9', $hset[5]);
    }

    public function testSessionHeartbeatScopesWorkingOnToTheSession(): void {
        SessionContext::set('abcdef1234567890');
        $couch = new FakeCouch([]);
        $resp = new FakeRespClient([]);
        $result = $this->tools($resp, $couch)->agent_heartbeat('dual format');
        $data = $result->getStructuredContent();
        $this->assertSame('abcdef1234567890', $data['session']);
        $this->assertStringContainsString('(session abcdef12)', $result->toArray()['content'][0]['text']);
        // identity hash carries only freshness; working_on lives on the session record
        $agentHset = $sessionHset = null;
        foreach ($resp->calls as $c) {
            if ($c[0] === 'HSET' && ($c[1] ?? '') === 'continuum:agent:test-agent') { $agentHset = $c; }
            if ($c[0] === 'HSET' && str_starts_with($c[1] ?? '', 'continuum:session:test-agent:')) { $sessionHset = $c; }
        }
        $this->assertNotNull($agentHset);
        $this->assertNotContains('working_on', $agentHset);
        $this->assertNotNull($sessionHset);
        $this->assertContains('abcdef1234567890', $sessionHset);
        $this->assertContains('working_on', $sessionHset);
        $this->assertContains('dual format', $sessionHset);
        // session card gets a TTL and membership entry
        $commands = array_map(fn($c) => $c[0], $resp->calls);
        $this->assertContains('EXPIRE', $commands);
        $this->assertContains('SADD', $commands);
    }

    public function testSessionRegisterStampsAStartedSession(): void {
        SessionContext::set('cafef00d');
        $resp = new FakeRespClient([[]]); // SMEMBERS agents (nobody)
        $result = $this->tools($resp)->agent_register(null, null);
        $this->assertSame('cafef00d', $result->getStructuredContent()['session']);
        $sessionWrites = array_filter($resp->calls,
            fn($c) => $c[0] === 'HSET' && str_contains($c[1] ?? '', 'continuum:session:test-agent:cafef00d'));
        $this->assertNotEmpty($sessionWrites);
        $this->assertContains('registered_at', current($sessionWrites));
    }
}
