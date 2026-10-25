<?php

namespace Continuum\Identity;

/**
 * Canonical envelope encoding for signed messages (docs/IDENTITY.md).
 *
 * The canonical form is the JSON encoding of a covered field set after a
 * recursive ksort: keys sorted at every depth, no whitespace, UTF-8,
 * JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE. Every signature in the
 * identity scheme (envelope `sig`, certificate `ws_sig`, Continuum
 * `attest`) signs these exact bytes, so non-PHP implementations must
 * match this encoder byte for byte; docs/IDENTITY.md pins the escaping
 * rules with fixed test vectors.
 */
class Envelope {

    /** v1 covered fields. Optional keys participate only when present. */
    public const REQUIRED = ['body', 'from', 'id', 'session', 'to', 'ts'];
    public const OPTIONAL = ['attest', 'cert', 'epoch', 'expires', 'kind', 'preemption', 'priority', 'replyTo', 'sig', 'task', 'topic'];

    /** Canonical JSON: recursive ksort, no whitespace, UTF-8, unescaped slashes/unicode. */
    public static function canonical(array $fields): string {
        self::ksortRecursive($fields);
        return json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Covered set for `sig` (the session key): every v1 field present
     * except the three carrier fields. `sig` cannot cover itself;
     * `cert` and `attest` are attached alongside or after it by other
     * parties (docs/IDENTITY.md 6.2).
     */
    public static function sigCovered(array $envelope): array {
        return self::covered($envelope, ['sig', 'cert', 'attest']);
    }

    /**
     * Covered set for `attest` (the Continuum key): every v1 field
     * present except `attest` itself, plus `workstream` = the
     * authenticated workstream. `sig` and `cert` are covered when the
     * sender attached them (docs/IDENTITY.md 6.3).
     */
    public static function attestCovered(array $envelope, string $workstream): array {
        if ($workstream === '') {
            throw new \InvalidArgumentException('workstream must not be empty');
        }
        $covered = self::covered($envelope, ['attest']);
        $covered['workstream'] = $workstream;
        return $covered;
    }

    /**
     * Present v1 fields minus $omit. `session` is always present: the
     * sender-declared session or the empty string. `to` is send-side
     * context (the recipient agent id) and must be supplied by caller.
     */
    private static function covered(array $envelope, array $omit): array {
        $covered = [];
        foreach (array_merge(self::REQUIRED, self::OPTIONAL) as $key) {
            if (in_array($key, $omit, true)) { continue; }
            if ($key === 'session') {
                $covered['session'] = (string)($envelope['session'] ?? '');
                continue;
            }
            if (!array_key_exists($key, $envelope) || $envelope[$key] === null) {
                if (in_array($key, self::REQUIRED, true)) {
                    throw new \InvalidArgumentException("missing required envelope field '{$key}'");
                }
                continue;
            }
            if (!is_string($envelope[$key])) {
                throw new \InvalidArgumentException("envelope field '{$key}' must be a string");
            }
            $covered[$key] = $envelope[$key];
        }
        return $covered;
    }

    private static function ksortRecursive(array &$fields): void {
        ksort($fields, SORT_STRING);
        foreach ($fields as &$value) {
            if (is_array($value)) { self::ksortRecursive($value); }
        }
        unset($value);
    }
}
