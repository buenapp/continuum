<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\EventTools;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class EventToolsTest extends TestCase {

    private function tools(FakeCouch $couch): EventTools {
        return new EventTools(new ContinuumStorage(
            new ValKeyStore(new FakeRespClient([])), $couch, new FakeArcade()
        ));
    }

    private function row(string $id, array $doc): object {
        return (object)['id' => $id, 'doc' => (object)$doc];
    }

    public function testNewestFirstWithFiltersAndLimit(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => ['rows' => [
            $this->row('E1', ['agent' => 'devin', 'type' => 'task_claim', 'ts' => '2026-09-13T01:00:00.000Z', 'data' => ['task' => 'T-1', 'scope' => 'proj']]),
            $this->row('E2', ['agent' => 'devin', 'type' => 'blackboard_write', 'ts' => '2026-09-13T03:00:00.000Z', 'data' => ['scope' => 'proj', 'key' => 'k']]),
            $this->row('E3', ['agent' => 'devin', 'type' => 'task_claim', 'ts' => '2026-09-13T02:00:00.000Z', 'data' => ['task' => 'T-2', 'scope' => 'ops']]),
        ]]]]);
        $all = $this->tools($couch)->event_log();
        $this->assertSame(['E2', 'E3', 'E1'], array_column($all['events'], 'id'));
        // filter by type
        $claims = $this->tools($couch->replay())->event_log(type: 'task_claim');
        $this->assertSame(2, $claims['count']);
        // filter by scope
        $scoped = $this->tools($couch->replay())->event_log(scope: 'ops');
        $this->assertSame(['E3'], array_column($scoped['events'], 'id'));
        // since filter is an ISO-8601 lower bound
        $recent = $this->tools($couch->replay())->event_log(since: '2026-09-13T02:30:00.000Z');
        $this->assertSame(['E2'], array_column($recent['events'], 'id'));
        // limit clips, total reports pre-clip count
        $clipped = $this->tools($couch->replay())->event_log(limit: 2);
        $this->assertSame(2, $clipped['count']);
        $this->assertSame(3, $clipped['total']);
    }
}
