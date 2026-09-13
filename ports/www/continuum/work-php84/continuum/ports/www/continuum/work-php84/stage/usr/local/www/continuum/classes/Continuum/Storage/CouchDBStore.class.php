<?php

namespace Continuum\Storage;

use EnchiladaHTTP;

/**
 * Durable engine adapter: plans, workflow snapshots, event log, board and
 * task documents on CouchDB (MVCC, revisioned).
 */
class CouchDBStore extends EnchiladaHTTP {

    private string $prefix;

    public function __construct(string $host, int $port, string $user, string $pass, string $databasePrefix = 'continuum_') {
        parent::__construct("http://{$host}:{$port}");
        $this->setPlaintextAuth($user, $pass);
        $this->prefix = $databasePrefix;
    }

    private function dbName(string $kind): string { return $this->prefix . $kind; }

    /** Store or update a document; MVCC rev conflicts bubble as exceptions. */
    public function put(string $kind, string $id, array $doc, ?string $rev = null): array {
        if ($rev !== null) { $doc['_rev'] = $rev; }
        $result = $this->call($this->dbName($kind) . '/' . rawurlencode($id), $doc, 'PUT');
        $code = $this->getHttpCode();
        if ($code === 409) {
            throw new \RuntimeException("CouchDB revision conflict on {$kind}/{$id}");
        }
        if ($code !== 201 && $code !== 202) {
            throw new \RuntimeException("CouchDB PUT failed ({$code}) on {$kind}/{$id}");
        }
        return ['id' => $result['id'] ?? $id, 'rev' => $result['rev'] ?? null];
    }

    /** Fetch a document; null when absent. */
    public function get(string $kind, string $id): ?array {
        $result = $this->call($this->dbName($kind) . '/' . rawurlencode($id), null, 'GET');
        if ($this->getHttpCode() === 404) { return null; }
        if (!is_array($result) && !is_object($result)) {
            throw new \RuntimeException("CouchDB GET failed ({$this->getHttpCode()}) on {$kind}/{$id}");
        }
        return (array)$result;
    }

    /** List all document ids in a database (ids only). */
    public function listIds(string $kind): array {
        $result = $this->call($this->dbName($kind) . '/_all_docs', null, 'GET');
        if (!is_array($result) && !is_object($result)) { return []; }
        $result = (array)$result;
        $ids = [];
        foreach (($result['rows'] ?? []) as $row) {
            $id = is_object($row) ? ($row->id ?? '') : ($row['id'] ?? '');
            if ($id !== '' && !str_starts_with($id, '_design/')) { $ids[] = $id; }
        }
        return $ids;
    }

    /** List all documents (id => doc) in one _all_docs round trip. */
    public function listDocs(string $kind): array {
        $result = $this->call($this->dbName($kind) . '/_all_docs?include_docs=true', null, 'GET');
        if (!is_array($result) && !is_object($result)) { return []; }
        $docs = [];
        foreach (((array)$result)['rows'] ?? [] as $row) {
            $row = (array)$row;
            $id = $row['id'] ?? '';
            if ($id === '' || str_starts_with($id, '_design/')) { continue; }
            $docs[$id] = isset($row['doc']) ? (array)$row['doc'] : [];
        }
        return $docs;
    }

    /** Append an event to the append-only log database. Returns the doc id. */
    public function appendLog(string $agentId, string $type, array $data): string {
        $ms = ((int)floor(microtime(true) * 1000)) % 1000;
        $doc = [
            'agent' => $agentId,
            'type' => $type,
            'data' => $data,
            'ts' => gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', $ms),
        ];
        $wrote = $this->put('events', $this->newId(), $doc);
        return $wrote['id'];
    }

    public function newId(): string {
        $result = $this->call('_uuids', null, 'GET');
        $uuid = is_array($result) && isset($result['uuids'][0]) ? $result['uuids'][0] : null;
        if ($uuid === null) { $uuid = sprintf('%08x%08x%s', time(), random_int(0, 0xffffffff), bin2hex(random_bytes(8))); }
        return $uuid;
    }

    /** Delete a document by id+rev; 404 (already gone) is accepted. */
    public function deleteDoc(string $kind, string $id, string $rev): void {
        $this->call($this->dbName($kind) . '/' . rawurlencode($id) . '?rev=' . rawurlencode($rev), null, 'DELETE');
        $code = $this->getHttpCode();
        if ($code !== 200 && $code !== 202 && $code !== 404) {
            throw new \RuntimeException("CouchDB DELETE failed ({$code}) on {$kind}/{$id}");
        }
    }
}
