<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\AgentResources;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class AgentResourcesTest extends TestCase {

    private function registry(FakeRespClient $resp): ToolRegistry {
        $registry = new ToolRegistry();
        $registry->register(new AgentResources(new ContinuumStorage(
            new ValKeyStore($resp), new FakeCouch([]), new FakeArcade()
        )));
        return $registry;
    }

    public function testRegistersStaticsAndTemplates(): void {
        $registry = $this->registry(new FakeRespClient([]));
        $this->assertSame(['continuum://agents'], array_column($registry->listResources(), 'uri'));
        $this->assertContains('continuum://agents/{id}', array_column($registry->listResourceTemplates(), 'uriTemplate'));
    }

    public function testDirectoryDecodesCapabilitiesAndMarksSelf(): void {
        $resp = new FakeRespClient([
            ['test-agent', 'alice'],                                             // SMEMBERS
            ['heartbeat', '3000', 'registered_at', '2026-09-01T00:00:00Z'],      // HGETALL test-agent
            ['heartbeat', '2000', 'label', 'Alice', 'capabilities', '["php"]'],  // HGETALL alice
        ]);
        $content = $this->registry($resp)->readResource('continuum://agents');
        $body = json_decode($content['text'], true);
        $this->assertSame(2, $body['count']);
        [$me, $other] = $body['agents'];
        $this->assertTrue($me['me']);
        $this->assertFalse($other['me']);
        $this->assertSame(['php'], $other['capabilities']);
        $this->assertSame(gmdate('c', 2000), $other['last_seen_at']);
        $this->assertSame(gmdate('c', 3000), $content['annotations']['lastModified']);
    }

    public function testCardForSingleAgent(): void {
        $resp = new FakeRespClient([
            ['alice'],
            ['heartbeat', '2000', 'working_on', 'T-9'],
        ]);
        $content = $this->registry($resp)->readResource('continuum://agents/alice');
        $body = json_decode($content['text'], true);
        $this->assertSame('alice', $body['agent']);
        $this->assertSame('T-9', $body['working_on']);
        $this->assertSame(gmdate('c', 2000), $content['annotations']['lastModified']);
    }

    public function testCardUnknownAgentThrows(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('agent ghost is not registered');
        $this->registry(new FakeRespClient([[]]))->readResource('continuum://agents/ghost');
    }
}
