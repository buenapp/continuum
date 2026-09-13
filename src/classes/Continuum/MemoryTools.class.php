<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ToolResult;
use Continuum\Storage\ContinuumStorage;
use Continuum\Bridge\HeliofaneMcpBridge;

/**
 * Bridge from execution state to durable knowledge. Continuum owns what
 * happens next; Heliofane owns what is permanently true. Promotion is a
 * deliberate, explicit act by an agent at handoff/completion.
 */
class MemoryTools {

    public function __construct(
        private ContinuumStorage $storage,
        private ?HeliofaneMcpBridge $bridge = null,
    ) {}

    #[McpTool(
        name: 'promote_to_memory',
        description: 'Promote distilled facts about an entity to long-term memory (Heliofane). Adds observations to the entity; with createIfMissing the entity is created first. Call at handoff or completion for outcomes that outlive the board.',
        openWorldHint: true,
        outputSchema: self::PROMOTE_SCHEMA
    )]
    public function promote_to_memory(string $entity, array $facts, ?string $entityType = null, bool $createIfMissing = true): ToolResult {
        if ($this->bridge === null) {
            throw new \RuntimeException('Heliofane bridge not configured (missing [heliofane] section in settings.ini)');
        }
        if (empty($facts)) { throw new \RuntimeException('facts must not be empty'); }

        $note = $this->bridge->callTool('note', ['entityName' => $entity, 'observations' => array_values($facts)]);
        $created = false;
        if (!$note['ok']) {
            if (!$createIfMissing) {
                throw new \RuntimeException("memory write failed for '{$entity}': {$note['error']}");
            }
            $remember = $this->bridge->callTool('remember', ['entities' => [[
                'name' => $entity,
                'entityType' => $entityType ?? 'concept',
                'observations' => array_values($facts),
            ]]]);
            if (!$remember['ok']) {
                throw new \RuntimeException("memory write failed for '{$entity}': {$remember['error']}");
            }
            $created = true;
        }
        $this->storage->appendLog(CONTINUUM_AGENT, 'promote_to_memory', [
            'entity' => $entity, 'facts' => count($facts), 'created' => $created,
        ]);
        $data = ['entity' => $entity, 'promoted' => count($facts), 'created' => $created];
        $n = count($facts);
        $text = "Promoted {$n} fact" . ($n === 1 ? '' : 's') . " to memory for '{$entity}'"
            . ($created ? ' (entity created)' : '') . '.';
        return ToolResult::structured($text, $data);
    }

    private const PROMOTE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'entity' => ['type' => 'string'],
            'promoted' => ['type' => 'integer'],
            'created' => ['type' => 'boolean'],
        ],
        'required' => ['entity', 'promoted', 'created'],
    ];
}
