<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\Identity\Cert;
use Continuum\Identity\Envelope;
use Continuum\Identity\SessionClaim;

/**
 * Proved versus declared session claims (docs/IDENTITY.md 12): a
 * `params._meta.session` claim is proved only when a delegation
 * certificate for that session, issued by the workstream that
 * authenticated the request, verifies against the registry.
 *
 * Uses the pinned test vectors from docs/IDENTITY.md 7.2 (never real key
 * material) and additionally mints certificates for the mismatch cases,
 * which the pinned vector cannot express.
 */
class SessionClaimTest extends TestCase {

    private const WS_SEED = 'continuum-workstream-key-seed-01';
    private const SESS_SEED = 'continuum-session-key-seed-00000';
    private const WS_PUB = 'oM5Oq3FFHpk+zsm8fL1TKYCMn1lM2FziK7IqOBbZePY=';
    private const SESS_PUB = 'fszv/eckulqJ+JodgNl0nnqo5/uchSS8s6n9CxS+oN8=';
    private const WS_SIG_VALUE = 'MEtDthuzzFogaP0fCCe8UoIVBT8tAvqigp5WXpT9/S+FNUGUehuEN8rQTPxmgDDOHKJzdFhlWJD/BdTdGnYLAQ==';

    /** Registry loader: the record a lookup for $workstream returns (null = unregistered). */
    private function registry(?string $workstream, string $pubkey = self::WS_PUB, string $status = 'active'): callable {
        return function (string $asked) use ($workstream, $pubkey, $status): ?array {
            if ($workstream === null || $asked !== $workstream) { return null; }
            return [
                '_id' => $asked,
                'pubkey' => $pubkey,
                'status' => $status,
                'created_at' => '2026-10-20T00:00:00+00:00',
                'rotated_at' => null,
                'revoked_at' => $status === 'revoked' ? '2026-10-21T00:00:00+00:00' : null,
            ];
        };
    }

    /** The pinned certificate of docs/IDENTITY.md 7.2 as the carried string. */
    private function pinnedCert(): string {
        return json_encode([
            'not_after' => '2126-10-21T08:03:04+00:00',
            'session' => 'sess-42',
            'session_pubkey' => self::SESS_PUB,
            'workstream' => 'devin',
            'ws_sig' => self::WS_SIG_VALUE,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Mint a certificate signed by the workstream seed, for mismatch cases. */
    private function mintedCert(array $overrides = []): string {
        $cert = $overrides + [
            'not_after' => '2126-10-21T08:03:04+00:00',
            'session' => 'sess-42',
            'session_pubkey' => base64_encode(sodium_crypto_sign_publickey(
                sodium_crypto_sign_seed_keypair(self::SESS_SEED)
            )),
            'workstream' => 'devin',
        ];
        $cert['ws_sig'] = base64_encode(sodium_crypto_sign_detached(
            Envelope::canonical(Cert::covered($cert)),
            sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(self::WS_SEED))
        ));
        return json_encode($cert, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // --- the proved path

    public function testPinnedCertificateProvesTheClaim(): void {
        $claim = SessionClaim::resolve('sess-42', $this->pinnedCert(), $this->registry('devin'), 'devin');
        $this->assertSame(['status' => 'proved', 'workstream' => 'devin', 'reason' => null], $claim);
    }

    // --- declared, with the reason recorded

    public function testNoCertificateIsDeclared(): void {
        foreach ([null, ''] as $cert) {
            $claim = SessionClaim::resolve('sess-42', $cert, $this->registry('devin'), 'devin');
            $this->assertSame('declared', $claim['status']);
            $this->assertSame('no certificate presented', $claim['reason']);
        }
    }

    public function testMalformedCertificateIsDeclaredNotAnError(): void {
        $claim = SessionClaim::resolve('sess-42', 'not json at all', $this->registry('devin'), 'devin');
        $this->assertSame('declared', $claim['status']);
        $this->assertStringContainsString('cert schema', $claim['reason']);
    }

    public function testCertificateForAnotherSessionIsDeclared(): void {
        $cert = $this->mintedCert(['session' => 'sess-other']);
        $claim = SessionClaim::resolve('sess-42', $cert, $this->registry('devin'), 'devin');
        $this->assertSame('declared', $claim['status']);
        $this->assertStringContainsString("delegates session 'sess-other'", $claim['reason']);
    }

    public function testCertificateFromAnotherWorkstreamCannotProveTheClaim(): void {
        // devin's own valid certificate, presented by an agent authenticated
        // as openhands: the delegation is real but it is not this caller's.
        $claim = SessionClaim::resolve('sess-42', $this->pinnedCert(), $this->registry('devin'), 'openhands');
        $this->assertSame('declared', $claim['status']);
        $this->assertStringContainsString("belongs to workstream 'devin'", $claim['reason']);
    }

    public function testUnregisteredWorkstreamIsDeclared(): void {
        $claim = SessionClaim::resolve('sess-42', $this->pinnedCert(), $this->registry(null), 'devin');
        $this->assertSame('declared', $claim['status']);
        $this->assertStringContainsString('is not registered', $claim['reason']);
    }

    public function testRevokedWorkstreamIsDeclared(): void {
        $claim = SessionClaim::resolve('sess-42', $this->pinnedCert(), $this->registry('devin', self::WS_PUB, 'revoked'), 'devin');
        $this->assertSame('declared', $claim['status']);
        $this->assertStringContainsString('revoked', $claim['reason']);
    }

    public function testExpiredCertificateIsDeclared(): void {
        $cert = $this->mintedCert(['not_after' => '2020-01-01T00:00:00+00:00']);
        $claim = SessionClaim::resolve('sess-42', $cert, $this->registry('devin'), 'devin');
        $this->assertSame('declared', $claim['status']);
        $this->assertStringContainsString('expired', $claim['reason']);
    }

    public function testTamperedCertificateIsDeclared(): void {
        // re-sign nothing: flip the session after signing
        $cert = json_decode($this->mintedCert(), true);
        $cert['session'] = 'sess-42';
        $cert['not_after'] = '2126-10-22T08:03:04+00:00';
        $claim = SessionClaim::resolve('sess-42', json_encode($cert, JSON_UNESCAPED_SLASHES), $this->registry('devin'), 'devin');
        $this->assertSame('declared', $claim['status']);
        $this->assertStringContainsString('ws_sig does not verify', $claim['reason']);
    }

    public function testWrongWorkstreamKeyIsDeclared(): void {
        $otherKey = base64_encode(sodium_crypto_sign_publickey(
            sodium_crypto_sign_seed_keypair(str_pad('other-workstream', SODIUM_CRYPTO_SIGN_SEEDBYTES, '-'))
        ));
        $claim = SessionClaim::resolve('sess-42', $this->pinnedCert(), $this->registry('devin', $otherKey), 'devin');
        $this->assertSame('declared', $claim['status']);
        $this->assertStringContainsString('ws_sig does not verify', $claim['reason']);
    }

    public function testMintedCertificateProvesSoTheFixtureItselfIsSound(): void {
        // guards the mismatch cases above: the mint helper produces a
        // certificate that would otherwise prove a matching claim
        $claim = SessionClaim::resolve('sess-42', $this->mintedCert(), $this->registry('devin'), 'devin');
        $this->assertSame('proved', $claim['status']);
    }
}
