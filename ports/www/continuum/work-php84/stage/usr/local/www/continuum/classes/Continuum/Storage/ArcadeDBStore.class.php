<?php

namespace Continuum\Storage;

use EnchiladaHTTP;

/**
 * Structural engine adapter: task dependency graph + agent relationships on
 * ArcadeDB (multi-model; vertices via SQL, edges typed).
 *
 * Types: Task (vertex), Agent (vertex). Edges: DEPENDS_ON, CLAIMED_BY.
 */
class ArcadeDBStore extends EnchiladaHTTP {

    private string $database;

    public function __construct(string $host, int $port, string $user, string $pass, string $database = 'continuum') {
        parent::__construct("http://{$host}:{$port}/api/v1");
        $this->setPlaintextAuth($user, $pass);
        $this->database = $database;
    }

    public function query(string $sql, array $params = []): array {
        $body = ['language' => 'sql', 'command' => $sql];
        if (!empty($params)) { $body['params'] = $params; }
        $result = $this->call("query/{$this->database}", $body, 'POST');
        if ($this->getHttpCode() !== 200) {
            throw new \RuntimeException("ArcadeDB query failed ({$this->getHttpCode()}): {$sql}");
        }
        return is_array($result) ? ($result['result'] ?? []) : [];
    }

    public function command(string $sql, array $params = []): void {
        $body = ['language' => 'sql', 'command' => $sql];
        if (!empty($params)) { $body['params'] = $params; }
        $this->call("command/{$this->database}", $body, 'POST');
        $code = $this->getHttpCode();
        if ($code !== 200 && $code !== 204) {
            throw new \RuntimeException("ArcadeDB command failed ({$code}): {$sql}");
        }
    }

    /** Ensure the vertex/edge types exist (idempotent bootstrap). */
    public function ensureSchema(): void {
        foreach (['Task', 'Agent'] as $t) {
            if (!$this->typeExists($t)) { $this->command("CREATE VERTEX TYPE {$t}"); }
        }
        // UPSERT in ArcadeDB requires an index covering the WHERE lookup.
        if (!$this->indexExists('Task', 'id')) {
            $this->command('CREATE PROPERTY Task.id STRING');
            $this->command('CREATE INDEX ON Task (id) UNIQUE');
        }
        if (!$this->indexExists('Agent', 'name')) {
            $this->command('CREATE PROPERTY Agent.name STRING');
            $this->command('CREATE INDEX ON Agent (name) UNIQUE');
        }
        foreach (['DEPENDS_ON', 'CLAIMED_BY'] as $e) {
            if (!$this->typeExists($e)) { $this->command("CREATE EDGE TYPE {$e}"); }
        }
    }

    private function typeExists(string $name): bool {
        $rows = $this->query("SELECT count(*) as c FROM schema:types WHERE name = :name", ['name' => $name]);
        return !empty($rows) && (int)($rows[0]['c'] ?? 0) > 0;
    }

    private function indexExists(string $type, string $property): bool {
        $rows = $this->query("SELECT count(*) as c FROM schema:indexes WHERE typeName = :t AND properties CONTAINS :p", ['t' => $type, 'p' => $property]);
        return !empty($rows) && (int)($rows[0]['c'] ?? 0) > 0;
    }

    /** Upsert a Task vertex keyed on task id. */
    public function upsertTaskNode(string $taskId, array $props = []): void {
        $set = 'SET status = :status, title = :title, updated = :updated';
        $this->command(
            "UPDATE Task {$set} UPSERT WHERE id = :id",
            [
                'id' => $taskId,
                'status' => $props['status'] ?? 'open',
                'title' => $props['title'] ?? '',
                'updated' => gmdate('c'),
            ]
        );
    }

    /** Create a DEPENDS_ON edge (child depends on parent). */
    public function linkTasks(string $childTaskId, string $parentTaskId): void {
        $this->command(
            "CREATE EDGE DEPENDS_ON FROM (SELECT FROM Task WHERE id = :child) TO (SELECT FROM Task WHERE id = :parent)",
            ['child' => $childTaskId, 'parent' => $parentTaskId]
        );
    }

    public function getDependencies(string $taskId): array {
        return $this->query(
            "SELECT expand(out('DEPENDS_ON')) FROM Task WHERE id = :id",
            ['id' => $taskId]
        );
    }

    public function getDependents(string $taskId): array {
        return $this->query(
            "SELECT expand(in('DEPENDS_ON')) FROM Task WHERE id = :id",
            ['id' => $taskId]
        );
    }

    /**
     * Record agent-task relationship (current claim). The edge carries the
     * task id as a property: ArcadeDB has no DELETE EDGE statement and no
     * link traversal in WHERE, so unclaim() matches on that property.
     */
    public function mapAgentRelationship(string $agentId, string $taskId): void {
        $this->command("UPDATE Agent SET name = :a UPSERT WHERE name = :a", ['a' => $agentId]);
        $this->command(
            "CREATE EDGE CLAIMED_BY FROM (SELECT FROM Task WHERE id = :t) TO (SELECT FROM Agent WHERE name = :a) SET task = :t",
            ['t' => $taskId, 'a' => $agentId]
        );
    }

    public function unclaim(string $taskId): void {
        $this->command(
            "DELETE FROM CLAIMED_BY WHERE task = :t",
            ['t' => $taskId]
        );
    }
}
