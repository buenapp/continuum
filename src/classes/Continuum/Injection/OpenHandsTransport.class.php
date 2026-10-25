<?php

namespace Continuum\Injection;

/** OpenHands agent-server REST injection (conversation events API). */
class OpenHandsTransport extends RestTransport {
    public function transportName(): string { return 'openhands'; }

    public function liveness(string $sessionId): string {
        $response = $this->expectSuccess(
            $this->request('GET', '/api/conversations/' . $this->pathId($sessionId)),
            true
        );
        if (($response['unreachable'] ?? false) === true) { return self::UNREACHABLE; }
        $body = $response['body'] ?? [];
        $record = is_array($body['data'] ?? null) ? $body['data'] : $body;
        if (!is_array($record)) {
            throw new TransportException('openhands returned a malformed session record');
        }
        return $this->stateFromRecord($record)
            ?? throw new TransportException('openhands session record has no execution status');
    }

    public function inject(string $sessionId, array $envelope, string $preemption, string $liveness): array {
        $id = $this->pathId($sessionId);
        if ($preemption === 'interrupt' && $liveness === self::RUNNING) {
            $this->interrupt('/api/conversations/' . $id . '/interrupt');
        }
        // SendMessageRequest has no metadata member. Keep the human body as
        // the first content item and the complete envelope as a second JSON
        // text item so sig/cert/attest and targeting fields survive unchanged.
        $payload = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => (string)($envelope['body'] ?? '')],
                ['type' => 'text', 'text' => $this->envelopeJson($envelope)],
            ],
            'run' => $preemption !== 'steer',
        ];
        $response = $this->request('POST', '/api/conversations/' . $id . '/events', $payload);
        // The agent-server documents 429 as "message saved, run capacity
        // full". Do not queue a second copy in Continuum in that case.
        if ((int)($response['status'] ?? 0) === 429) {
            return ['http_status' => 429, 'resumed' => false];
        }
        $this->expectSuccess($response);
        return ['http_status' => (int)$response['status']];
    }
}
