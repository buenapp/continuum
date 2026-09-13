<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\ContextResources;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class ContextResourcesTest extends TestCase {

    private function registry(FakeCouch $couch, ?FakeRespClient $resp = null): ToolRegistry {
        $registry = new ToolRegistry();
        $registry->register(new ContextResources(new ContinuumStorage(
            new ValKeyStore($resp ?? new FakeRespClient([])), $couch, new FakeArcade()
        )));
        return $registry;
    }

    public function testRegistersPackTemplate(): void {
        $templates = $this->registry(new FakeCouch([]))->listResourceTemplates();
        $pack = null;
        foreach ($templates as $t) {
            if ($t['uriTemplate'] === 'continuum://context/pack/{scope}') { $pack = $t; }
        }
        $this->assertNotNull($pack);
        $this->assertSame('text/markdown', $pack['mimeType']);
        $this->assertSame(0.9, $pack['annotations']['priority']);
    }

    public function testPackRendersMarkdownBrief(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['rows' => [
                (object)['id' => 'T-1', 'doc' => (object)['title' => 'Fix parser', 'status' => 'pending', 'scope' => 'proj', 'priority' => 1, 'updated_at' => '2026-09-13T02:00:00Z']],
            ]]],
            ['code' => 200, 'body' => ['rows' => [
                (object)['id' => 'proj/roadmap', 'doc' => (object)['value' => 'ship phase 4', 'updated_by' => 'devin', 'updated_at' => '2026-09-13T02:00:00Z']],
            ]]],
            ['code' => 200, 'body' => ['rows' => []]], // global board (empty)
        ]);
        $content = $this->registry($couch, new FakeRespClient([[]]))->readResource('continuum://context/pack/proj');
        $this->assertSame('continuum://context/pack/proj', $content['uri']);
        $this->assertSame('text/markdown', $content['mimeType']);
        $this->assertStringContainsString('# Context Pack (scope: proj)', $content['text']);
        $this->assertStringContainsString('T-1', $content['text']);
        $this->assertStringContainsString('roadmap', $content['text']);
        $this->assertSame(['assistant'], $content['annotations']['audience']);
    }
}
