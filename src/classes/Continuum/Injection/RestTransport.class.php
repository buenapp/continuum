<?php

namespace Continuum\Injection;

use Enchilada\Tortilla\HttpClient;

/**
 * Harness-specific REST behavior over the vendored Tortilla HTTP client.
 * URL construction, cURL execution, response decoding, and transport errors
 * stay in Enchilada/Extras; this class only handles auth and API contracts.
 */
abstract class RestTransport implements InjectionTransportInterface {
    protected HttpClient $http;

    /**
     * @param array $auth scheme (none|basic|bearer|header), credential_file,
     *                    username, header; tests may supply credential directly.
     */
    public function __construct(
        string $baseUrl,
        protected array $auth = [],
        protected int $timeout = 5,
        ?string $caFile = null,
        ?HttpClient $http = null,
    ) {
        $parts = parse_url($baseUrl);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('injection base_url must be an http(s) URL without credentials, query, or fragment');
        }
        if ($timeout < 1 || $timeout > 60) {
            throw new \InvalidArgumentException('injection timeout must be between 1 and 60 seconds');
        }
        if ($http === null) {
            $multi = new \EnchiladaMultiHTTP(rtrim($baseUrl, '/'));
            $multi->setTimeout($timeout);
            if ($caFile !== null && $caFile !== '') {
                if (!is_readable($caFile)) {
                    throw new \InvalidArgumentException('injection ca_file is not readable');
                }
                $multi->setCaCert($caFile);
            }
            // No EventLoop is injected: Apache/mod_php and other stateless
            // request workers use Tortilla's blocking perform() path.
            $http = new HttpClient($multi);
        }
        $this->http = $http;
    }

    protected function request(string $method, string $path, ?array $payload = null): array {
        try {
            $body = $this->http->call(ltrim($path, '/'), $payload, $method, $this->headers(), $this->timeout, 'json');
        } catch (\Exception $e) {
            throw new TransportException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }
        $status = $this->http->getHttpCode();
        if ($status === 0) {
            $error = $this->http->getLastCurlError();
            throw new TransportException('HTTP transport error' . ($error !== '' ? ': ' . $error : ''));
        }
        return ['status' => $status, 'body' => $body];
    }

    /** @return array<int,string> */
    protected function headers(): array {
        $headers = ['Accept: application/json'];
        $scheme = strtolower((string)($this->auth['scheme'] ?? 'none'));
        if ($scheme === 'none') { return $headers; }
        $secret = $this->credential();
        if ($scheme === 'basic') {
            $username = (string)($this->auth['username'] ?? '');
            if ($username === '' || preg_match('/[\r\n:]/', $username)) {
                throw new TransportException('basic authentication requires a valid username');
            }
            $headers[] = 'Authorization: Basic ' . base64_encode($username . ':' . $secret);
            return $headers;
        }
        if ($scheme === 'bearer') {
            $headers[] = 'Authorization: Bearer ' . $secret;
            return $headers;
        }
        if ($scheme === 'header') {
            $name = (string)($this->auth['header'] ?? '');
            if (!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name)) {
                throw new TransportException('invalid authentication header name');
            }
            $headers[] = $name . ': ' . $secret;
            return $headers;
        }
        throw new TransportException("unsupported authentication scheme '{$scheme}'");
    }

    private function credential(): string {
        // The direct value is for programmatic construction/tests. settings.ini
        // only exposes credential_file, so secrets need not live in the repo.
        if (array_key_exists('credential', $this->auth)) {
            $secret = (string)$this->auth['credential'];
        } else {
            $file = (string)($this->auth['credential_file'] ?? '');
            if ($file === '' || !is_readable($file)) {
                throw new TransportException('credential_file is missing or unreadable');
            }
            $secret = file_get_contents($file);
            if ($secret === false) {
                throw new TransportException('credential_file could not be read');
            }
            $secret = rtrim($secret, "\r\n");
        }
        if ($secret === '' || preg_match('/[\r\n]/', $secret)) {
            throw new TransportException('credential must be non-empty and single-line');
        }
        return $secret;
    }

    protected function expectSuccess(array $response, bool $notFoundIsUnreachable = false): array {
        $status = (int)($response['status'] ?? 0);
        if ($notFoundIsUnreachable && $status === 404) {
            return ['unreachable' => true];
        }
        if ($status < 200 || $status >= 300) {
            throw new TransportException($this->transportName() . ' returned HTTP ' . $status);
        }
        $body = $response['body'] ?? null;
        if (is_array($body) && (($body['success'] ?? true) === false || isset($body['error']))) {
            // Remote prose is not copied into model-facing results or logs.
            throw new TransportException($this->transportName() . ' rejected the request');
        }
        return $response;
    }

    protected function state(?string $value): string {
        $value = strtolower((string)$value);
        if (in_array($value, ['running', 'busy', 'working', 'active', 'processing'], true)) {
            return self::RUNNING;
        }
        if (in_array($value, ['idle', 'inactive', 'completed', 'stopped', 'paused'], true)) {
            return self::IDLE;
        }
        if (in_array($value, ['unreachable', 'offline', 'not_found', 'missing'], true)) {
            return self::UNREACHABLE;
        }
        throw new TransportException($this->transportName() . ' returned an unknown session state');
    }

    protected function stateFromRecord(array $record): ?string {
        foreach (['execution_status', 'status', 'state'] as $key) {
            if (isset($record[$key]) && is_string($record[$key])) {
                return $this->state($record[$key]);
            }
        }
        foreach (['busy', 'running'] as $key) {
            if (array_key_exists($key, $record) && is_bool($record[$key])) {
                return $record[$key] ? self::RUNNING : self::IDLE;
            }
        }
        return null;
    }

    protected function interrupt(string $path): void {
        $this->expectSuccess($this->request('POST', $path));
    }

    /** JSON carrier used when a harness has no metadata field. */
    protected function envelopeJson(array $envelope): string {
        try {
            return json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransportException('message envelope could not be encoded as JSON', 0, $e);
        }
    }

    protected function pathId(string $sessionId): string {
        if ($sessionId === '') { throw new TransportException('session id must not be empty'); }
        return rawurlencode($sessionId);
    }

    /** Text carrier for prompt schemas which have no metadata member. */
    protected function bodyWithEnvelope(array $envelope): string {
        return (string)($envelope['body'] ?? '') . "\n\n<continuum-envelope>\n"
            . $this->envelopeJson($envelope) . "\n</continuum-envelope>";
    }
}
