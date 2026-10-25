<?php

namespace Continuum\Injection;

use Enchilada\Config\IniConfig;

/** Resolves operator-declared agent capabilities and attempts live delivery. */
class InjectionManager {
    /**
     * @param array<string,array{transport:InjectionTransportInterface,session_resolution?:string,session_id?:string,session_map?:array}> $targets
     */
    public function __construct(private array $targets = []) {
        foreach ($targets as $agent => $target) {
            if (!is_string($agent) || $agent === '' || !($target['transport'] ?? null) instanceof InjectionTransportInterface) {
                throw new \InvalidArgumentException('each injection target needs an agent and a transport');
            }
        }
    }

    /** Build targets from [injection.<agent>] sections; no network is touched. */
    public static function fromSettings(?IniConfig $settings): self {
        if ($settings === null) { return new self(); }
        $data = $settings->toArray();
        $global = $data['injection'] ?? [];
        if (!is_array($global)) {
            throw new \InvalidArgumentException('[injection] must be a configuration section');
        }
        if (!self::boolValue($global['enabled'] ?? true, 'injection.enabled')) {
            return new self();
        }
        $timeout = self::intValue($global['timeout'] ?? 5, 'injection.timeout', 1, 60);
        $targets = [];
        foreach ($data as $section => $values) {
            if (!is_string($section) || !str_starts_with($section, 'injection.')) { continue; }
            $agent = substr($section, strlen('injection.'));
            if ($agent === '' || !is_array($values)) {
                throw new \InvalidArgumentException("invalid injection target section [{$section}]");
            }
            $target = self::target($values, $timeout);
            if ($target !== null) { $targets[$agent] = $target; }
        }
        return new self($targets);
    }

    /**
     * Try a live delivery. The return value is deliberately not an exception:
     * message_send uses it to choose its normal XMPP/inbox fallback.
     *
     * @return array{pushed:bool,transport:?string,liveness:?string,reason:?string,ack?:array}
     */
    public function deliver(string $agent, string $session, array $envelope, string $preemption): array {
        $target = $this->targets[$agent] ?? null;
        if ($target === null) {
            return ['pushed' => false, 'transport' => null, 'liveness' => null, 'reason' => 'no direct transport configured'];
        }
        $transport = $target['transport'];
        $name = $transport->transportName();
        try {
            $remoteSession = $this->resolveSession($target, $session);
            $liveness = $transport->liveness($remoteSession);
            if (!in_array($liveness, [InjectionTransportInterface::RUNNING, InjectionTransportInterface::IDLE, InjectionTransportInterface::UNREACHABLE], true)) {
                throw new TransportException('transport returned an invalid liveness state');
            }
            if ($liveness === InjectionTransportInterface::UNREACHABLE) {
                return ['pushed' => false, 'transport' => $name, 'liveness' => $liveness, 'reason' => 'session unreachable'];
            }
            $ack = $transport->inject($remoteSession, $envelope, $preemption, $liveness);
            return ['pushed' => true, 'transport' => $name, 'liveness' => $liveness, 'reason' => null, 'ack' => $ack];
        } catch (TransportException $e) {
            return [
                'pushed' => false,
                'transport' => $name,
                'liveness' => $liveness ?? null,
                'reason' => self::reason($name, $e),
            ];
        }
    }

    private function resolveSession(array $target, string $session): string {
        $resolution = $target['session_resolution'] ?? 'message';
        if ($resolution === 'message') { $resolved = $session; }
        elseif ($resolution === 'fixed') {
            $resolved = (string)($target['session_id'] ?? '');
            if ($resolved === '') { throw new TransportException('fixed session resolution has no session_id'); }
        } elseif ($resolution === 'map') {
            $map = $target['session_map'] ?? [];
            if (!array_key_exists($session, $map) || !is_string($map[$session]) || $map[$session] === '') {
                throw new TransportException("no harness session mapping for '{$session}'");
            }
            $resolved = $map[$session];
        } else {
            throw new TransportException("unknown session resolution '{$resolution}'");
        }
        if ($resolved === '') { throw new TransportException('harness session id must not be empty'); }
        return $resolved;
    }

    private static function reason(string $name, TransportException $e): string {
        $reason = trim(preg_replace('/\s+/', ' ', $name . ': ' . $e->getMessage()) ?? $name);
        return strlen($reason) > 300 ? substr($reason, 0, 297) . '...' : $reason;
    }

    private static function target(array $values, int $defaultTimeout): ?array {
        $kind = strtolower(self::stringValue($values, 'transport', 'none'));
        if (in_array($kind, ['none', 'null', ''], true)) { return null; }
        if ($kind === 'rest') {
            $kind = strtolower(self::stringValue($values, 'driver', ''));
        }
        if (in_array($kind, ['acp', 'stdio'], true)) {
            throw new \InvalidArgumentException('ACP/stdio injection is reserved for a future ACP client topology');
        }
        if ($kind === 'xmpp') {
            throw new \InvalidArgumentException('XMPP is a queued fallback; configure it under [xmpp], not [injection.*]');
        }
        $timeout = self::intValue($values['timeout'] ?? $defaultTimeout, 'injection.timeout', 1, 60);
        $target = self::sessionConfig($values);
        if (in_array($kind, ['openhands', 'opencode'], true)) {
            $baseUrl = self::requiredString($values, 'base_url');
            $auth = self::authConfig($values, $kind);
            $caFile = self::stringValue($values, 'ca_file', '');
            if ($kind === 'openhands') {
                $target['transport'] = new OpenHandsTransport($baseUrl, $auth, $timeout, $caFile !== '' ? $caFile : null);
            } else {
                $payload = self::stringValue($values, 'payload', 'text');
                $target['transport'] = new OpenCodeTransport($baseUrl, $auth, $timeout, $caFile !== '' ? $caFile : null, null, $payload);
            }
            return $target;
        }
        if (in_array($kind, ['tcp', 'tcp-json'], true)) {
            $host = self::requiredString($values, 'host');
            $port = self::intValue($values['port'] ?? null, 'injection.port', 1, 65535);
            $scheme = self::stringValue($values, 'scheme', 'tcp');
            $caFile = self::stringValue($values, 'ca_file', '');
            $target['transport'] = new TcpJsonTransport($host, $port, $timeout, $scheme, $caFile !== '' ? $caFile : null);
            return $target;
        }
        throw new \InvalidArgumentException("unknown injection transport '{$kind}'");
    }

    private static function sessionConfig(array $values): array {
        $resolution = self::stringValue($values, 'session_resolution', 'message');
        if (!in_array($resolution, ['message', 'fixed', 'map'], true)) {
            throw new \InvalidArgumentException("unknown session_resolution '{$resolution}'");
        }
        $target = ['session_resolution' => $resolution];
        if ($resolution === 'fixed') {
            $target['session_id'] = self::requiredString($values, 'session_id');
        }
        if ($resolution === 'map') {
            $json = self::requiredString($values, 'session_map');
            try {
                $map = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('session_map must be a JSON object', 0, $e);
            }
            if (!is_array($map) || array_is_list($map)) {
                throw new \InvalidArgumentException('session_map must be a JSON object');
            }
            foreach ($map as $from => $to) {
                if (!is_string($to) || $to === '') {
                    throw new \InvalidArgumentException('session_map values must be non-empty strings');
                }
            }
            $target['session_map'] = $map;
        }
        return $target;
    }

    private static function authConfig(array $values, string $kind): array {
        $scheme = strtolower(self::stringValue($values, 'auth_scheme', 'none'));
        if (!in_array($scheme, ['none', 'basic', 'bearer', 'header'], true)) {
            throw new \InvalidArgumentException("unknown auth_scheme '{$scheme}'");
        }
        $auth = ['scheme' => $scheme];
        if ($scheme === 'none') { return $auth; }
        $auth['credential_file'] = self::requiredString($values, 'credential_file');
        if ($scheme === 'basic') {
            $auth['username'] = self::stringValue($values, 'username', $kind === 'opencode' ? 'opencode' : '');
        } elseif ($scheme === 'header') {
            $auth['header'] = self::stringValue($values, 'auth_header', $kind === 'openhands' ? 'X-Session-API-Key' : 'Authorization');
        }
        return $auth;
    }

    private static function requiredString(array $values, string $key): string {
        $value = self::stringValue($values, $key, '');
        if ($value === '') { throw new \InvalidArgumentException("injection setting '{$key}' is required"); }
        return $value;
    }

    private static function stringValue(array $values, string $key, ?string $default): string {
        $value = $values[$key] ?? $default;
        if ($value === null) { return ''; }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException("injection setting '{$key}' must be a string");
        }
        return trim((string)$value);
    }

    private static function intValue(mixed $value, string $name, int $min, int $max): int {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', trim($value)))) {
            throw new \InvalidArgumentException("{$name} must be an integer");
        }
        $value = (int)$value;
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("{$name} must be between {$min} and {$max}");
        }
        return $value;
    }

    private static function boolValue(mixed $value, string $name): bool {
        if (is_bool($value)) { return $value; }
        if (is_int($value) && ($value === 0 || $value === 1)) { return $value === 1; }
        if (is_string($value) && in_array(strtolower($value), ['true', 'yes', 'on', '1'], true)) { return true; }
        if (is_string($value) && in_array(strtolower($value), ['false', 'no', 'off', '0'], true)) { return false; }
        throw new \InvalidArgumentException("{$name} must be boolean");
    }
}
