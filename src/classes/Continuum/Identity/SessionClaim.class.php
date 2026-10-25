<?php

namespace Continuum\Identity;

/**
 * Proved versus declared session claims (docs/IDENTITY.md 12).
 *
 * A `params._meta.session` claim is only what the caller says it is:
 * any key holder can name any session id. When the caller also presents
 * the delegation certificate for that session (the same JSON `cert` the
 * envelope carries), the claim is proved: the certificate binds the
 * session to a workstream whose key the registry vouches for, and which
 * must be the workstream that authenticated this request. Anything else
 * downgrades the claim to declared with the reason recorded, so a
 * consumer can trust a proved claim and treat a declared one with care.
 *
 * Continuum never enforces a minimum level here: consumers decide (the
 * accepted interim trust for MCP-only agents is that their session
 * claims are declared but authenticated).
 */
class SessionClaim {

    public const PROVED = 'proved';
    public const DECLARED = 'declared';

    /**
     * Classify one session claim. Never throws for anything about the
     * claim itself: an unusable certificate is a declared claim, not a
     * failed request.
     *
     * $session:     the effective session being claimed (non-empty).
     * $cert:        the delegation certificate as carried on the wire.
     * $loadIdentity: fn(string $workstream): ?array, the registry record.
     * $workstream:  the authenticated workstream; a certificate delegating
     *               from any other workstream cannot prove this caller's
     *               claim.
     *
     * Returns ['status' => proved|declared, 'workstream' => ?string,
     * 'reason' => ?string]; workstream and reason are null when proved.
     */
    public static function resolve(string $session, ?string $cert, callable $loadIdentity, string $workstream): array {
        if ($cert === null || $cert === '') {
            return self::declared('no certificate presented');
        }
        try {
            $parsed = Cert::parse($cert);
        } catch (\Throwable $e) {
            return self::declared('cert schema: ' . $e->getMessage());
        }
        if ($parsed['session'] !== $session) {
            return self::declared("cert delegates session '{$parsed['session']}', not '{$session}'");
        }
        if ($parsed['workstream'] !== $workstream) {
            return self::declared("cert belongs to workstream '{$parsed['workstream']}', not '{$workstream}'");
        }
        $record = $loadIdentity($parsed['workstream']);
        if (!is_array($record)) {
            return self::declared("workstream '{$parsed['workstream']}' is not registered");
        }
        try {
            Cert::verify($parsed, $record);
        } catch (\Throwable $e) {
            return self::declared('cert verification: ' . $e->getMessage());
        }
        return ['status' => self::PROVED, 'workstream' => $parsed['workstream'], 'reason' => null];
    }

    private static function declared(string $reason): array {
        return ['status' => self::DECLARED, 'workstream' => null, 'reason' => $reason];
    }
}
