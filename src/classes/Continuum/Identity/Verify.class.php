<?php

namespace Continuum\Identity;

/**
 * Trust decision for a received envelope (docs/IDENTITY.md 8).
 *
 * Three levels, highest trust first:
 *   signed    - end to end: `sig` by the session key delegated by `cert`
 *   attested  - Continuum's own `attest` for MCP-only agents
 *   legacy    - neither; the envelope carries no identity claims
 */
class Verify {

    public const LEVEL_SIGNED = 'signed';
    public const LEVEL_ATTESTED = 'attested';
    public const LEVEL_LEGACY = 'legacy';

    /**
     * Verify one envelope against a registry record.
     *
     * $envelope: the received/stored fields plus `to` (the recipient
     * agent id, send-side context). $identityDoc: the registry record
     * for the key that must verify the outermost signature - the cert's
     * workstream for end to end, workstream 'continuum' for attestation
     * (irrelevant for legacy envelopes). Returns
     * ['level','valid','workstream','session','reason']; envelope
     * problems come back as valid=false with a reason, never as throws.
     */
    public static function envelope(array $envelope, array $identityDoc): array {
        $hasSig = isset($envelope['sig']);
        $hasCert = isset($envelope['cert']);
        if ($hasSig !== $hasCert) {
            return self::result(self::LEVEL_SIGNED, false, null, null, 'sig and cert must both be present or both absent');
        }
        if ($hasSig) {
            return self::verifySigned($envelope, $identityDoc);
        }
        if (isset($envelope['attest'])) {
            return self::verifyAttested($envelope, $identityDoc);
        }
        return self::result(self::LEVEL_LEGACY, true, null, null, null);
    }

    /** End to end: cert chain first, then `sig` by the delegated session key. */
    private static function verifySigned(array $envelope, array $identityDoc): array {
        try {
            $cert = Cert::parse((string)$envelope['cert']);
            Cert::verify($cert, $identityDoc);
            $session = (string)($envelope['session'] ?? '');
            if ($session !== $cert['session']) {
                throw new \RuntimeException("envelope session '{$session}' does not match cert session '{$cert['session']}'");
            }
            $sig = base64_decode((string)$envelope['sig'], true);
            if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
                throw new \RuntimeException('sig is not a base64 Ed25519 signature');
            }
            $message = Envelope::canonical(Envelope::sigCovered($envelope));
            $sessionPubkey = base64_decode($cert['session_pubkey'], true);
            if (!sodium_crypto_sign_verify_detached($sig, $message, $sessionPubkey)) {
                throw new \RuntimeException('sig does not verify against the cert session key (envelope tampered?)');
            }
            return self::result(self::LEVEL_SIGNED, true, $cert['workstream'], $cert['session'], null);
        } catch (\Throwable $e) {
            return self::result(self::LEVEL_SIGNED, false, null, null, $e->getMessage());
        }
    }

    /** Continuum attestation: `attest` by the workstream 'continuum' key. */
    private static function verifyAttested(array $envelope, array $identityDoc): array {
        try {
            $docName = $identityDoc['_id'] ?? $identityDoc['workstream'] ?? null;
            if ($docName !== null && $docName !== 'continuum') {
                throw new \RuntimeException("registry record '{$docName}' is not the continuum attestation key");
            }
            if (($identityDoc['status'] ?? null) !== 'active') {
                throw new \RuntimeException("workstream 'continuum' is revoked");
            }
            $pubkey = base64_decode((string)($identityDoc['pubkey'] ?? ''), true);
            if ($pubkey === false || strlen($pubkey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new \RuntimeException('registry record has no usable Ed25519 pubkey');
            }
            $attest = base64_decode((string)$envelope['attest'], true);
            if ($attest === false || strlen($attest) !== SODIUM_CRYPTO_SIGN_BYTES) {
                throw new \RuntimeException('attest is not a base64 Ed25519 signature');
            }
            // The attested workstream is `from` (v1: message_send stamps
            // it with the authenticated workstream). If `from` is absent
            // the covered set throws and lands in the catch below.
            $workstream = (string)($envelope['from'] ?? '');
            $message = Envelope::canonical(Envelope::attestCovered($envelope, $workstream));
            if (!sodium_crypto_sign_verify_detached($attest, $message, $pubkey)) {
                throw new \RuntimeException('attest does not verify against the continuum key (envelope tampered?)');
            }
            return self::result(self::LEVEL_ATTESTED, true, $workstream, $envelope['session'] ?? null, null);
        } catch (\Throwable $e) {
            return self::result(self::LEVEL_ATTESTED, false, null, null, $e->getMessage());
        }
    }

    private static function result(string $level, bool $valid, ?string $workstream, ?string $session, ?string $reason): array {
        return ['level' => $level, 'valid' => $valid, 'workstream' => $workstream, 'session' => $session, 'reason' => $reason];
    }
}
