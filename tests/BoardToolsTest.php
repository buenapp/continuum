<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\BoardTools;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class BoardToolsTest extends TestCase {

    private function storage(FakeCouch $couch): ContinuumStorage {
        return new ContinuumStorage(new ValKeyStore(new FakeRespClient([])), $couch, new FakeArcade());
    }

    public function testRegistersAllTools(): void {
        $registry = new ToolRegistry();
        $registry->register(new BoardTools($this->storage(new FakeCouch([]))));
        foreach (['blackboard_write', 'blackboard_read', 'blackboard_keys', 'blackboard_delete'] as $tool) {
            $this->assertTrue($registry->hasTool($tool), "missing tool {$tool}");
        }
    }

    public function testWriteCreatesEntryAndLogs(): void {
        $couch = new FakeCouch([
            ['code' => 404, 'body' => null],                                        // GET boards/global/foo
            ['code' => 201, 'body' => ['id' => 'global/foo', 'rev' => '1-a']],      // PUT entry
            ['code' => 200, 'body' => ['uuids' => ['ev1']]],                        // _uuids
            ['code' => 201, 'body' => ['id' => 'ev1', 'rev' => '1-b']],             // PUT event
        ]);
        $result = (new BoardTools($this->storage($couch)))->blackboard_write('foo', ['n' => 1]);
        $this->assertSame('global', $result['scope']);
        $this->assertSame('test-agent', $result['updated_by']);
        $this->assertSame('1-a', $result['rev']);
        // event log write went to continuum_events with the agent identity
        $this->assertSame('continuum_events/' . 'ev1', $couch->calls[3]['path']);
        $this->assertSame('test-agent', $couch->calls[3]['data']['agent']);
        $this->assertSame('blackboard_write', $couch->calls[3]['data']['type']);
    }

    public function testReadMissingThrows(): void {
        $couch = new FakeCouch([['code' => 404, 'body' => null]]);
        $this->expectException(\RuntimeException::class);
        (new BoardTools($this->storage($couch)))->blackboard_read('nope');
    }

    public function testReadReturnsDualFormat(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['value' => ['n' => 1], 'updated_by' => 'alice', 'updated_at' => '2026-09-13T01:00:00Z']],
        ]);
        $result = (new BoardTools($this->storage($couch)))->blackboard_read('foo');
        $data = $result->getStructuredContent();
        $this->assertSame('global', $data['scope']);
        $this->assertSame(['n' => 1], $data['value']);
        $this->assertSame('alice', $data['updated_by']);
        // text block renders the entry human-first, value as a json block
        $text = $result->toArray()['content'][0]['text'];
        $this->assertStringContainsString('# global/foo', $text);
        $this->assertStringContainsString('by alice', $text);
        $this->assertStringContainsString('"n": 1', $text);
    }

    public function testKeysListsScope(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['rows' => [
                (object)['id' => 'global/a', 'doc' => null],
                (object)['id' => 'global/b', 'doc' => null],
                (object)['id' => 'ops/c', 'doc' => null],
            ]]],
        ]);
        $result = (new BoardTools($this->storage($couch)))->blackboard_keys();
        $this->assertSame(['a', 'b'], $result->getStructuredContent()['keys']);
        $this->assertStringContainsString('- a', $result->toArray()['content'][0]['text']);
    }

    public function testWriteRejectsReservedScopeBeforeAnyEngineCall(): void {
        // '_' is the CouchDB reserved id prefix; validation must fire first.
        $couch = new FakeCouch([]);
        $this->expectException(\InvalidArgumentException::class);
        (new BoardTools($this->storage($couch)))->blackboard_write('foo', 'x', '_live');
    }

    public function testDeleteRejectsForeignEntry(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['value' => 'x', 'updated_by' => 'bob', '_rev' => '1-a']],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not permitted');
        (new BoardTools($this->storage($couch)))->blackboard_delete('foo');
    }

    public function testDeleteAllowedInOwnScope(): void {
        $hiddenagent = 'test-agent';
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['value' => 'x', 'updated_by' => 'bob', '_rev' => '2-c']],
            ['code' => 200, 'body' => ['ok' => true]],                                // DELETE
            ['code' => 200, 'body' => ['uuids' => ['ev2']]],
            ['code' => 201, 'body' => ['id' => 'ev2', 'rev' => '1-x']],
        ]);
        $result = (new BoardTools($this->storage($couch)))->blackboard_delete('foo', $hiddenagent);
        $this->assertTrue($result['deleted']);
        $this->assertStringStartsWith('DELETE', $couch->calls[1]['verb']);
    }
}
