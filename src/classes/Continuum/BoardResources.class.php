<?php

namespace Continuum;

use EnchiladaMCP\McpResource;
use Continuum\Storage\ContinuumStorage;

/**
 * Blackboard resources: the read-only URI surface over the shared board.
 *
 * Board state is live data (no client caching); attribution and
 * `updated_at` from the entry doc feed per-read lastModified annotations.
 */
class BoardResources {

    public function __construct(private ContinuumStorage $storage) {}

    /**
     * Index of every board scope with its keys and attribution.
     */
    #[McpResource(
        uriTemplate: 'continuum://board/index',
        description: 'Index of all board scopes and their keys with attribution (updated_by, updated_at).',
        annotations: ['audience' => ['assistant'], 'priority' => 0.5]
    )]
    public function board_index(): array {
        $scopes = [];
        $latest = null;
        foreach ($this->storage->listBoardDocsAll() as $id => $doc) {
            $segments = explode('/', (string)$id, 2);
            if (count($segments) !== 2) { continue; }
            [$scope, $key] = $segments;
            $scopes[$scope][$key] = [
                'updated_by' => $doc['updated_by'] ?? null,
                'updated_at' => $doc['updated_at'] ?? null,
            ];
            $latest = max($latest ?? '', $doc['updated_at'] ?? '');
        }
        ksort($scopes);
        return $this->withLastModified(['scopes' => $scopes], $latest);
    }

    /**
     * Read a single entry, e.g. continuum://board/global/my-key.
     */
    #[McpResource(
        uriTemplate: 'continuum://board/{scope}/{key}',
        description: 'Read a single board entry from a scope.',
        annotations: ['audience' => ['assistant']]
    )]
    public function board_entry(string $scope, string $key): array {
        $entry = $this->storage->loadBoardEntry($scope, $key)
            ?? throw new \RuntimeException("no board entry {$scope}/{$key}");
        return $this->withLastModified([
            'scope' => $scope,
            'key' => $key,
            'value' => $entry['value'] ?? null,
            'updated_by' => $entry['updated_by'] ?? null,
            'updated_at' => $entry['updated_at'] ?? null,
        ], $entry['updated_at'] ?? null);
    }

    /**
     * Dump all entries of one scope, most recently updated first.
     */
    #[McpResource(
        uriTemplate: 'continuum://snapshot/{scope}',
        description: 'All board entries in one scope, most recently updated first.',
        annotations: ['audience' => ['assistant']]
    )]
    public function board_snapshot(string $scope): array {
        $docs = $this->storage->listBoardDocs($scope);
        uasort($docs, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
        $entries = [];
        $latest = null;
        foreach ($docs as $key => $doc) {
            $entries[$key] = [
                'value' => $doc['value'] ?? null,
                'updated_by' => $doc['updated_by'] ?? null,
                'updated_at' => $doc['updated_at'] ?? null,
            ];
            $latest = max($latest ?? '', $doc['updated_at'] ?? '');
        }
        return $this->withLastModified(['scope' => $scope, 'count' => count($entries), 'entries' => $entries], $latest);
    }

    /** JSON body plus a dynamic lastModified annotation when a timestamp is known. */
    private function withLastModified(array $payload, ?string $updatedAt): array {
        $annotations = ['audience' => ['assistant']];
        if ($updatedAt !== null && $updatedAt !== '') {
            $annotations['lastModified'] = $updatedAt;
        }
        return [
            'text' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'annotations' => $annotations,
        ];
    }
}
