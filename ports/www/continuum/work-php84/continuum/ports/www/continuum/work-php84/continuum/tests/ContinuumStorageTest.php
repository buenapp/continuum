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

    public function testListBoardKeysScoped(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => [
            'rows' => [(object)['id' => 'main/a'], (object)['id' => 'main/b'], (object)['id' => 'other/c']],
        ]]]);
        $storage = $this->facade($couch, new FakeArcade());
        $this->assertSame(['a', 'b'], $storage->listBoardKeys('main'));
    }
}
