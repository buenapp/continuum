<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\CouchDBStore;
use Continuum\Storage\ArcadeDBStore;
use Continuum\Storage\ContinuumStorage;

class ContinuumStorageTest extends TestCase {

    private function facade(FakeCouch $couch, FakeArcade $arcade): ContinuumStorage {
        return new ContinuumStorage(new ValKeyStore(new FakeRespClient([])), $couch, $arcade);
    }

    public function testSaveTaskWritesDurableAndGraph(): void {
        $couch = new FakeCouch([['code' => 201, 'body' => ['id' => 'T-1', 'rev' => '1-a']]]);
        $arcade = new FakeArcade();
        $this->facade($couch, $arcade)->saveTask('T-1', ['status' => 'open', 'title' => 'demo']);
        $this->assertCount(1, $couch->calls);
        $this->assertSame('continuum_tasks/T-1', $couch->calls[0]['path']);
        $this->assertCount(1, $arcade->calls);
        $this->assertStringContainsString('UPDATE Task', $arcade->calls[0]['body']['command']);
    }

    public function testScopeNormalizationKeepsOtherIdentifiersExact(): void {
        $resp = new FakeRespClient([]);
        $couch = new FakeCouch([
            ['code' => 201, 'body' => ['id' => 'T-MixedID', 'rev' => '1-a']],
            ['code' => 201, 'body' => ['id' => 'heliofane/CaseKey', 'rev' => '1-a']],
        ]);
        $storage = new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade());
        $storage->saveTask('T-MixedID', ['title' => 'demo', 'scope' => 'HELIOFANE', 'session' => 'SessionMixed']);
        $storage->enqueueTask('Heliofane', 'T-MixedID', []);
        $storage->saveBoardEntry('HELIOFANE', 'CaseKey', ['value' => true]);

        $this->assertSame('continuum_tasks/T-MixedID', $couch->calls[0]['path']);
        $this->assertSame('heliofane', $couch->calls[0]['data']['scope']);
        $this->assertSame('SessionMixed', $couch->calls[0]['data']['session']);
        $this->assertContains('continuum:queue:heliofane', array_map(fn($call) => $call[1] ?? '', $resp->calls));
        $this->assertSame('continuum_boards/heliofane%2FCaseKey', $couch->calls[1]['path']);
    }

    public function testListBoardKeysScoped(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => [
            'rows' => [(object)['id' => 'main/a'], (object)['id' => 'main/b'], (object)['id' => 'other/c']],
        ]]]);
        $storage = $this->facade($couch, new FakeArcade());
        $this->assertSame(['a', 'b'], $storage->listBoardKeys('main'));
    }
}
