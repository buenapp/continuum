<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\MemoryTools;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\CouchDBStore;
use Continuum\Storage\ArcadeDBStore;
use Continuum\Storage\ContinuumStorage;
use Continuum\Bridge\HeliofaneMcpBridge;

class MemoryToolsTest extends TestCase {

    private function tools(?HeliofaneMcpBridge $bridge, ?FakeCouch $couch = null): MemoryTools {
        return new MemoryTools(
            new ContinuumStorage(new ValKeyStore(new FakeRespClient([])),
                $couch ?? new FakeCouch([['code' => 200, 'body' => ['uuids' => ['e']]], ['code' => 201, 'body' => []]]),
                new FakeArcade()),
            $bridge
        );
    }

    public function testRegisters(): void {
        $registry = new ToolRegistry();
        $registry->register($this->tools(null));
        $this->assertTrue($registry->hasTool('promote_to_memory'));
    }

    public function testNoteSuccessPath(): void {
        $bridge = new FakeHeliofaneBridge([['ok' => true, 'result' => [], 'error' => null]]);
        $result = $this->tools($bridge)->promote_to_memory('Project X', ['fact one', 'fact two']);
        $data = $result->getStructuredContent();
        $this->assertSame(2, $data['promoted']);
        $this->assertFalse($data['created']);
        $this->assertStringContainsString("Promoted 2 facts to memory for 'Project X'", $result->toArray()['content'][0]['text']);
        $this->assertSame('note', $bridge->calls[0]['tool']);
        $this->assertSame(['fact one', 'fact two'], $bridge->calls[0]['arguments']['observations']);
    }

    public function testMissingEntityFallsBackToCreate(): void {
        $bridge = new FakeHeliofaneBridge([
            ['ok' => false, 'result' => null, 'error' => "Unknown entity 'New Thing'"],
            ['ok' => true, 'result' => [], 'error' => null],
        ]);
        $result = $this->tools($bridge)->promote_to_memory('New Thing', ['born today'], 'project');
        $this->assertTrue($result->getStructuredContent()['created']);
        $this->assertStringContainsString('(entity created)', $result->toArray()['content'][0]['text']);
        $this->assertSame('remember', $bridge->calls[1]['tool']);
        $this->assertSame('New Thing', $bridge->calls[1]['arguments']['entities'][0]['name']);
        $this->assertSame('project', $bridge->calls[1]['arguments']['entities'][0]['entityType']);
    }

    public function testFailureWithoutCreateFlagThrows(): void {
        $bridge = new FakeHeliofaneBridge([['ok' => false, 'result' => null, 'error' => 'boom']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $this->tools($bridge)->promote_to_memory('X', ['f'], null, false);
    }

    public function testUnconfiguredBridgeGivesConfigHint(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not configured');
        $this->tools(null)->promote_to_memory('X', ['f']);
    }

    public function testPromotionIsLogged(): void {
        $bridge = new FakeHeliofaneBridge([['ok' => true, 'result' => [], 'error' => null]]);
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['uuids' => ['ev']]],
            ['code' => 201, 'body' => []],
        ]);
        $this->tools($bridge, $couch)->promote_to_memory('X', ['f']);
        $this->assertSame('promote_to_memory', $couch->calls[1]['data']['type']);
    }
}
