<?php

namespace Continuum;

use EnchiladaMCP\McpResource;
use Continuum\Storage\ContinuumStorage;

/**
 * Task resources: open-task list and per-task cards as addressable reads.
 *
 * Cards carry the full doc (notes, handoffs) plus the ArcadeDB graph
 * neighborhood; terminal tasks remain readable by id but are excluded
 * from the list.
 */
class TaskResources {

    public function __construct(private ContinuumStorage $storage) {}

    /**
     * All non-terminal tasks, most recently updated first.
     */
    #[McpResource(
        uriTemplate: 'continuum://tasks',
        description: 'Open (non-terminal) tasks across all scopes, most recently updated first.',
        annotations: ['audience' => ['assistant'], 'priority' => 0.7]
    )]
    public function tasks_open(): array {
        $tasks = [];
        $latest = null;
        foreach ($this->storage->listTaskDocs() as $id => $doc) {
            $status = $doc['status'] ?? 'pending';
            if ($status === 'done' || $status === 'cancelled') { continue; }
            $tasks[] = $this->summarize((string)$id, $doc);
            $latest = max($latest ?? '', $doc['updated_at'] ?? '');
        }
        usort($tasks, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
        return $this->withLastModified(['count' => count($tasks), 'tasks' => $tasks], $latest);
    }

    /**
     * Full card for one task: doc fields, recent notes/handoffs, graph deps.
     */
    #[McpResource(
        uriTemplate: 'continuum://tasks/{id}',
        description: 'Full task card: status, ownership, notes, handoffs, and dependency neighborhood.',
        annotations: ['audience' => ['assistant']]
    )]
    public function task_card(string $id): array {
        $task = $this->storage->loadTask($id)
            ?? throw new \RuntimeException("task {$id} not found");
        unset($task['_rev']);
        $task['task'] = $id;
        $task['notes'] = array_slice($task['notes'] ?? [], -5);
        $task['handoffs'] = array_slice($task['handoffs'] ?? [], -3);
        $task['depends_on'] = $this->storage->getDependencies($id);
        $task['blocks'] = $this->storage->getDependents($id);
        return $this->withLastModified($task, $task['updated_at'] ?? null);
    }

    private function summarize(string $taskId, array $task): array {
        return [
            'task' => $taskId,
            'title' => $task['title'] ?? '',
            'scope' => $task['scope'] ?? 'global',
            'status' => $task['status'] ?? 'pending',
            'owner' => $task['owner'] ?? null,
            'assignee' => $task['assignee'] ?? null,
            'priority' => $task['priority'] ?? 2,
            'updated_at' => $task['updated_at'] ?? null,
        ];
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
