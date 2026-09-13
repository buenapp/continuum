<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ToolResult;
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
        description: 'Register (or refresh) your agent presence record with capabilities and an optional human label. Registers the identity derived from your API key.',
        outputSchema: self::REGISTER_SCHEMA
    )]
    public function agent_register(?array $capabilities = null, ?string $label = null): ToolResult {
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
        $data = ['agent' => CONTINUUM_AGENT, 'registered' => true];
        $text = "Registered '" . CONTINUUM_AGENT . "'"
            . ($label !== null ? " ({$label})" : '')
            . ($capabilities !== null ? ' with ' . count($capabilities) . ' capabilities' : '') . '.';
        return ToolResult::structured($text, $data);
    }

    #[McpTool(
        name: 'agent_heartbeat',
        description: 'Refresh your presence heartbeat, optionally declaring what you are currently working on.',
        idempotentHint: true,
        outputSchema: self::HEARTBEAT_SCHEMA
    )]
    public function agent_heartbeat(?string $workingOn = null): ToolResult {
        $meta = $workingOn !== null ? ['working_on' => $workingOn] : [];
        $this->storage->heartbeat(CONTINUUM_AGENT, $meta);
        $data = ['agent' => CONTINUUM_AGENT, 'ts' => time()];
        $text = "Heartbeat for '" . CONTINUUM_AGENT . "' recorded"
            . ($workingOn !== null ? " — working on: {$workingOn}" : '') . '.';
        return ToolResult::structured($text, $data);
    }

    private const REGISTER_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'agent' => ['type' => 'string'],
            'registered' => ['type' => 'boolean'],
        ],
        'required' => ['agent', 'registered'],
    ];

    private const HEARTBEAT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'agent' => ['type' => 'string'],
            'ts' => ['type' => 'integer'],
        ],
        'required' => ['agent', 'ts'],
    ];
}
