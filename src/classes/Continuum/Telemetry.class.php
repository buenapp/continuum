<?php

namespace Continuum;

use EnchiladaOTLP;

/**
 * OTLP log telemetry for MCP requests (0.5.0 slice).
 *
 * Fire-and-forget structured log records over EnchiladaOTLP (HTTP/JSON),
 * pointed at the central telemetry collector by default ([otlp] enabled +
 * endpoint). Never breaks request handling: the exporter buffers with
 * suppressErrors=true and every emit runs in a catch-all.
 */
class Telemetry {

    public const DEFAULT_ENDPOINT = 'http://telemetry.morante.com';

    public function __construct(
        private ?EnchiladaOTLP $exporter,
    ) {}

    /** Disabled telemetry: passthrough only. */
    public static function disabled(): self {
        return new self(null);
    }

    /**
     * Build from settings. Enabled only when [otlp] enabled=true; endpoint
     * defaults to the central collector; environment defaults to 'prod'.
     */
    public static function fromSettings($SETTINGS, string $version): self {
        if (!($SETTINGS?->getBool('otlp', 'enabled', false) ?? false)) {
            return self::disabled();
        }
        $endpoint = trim((string)($SETTINGS->getString('otlp', 'endpoint', self::DEFAULT_ENDPOINT) ?? '')) ?: self::DEFAULT_ENDPOINT;
        $environment = trim((string)($SETTINGS->getString('otlp', 'environment', 'prod') ?? '')) ?: 'prod';
        return new self(new EnchiladaOTLP(
            $endpoint,
            [
                'service.name' => 'continuum',
                'service.version' => $version,
                'deployment.environment.name' => $environment,
            ],
            'continuum.mcp',
            $version,
        ));
    }

    public function enabled(): bool {
        return $this->exporter !== null;
    }

    /** Announce process startup (one record per request cycle is not useful here; callers decide cadence). */
    public function started(): void {
        $this->info('continuum started', ['mcp.request.method' => 'startup']);
    }

    public function info(string $body, array $attributes = []): void {
        if ($this->exporter === null) { return; }
        try {
            $this->exporter->info($body, $attributes);
        } catch (\Throwable) {
            // telemetry is a side channel; never break the request path
        }
    }

    public function error(string $body, array $attributes = []): void {
        if ($this->exporter === null) { return; }
        try {
            $this->exporter->error($body, $attributes);
        } catch (\Throwable) {
        }
    }

    /**
     * Wrap the JSON-RPC dispatcher with a timed record per request.
     * Notifications (no id, notifications/* methods) are not recorded.
     * The wrapper never throws beyond rethrowing the handler's own errors.
     */
    public function wrap(callable $handle, string $agent): callable {
        return function (array $request) use ($handle, $agent) {
            $method = (string)($request['method'] ?? '');
            if ($this->exporter === null || str_starts_with($method, 'notifications/')) {
                return $handle($request);
            }
            $tool = (string)($request['params']['name'] ?? '');
            $start = microtime(true);
            try {
                $response = $handle($request);
                $this->record($method, $tool, $agent, $start, 'ok', null);
                return $response;
            } catch (\Throwable $e) {
                $this->record($method, $tool, $agent, $start, 'error', $e);
                throw $e;
            }
        };
    }

    private function record(string $method, string $tool, string $agent, float $start, string $outcome, ?\Throwable $error): void {
        $attributes = [
            'mcp.request.method' => $method,
            'mcp.tool.name' => $tool,
            'mcp.agent' => $agent,
            'duration_ms' => (int)round((microtime(true) - $start) * 1000),
            'mcp.outcome' => $outcome,
        ];
        $body = 'mcp request ' . ($method !== '' ? $method : '?') . ($tool !== '' ? " {$tool}" : '') . " {$outcome}";
        if ($error !== null) {
            $attributes['mcp.error'] = substr($error->getMessage(), 0, 160);
            $this->error("{$body}: {$attributes['mcp.error']}", $attributes);
            return;
        }
        $this->info($body, $attributes);
    }
}
