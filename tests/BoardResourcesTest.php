<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\BoardResources;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class BoardResourcesTest extends TestCase {

    private function registry(FakeCouch $couch): ToolRegistry {
        $registry = new ToolRegistry();
        $registry->register(new BoardResources(new ContinuumStorage(
            new ValKeyStore(new FakeRespClient([])), $couch, new FakeArcade()
        )));
        return $registry;
    }

    public function testRegistersStaticsAndTemplates(): void {
        $registry = $this->registry(new FakeCouch([]));
        $this->assertTrue($registry->hasResources());
        $this->assertSame(['continuum://board/index'], array_column($registry->listResources(), 'uri'));
        $templates = array_column($registry->listResourceTemplates(), 'uriTemplate');
        $this->assertContains('continuum://board/{scope}/{key}', $templates);
        $this->assertContains('continuum://snapshot/{scope}', $templates);
    }

    public function testEntryReadWithLastModified(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['value' => ['n' => 1], 'updated_by' => 'bob', 'updated_at' => '2026-09-13T02:00:00Z', '_rev' => '1-a']],
        ]);
        $content = $this->registry($couch)->readResource('continuum://board/global/foo');
        $this->assertSame('continuum://board/global/foo', $content['uri']);
        $this->assertSame('application/json', $content['mimeType']);
        $body = json_decode($content['text'], true);
        $this->assertSame(['n' => 1], $body['value']);
        $this->assertSame('bob', $body['updated_by']);
        $this->assertSame('2026-09-13T02:00:00Z', $content['annotations']['lastModified']);
        $this->assertSame(['assistant'], $content['annotations']['audience']);
    }

    public function testEntryMissingThrows(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no board entry global/nope');
        $this->registry(new FakeCouch([['code' => 404, 'body' => null]]))->readResource('continuum://board/global/nope');
    }

    private function indexRows(): array {
        return ['rows' => [
            (object)['id' => 'ops/deploy', 'doc' => (object)['value' => 'go', 'updated_by' => 'alice', 'updated_at' => '2026-09-13T03:00:00Z']],
            (object)['id' => 'global/motto', 'doc' => (object)['value' => 'x', 'updated_by' => 'bob', 'updated_at' => '2026-09-13T01:00:00Z']],
        ]];
    }

    public function testIndexGroupsScopesAndLatestTimestamp(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => $this->indexRows()]]);
        $content = $this->registry($couch)->readResource('continuum://board/index');
        $body = json_decode($content['text'], true);
        $this->assertSame(['global', 'ops'], array_keys($body['scopes']));
        $this->assertSame('alice', $body['scopes']['ops']['deploy']['updated_by']);
        $this->assertSame('2026-09-13T03:00:00Z', $content['annotations']['lastModified']);
    }

    public function testSnapshotFiltersScopeAndOrdersNewestFirst(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => $this->indexRows()]]);
        $content = $this->registry($couch)->readResource('continuum://snapshot/global');
        $body = json_decode($content['text'], true);
        $this->assertSame('global', $body['scope']);
        $this->assertSame(['motto'], array_keys($body['entries']));
        $this->assertSame('2026-09-13T01:00:00Z', $content['annotations']['lastModified']);
    }

    public function testUnknownUriThrows(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry(new FakeCouch([]))->readResource('continuum://nowhere');
    }
}
