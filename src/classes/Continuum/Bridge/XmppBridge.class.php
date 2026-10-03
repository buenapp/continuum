<?php

namespace Continuum\Bridge;

final class XmppBridgeClient implements XmppBridgeInterface {

    public function __construct(private string $socketPath) {}

    public function send(string $account, string $to, string $body, array $fields): array {
        $payload = json_encode(array_merge([
            'account' => $account,
            'to' => $to,
            'body' => $body,
        ], $fields));
        return $this->request('PUT', '/v1/send', $payload);
    }

    public function health(): array {
        return $this->request('GET', '/v1/health', null);
    }

    private function request(string $method, string $path, ?string $body): array {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl extension unavailable');
        }
        $ch = curl_init('http://localhost' . $path);
        $headers = ['Content-Type: application/json'];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if (defined('CURLOPT_UNIX_SOCKET_PATH')) {
            $opts[CURLOPT_UNIX_SOCKET_PATH] = $this->socketPath;
        }
        if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = $body; }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("xmpp bridge unreachable: {$err}");
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = json_decode((string)$resp, true);
        if (!is_array($data)) { $data = []; }
        if ($code >= 400) {
            throw new \RuntimeException('xmpp bridge error HTTP ' . $code . ': ' . ($data['error'] ?? ''));
        }
        return $data;
    }
}
