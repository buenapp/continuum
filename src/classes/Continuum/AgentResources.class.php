<?php

namespace Continuum;

use EnchiladaMCP\McpResource;
use Continuum\Storage\ContinuumStorage;

/**
 * Agent presence resources: the registry directory and per-agent cards.
 *
 * Presence records are ValKey hashes (heartbeat unix ts + meta fields).
 * Stored JSON fields (capabilities) are decoded back for the card.
 */
class AgentResources {

    public function __construct(private ContinuumStorage $storage) {}

    /**
     * Every registered agent with presence age and declared meta.
     */
    #[McpResource(
        uriTemplate: 'continuum://agents',
        description: 'Directory of registered agents: label, capabilities, working-on, and last-seen.',
        annotations: ['audience' => ['assistant']]
    )]
    public function agent_directory(): array {
        $agents = [];
        $latest = null;
        foreach ($this->storage->agentDirectory() as $id => $record) {
            $agents[] = $this->card($id, $record ?: []);
            $hb = $record['heartbeat'] ?? null;
            if ($hb !== null) { $latest = max($latest ?? 0, (int)$hb); }
        }
        $annotations = ['audience' => ['assistant']];
        if ($latest) { $annotations['lastModified'] = gmdate('c', (int)$latest); }
        return [
            'text' => json_encode(['count' => count($agents), 'agents' => $agents], JSON_UNESCAPED_SLASHES),
            'annotations' => $annotations,
        ];
    }

    /**
     * Presence card for one agent.
     */
    #[McpResource(
        uriTemplate: 'continuum://agents/{id}',
        description: 'Presence card for one agent: capabilities, working-on, registration and heartbeat times.',
        annotations: ['audience' => ['assistant']]
    )]
    public function agent_card(string $id): array {
        $record = $this->storage->agentDirectory()[$id] ?? null;
        if ($record === null) {
            throw new \RuntimeException("agent {$id} is not registered");
        }
        $card = $this->card($id, $record);
        $annotations = ['audience' => ['assistant']];
        if (!empty($record['heartbeat'])) {
            $annotations['lastModified'] = gmdate('c', (int)$record['heartbeat']);
        }
        return [
            'text' => json_encode($card, JSON_UNESCAPED_SLASHES),
            'annotations' => $annotations,
        ];
    }

    private function card(string $id, array $record): array {
        $heartbeat = isset($record['heartbeat']) ? (int)$record['heartbeat'] : null;
        $capabilities = $record['capabilities'] ?? null;
        if (is_string($capabilities)) {
            $capabilities = json_decode($capabilities, true);
        }
        $sessions = [];
        foreach ((array)($record['sessions'] ?? []) as $sid => $srec) {
            $sHb = isset($srec['heartbeat']) ? (int)$srec['heartbeat'] : null;
            $sessions[] = [
                'session' => $sid,
                'working_on' => $srec['working_on'] ?? null,
                'registered_at' => $srec['registered_at'] ?? null,
                'last_seen_seconds_ago' => $sHb !== null ? time() - $sHb : null,
            ];
        }
        return [
            'agent' => $id,
            'label' => $record['label'] ?? null,
            'capabilities' => $capabilities,
            'working_on' => $record['working_on'] ?? null,
            'sessions' => $sessions,
            'registered_at' => $record['registered_at'] ?? null,
            'last_seen_at' => $heartbeat !== null ? gmdate('c', $heartbeat) : null,
            'last_seen_seconds_ago' => $heartbeat !== null ? time() - $heartbeat : null,
            'me' => $id === CONTINUUM_AGENT,
        ];
    }
}
