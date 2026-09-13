<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;

class ArcadeDBStoreTest extends TestCase {

    public function testQueryReturnsRows(): void {
        $arcade = new FakeArcade([['result' => [['id' => 'T-parent', 'status' => 'open']]]]);
        $deps = $arcade->getDependencies('T-1');
        $this->assertSame('T-parent', $deps[0]['id']);
    }

    public function testEnsureSchemaCreatesMissingTypes(): void {
        // All six checks report missing: 2 vertex types, 2 indexes, 2 edge types.
        $arcade = new FakeArcade(array_fill(0, 6, ['result' => [['c' => 0]]]));
        $arcade->ensureSchema();
        $commands = array_values(array_filter($arcade->calls, fn($c) => str_starts_with($c['endpoint'], 'command/')));
        $this->assertCount(8, $commands); // 2 x CREATE VERTEX + 2 x (CREATE PROPERTY + CREATE INDEX) + 2 x CREATE EDGE
    }

    public function testEnsureSchemaIdempotent(): void {
        $arcade = new FakeArcade(array_fill(0, 6, ['result' => [['c' => 1]]]));
        $arcade->ensureSchema();
        $commands = array_values(array_filter($arcade->calls, fn($c) => str_starts_with($c['endpoint'], 'command/')));
        $this->assertCount(0, $commands);
    }

    public function testLinkTasksEdgeDirection(): void {
        $arcade = new FakeArcade();
        $arcade->linkTasks('T-child', 'T-parent');
        $cmd = $arcade->calls[0]['body']['command'];
        $this->assertStringContainsString('CREATE EDGE DEPENDS_ON', $cmd);
        $this->assertStringContainsString('FROM (SELECT FROM Task WHERE id = :child)', $cmd);
        $this->assertSame(['child' => 'T-child', 'parent' => 'T-parent'], $arcade->calls[0]['body']['params']);
    }

    public function testMapAgentRelationshipUpsertsAgentAndEdges(): void {
        $arcade = new FakeArcade();
        $arcade->mapAgentRelationship('agent-a', 'T-1');
        $this->assertCount(2, $arcade->calls);
        $this->assertStringContainsString('UPSERT', $arcade->calls[0]['body']['command']);
        $this->assertStringContainsString('CREATE EDGE CLAIMED_BY', $arcade->calls[1]['body']['command']);
    }
}
