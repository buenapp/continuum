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
        foreach (['bb_write', 'bb_read', 'bb_keys', 'bb_delete'] as $tool) {
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
        $result = (new BoardTools($this->storage($couch)))->bb_write('foo', ['n' => 1]);
        $this->assertSame('global', $result['scope']);
        $this->assertSame('test-agent', $result['updated_by']);
        $this->assertSame('1-a', $result['rev']);
        // event log write went to continuum_events with the agent identity
        $this->assertSame('continuum_events/' . 'ev1', $couch->calls[3]['path']);
        $this->assertSame('test-agent', $couch->calls[3]['data']['agent']);
        $this->assertSame('bb_write', $couch->calls[3]['data']['type']);
    }

    public function testReadMissingThrows(): void {
        $couch = new FakeCouch([['code' => 404, 'body' => null]]);
        $this->expectException(\RuntimeException::class);
        (new BoardTools($this->storage($couch)))->bb_read('nope');
    }

    public function testWriteRejectsReservedScopeBeforeAnyEngineCall(): void {
        // '_' is the CouchDB reserved id prefix; validation must fire first.
        $couch = new FakeCouch([]);
        $this->expectException(\InvalidArgumentException::class);
        (new BoardTools($this->storage($couch)))->bb_write('foo', 'x', '_live');
    }

    public function testDeleteRejectsForeignEntry(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['value' => 'x', 'updated_by' => 'bob', '_rev' => '1-a']],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not permitted');
        (new BoardTools($this->storage($couch)))->bb_delete('foo');
    }

    public function testDeleteAllowedInOwnScope(): void {
        $hiddenagent = 'test-agent';
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['value' => 'x', 'updated_by' => 'bob', '_rev' => '2-c']],
            ['code' => 200, 'body' => ['ok' => true]],                                // DELETE
            ['code' => 200, 'body' => ['uuids' => ['ev2']]],
            ['code' => 201, 'body' => ['id' => 'ev2', 'rev' => '1-x']],
        ]);
        $result = (new BoardTools($this->storage($couch)))->bb_delete('foo', $hiddenagent);
        $this->assertTrue($result['deleted']);
        $this->assertStringStartsWith('DELETE', $couch->calls[1]['verb']);
    }
}
