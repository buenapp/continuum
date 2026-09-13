<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\TaskTools;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class MilestoneSyncTest extends TestCase {

    private function tools(FakeMilestoneSync $sync, FakeCouch $couch): TaskTools {
        return new TaskTools(
            new ContinuumStorage(new ValKeyStore(new FakeRespClient([])), $couch, new FakeArcade()),
            $sync
        );
    }

    public function testClaimFiresStarted(): void {
        $sync = new FakeMilestoneSync();
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '1-a', 'status' => 'pending', 'owner' => null, 'title' => 'T', 'phorge_task_id' => 'T123']],
            ['code' => 201, 'body' => ['id' => 'T-1', 'rev' => '2-b']],
            ['code' => 200, 'body' => ['uuids' => ['ev1']]],
            ['code' => 201, 'body' => []],
        ]);
        $this->tools($sync, $couch)->task_claim('T-1');
        $this->assertCount(1, $sync->syncs);
        $this->assertSame('started', $sync->syncs[0]['milestone']);
        $this->assertSame('T123', $sync->syncs[0]['data']['phorge_task_id']);
        $this->assertSame('test-agent', $sync->syncs[0]['data']['agent']);
    }

    public function testBlockedAndDoneFireWithContext(): void {
        $sync = new FakeMilestoneSync();
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '2-b', 'status' => 'in_progress', 'owner' => 'test-agent', 'title' => 'T']],
            ['code' => 201, 'body' => ['id' => 'T-1', 'rev' => '3-c']],
            ['code' => 200, 'body' => ['uuids' => ['ev1']]],
            ['code' => 201, 'body' => []],
            ['code' => 200, 'body' => ['_rev' => '3-c', 'status' => 'blocked', 'owner' => 'test-agent', 'title' => 'T']],
            ['code' => 201, 'body' => ['id' => 'T-1', 'rev' => '4-d']],
            ['code' => 200, 'body' => ['uuids' => ['ev2']]],
            ['code' => 201, 'body' => []],
        ]);
        $tools = $this->tools($sync, $couch);
        $tools->task_update_status('T-1', 'blocked', 'waiting on upstream');
        $tools->task_update_status('T-1', 'done', 'shipped');
        $this->assertSame(['blocked', 'resolved'], array_column($sync->syncs, 'milestone'));
        $this->assertSame('waiting on upstream', $sync->syncs[0]['data']['reason']);
        $this->assertSame('shipped', $sync->syncs[1]['data']['summary']);
    }

    public function testNonMilestoneStatusesDoNotFire(): void {
        $sync = new FakeMilestoneSync();
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '2-b', 'status' => 'claimed', 'owner' => 'test-agent']],
            ['code' => 201, 'body' => ['id' => 'T-1', 'rev' => '3-c']],
            ['code' => 200, 'body' => ['uuids' => ['ev1']]],
            ['code' => 201, 'body' => []],
        ]);
        $this->tools($sync, $couch)->task_update_status('T-1', 'in_progress');
        $this->assertCount(0, $sync->syncs);
    }
}
