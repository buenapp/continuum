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

    public function testInboxLeaseAdoptsInactiveTargetWithoutRewritingEnvelope(): void {
        $now = time();
        $original = [
            'id' => 'M-OLD', 'from' => 'alice', 'body' => 'recover', 'session' => 'old',
            'sig' => 'c2ln', 'cert' => '{"session":"old"}',
        ];
        $fake = new FakeRespClient([
            'OK',                       // WATCH inbox
            [json_encode($original), json_encode(['id' => 'M-OPEN', 'body' => 'untargeted'])],
            'OK', 'OK',                 // WATCH session set + old target hash
            ['old', 'new'],             // SMEMBERS agent-sessions:agent-a
            [],                         // HGETALL old: presence expired
            1,                          // SREM old ghost
            ['session', 'new', 'heartbeat', '1'], // HGETALL new: live
            'OK', 'QUEUED', 'QUEUED', [1, 2], // MULTI, DEL, RPUSH, EXEC
        ]);
        $result = (new ValKeyStore($fake))->inboxLease('agent-a', 'new', 10, 60, $now, false);
        $this->assertSame(1, count($result['messages']));
        $this->assertSame($original['session'], $result['messages'][0]['session']);
        $this->assertSame($original['sig'], $result['messages'][0]['sig']);
        $this->assertSame($original['cert'], $result['messages'][0]['cert']);
        $this->assertSame($result['id'], $result['messages'][0]['lease']);
        $this->assertSame('SREM', $fake->calls[6][0]);
    }

    public function testInactiveTargetWaitsForActiveMessageLeaseExpiry(): void {
        $now = time();
        $entry = json_encode(['id' => 'M-OLD', 'session' => 'old', 'lease' => 'L-ACTIVE', 'lease_until' => $now + 60]);
        $fake = new FakeRespClient([
            'OK', [$entry],             // WATCH, LRANGE
            'OK', 'OK',                 // WATCH session set + old target hash
            [],                         // SMEMBERS agent-sessions: no live target
            'OK',                       // UNWATCH: no changes
        ]);
        $result = (new ValKeyStore($fake))->inboxLease('agent-a', 'new', 10, 60, $now, false);
        $this->assertSame([], $result['messages']);
        $this->assertSame('UNWATCH', $fake->calls[5][0]);
    }

    public function testExpiredMessageIsNotRecovered(): void {
        $now = time();
        $entry = json_encode(['id' => 'M-OLD', 'session' => 'old', 'expires' => gmdate('c', $now - 1)]);
        $fake = new FakeRespClient([
            'OK', [$entry],             // WATCH, LRANGE
            'OK', 'OK',                 // WATCH session set + old target hash
            [],                         // SMEMBERS agent-sessions
            'OK', 'QUEUED', [0],        // MULTI, DEL, EXEC (expired entry dropped)
        ]);
        $result = (new ValKeyStore($fake))->inboxLease('agent-a', 'new', 10, 60, $now, false);
        $this->assertSame([], $result['messages']);
    }

    public function testTargetHeartbeatDuringLeaseInvalidatesOrphanAdoption(): void {
        $now = time();
        $entry = json_encode(['id' => 'M-OLD', 'session' => 'old']);
        $fake = new FakeRespClient([
            'OK', [$entry], 'OK', 'OK', [],       // attempt 1: target inactive
            'OK', 'QUEUED', 'QUEUED', null,       // EXEC fails after target heartbeats
            'OK', [$entry], 'OK', 'OK',
            ['old'], ['session', 'old', 'heartbeat', (string)$now], // attempt 2: live
            'OK',                                 // UNWATCH: no adoption
        ]);
        $result = (new ValKeyStore($fake))->inboxLease('agent-a', 'new', 10, 60, $now, false);
        $this->assertSame([], $result['messages']);
        $this->assertSame('UNWATCH', $fake->calls[15][0]);
    }

    public function testZeroSessionIdIsNotTreatedAsUntargeted(): void {
        $now = time();
        $targeted = json_encode(['id' => 'M-ZERO', 'session' => '0']);
        $open = json_encode(['id' => 'M-OPEN']);
        $fake = new FakeRespClient([
            'OK', [$targeted, $open],           // inboxLease: WATCH, LRANGE
            'OK', 'OK', ['0'], ['heartbeat', '1'],
            'OK', 'QUEUED', 'QUEUED', [1, 2],   // only open is leased
            'OK', [$targeted, $open],           // inboxPull: WATCH, LRANGE
            'OK', 'QUEUED', 'QUEUED', [1, 1],   // only open is pulled
        ]);
        $store = new ValKeyStore($fake);
        $leased = $store->inboxLease('agent-a', 'new', 10, 60, $now, true);
        $this->assertSame(['M-OPEN'], array_column($leased['messages'], 'id'));
        $pulled = $store->inboxPull('agent-a', 10);
        $this->assertSame(['M-OPEN'], array_column($pulled, 'id'));
    }

    public function testOrphanRecoveryCannotCrossInboxIdentity(): void {
        $fake = new FakeRespClient([
            'OK', [],                   // WATCH, LRANGE empty agent-b inbox
            'OK', [],                   // WATCH + SMEMBERS agent-sessions:agent-b
        ]);
        $result = (new ValKeyStore($fake))->inboxLease('agent-b', 'new', 10, 60, time(), false);
        $this->assertSame([], $result['messages']);
        foreach ($fake->calls as $call) {
            $this->assertNotContains('continuum:inbox:agent-a', $call);
        }
    }
}
