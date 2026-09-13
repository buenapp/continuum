<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\LockTools;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class LockToolsTest extends TestCase {

    private function tools(FakeRespClient $resp, ?FakeCouch $couch = null): LockTools {
        $storage = new ContinuumStorage(
            new ValKeyStore($resp),
            $couch ?? new FakeCouch([['code' => 200, 'body' => ['uuids' => ['e']]], ['code' => 201, 'body' => []]]),
            new FakeArcade()
        );
        return new LockTools($storage);
    }

    public function testAcquireFreeLockLogsEvent(): void {
        $resp = new FakeRespClient([
            null,    // GET lock:build (free)
            'OK',    // SET NX EX
        ]);
        $result = $this->tools($resp)->advisory_lock_acquire('build', 60);
        $this->assertTrue($result['acquired']);
        $this->assertSame('test-agent', $result['owner']);
        // lock value is the agent identity with the TTL
        $set = $resp->calls[1];
        $this->assertSame('SET', $set[0]);
        $this->assertSame('test-agent', $set[2]);
        $this->assertContains('60', $set);
    }

    public function testAcquireHeldByOtherReturnsOwnerWithoutMutation(): void {
        $resp = new FakeRespClient([
            'bob',   // GET lock:deploy
            45000,   // PTTL
        ]);
        $result = $this->tools($resp)->advisory_lock_acquire('deploy');
        $this->assertFalse($result['acquired']);
        $this->assertSame('bob', $result['owner']);
        $this->assertSame(45000, $result['ttl_ms']);
        $this->assertCount(2, $resp->calls); // no SET attempted
    }

    public function testReleaseOnlyAsOwner(): void {
        $resp = new FakeRespClient([1]); // Lua compare-and-delete matched
        $result = $this->tools($resp)->advisory_lock_release('build');
        $this->assertTrue($result['released']);
        $this->assertSame('EVAL', $resp->calls[0][0]);
    }

    public function testReleaseForeignLockFails(): void {
        $resp = new FakeRespClient([0]); // Lua returned 0: not the owner
        $result = $this->tools($resp)->advisory_lock_release('build');
        $this->assertFalse($result['released']);
    }

    public function testCheckFreeAndHeld(): void {
        $free = $this->tools(new FakeRespClient([null]))->advisory_lock_check('x')->getStructuredContent();
        $this->assertTrue($free['free']);
        $heldResult = $this->tools(new FakeRespClient(['alice', 999]))->advisory_lock_check('x');
        $held = $heldResult->getStructuredContent();
        $this->assertFalse($held['free']);
        $this->assertSame('alice', $held['owner']);
        $this->assertFalse($held['mine']);
        $this->assertStringContainsString("held by alice (999ms TTL left)", $heldResult->toArray()['content'][0]['text']);
    }
}
