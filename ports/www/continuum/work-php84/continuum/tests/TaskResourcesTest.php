<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\TaskResources;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class TaskResourcesTest extends TestCase {

    private function registry(FakeCouch $couch, ?FakeArcade $arcade = null): ToolRegistry {
        $registry = new ToolRegistry();
        $registry->register(new TaskResources(new ContinuumStorage(
            new ValKeyStore(new FakeRespClient([])), $couch, $arcade ?? new FakeArcade()
        )));
        return $registry;
    }

    public function testRegistersStaticsAndTemplates(): void {
        $registry = $this->registry(new FakeCouch([]));
        $this->assertSame(['continuum://tasks'], array_column($registry->listResources(), 'uri'));
        $this->assertContains('continuum://tasks/{id}', array_column($registry->listResourceTemplates(), 'uriTemplate'));
    }

    public function testOpenListExcludesTerminalAndOrdersNewestFirst(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => ['rows' => [
            (object)['id' => 'T-1', 'doc' => (object)['title' => 'Old', 'status' => 'pending', 'updated_at' => '2026-09-13T01:00:00Z']],
            (object)['id' => 'T-2', 'doc' => (object)['title' => 'Done already', 'status' => 'done', 'updated_at' => '2026-09-13T03:00:00Z']],
            (object)['id' => 'T-3', 'doc' => (object)['title' => 'Active', 'status' => 'in_progress', 'owner' => 'bob', 'updated_at' => '2026-09-13T02:00:00Z']],
        ]]]]);
        $content = $this->registry($couch)->readResource('continuum://tasks');
        $body = json_decode($content['text'], true);
        $this->assertSame(2, $body['count']);
        $this->assertSame(['T-3', 'T-1'], array_column($body['tasks'], 'task'));
        $this->assertSame('2026-09-13T02:00:00Z', $content['annotations']['lastModified']);
    }

    public function testCardIncludesNotesHandoffsAndGraph(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => [
            '_rev' => '2-a', 'title' => 'Fix parser', 'status' => 'in_progress', 'owner' => 'test-agent',
            'scope' => 'proj', 'updated_at' => '2026-09-13T02:00:00Z',
            'notes' => [['agent' => 'bob', 'text' => 'digging']],
            'handoffs' => [['agent' => 'alice', 'summary' => 'started', 'ts' => '2026-09-12T00:00:00Z']],
        ]]]);
        $arcade = new FakeArcade([
            ['result' => [['id' => 'T-0', 'title' => 'Prereq', 'status' => 'done']]],
            ['result' => []],
        ]);
        $content = $this->registry($couch, $arcade)->readResource('continuum://tasks/T-1');
        $body = json_decode($content['text'], true);
        $this->assertSame('T-1', $body['task']);
        $this->assertSame('Fix parser', $body['title']);
        $this->assertSame('digging', $body['notes'][0]['text']);
        $this->assertSame('started', $body['handoffs'][0]['summary']);
        $this->assertSame('T-0', $body['depends_on'][0]['id']);
        $this->assertSame([], $body['blocks']);
        $this->assertArrayNotHasKey('_rev', $body);
        $this->assertSame('2026-09-13T02:00:00Z', $content['annotations']['lastModified']);
    }

    public function testCardMissingThrows(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('task T-X not found');
        $this->registry(new FakeCouch([['code' => 404, 'body' => null]]))->readResource('continuum://tasks/T-X');
    }
}
