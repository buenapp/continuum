<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\EventResources;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class EventResourcesTest extends TestCase {

    private function registry(FakeCouch $couch): ToolRegistry {
        $registry = new ToolRegistry();
        $registry->register(new EventResources(new ContinuumStorage(
            new ValKeyStore(new FakeRespClient([])), $couch, new FakeArcade()
        )));
        return $registry;
    }

    private function rows(): array {
        return ['rows' => [
            (object)['id' => 'E1', 'doc' => (object)['agent' => 'devin', 'type' => 'task_claim', 'ts' => '2026-09-13T01:00:00Z', 'data' => ['task' => 'T-1']]],
            (object)['id' => 'E2', 'doc' => (object)['agent' => 'alice', 'type' => 'blackboard_write', 'ts' => '2026-09-13T03:00:00Z', 'data' => ['scope' => 'global', 'key' => 'k']]],
            (object)['id' => 'E3', 'doc' => (object)['agent' => 'devin', 'type' => 'task_handoff', 'ts' => '2026-09-13T02:00:00Z', 'data' => ['task' => 'T-2']]],
        ]];
    }

    public function testRegistersStaticsAndTemplates(): void {
        $registry = $this->registry(new FakeCouch([]));
        $this->assertSame(['continuum://events'], array_column($registry->listResources(), 'uri'));
        $this->assertContains('continuum://events/since/{timestamp}', array_column($registry->listResourceTemplates(), 'uriTemplate'));
    }

    public function testTailNewestFirstWithLastModified(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => $this->rows()]]);
        $content = $this->registry($couch)->readResource('continuum://events');
        $body = json_decode($content['text'], true);
        $this->assertSame(3, $body['count']);
        $this->assertSame(['E2', 'E3', 'E1'], array_column($body['events'], 'id'));
        $this->assertSame('2026-09-13T03:00:00Z', $content['annotations']['lastModified']);
    }

    public function testSinceReplaysFromTimestamp(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => $this->rows()]]);
        $content = $this->registry($couch)->readResource('continuum://events/since/2026-09-13T02:00:00Z');
        $body = json_decode($content['text'], true);
        $this->assertSame(['E2', 'E3'], array_column($body['events'], 'id'));
    }
}
