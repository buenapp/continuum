<?php

namespace Continuum\Injection;

/** OpenCode REST injection, with explicit support for its prompt wire versions. */
class OpenCodeTransport extends RestTransport {
    public function __construct(
        string $baseUrl,
        array $auth = [],
        int $timeout = 5,
        ?string $caFile = null,
        ?HttpClientInterface $http = null,
        private string $payloadStyle = 'text',
    ) {
        if (!in_array($payloadStyle, ['text', 'nested', 'parts'], true)) {
            throw new \InvalidArgumentException("unknown OpenCode payload style '{$payloadStyle}'");
        }
        parent::__construct($baseUrl, $auth, $timeout, $caFile, $http);
    }

    public function transportName(): string { return 'opencode'; }

    public function liveness(string $sessionId): string {
        $id = $this->pathId($sessionId);
        $session = $this->expectSuccess($this->request('GET', '/api/session/' . $id), true);
        if (($session['unreachable'] ?? false) === true) { return self::UNREACHABLE; }

        // 2.x exposes active drains as a map. A session present there is
        // running; an existing session absent from it is idle. Older servers
        // may not have /active, in which case the session record is enough.
        $active = $this->request('GET', '/api/session/active');
        if ((int)($active['status'] ?? 0) === 404) {
            $body = $session['body'] ?? [];
            $record = is_array($body['data'] ?? null) ? $body['data'] : $body;
            if (!is_array($record)) {
                throw new TransportException('opencode returned a malformed session record');
            }
            return $this->stateFromRecord($record)
                ?? throw new TransportException('opencode cannot determine session liveness');
        }
        $this->expectSuccess($active);
        $body = $active['body'] ?? [];
        $records = $body['data'] ?? $body;
        if (!is_array($records)) {
            throw new TransportException('opencode returned a malformed active-session record');
        }
        if (array_key_exists($sessionId, $records)) {
            $record = $records[$sessionId];
            if (is_array($record)) {
                return $this->stateFromRecord($record) ?? self::RUNNING;
            }
            return self::RUNNING;
        }
        // Some older builds return one active record rather than a map.
        if (is_array($records) && ($records['sessionID'] ?? $records['id'] ?? null) === $sessionId) {
            return self::RUNNING;
        }
        return self::IDLE;
    }

    public function inject(string $sessionId, array $envelope, string $preemption, string $liveness): array {
        $id = $this->pathId($sessionId);
        if ($preemption === 'interrupt' && $liveness === self::RUNNING) {
            $this->interrupt('/api/session/' . $id . '/interrupt');
        }
        $path = '/api/session/' . $id . ($this->payloadStyle === 'parts' ? '/prompt_async' : '/prompt');
        $response = $this->expectSuccess($this->request('POST', $path, $this->payload($envelope, $preemption)));
        return ['http_status' => (int)$response['status']];
    }

    private function payload(array $envelope, string $preemption): array {
        $body = (string)($envelope['body'] ?? '');
        $resume = $preemption !== 'steer';
        if ($this->payloadStyle === 'parts') {
            // The legacy API has no metadata member. Its second text part is
            // the same lossless carrier used by the OpenHands adapter.
            return [
                'parts' => [
                    ['type' => 'text', 'text' => $body],
                    ['type' => 'text', 'text' => $this->envelopeJson($envelope)],
                ],
                'noReply' => !$resume,
            ];
        }
        $payload = ['resume' => $resume];
        if ($preemption === 'steer') { $payload['delivery'] = 'steer'; }
        if ($this->payloadStyle === 'nested') {
            // The nested prompt schema has no metadata member. Keep the
            // envelope in a marked text trailer instead of sending an
            // unknown top-level field which the server would reject.
            $payload['prompt'] = ['text' => $this->bodyWithEnvelope($envelope)];
        } else {
            // OpenCode 2.0.x: PromptInput fields and metadata are top-level.
            $payload['text'] = $body;
            $payload['metadata'] = ['continuum' => $envelope];
        }
        return $payload;
    }
}
