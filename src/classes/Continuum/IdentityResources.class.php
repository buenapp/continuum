<?php

namespace Continuum;

use EnchiladaMCP\McpResource;
use Continuum\Storage\ContinuumStorage;

/**
 * Workstream identity registry resources (issue #6): one Ed25519 public
 * key and status per workstream. Records are written only by the operator
 * CLI (src/bin/identity-admin); the resource surface is read-only.
 */
class IdentityResources {

    public function __construct(private ContinuumStorage $storage) {}

    /**
     * Every registered workstream identity, active and revoked.
     */
    #[McpResource(
        uriTemplate: 'continuum://identities',
        description: 'Workstream identity registry: Ed25519 public keys and status per workstream.',
        annotations: ['audience' => ['assistant']]
    )]
    public function identity_registry(): array {
        $docs = $this->storage->listIdentities();
        ksort($docs);
        $identities = [];
        $stamps = [];
        foreach ($docs as $workstream => $doc) {
            $doc = (array)$doc;
            $identities[] = $this->card((string)$workstream, $doc);
            foreach ($this->stamps($doc) as $value) { $stamps[] = $value; }
        }
        $annotations = ['audience' => ['assistant']];
        $latest = $this->newest($stamps);
        if ($latest !== null) {
            $annotations['lastModified'] = $latest;
        }
        return [
            'text' => json_encode(['count' => count($identities), 'identities' => $identities], JSON_UNESCAPED_SLASHES),
            'annotations' => $annotations,
        ];
    }

    /**
     * Identity record for one workstream.
     */
    #[McpResource(
        uriTemplate: 'continuum://identities/{workstream}',
        description: 'Identity record for one workstream: Ed25519 public key, status, rotation and revocation times.',
        annotations: ['audience' => ['assistant']]
    )]
    public function identity_record(string $workstream): array {
        $doc = $this->storage->loadIdentity($workstream);
        if ($doc === null) {
            throw new \RuntimeException("identity {$workstream} is not registered");
        }
        $annotations = ['audience' => ['assistant']];
        $latest = $this->newest($this->stamps($doc));
        if ($latest !== null) {
            $annotations['lastModified'] = $latest;
        }
        return [
            'text' => json_encode($this->card($workstream, $doc), JSON_UNESCAPED_SLASHES),
            'annotations' => $annotations,
        ];
    }

    private function card(string $workstream, array $doc): array {
        return [
            'workstream' => $workstream,
            'pubkey' => $doc['pubkey'] ?? null,
            'status' => $doc['status'] ?? null,
            'created_at' => $doc['created_at'] ?? null,
            'rotated_at' => $doc['rotated_at'] ?? null,
            'revoked_at' => $doc['revoked_at'] ?? null,
        ];
    }

    /** Registry timestamps present on the record (ISO-8601). */
    private function stamps(array $doc): array {
        $values = [];
        foreach (['created_at', 'rotated_at', 'revoked_at'] as $field) {
            if (isset($doc[$field]) && is_string($doc[$field]) && $doc[$field] !== '') {
                $values[] = $doc[$field];
            }
        }
        return $values;
    }

    /** Chronologically newest of the given ISO-8601 timestamps, as stored. */
    private function newest(array $values): ?string {
        $latest = null;
        $latestTs = null;
        foreach ($values as $value) {
            $ts = strtotime($value);
            if ($ts === false) { continue; }
            if ($latestTs === null || $ts > $latestTs) {
                $latestTs = $ts;
                $latest = $value;
            }
        }
        return $latest;
    }
}
