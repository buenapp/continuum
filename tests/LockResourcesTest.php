<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\LockResources;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class LockResourcesTest extends TestCase {

    private function registry(FakeRespClient $resp): ToolRegistry {
        $registry = new ToolRegistry();
        $registry->register(new LockResources(new ContinuumStorage(
            new ValKeyStore($resp), new FakeCouch([]), new FakeArcade()
        )));
        return $registry;
    }

    public function testRegistersStaticsAndTemplates(): void {
        $registry = $this->registry(new FakeRespClient([]));
        $this->assertSame(['continuum://locks'], array_column($registry->listResources(), 'uri'));
        $this->assertContains('continuum://locks/{name}', array_column($registry->listResourceTemplates(), 'uriTemplate'));
    }

    public function testListAllHeldLocks(): void {
        $resp = new FakeRespClient([
            ['0', ['continuum:lock:build']],   // SCAN (cursor 0 = done)
            'test-agent',                      // GET lock value
            45000,                             // PTTL
        ]);
        $content = $this->registry($resp)->readResource('continuum://locks');
        $body = json_decode($content['text'], true);
        $this->assertSame(1, $body['count']);
        $this->assertSame('build', $body['locks'][0]['name']);
        $this->assertTrue($body['locks'][0]['mine']);
    }

    public function testCardFreeAndHeld(): void {
        $free = $this->registry(new FakeRespClient([null]))->readResource('continuum://locks/x');
        $this->assertTrue(json_decode($free['text'], true)['free']);

        $held = $this->registry(new FakeRespClient(['bob', 999]))->readResource('continuum://locks/x');
        $body = json_decode($held['text'], true);
        $this->assertFalse($body['free']);
        $this->assertSame('bob', $body['owner']);
        $this->assertSame(999, $body['ttl_ms']);
        $this->assertFalse($body['mine']);
    }
}
