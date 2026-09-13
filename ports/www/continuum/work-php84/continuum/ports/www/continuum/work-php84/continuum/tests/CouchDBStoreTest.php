<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;

class CouchDBStoreTest extends TestCase {

    public function testPutOkReturnsRev(): void {
        $couch = new FakeCouch([['code' => 201, 'body' => ['id' => 'T-1', 'rev' => '2-new']]]);
        $wrote = $couch->put('tasks', 'T-1', ['title' => 'x'], '1-old');
        $this->assertSame('2-new', $wrote['rev']);
        $this->assertSame('continuum_tasks/T-1', $couch->calls[0]['path']);
        $this->assertSame('PUT', $couch->calls[0]['verb']);
        $this->assertSame('1-old', $couch->calls[0]['data']['_rev']); // MVCC token supplied
    }

    public function testPutConflictThrows(): void {
        $couch = new FakeCouch([['code' => 409, 'body' => ['error' => 'conflict']]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('revision conflict');
        $couch->put('tasks', 'T-1', ['title' => 'x'], '1-old');
    }

    public function testGetMissingReturnsNull(): void {
        $couch = new FakeCouch([['code' => 404, 'body' => ['error' => 'not_found']]]);
        $this->assertNull($couch->get('tasks', 'missing'));
    }

    public function testListIdsSkipsDesignDocs(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => [
            'rows' => [(object)['id' => 'T-1'], (object)['id' => '_design/idx'], (object)['id' => 'T-2']],
        ]]]);
        $this->assertSame(['T-1', 'T-2'], $couch->listIds('tasks'));
    }

    public function testAppendLogShape(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['uuids' => ['evt-1']]],
            ['code' => 201, 'body' => ['id' => 'evt-1', 'rev' => '1-x']],
        ]);
        $id = $couch->appendLog('agent-a', 'task_claimed', ['task' => 'T-1']);
        $this->assertSame('evt-1', $id);
        $doc = $couch->calls[1]['data'];
        $this->assertSame('agent-a', $doc['agent']);
        $this->assertSame('task_claimed', $doc['type']);
        $this->assertSame('continuum_events/evt-1', $couch->calls[1]['path']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $doc['ts']);
    }

    public function testNewIdFallsBackLocally(): void {
        $couch = new FakeCouch([['code' => 500, 'body' => ['uuids' => []]]]);
        $this->assertNotEmpty($couch->newId());
    }
}
