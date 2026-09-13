<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use Continuum\Storage\ContinuumStorage;

/**
 * Agent presence tools. Identity comes from the API key (CONTINUUM_AGENT);
 * registration is a presence record with capabilities/meta. Heartbeats
 * are high-frequency churn, so they are deliberately NOT written to the
 * event log — registration is logged.
 */
class AgentTools {

    public function __construct(private ContinuumStorage $storage) {}

    #[McpTool(
        name: 'agent_register',
        description: 'Register (or refresh) your agent presence record with capabilities and an optional human label. Registers the identity derived from your API key.'
    )]
    public function agent_register(?array $capabilities = null, ?string $label = null): array {
        // Presence records live in ValKey; read direct to preserve created-on fields.
        $existing = $this->storage->agentDirectory()[CONTINUUM_AGENT] ?? null;
        $meta = [];
        if ($capabilities !== null) { $meta['capabilities'] = $capabilities; }
        if ($label !== null) { $meta['label'] = $label; }
        if (empty($existing['registered_at'])) { $meta['registered_at'] = gmdate('c'); }
        $this->storage->heartbeat(CONTINUUM_AGENT, $meta);
        if (empty($existing['registered_at'])) {
            $this->storage->appendLog(CONTINUUM_AGENT, 'agent_register', ['capabilities' => $capabilities]);
        }
        return ['agent' => CONTINUUM_AGENT, 'registered' => true];
    }

    #[McpTool(
        name: 'agent_heartbeat',
        description: 'Refresh your presence heartbeat, optionally declaring what you are currently working on.',
        idempotentHint: true
    )]
    public function agent_heartbeat(?string $workingOn = null): array {
        $meta = $workingOn !== null ? ['working_on' => $workingOn] : [];
        $this->storage->heartbeat(CONTINUUM_AGENT, $meta);
        return ['agent' => CONTINUUM_AGENT, 'ts' => time()];
    }
}
