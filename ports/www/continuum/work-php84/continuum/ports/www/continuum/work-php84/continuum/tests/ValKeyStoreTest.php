<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\Storage\ValKeyStore;

class ValKeyStoreTest extends TestCase {

    private function store(array $script = []): ValKeyStore {
        return new ValKeyStore(new FakeRespClient($script));
    }

    public function testAcquireLockUsesSetNxEx(): void {
        $fake = new FakeRespClient(['OK']);
        $store = new ValKeyStore($fake);
        $this->assertTrue($store->acquireLock('board:schedule', 'agent-a', 30));
        $this->assertSame('SET', $fake->calls[0][0]);
        $this->assertSame(['continuum:lock:board:schedule', 'agent-a', 'NX', 'EX', '30'],
            array_slice($fake->calls[0], 1));
    }

    public function testAcquireLockFailsWhenHeld(): void {
        $this->assertFalse($this->store([null])->acquireLock('board:schedule', 'agent-b', 30));
    }

    public function testReleaseLockOwnerScopedViaLua(): void {
        $fake = new FakeRespClient([1]);
        $this->assertTrue((new ValKeyStore($fake))->releaseLock('l', 'agent-a'));
        $call = $fake->calls[0];
        $this->assertSame('EVAL', $call[0]);
        $this->assertStringContainsString('redis.call("get"', $call[1]); // compare-then-delete
        $this->assertSame('continuum:lock:l', $call[3]);
        $this->assertSame('agent-a', $call[4]);
    }

    public function testReleaseLockByNonOwnerFails(): void {
        $this->assertFalse($this->store([0])->releaseLock('l', 'agent-b'));
    }

    public function testEnqueueThenClaimRoundTrip(): void {
        $fake = new FakeRespClient([1, 1, 'T-1', ['payload', '{"title":"x"}']]);
        $store = new ValKeyStore($fake);
        $store->enqueueTask('default', 'T-1', ['title' => 'x']);
        $this->assertSame(['HSET', 'continuum:task:T-1', 'payload', '{"title":"x"}'], $fake->calls[0]);
        $this->assertSame(['RPUSH', 'continuum:queue:default', 'T-1'], $fake->calls[1]);
        $claimed = $store->claimTask('default');
        $this->assertSame('T-1', $claimed['id']);
        $this->assertSame(['title' => 'x'], $claimed['payload']);
    }

    public function testClaimEmptyQueueReturnsNull(): void {
        $this->assertNull($this->store([null])->claimTask('default'));
    }

    public function testInboxDrainEmptiesAndDecodes(): void {
        $fake = new FakeRespClient([['{"m":1}'], 1]);
        $store = new ValKeyStore($fake);
        $this->assertSame([['m' => 1]], $store->inboxDrain('agent-a'));
        $this->assertSame('LRANGE', $fake->calls[0][0]);
        $this->assertSame('DEL', $fake->calls[1][0]);
    }

    public function testHeartbeatRegistersAgent(): void {
        $fake = new FakeRespClient([1, 2]);
        (new ValKeyStore($fake))->heartbeat('agent-a', ['pid' => 42]);
        $this->assertSame('SADD', $fake->calls[0][0]);
        $this->assertSame('HSET', $fake->calls[1][0]);
        $this->assertContains('pid', $fake->calls[1]);
        $this->assertContains('42', $fake->calls[1]);
    }
}
