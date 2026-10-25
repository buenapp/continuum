<?php

namespace Continuum\Injection;

/** Request-scoped JSON HTTP client backed by the vendored Enchilada client. */
class CurlHttpClient implements HttpClientInterface {
    private \EnchiladaHTTP $client;

    public function __construct(string $baseUrl, private int $timeout = 5, ?string $caFile = null) {
        $parts = parse_url($baseUrl);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('injection base_url must be an http(s) URL without credentials, query, or fragment');
        }
        if ($timeout < 1 || $timeout > 60) {
            throw new \InvalidArgumentException('injection timeout must be between 1 and 60 seconds');
        }
        $this->client = new \EnchiladaHTTP(rtrim($baseUrl, '/'));
        $this->client->setTimeout($timeout);
        if ($caFile !== null && $caFile !== '') {
            if (!is_readable($caFile)) {
                throw new \InvalidArgumentException('injection ca_file is not readable');
            }
            $this->client->setCaCert($caFile);
        }
    }

    public function request(string $method, string $path, ?array $payload = null, array $headers = []): array {
        try {
            $body = $this->client->call(ltrim($path, '/'), $payload, $method, $headers, $this->timeout, 'json');
        } catch (\Exception $e) {
            throw new TransportException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }
        $status = $this->client->getHttpCode();
        if ($status === 0) {
            $error = $this->client->getLastCurlError();
            throw new TransportException('HTTP transport error' . ($error !== '' ? ': ' . $error : ''));
        }
        return ['status' => $status, 'body' => $body];
    }
}
