<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
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
        readOnlyHint: true
    )]
    public function blackboard_read(string $key, ?string $scope = null): array {
        $board = $this->scopeOf($scope);
        $entry = $this->storage->loadBoardEntry($board, $key);
        if ($entry === null) {
            throw new \RuntimeException("no board entry {$board}/{$key}");
        }
        return [
            'scope' => $board,
            'key' => $key,
            'value' => $entry['value'] ?? null,
            'updated_by' => $entry['updated_by'] ?? null,
            'updated_at' => $entry['updated_at'] ?? null,
        ];
    }

    /**
     * List the keys present in a scope.
     */
    #[McpTool(
        name: 'blackboard_keys',
        renamedFrom: 'bb_keys',
        description: 'List blackboard keys in a scope (default: global).',
        readOnlyHint: true
    )]
    public function blackboard_keys(?string $scope = null): array {
        $board = $this->scopeOf($scope);
        return ['scope' => $board, 'keys' => $this->storage->listBoardKeys($board)];
    }

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
