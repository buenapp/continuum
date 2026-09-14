<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\ContextTools;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class ContextToolsTest extends TestCase {

    private function tools(FakeCouch $couch, ?FakeRespClient $resp = null, ?FakeArcade $arcade = null): ContextTools {
        return new ContextTools(new ContinuumStorage(
            new ValKeyStore($resp ?? new FakeRespClient([])),
            $couch,
            $arcade ?? new FakeArcade()
        ));
    }

    public function testRegisters(): void {
        $registry = new ToolRegistry();
        $registry->register($this->tools(new FakeCouch([])));
        $this->assertTrue($registry->hasTool('context_pack'));
    }

    private function boardsRows(): array {
        return ['rows' => [
            (object)['id' => 'proj/roadmap', 'doc' => (object)['value' => 'ship phase 4', 'updated_by' => 'devin', 'updated_at' => '2026-09-13T02:00:00Z']],
            (object)['id' => 'global/motto', 'doc' => (object)['value' => 'what happens next', 'updated_by' => 'devin', 'updated_at' => '2026-09-13T01:00:00Z']],
        ]];
    }

    public function testPackFocusesTaskWithGraphNeighborhood(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '1-a', 'status' => 'in_progress', 'title' => 'Fix parser', 'scope' => 'proj', 'notes' => [['agent' => 'devin', 'text' => 'near done']], 'handoffs' => []]],
            ['code' => 200, 'body' => ['rows' => [
                (object)['id' => 'T-9', 'doc' => (object)['status' => 'in_progress', 'title' => 'Fix parser', 'scope' => 'proj', 'priority' => 1, 'updated_at' => '2026-09-13T02:01:00Z']],
                (object)['id' => 'T-2', 'doc' => (object)['status' => 'pending', 'title' => 'Write tests', 'scope' => 'proj', 'priority' => 2, 'updated_at' => '2026-09-13T02:00:00Z']],
                (object)['id' => 'T-3', 'doc' => (object)['status' => 'done', 'title' => 'Old', 'scope' => 'proj', 'updated_at' => '2026-09-13T01:00:00Z']],
            ]]],
            ['code' => 200, 'body' => $this->boardsRows()],
            ['code' => 200, 'body' => $this->boardsRows()],
        ]);
        $arcade = new FakeArcade([
            ['result' => [['id' => 'T-1', 'title' => 'Parent', 'status' => 'done']]],
            ['result' => [['id' => 'T-10', 'title' => 'Followup', 'status' => 'pending']]],
        ]);
        $resp = new FakeRespClient([
            ['devin'],                            // SMEMBERS agents
            ['heartbeat', '1757729800'],          // HGETALL devin
            [],                                   // SMEMBERS agent-sessions:devin
            '1757729800',                         // HGET heartbeat devin
        ]);
        $result = $this->tools($couch, $resp, $arcade)->context_pack('proj', 'T-9');
        $pack = $result->getStructuredContent()['pack'];
        $this->assertStringContainsString('## Focus: Fix parser [in_progress] (T-9)', $pack);
        $this->assertStringContainsString('note(devin): near done', $pack);
        $this->assertStringContainsString('depends on: Parent [done] (T-1)', $pack);
        $this->assertStringContainsString('blocks: Followup [pending] (T-10)', $pack);
        $this->assertStringContainsString('## Open tasks (2)', $pack); // done task excluded
        // human-first task lines: title leads, coordination id trails
        $this->assertStringContainsString('- Write tests [pending, p2] (T-2)', $pack);
        $this->assertStringContainsString('- Fix parser [in_progress, p1] (T-9)', $pack);
        $this->assertStringContainsString('## Board: proj', $pack);
        $this->assertStringContainsString('roadmap', $pack);
        $this->assertStringContainsString('## Agents', $pack);
        $this->assertStringContainsString('devin', $pack);
        // dual format: the text block IS the pack; structuredContent alongside
        $this->assertSame($pack, $result->toArray()['content'][0]['text']);
        $this->assertSame('proj', $result->getStructuredContent()['scope']);
    }

    public function testBudgetTrimsAndReportsOmissions(): void {
        $long = str_repeat('x', 200);
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $rows[] = (object)['id' => 'global/k' . $i, 'doc' => (object)['value' => $long, 'updated_by' => 'devin', 'updated_at' => '2026-09-13T02:0' . $i . ':00Z']];
        }
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['rows' => []]],
            ['code' => 200, 'body' => ['rows' => $rows]],
        ]);
        $result = $this->tools($couch)->context_pack(null, null, 100)->getStructuredContent();
        $this->assertLessThanOrEqual(100, $result['est_tokens']);
        $this->assertGreaterThan(0, $result['omitted']['board']);
    }

    public function testUnknownFocusTaskThrows(): void {
        $couch = new FakeCouch([['code' => 404, 'body' => null]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $this->tools($couch)->context_pack(null, 'T-X');
    }
}
