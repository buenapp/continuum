<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\AgentTools;
use Continuum\BoardTools;
use Continuum\ContextTools;
use Continuum\EventTools;
use Continuum\LockTools;
use Continuum\MemoryTools;
use Continuum\MessageTools;
use Continuum\ServerTools;
use Continuum\StatusTools;
use Continuum\TaskTools;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

/**
 * Wire-shape contract for advertised tool output schemas.
 *
 * The MCP TS SDK validates tools/list strictly: outputSchema must have
 * root type 'object' and every `properties` entry must be an object
 * subschema. The JSON Schema boolean shorthand (`true`/`false`) and
 * empty PHP lists both fail that check, so clients (e.g. the llama.cpp
 * webui) discard the entire tool list on a single bad leaf.
 */
class ToolsOutputSchemaTest extends TestCase {

    private function registry(): ToolRegistry {
        $storage = new ContinuumStorage(
            new ValKeyStore(new FakeRespClient([])),
            new FakeCouch([]),
            new FakeArcade(),
        );
        $registry = new ToolRegistry();
        foreach ([
            new ServerTools(),
            new BoardTools($storage),
            new TaskTools($storage),
            new LockTools($storage),
            new ContextTools($storage),
            new AgentTools($storage),
            new MessageTools($storage),
            new EventTools($storage),
            new StatusTools($storage),
            new MemoryTools($storage),
        ] as $tools) {
            $registry->register($tools);
        }
        return $registry;
    }

    public function testOutputSchemasRoundTripAsStrictClientSchema(): void {
        $checked = 0;
        foreach ($this->registry()->listTools() as $tool) {
            if (!isset($tool['outputSchema'])) { continue; }
            $checked++;
            $wire = json_decode(json_encode($tool), false, 512, JSON_THROW_ON_ERROR);
            $schema = $wire->outputSchema;
            $this->assertInstanceOf(\stdClass::class, $schema, "{$tool['name']} outputSchema must encode as an object");
            $this->assertSame('object', $schema->type ?? null, "{$tool['name']} outputSchema root must be type 'object'");
            $this->walkProperties($schema, "{$tool['name']}.outputSchema");
        }
        $this->assertGreaterThan(0, $checked, 'no tools advertised an outputSchema; the strict-schema assertions covered nothing');
    }

    /** Assert every level of `properties` holds only object subschemas. */
    private function walkProperties(object $schema, string $path): void {
        $properties = $schema->properties ?? null;
        if ($properties === null) { return; }
        $this->assertInstanceOf(\stdClass::class, $properties, "{$path}.properties must encode as an object");
        foreach (get_object_vars($properties) as $name => $sub) {
            $this->assertInstanceOf(\stdClass::class, $sub, "{$path}.properties.{$name} must be an object subschema (no boolean shorthand, no empty list)");
            if (isset($sub->properties)) {
                $this->walkProperties($sub, "{$path}.properties.{$name}");
            }
        }
    }
}
