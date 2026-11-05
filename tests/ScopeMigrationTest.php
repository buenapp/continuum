<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class ScopeMigrationTest extends TestCase {

    private function storage(FakeCouch $couch, FakeRespClient $resp): ContinuumStorage {
        return new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade());
    }

    private function row(string $id, array $doc): object {
        return (object)['id' => $id, 'doc' => (object)$doc];
    }

    public function testDryRunFindsEveryCaseVariantWithoutMutating(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['rows' => [
                $this->row('HELIOFANE/old', ['_rev' => '1-a', 'value' => 'older']),
                $this->row('heliofane/active', ['_rev' => '1-b', 'value' => 'active']),
            ]]],
            ['code' => 200, 'body' => ['rows' => [
                $this->row('T-MixedID', ['_rev' => '2-c', 'scope' => 'HELIOFANE', 'session' => 'SessionMixed']),
            ]]],
        ]);
        $resp = new FakeRespClient([
            ['0', ['continuum:queue:HELIOFANE', 'continuum:queue:heliofane']],
        ]);
        $report = $this->storage($couch, $resp)->migrateScopes();

        $this->assertFalse($report['applied']);
        $this->assertSame([], $report['conflicts']);
        $this->assertSame([['source' => 'HELIOFANE/old', 'target' => 'heliofane/old']], $report['boards']);
        $this->assertSame([['task' => 'T-MixedID', 'source' => 'HELIOFANE', 'target' => 'heliofane']], $report['tasks']);
        $this->assertSame([['source' => 'HELIOFANE', 'target' => 'heliofane']], $report['queues']);
        $this->assertCount(2, $couch->calls);
        $this->assertCount(1, $resp->calls);
    }

    public function testApplyRefusesBoardValueConflictBeforeAnyMutation(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['rows' => [
                $this->row('HELIOFANE/shared', ['_rev' => '1-a', 'value' => 'old']),
                $this->row('heliofane/shared', ['_rev' => '1-b', 'value' => 'new']),
            ]]],
            ['code' => 200, 'body' => ['rows' => []]],
        ]);
        $resp = new FakeRespClient([['0', []]]);
        $report = $this->storage($couch, $resp)->migrateScopes(true);

        $this->assertFalse($report['applied']);
        $this->assertStringContainsString('heliofane/shared', $report['conflicts'][0]);
        $this->assertCount(2, $couch->calls);
        $this->assertCount(1, $resp->calls);
    }

    public function testApplyMergesDataAndPassesPostCheck(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['rows' => [
                $this->row('HELIOFANE/old', ['_id' => 'HELIOFANE/old', '_rev' => '1-a', 'value' => 'older', 'updated_by' => 'devin']),
            ]]],
            ['code' => 200, 'body' => ['rows' => [
                $this->row('T-MixedID', ['_rev' => '2-c', 'scope' => 'HELIOFANE', 'session' => 'SessionMixed']),
            ]]],
            ['code' => 201, 'body' => ['id' => 'heliofane/old', 'rev' => '1-d']],
            ['code' => 200, 'body' => ['ok' => true]],
            ['code' => 201, 'body' => ['id' => 'T-MixedID', 'rev' => '3-e']],
            ['code' => 200, 'body' => ['rows' => [
                $this->row('heliofane/old', ['_rev' => '1-d', 'value' => 'older', 'updated_by' => 'devin']),
            ]]],
            ['code' => 200, 'body' => ['rows' => [
                $this->row('T-MixedID', ['_rev' => '3-e', 'scope' => 'heliofane', 'session' => 'SessionMixed']),
            ]]],
        ]);
        $resp = new FakeRespClient([
            ['0', ['continuum:queue:HELIOFANE']],
            1,
            ['0', ['continuum:queue:heliofane']],
        ]);
        $report = $this->storage($couch, $resp)->migrateScopes(true);

        $this->assertTrue($report['applied']);
        $this->assertSame('continuum_boards/heliofane%2Fold', $couch->calls[2]['path']);
        $this->assertArrayNotHasKey('_rev', $couch->calls[2]['data']);
        $this->assertSame('devin', $couch->calls[2]['data']['updated_by']);
        $this->assertSame('DELETE', $couch->calls[3]['verb']);
        $this->assertSame('continuum_tasks/T-MixedID', $couch->calls[4]['path']);
        $this->assertSame('heliofane', $couch->calls[4]['data']['scope']);
        $this->assertSame('SessionMixed', $couch->calls[4]['data']['session']);
        $merge = $resp->calls[1];
        $this->assertSame('EVAL', $merge[0]);
        $this->assertSame('continuum:queue:heliofane', $merge[3]);
        $this->assertSame('continuum:queue:HELIOFANE', $merge[4]);
    }
}
