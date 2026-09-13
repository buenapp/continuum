<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\TaskTools;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class TaskToolsTest extends TestCase {

    private function storage(FakeCouch $couch, ?FakeRespClient $resp = null, ?FakeArcade $arcade = null): ContinuumStorage {
        return new ContinuumStorage(
            new ValKeyStore($resp ?? new FakeRespClient([])),
            $couch,
            $arcade ?? new FakeArcade()
        );
    }

    public function testRegistersAllTools(): void {
        $registry = new ToolRegistry();
        $registry->register(new TaskTools($this->storage(new FakeCouch([]))));
        foreach (['task_create', 'task_list', 'task_claim', 'task_update_status', 'task_handoff'] as $tool) {
            $this->assertTrue($registry->hasTool($tool), "missing tool {$tool}");
        }
    }

    public function testCreateWritesAllThreeEnginesAndLogs(): void {
        $resp = new FakeRespClient([]);
        $arcade = new FakeArcade();
        $couch = new FakeCouch([
            ['code' => 201, 'body' => ['id' => 'x', 'rev' => '1-a']],               // PUT task doc
            ['code' => 200, 'body' => ['uuids' => ['ev1']]],                        // _uuids (event)
            ['code' => 201, 'body' => ['id' => 'ev1', 'rev' => '1-b']],             // PUT event
        ]);
        $result = (new TaskTools($this->storage($couch, $resp, $arcade)))->task_create('Demo', 'proj', dependsOn: ['T-PARENT']);
        $this->assertMatchesRegularExpression('/^T-[0-9A-F]{8}$/', $result['task']);
        $this->assertSame('pending', $result['status']);
        $this->assertSame('proj', $result['scope']);
        // durable doc + graph vertex + dependency edge
        $this->assertSame('continuum_tasks/' . $result['task'], $couch->calls[0]['path']);
        $commands = array_column(array_column($arcade->calls, 'body'), 'command');
        $this->assertStringContainsString('UPDATE Task', implode("\n", $commands));
        $this->assertStringContainsString('DEPENDS_ON', implode("\n", $commands));
        // queued in ValKey under the scope queue
        $keys = array_map(fn($c) => $c[1] ?? '', $resp->calls);
        $this->assertContains('continuum:queue:proj', $keys);
    }

    public function testCreateRetriesOnIdCollision(): void {
        $couch = new FakeCouch([
            ['code' => 409, 'body' => ['error' => 'conflict']],                     // id already taken
            ['code' => 201, 'body' => ['id' => 'x', 'rev' => '1-a']],
            ['code' => 200, 'body' => ['uuids' => ['ev2']]],
            ['code' => 201, 'body' => ['id' => 'ev2', 'rev' => '1-x']],
        ]);
        $result = (new TaskTools($this->storage($couch)))->task_create('Retry');
        $this->assertMatchesRegularExpression('/^T-[0-9A-F]{8}$/', $result['task']);
        $this->assertCount(4, $couch->calls);
    }

    public function testCreateRejectsInvalidScope(): void {
        $this->expectException(\InvalidArgumentException::class);
        (new TaskTools($this->storage(new FakeCouch([]))))->task_create('X', '_bad');
    }

    public function testClaimSucceedsOnPendingTask(): void {
        $resp = new FakeRespClient([]);
        $arcade = new FakeArcade();
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '1-a', 'status' => 'pending', 'owner' => null, 'title' => 'T']],
            ['code' => 201, 'body' => ['id' => 'T-1', 'rev' => '2-b']],
            ['code' => 200, 'body' => ['uuids' => ['ev2']]],
            ['code' => 201, 'body' => ['id' => 'ev2', 'rev' => '1-x']],
        ]);
        $result = (new TaskTools($this->storage($couch, $resp, $arcade)))->task_claim('T-1');
        $this->assertSame('claimed', $result['status']);
        $this->assertSame('test-agent', $result['owner']);
        // MVCC rev supplied on the claim write
        $this->assertSame('1-a', $couch->calls[1]['data']['_rev']);
        // CLAIMED_BY edge recorded
        $commands = array_column(array_column($arcade->calls, 'body'), 'command');
        $this->assertStringContainsString('CLAIMED_BY', implode("\n", $commands));
    }

    public function testClaimRejectsHeldTask(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '3-c', 'status' => 'claimed', 'owner' => 'alice']],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not claimable');
        (new TaskTools($this->storage($couch)))->task_claim('T-1');
    }

    public function testClaimConflictRaisesFriendlyError(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '1-a', 'status' => 'pending', 'owner' => null]],
            ['code' => 409, 'body' => ['error' => 'conflict']],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('changed concurrently');
        (new TaskTools($this->storage($couch)))->task_claim('T-1');
    }

    public function testUpdateStatusDoneReleasesClaimAndLogs(): void {
        $arcade = new FakeArcade();
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '2-b', 'status' => 'in_progress', 'owner' => 'test-agent', 'title' => 'T']],
            ['code' => 201, 'body' => ['id' => 'T-1', 'rev' => '3-c']],
            ['code' => 200, 'body' => ['uuids' => ['ev3']]],
            ['code' => 201, 'body' => ['id' => 'ev3', 'rev' => '1-x']],
        ]);
        $result = (new TaskTools($this->storage($couch, null, $arcade)))->task_update_status('T-1', 'done', 'finished');
        $this->assertSame('done', $result['status']);
        $this->assertNull($result['owner']);
        // note recorded on the doc
        $this->assertSame('finished', $couch->calls[1]['data']['notes'][0]['text']);
        // CLAIMED_BY edge removed (matched on the edge's task property)
        $commands = array_column(array_column($arcade->calls, 'body'), 'command');
        $this->assertStringContainsString('DELETE FROM CLAIMED_BY WHERE task = :t', implode("\n", $commands));
    }

    public function testUpdateStatusRequiresOwnership(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '2-b', 'status' => 'in_progress', 'owner' => 'bob']],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('owned by bob');
        (new TaskTools($this->storage($couch)))->task_update_status('T-1', 'blocked');
    }

    public function testHandoffRequeuesAndClearsOwner(): void {
        $resp = new FakeRespClient([]);
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '4-d', 'status' => 'in_progress', 'owner' => 'test-agent', 'title' => 'T', 'scope' => 'proj']],
            ['code' => 201, 'body' => ['id' => 'T-1', 'rev' => '5-e']],
            ['code' => 200, 'body' => ['uuids' => ['ev4']]],
            ['code' => 201, 'body' => ['id' => 'ev4', 'rev' => '1-x']],
        ]);
        $result = (new TaskTools($this->storage($couch, $resp)))->task_handoff('T-1', 'halfway there', 'finish parsing');
        $this->assertSame('pending', $result['status']);
        $this->assertNull($result['owner']);
        $this->assertSame('halfway there', $couch->calls[1]['data']['handoffs'][0]['summary']);
        $keys = array_map(fn($c) => $c[1] ?? '', $resp->calls);
        $this->assertContains('continuum:queue:proj', $keys);
    }
}
