<?php

namespace Continuum\Identity;

/**
 * Delegation certificate v1 (docs/IDENTITY.md 6.1).
 *
 * ONE opaque field carried in the envelope's `cert` as a JSON string:
 * {not_after, scope?, session, session_pubkey, workstream, ws_sig}
 * where ws_sig is the workstream key's base64 Ed25519 signature over
 * the canonical encoding of the other fields. A session proves its key
 * with `sig`; this certificate binds that key to a workstream, a
 * session and an expiry.
 */
class Cert {

    /** Certificate fields; ws_sig signs exactly these minus itself. */
    public const FIELDS = ['not_after', 'scope', 'session', 'session_pubkey', 'workstream'];

    /**
     * Parse the opaque cert string. Throws \InvalidArgumentException on
     * any schema violation: unknown keys are rejected so no unsigned
     * field can ride along.
     */
    public static function parse(string $cert): array {
        $decoded = json_decode($cert, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException('cert is not a JSON object');
        }
        self::checkSchema($decoded);
        return $decoded;
    }

    /** Covered set for ws_sig: the cert fields minus ws_sig. */
    public static function covered(array $cert): array {
        $covered = [];
        foreach (self::FIELDS as $key) {
            if (array_key_exists($key, $cert)) { $covered[$key] = $cert[$key]; }
        }
        return $covered;
    }

    /**
     * Verify one parsed certificate against its workstream registry
     * record. Order (docs/IDENTITY.md 6.1): schema -> ws_sig over the
     * canonical covered set -> workstream status active -> not_after.
     * Throws with the failing step on any failure.
     */
    public static function verify(array $cert, array $identityDoc, ?int $now = null): void {
        try {
            self::checkSchema($cert);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('cert schema: ' . $e->getMessage());
        }
        $pubkey = self::identityPubkey($identityDoc, $cert['workstream']);
        $wsSig = base64_decode($cert['ws_sig'], true);
        if ($wsSig === false || strlen($wsSig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new \RuntimeException('cert ws_sig is not a base64 Ed25519 signature');
        }
        if (!sodium_crypto_sign_verify_detached($wsSig, Envelope::canonical(self::covered($cert)), $pubkey)) {
            throw new \RuntimeException('cert ws_sig does not verify against the workstream key');
        }
        if (($identityDoc['status'] ?? null) !== 'active') {
            throw new \RuntimeException("workstream '{$cert['workstream']}' is revoked");
        }
        $notAfter = strtotime($cert['not_after']);
        if ($notAfter === false) {
            throw new \RuntimeException('cert not_after is unparseable');
        }
        if (($now ?? time()) >= $notAfter) {
            throw new \RuntimeException('cert has expired');
        }
    }

    /** Schema gate shared by parse() and verify(). */
    private static function checkSchema(array $cert): void {
        foreach (['not_after', 'session', 'session_pubkey', 'workstream', 'ws_sig'] as $key) {
            if (!isset($cert[$key]) || !is_string($cert[$key]) || $cert[$key] === '') {
                throw new \InvalidArgumentException("cert field '{$key}' must be a non-empty string");
            }
        }
        if (isset($cert['scope']) && !is_string($cert['scope'])) {
            throw new \InvalidArgumentException("cert field 'scope' must be a string");
        }
        foreach ($cert as $key => $value) {
            if (!in_array($key, array_merge(self::FIELDS, ['ws_sig']), true)) {
                throw new \InvalidArgumentException("unknown cert field '{$key}'");
            }
        }
        $sessionPubkey = base64_decode($cert['session_pubkey'], true);
        if ($sessionPubkey === false || strlen($sessionPubkey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException('cert session_pubkey is not a base64 Ed25519 public key');
        }
    }

    /** Registry record -> raw workstream public key, name-checked when derivable. */
    private static function identityPubkey(array $identityDoc, string $workstream): string {
        $docName = $identityDoc['_id'] ?? $identityDoc['workstream'] ?? null;
        if ($docName !== null && $docName !== $workstream) {
            throw new \RuntimeException("registry record '{$docName}' does not match cert workstream '{$workstream}'");
        }
        $pubkey = base64_decode((string)($identityDoc['pubkey'] ?? ''), true);
        if ($pubkey === false || strlen($pubkey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \RuntimeException('registry record has no usable Ed25519 pubkey');
        }
        return $pubkey;
    }
}
