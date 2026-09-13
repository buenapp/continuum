<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ToolResult;
use Continuum\Storage\ContinuumStorage;

/**
 * Blackboard tools: shared working state keyed by (scope, key).
 *
 * Scopes are a flat namespace; 'global' is the default. Entries carry
 * attribution (updated_by) and entries may only be deleted by their
 * author or by the agent that owns the scope (scope name == agent name).
 * Every mutation lands in the append-only event log.
 */
class BoardTools {

    public function __construct(private ContinuumStorage $storage) {}

    private function scopeOf(?string $scope): string {
        return ($scope === null || $scope === '') ? 'global' : $scope;
    }

    /**
     * Write a board entry.
     */
    #[McpTool(
        name: 'blackboard_write',
        renamedFrom: 'bb_write',
        description: 'Write a blackboard entry under a scope (default: global). Value may be any JSON value. Existing keys are overwritten with MVCC protection.'
    )]
    public function blackboard_write(string $key, mixed $value, ?string $scope = null): array {
        $board = $this->scopeOf($scope);
        $existing = $this->storage->loadBoardEntry($board, $key);
        $entry = [
            'value' => $value,
            'updated_by' => CONTINUUM_AGENT,
            'updated_at' => gmdate('c'),
        ];
        $wrote = $this->storage->saveBoardEntry($board, $key, $entry, $existing['_rev'] ?? null);
        $this->storage->appendLog(CONTINUUM_AGENT, 'blackboard_write', ['scope' => $board, 'key' => $key]);
        return ['scope' => $board, 'key' => $key, 'rev' => $wrote['rev'], 'updated_by' => CONTINUUM_AGENT];
    }

    /**
     * Read a board entry.
     */
    #[McpTool(
        name: 'blackboard_read',
        renamedFrom: 'bb_read',
        description: 'Read a blackboard entry from a scope (default: global). Errors when the key does not exist.',
        readOnlyHint: true,
        outputSchema: self::READ_SCHEMA
    )]
    public function blackboard_read(string $key, ?string $scope = null): ToolResult {
        $board = $this->scopeOf($scope);
        $entry = $this->storage->loadBoardEntry($board, $key);
        if ($entry === null) {
            throw new \RuntimeException("no board entry {$board}/{$key}");
        }
        $data = [
            'scope' => $board,
            'key' => $key,
            'value' => $entry['value'] ?? null,
            'updated_by' => $entry['updated_by'] ?? null,
            'updated_at' => $entry['updated_at'] ?? null,
        ];
        $value = is_string($data['value']) ? $data['value'] : json_encode($data['value'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $text = "# {$board}/{$key}\n"
            . '_by ' . ($data['updated_by'] ?? '?') . ' at ' . ($data['updated_at'] ?? '?') . "_\n\n"
            . (is_string($data['value']) ? $value : "```json\n{$value}\n```");
        return ToolResult::structured($text, $data);
    }

    /**
     * List the keys present in a scope.
     */
    #[McpTool(
        name: 'blackboard_keys',
        renamedFrom: 'bb_keys',
        description: 'List blackboard keys in a scope (default: global).',
        readOnlyHint: true,
        outputSchema: self::KEYS_SCHEMA
    )]
    public function blackboard_keys(?string $scope = null): ToolResult {
        $board = $this->scopeOf($scope);
        $keys = $this->storage->listBoardKeys($board);
        $data = ['scope' => $board, 'keys' => $keys];
        $lines = ['# Keys in ' . $board . ' (' . count($keys) . ')'];
        foreach ($keys as $k) { $lines[] = "- {$k}"; }
        if ($keys === []) { $lines[] = '(none)'; }
        return ToolResult::structured(implode("\n", $lines), $data);
    }

    private const READ_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'scope' => ['type' => 'string'],
            'key' => ['type' => 'string'],
            'value' => true,
            'updated_by' => ['type' => ['string', 'null']],
            'updated_at' => ['type' => ['string', 'null']],
        ],
        'required' => ['scope', 'key', 'value', 'updated_by', 'updated_at'],
    ];

    private const KEYS_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'scope' => ['type' => 'string'],
            'keys' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['scope', 'keys'],
    ];

    /**
     * Delete a board entry. Only the entry author or the agent owning the
     * scope may delete.
     */
    #[McpTool(
        name: 'blackboard_delete',
        renamedFrom: 'bb_delete',
        description: 'Delete a blackboard entry. Restricted to the entry author or the agent that owns the scope.',
        destructiveHint: true,
        idempotentHint: false
    )]
    public function blackboard_delete(string $key, ?string $scope = null): array {
        $board = $this->scopeOf($scope);
        $entry = $this->storage->loadBoardEntry($board, $key);
        if ($entry === null) {
            throw new \RuntimeException("no board entry {$board}/{$key}");
        }
        $author = $entry['updated_by'] ?? null;
        if ($author !== CONTINUUM_AGENT && $board !== CONTINUUM_AGENT) {
            throw new \RuntimeException("not permitted: entry {$board}/{$key} is owned by " . ($author ?? 'unknown'));
        }
        $this->storage->deleteBoardEntry($board, $key, $entry['_rev']);
        $this->storage->appendLog(CONTINUUM_AGENT, 'blackboard_delete', ['scope' => $board, 'key' => $key]);
        return ['scope' => $board, 'key' => $key, 'deleted' => true];
    }
}
