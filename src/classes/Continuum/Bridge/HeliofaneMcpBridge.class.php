<?php

namespace Continuum\Bridge;

use EnchiladaHTTP;

/**
 * Minimal MCP client for calling Heliofane's Streamable HTTP endpoint.
 *
 * Handles the initialize/handshake dance, session-id echo, and both
 * JSON and SSE-framed responses. Only what promote_to_memory needs.
 */
class HeliofaneMcpBridge {

    private EnchiladaHTTP $http;
    private ?string $sessionId = null;
    private int $nextId = 1;

    /** @param string $baseUrl Heliofane base URL (no trailing slash, no path). */
    public function __construct(string $baseUrl, string $apiKey, string $mcpPath = 'mcp') {
        $this->http = new EnchiladaHTTP(rtrim($baseUrl, '/'));
        $this->apiKey = $apiKey;
        $this->mcpPath = $mcpPath;
    }

    private string $apiKey;
    private string $mcpPath;

    /**
     * Call an MCP tool. Returns ['ok' => bool, 'result' => array|null, 'error' => string|null].
     * 'result' carries the MCP result object (content/structuredContent/isError).
     */
    public function callTool(string $tool, array $arguments): array {
        try {
            $this->ensureSession();
            $rpc = $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments]);
            if (isset($rpc['error'])) {
                return ['ok' => false, 'result' => null, 'error' => (string)($rpc['error']['message'] ?? 'rpc error')];
            }
            $result = $rpc['result'] ?? [];
            $isError = (bool)($result['isError'] ?? false);
            $text = $result['content'][0]['text'] ?? '';
            // Heliofane reports some tool errors as result text without an
            // isError flag ("**ERROR**: ...").
            if (!$isError && str_starts_with(ltrim($text), '**ERROR**')) { $isError = true; }
            return [
                'ok' => !$isError,
                'result' => $result,
                'error' => $isError ? ($text !== '' ? $text : 'tool reported error') : null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'result' => null, 'error' => $e->getMessage()];
        }
    }

    private bool $handshook = false;

    /**
     * Full MCP handshake once per bridge instance. A session id is optional
     * (stateless servers issue none); echoed back when one was assigned.
     */
    private function ensureSession(): void {
        if ($this->handshook) { return; }
        $init = $this->rpc('initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'continuum', 'version' => APPLICATION_VERSION],
        ]);
        if (isset($init['error'])) {
            throw new \RuntimeException('Heliofane initialize failed: ' . ($init['error']['message'] ?? ''));
        }
        $this->handshook = true;
        $this->rpc('notifications/initialized', null); // notification: id omitted
    }

    /** One JSON-RPC message; parses JSON or SSE-framed responses. */
    private function rpc(string $method, ?array $params): array {
        $id = null;
        $message = ['jsonrpc' => '2.0', 'method' => $method];
        if (!str_starts_with($method, 'notifications/')) { $message['id'] = $id = $this->nextId++; }
        if ($params !== null) { $message['params'] = $params; }

        // Strings pass through EnchiladaHTTP untouched; arrays under a
        // non-'json' format would be form-encoded, so json_encode here.
        $headers = ['Accept: application/json, text/event-stream', 'Content-Type: application/json'];
        if ($this->apiKey !== '') { $headers[] = 'X-Api-Key: ' . $this->apiKey; }
        if ($this->sessionId !== null) { $headers[] = 'Mcp-Session-Id: ' . $this->sessionId; }

        $raw = $this->http->call($this->mcpPath, json_encode($message), 'POST', $headers, null, 'text');

        foreach ($this->http->getLastResponseHeaders() as $name => $value) {
            if (strtolower($name) === 'mcp-session-id' && is_string($value)) { $this->sessionId = $value; }
        }

        $code = $this->http->getHttpCode();
        if ($id === null) { return []; } // notification: 202 + no body
        if ($code === 202) { return []; }
        if ($raw === false || $raw === '') {
            throw new \RuntimeException("Heliofane MCP HTTP {$code}, empty response");
        }
        return $this->parseFrames($raw, $id)
            ?? throw new \RuntimeException("Heliofane MCP HTTP {$code}: no JSON-RPC frame found");
    }

    /** Accept either a bare JSON body or one-or-more SSE data frames. */
    private function parseFrames(string $body, int $wantId): ?array {
        $body = trim($body);
        if (str_starts_with($body, '{')) {
            $decoded = json_decode($body, true);
            return (($decoded['id'] ?? null) === $wantId) ? $decoded : null;
        }
        $found = null;
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (!str_starts_with($line, 'data:')) { continue; }
            $decoded = json_decode(trim(substr($line, 5)), true);
            if (is_array($decoded) && ($decoded['id'] ?? null) === $wantId) { $found = $decoded; }
        }
        return $found;
    }
}
