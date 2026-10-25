<?php

namespace Continuum\Injection;

/**
 * One-request-per-connection JSON-lines transport for custom agents.
 *
 * Requests and responses are one JSON object terminated by LF. The response
 * to liveness is {state: running|idle|unreachable}; injection and interrupt
 * responses must explicitly say {accepted: true} (or ok/success: true).
 * This is a request-scoped client, not a listener or an event loop.
 */
class TcpJsonTransport implements InjectionTransportInterface {
    private const MAX_RESPONSE_BYTES = 1048576;
    private ?\Closure $connector;

    public function __construct(
        private string $host,
        private int $port,
        private int $timeout = 5,
        private string $scheme = 'tcp',
        private ?string $caFile = null,
        ?callable $connector = null,
    ) {
        if ($host === '' || preg_match('/[\r\n]/', $host)) {
            throw new \InvalidArgumentException('TCP injection host must be non-empty and single-line');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('TCP injection port must be between 1 and 65535');
        }
        if ($timeout < 1 || $timeout > 60) {
            throw new \InvalidArgumentException('injection timeout must be between 1 and 60 seconds');
        }
        if (!in_array($scheme, ['tcp', 'tls'], true)) {
            throw new \InvalidArgumentException("TCP injection scheme must be 'tcp' or 'tls'");
        }
        if ($caFile !== null && $caFile !== '' && !is_readable($caFile)) {
            throw new \InvalidArgumentException('injection ca_file is not readable');
        }
        $this->connector = $connector !== null ? \Closure::fromCallable($connector) : null;
    }

    public function transportName(): string { return 'tcp-json'; }

    public function liveness(string $sessionId): string {
        $response = $this->roundTrip(['op' => 'liveness', 'session' => $sessionId]);
        if (($response['reachable'] ?? true) === false) { return self::UNREACHABLE; }
        $state = $response['state'] ?? $response['status'] ?? null;
        if (!is_string($state)) {
            throw new TransportException('tcp-json liveness response has no state');
        }
        $state = strtolower($state);
        if (in_array($state, ['running', 'busy', 'working', 'active', 'processing'], true)) {
            return self::RUNNING;
        }
        if (in_array($state, ['idle', 'inactive', 'completed', 'stopped', 'paused'], true)) {
            return self::IDLE;
        }
        if (in_array($state, ['unreachable', 'offline', 'not_found', 'missing'], true)) {
            return self::UNREACHABLE;
        }
        throw new TransportException("tcp-json returned an unknown session state '{$state}'");
    }

    public function inject(string $sessionId, array $envelope, string $preemption, string $liveness): array {
        if ($preemption === 'interrupt' && $liveness === self::RUNNING) {
            $this->accepted($this->roundTrip([
                'op' => 'interrupt',
                'session' => $sessionId,
                'id' => $envelope['id'] ?? null,
            ]));
        }
        $response = $this->roundTrip([
            'op' => 'inject',
            'session' => $sessionId,
            'preemption' => $preemption,
            'id' => $envelope['id'] ?? null,
            'envelope' => $envelope,
        ]);
        $this->accepted($response);
        return ['remote' => $response['id'] ?? null];
    }

    private function accepted(array $response): void {
        if (($response['accepted'] ?? $response['ok'] ?? $response['success'] ?? false) !== true) {
            throw new TransportException('tcp-json peer rejected the request');
        }
    }

    private function roundTrip(array $request): array {
        if ($this->connector !== null) {
            $response = ($this->connector)($request);
            if (!is_array($response)) {
                throw new TransportException('tcp-json connector returned a non-object response');
            }
            return $response;
        }
        return $this->socketRoundTrip($request);
    }

    private function socketRoundTrip(array $request): array {
        $contextOptions = [];
        if ($this->scheme === 'tls') {
            $contextOptions['ssl'] = [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $this->host,
            ];
            if ($this->caFile !== null && $this->caFile !== '') {
                $contextOptions['ssl']['cafile'] = $this->caFile;
            }
        }
        $remote = $this->scheme . '://' . $this->host . ':' . $this->port;
        $stream = @stream_socket_client(
            $remote,
            $errno,
            $error,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create($contextOptions)
        );
        if (!is_resource($stream)) {
            throw new TransportException("tcp-json connection failed ({$errno})");
        }
        try {
            stream_set_timeout($stream, $this->timeout);
            $payload = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            $offset = 0;
            $length = strlen($payload);
            while ($offset < $length) {
                $written = fwrite($stream, substr($payload, $offset));
                if ($written === false || $written === 0) {
                    throw new TransportException('tcp-json write failed or timed out');
                }
                $offset += $written;
            }
            $response = '';
            while (!str_ends_with($response, "\n")) {
                $chunk = fgets($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    $meta = stream_get_meta_data($stream);
                    throw new TransportException(!empty($meta['timed_out'])
                        ? 'tcp-json read timed out'
                        : 'tcp-json peer closed without a response');
                }
                $response .= $chunk;
                if (strlen($response) > self::MAX_RESPONSE_BYTES) {
                    throw new TransportException('tcp-json response exceeds 1 MiB');
                }
            }
            $decoded = json_decode($response, true);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new TransportException('tcp-json peer returned malformed JSON');
            }
            return $decoded;
        } catch (\JsonException $e) {
            throw new TransportException('tcp-json request could not be encoded', 0, $e);
        } finally {
            fclose($stream);
        }
    }
}
