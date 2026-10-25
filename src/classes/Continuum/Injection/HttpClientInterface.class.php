<?php

namespace Continuum\Injection;

/** Small HTTP seam used by REST transports and their wire-format tests. */
interface HttpClientInterface {
    /**
     * @param string $method HTTP verb.
     * @param string $path Absolute path appended to the configured base URL.
     * @param array|null $payload JSON payload, or null for no body.
     * @param array<int,string> $headers Additional HTTP headers.
     * @return array{status:int,body:mixed}
     * @throws TransportException On a transport-level failure.
     */
    public function request(string $method, string $path, ?array $payload = null, array $headers = []): array;
}
