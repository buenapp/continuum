<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\Identity\Attestor;
use Continuum\Identity\Cert;
use Continuum\Identity\Envelope;
use Continuum\Identity\Verify;

/**
 * Byte-exact identity matrix (docs/IDENTITY.md): canonical encoding
 * vectors, delegation certificates, end to end signatures, Continuum
 * attestation and the three trust levels. Registry records arrive in
 * the contract shape (db 'identities', doc id = workstream) through
 * FakeCouch, standing in for the track A read path.
 */
class IdentityTest extends TestCase {

    /** Public test vectors from docs/IDENTITY.md 7 (never real key material). */
    private const WS_SEED = 'continuum-workstream-key-seed-01';
    private const SESS_SEED = 'continuum-session-key-seed-00000';
    private const ATT_SEED = 'continuum-attest-key-seed-000000';
    private const WS_PUB = 'oM5Oq3FFHpk+zsm8fL1TKYCMn1lM2FziK7IqOBbZePY=';
    private const SESS_PUB = 'fszv/eckulqJ+JodgNl0nnqo5/uchSS8s6n9CxS+oN8=';
    private const ATT_PUB = 'YCRtP5KAQVdODEht1tVgZYVRIPvP+29IWCludNaZEv4=';

    private const SIG_CANON = '{"body":"ready for review","from":"devin","id":"M-1A2B3C4D","kind":"directive","session":"sess-42","to":"sonya","ts":"2026-10-21T02:03:04+00:00"}';
    private const SIG_VALUE = '++LNWcieroac3BVHFLi6Oj0Qq9WSty09Wi7n2WHMrbwkBFktdkQOgWgW+6MUExH+haBlZAKMMtzUcT48L5v6Ag==';
    private const CERT_CANON = '{"not_after":"2126-10-21T08:03:04+00:00","session":"sess-42","session_pubkey":"fszv/eckulqJ+JodgNl0nnqo5/uchSS8s6n9CxS+oN8=","workstream":"devin"}';
    private const WS_SIG_VALUE = 'MEtDthuzzFogaP0fCCe8UoIVBT8tAvqigp5WXpT9/S+FNUGUehuEN8rQTPxmgDDOHKJzdFhlWJD/BdTdGnYLAQ==';
    private const ATT_CANON = '{"body":"standup done","from":"openhands","id":"M-2E3F4A5B","session":"","to":"sonya","ts":"2026-10-21T02:04:05+00:00","workstream":"openhands"}';
    private const ATT_VALUE = 'xnMydONBQCSCpTMSQOJNgGja+GO2FHH5ZYIElpbfOF13DfKDLLJ+Diu1gpxMS+uEG6ljD42J4E+mG7t/SHJxBA==';

    private function keypair(string $seed): string {
        return sodium_crypto_sign_seed_keypair($seed);
    }

    /** Signed example envelope (docs/IDENTITY.md 7.1) without carriers. */
    private function signedEnvelope(): array {
        return [
            'id' => 'M-1A2B3C4D',
            'from' => 'devin',
            'body' => 'ready for review',
            'ts' => '2026-10-21T02:03:04+00:00',
            'kind' => 'directive',
            'session' => 'sess-42',
        ];
    }

    /** Delegation certificate (docs/IDENTITY.md 7.2) as a parsed array. */
    private function certFields(): array {
        return [
            'not_after' => '2126-10-21T08:03:04+00:00',
            'session' => 'sess-42',
            'session_pubkey' => self::SESS_PUB,
            'workstream' => 'devin',
            'ws_sig' => self::WS_SIG_VALUE,
        ];
    }

    /** Registry record in the contract shape, read as track A's loadIdentity will. */
    private function registryDoc(string $workstream, string $pubkey, string $status = 'active'): array {
        $doc = [
            '_id' => $workstream,
            '_rev' => '1-9c4a',
            'pubkey' => $pubkey,
            'status' => $status,
            'created_at' => '2026-10-20T00:00:00+00:00',
            'rotated_at' => null,
            'revoked_at' => $status === 'revoked' ? '2026-10-21T00:00:00+00:00' : null,
        ];
        $couch = new FakeCouch([['code' => 200, 'body' => $doc]]);
        return $couch->get('identities', $workstream);
    }

    public function testRegistryRecordReadsThroughTheContractShape(): void {
        $couch = new FakeCouch([['code' => 200, 'body' => ['_id' => 'devin']]]);
        $couch->get('identities', 'devin');
        // db 'identities' behind the storage facade's prefix, doc id = workstream
        $this->assertStringEndsWith('identities/devin', $couch->calls[0]['path']);
    }

    // --- canonical encoding (docs/IDENTITY.md 3)

    public function testWorkedExampleCanonicalBytes(): void {
        $this->assertSame(self::SIG_CANON, Envelope::canonical(Envelope::sigCovered($this->signedEnvelope() + ['to' => 'sonya'])));
        $this->assertSame(self::CERT_CANON, Envelope::canonical(Cert::covered($this->certFields())));
        $attest = [
            'id' => 'M-2E3F4A5B',
            'from' => 'openhands',
            'body' => 'standup done',
            'ts' => '2026-10-21T02:04:05+00:00',
        ];
        $this->assertSame(self::ATT_CANON, Envelope::canonical(Envelope::attestCovered($attest + ['to' => 'sonya'], 'openhands')));
    }

    public function testPreemptionParticipatesInCanonicalAndAttestation(): void {
        $fields = $this->signedEnvelope() + ['preemption' => 'interrupt'];
        $this->assertSame(
            '{"body":"ready for review","from":"devin","id":"M-1A2B3C4D","kind":"directive","preemption":"interrupt","session":"sess-42","to":"sonya","ts":"2026-10-21T02:03:04+00:00"}',
            Envelope::canonical(Envelope::sigCovered($fields + ['to' => 'sonya']))
        );

        $envelope = [
            'id' => 'M-PREEMPT1', 'from' => 'sonya', 'body' => 'stop', 'ts' => '2026-10-21T02:03:04+00:00',
            'session' => 'sess-42', 'kind' => 'control', 'preemption' => 'interrupt',
        ];
        $envelope['attest'] = (new Attestor($this->keypair(self::ATT_SEED)))->attest($envelope + ['to' => 'bob'], 'sonya');
        $this->assertTrue(Verify::envelope($envelope + ['to' => 'bob'], $this->registryDoc('continuum', self::ATT_PUB))['valid']);
        $envelope['preemption'] = 'wakeup';
        $this->assertFalse(Verify::envelope($envelope + ['to' => 'bob'], $this->registryDoc('continuum', self::ATT_PUB))['valid']);
    }

    public function testCanonicalEscapingVector(): void {
        $envelope = [
            'body' => "quote \" slash / nl \n tab \t unicode \u{00E9} \u{1F680}",
            'from' => 'x',
            'id' => 'M-00000000',
            'to' => 'y',
            'ts' => '2026-10-21T00:00:00+00:00',
            'session' => '',
        ];
        $this->assertSame(
            '{"body":"quote \\" slash / nl \\n tab \\t unicode é 🚀","from":"x","id":"M-00000000","session":"","to":"y","ts":"2026-10-21T00:00:00+00:00"}',
            Envelope::canonical($envelope)
        );
    }

    public function testCanonicalSortsKeysRecursivelyWithStringOrder(): void {
        // SORT_STRING is byte order of the key names: '10' sorts before '9'.
        $this->assertSame('{"10":"b","9":"a"}', Envelope::canonical(['9' => 'a', '10' => 'b']));
        $this->assertSame('{"a":3,"b":{"a":2,"z":1}}', Envelope::canonical(['b' => ['z' => 1, 'a' => 2], 'a' => 3]));
    }

    public function testCoveredSetNormalizesSessionAndDemandsTo(): void {
        $envelope = ['id' => 'M-1', 'from' => 'a', 'body' => 'b', 'ts' => '2026-10-21T00:00:00+00:00'];
        $covered = Envelope::sigCovered($envelope + ['to' => 'z']);
        $this->assertSame('', $covered['session']);
        $this->assertArrayNotHasKey('sig', $covered);
        $this->assertArrayNotHasKey('cert', $covered);
        $this->assertArrayNotHasKey('attest', $covered);
        $this->expectException(\InvalidArgumentException::class);
        Envelope::sigCovered($envelope);
    }

    public function testCanonicalThrowsOnNonStringField(): void {
        $this->expectException(\InvalidArgumentException::class);
        Envelope::sigCovered(['id' => 'M-1', 'from' => 'a', 'body' => 'b', 'ts' => 7, 'to' => 'z']);
    }

    // --- certificate (docs/IDENTITY.md 6.1)

    public function testCertParsesContractShape(): void {
        $json = json_encode($this->certFields() + ['scope' => 'no control'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $cert = Cert::parse($json);
        $this->assertSame('devin', $cert['workstream']);
        $this->assertSame('no control', $cert['scope']);
    }

    public function testCertParseRejectsUnknownAndMissingFields(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown cert field/');
        Cert::parse(json_encode($this->certFields() + ['extra' => 'smuggled']));
    }

    public function testCertParseRejectsMissingWsSig(): void {
        $fields = $this->certFields();
        unset($fields['ws_sig']);
        $this->expectException(\InvalidArgumentException::class);
        Cert::parse(json_encode($fields));
    }

    public function testCertParseRejectsWrongSessionKeyLength(): void {
        $fields = $this->certFields();
        $fields['session_pubkey'] = base64_encode('short');
        $this->expectException(\InvalidArgumentException::class);
        Cert::parse(json_encode($fields));
    }

    public function testCertVerifySucceedsWithActiveWorkstreamRecord(): void {
        Cert::verify($this->certFields(), $this->registryDoc('devin', self::WS_PUB));
        $this->addToAssertionCount(1);
    }

    public function testCertVerifyFailsOnTamperedWsSig(): void {
        $cert = $this->certFields();
        $cert['ws_sig'] = base64_encode(str_repeat('x', SODIUM_CRYPTO_SIGN_BYTES));
        try {
            Cert::verify($cert, $this->registryDoc('devin', self::WS_PUB));
            $this->fail('expected failure on a tampered ws_sig');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ws_sig does not verify', $e->getMessage());
        }
    }

    public function testCertVerifyFailsWithWrongWorkstreamKey(): void {
        $other = sodium_crypto_sign_publickey($this->keypair(self::ATT_SEED));
        try {
            Cert::verify($this->certFields(), $this->registryDoc('devin', base64_encode($other)));
            $this->fail('expected failure on the wrong workstream key');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ws_sig does not verify', $e->getMessage());
        }
    }

    public function testCertVerifyFailsOnRegistryRecordForAnotherWorkstream(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not match cert workstream/');
        Cert::verify($this->certFields(), $this->registryDoc('someone-else', self::WS_PUB));
    }

    public function testCertVerifyRejectsExpiredByClock(): void {
        $cert = $this->certFields();
        // boundary: at not_after itself the certificate is already expired
        try {
            Cert::verify($cert, $this->registryDoc('devin', self::WS_PUB), strtotime($cert['not_after']));
            $this->fail('expected expiry failure at not_after');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }
    }

    // --- end to end envelope (docs/IDENTITY.md 6.2, 8)

    public function testSignedEnvelopeVerifies(): void {
        $envelope = $this->signedEnvelope() + ['to' => 'sonya', 'sig' => self::SIG_VALUE, 'cert' => json_encode($this->certFields(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        $result = Verify::envelope($envelope, $this->registryDoc('devin', self::WS_PUB));
        $this->assertSame(['level' => 'signed', 'valid' => true, 'workstream' => 'devin', 'session' => 'sess-42', 'reason' => null], $result);
    }

    public function testTamperedBodyFailsVerify(): void {
        $envelope = $this->signedEnvelope() + ['to' => 'sonya', 'sig' => self::SIG_VALUE, 'cert' => json_encode($this->certFields(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        $envelope['body'] = 'ready for review NOW';
        $result = Verify::envelope($envelope, $this->registryDoc('devin', self::WS_PUB));
        $this->assertFalse($result['valid']);
        $this->assertSame('signed', $result['level']);
        $this->assertStringContainsString('sig does not verify', $result['reason']);
    }

    public function testWrongSessionKeyFailsVerify(): void {
        // a signature by a key the certificate does not delegate
        $rogue = $this->keypair(self::ATT_SEED);
        $sig = base64_encode(sodium_crypto_sign_detached(
            Envelope::canonical(Envelope::sigCovered($this->signedEnvelope() + ['to' => 'sonya'])),
            sodium_crypto_sign_secretkey($rogue)
        ));
        $envelope = $this->signedEnvelope() + ['to' => 'sonya', 'sig' => $sig, 'cert' => json_encode($this->certFields(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        $result = Verify::envelope($envelope, $this->registryDoc('devin', self::WS_PUB));
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('sig does not verify', $result['reason']);
    }

    public function testExpiredCertFailsVerify(): void {
        $cert = $this->certFields();
        $cert['not_after'] = '2020-01-01T00:00:00+00:00';
        $cert['ws_sig'] = base64_encode(sodium_crypto_sign_detached(
            Envelope::canonical(Cert::covered($cert)),
            sodium_crypto_sign_secretkey($this->keypair(self::WS_SEED))
        ));
        $envelope = $this->signedEnvelope() + ['to' => 'sonya', 'sig' => self::SIG_VALUE, 'cert' => json_encode($cert, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        $result = Verify::envelope($envelope, $this->registryDoc('devin', self::WS_PUB));
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('expired', $result['reason']);
    }

    public function testRevokedWorkstreamFailsVerify(): void {
        $envelope = $this->signedEnvelope() + ['to' => 'sonya', 'sig' => self::SIG_VALUE, 'cert' => json_encode($this->certFields(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        $result = Verify::envelope($envelope, $this->registryDoc('devin', self::WS_PUB, 'revoked'));
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('revoked', $result['reason']);
    }

    public function testEnvelopeSessionMustMatchCertSession(): void {
        $envelope = $this->signedEnvelope() + ['to' => 'sonya', 'sig' => self::SIG_VALUE, 'cert' => json_encode($this->certFields(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        $envelope['session'] = 'sess-other';
        $result = Verify::envelope($envelope, $this->registryDoc('devin', self::WS_PUB));
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('does not match cert session', $result['reason']);
    }

    public function testRecipientContextIsRequiredForVerification(): void {
        $envelope = $this->signedEnvelope() + ['sig' => self::SIG_VALUE, 'cert' => json_encode($this->certFields(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        $result = Verify::envelope($envelope, $this->registryDoc('devin', self::WS_PUB));
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString("missing required envelope field 'to'", $result['reason']);
    }

    public function testIncompleteCarrierMaterialIsInvalid(): void {
        $onlySig = $this->signedEnvelope() + ['to' => 'sonya', 'sig' => self::SIG_VALUE];
        $this->assertFalse(Verify::envelope($onlySig, $this->registryDoc('devin', self::WS_PUB))['valid']);
        $onlyCert = $this->signedEnvelope() + ['to' => 'sonya', 'cert' => json_encode($this->certFields(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        $this->assertFalse(Verify::envelope($onlyCert, $this->registryDoc('devin', self::WS_PUB))['valid']);
    }

    // --- Continuum attestation (docs/IDENTITY.md 6.3)

    public function testAttestedEnvelopeVerifies(): void {
        $envelope = [
            'id' => 'M-2E3F4A5B',
            'from' => 'openhands',
            'body' => 'standup done',
            'ts' => '2026-10-21T02:04:05+00:00',
            'to' => 'sonya',
            'attest' => self::ATT_VALUE,
        ];
        $result = Verify::envelope($envelope, $this->registryDoc('continuum', self::ATT_PUB));
        $this->assertSame(['level' => 'attested', 'valid' => true, 'workstream' => 'openhands', 'session' => null, 'reason' => null], $result);
    }

    public function testAttestedTamperedBodyFailsVerify(): void {
        $envelope = [
            'id' => 'M-2E3F4A5B',
            'from' => 'openhands',
            'body' => 'standup done, really',
            'ts' => '2026-10-21T02:04:05+00:00',
            'to' => 'sonya',
            'attest' => self::ATT_VALUE,
        ];
        $result = Verify::envelope($envelope, $this->registryDoc('continuum', self::ATT_PUB));
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('attest does not verify', $result['reason']);
    }

    public function testAttestedWithWrongContinuumKeyFailsVerify(): void {
        $envelope = [
            'id' => 'M-2E3F4A5B',
            'from' => 'openhands',
            'body' => 'standup done',
            'ts' => '2026-10-21T02:04:05+00:00',
            'to' => 'sonya',
            'attest' => self::ATT_VALUE,
        ];
        $result = Verify::envelope($envelope, $this->registryDoc('continuum', self::WS_PUB));
        $this->assertFalse($result['valid']);
    }

    public function testAttestedWithRevokedContinuumKeyFailsVerify(): void {
        $envelope = [
            'id' => 'M-2E3F4A5B',
            'from' => 'openhands',
            'body' => 'standup done',
            'ts' => '2026-10-21T02:04:05+00:00',
            'to' => 'sonya',
            'attest' => self::ATT_VALUE,
        ];
        $result = Verify::envelope($envelope, $this->registryDoc('continuum', self::ATT_PUB, 'revoked'));
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('revoked', $result['reason']);
    }

    public function testAttestCoversSigAndCertWhenPresent(): void {
        $envelope = $this->signedEnvelope() + [
            'to' => 'sonya',
            'sig' => self::SIG_VALUE,
            'cert' => json_encode($this->certFields(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
        $attestor = new Attestor($this->keypair(self::ATT_SEED));
        $attest = $attestor->attest($envelope, 'devin');
        $key = sodium_crypto_sign_publickey($this->keypair(self::ATT_SEED));
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($attest),
            Envelope::canonical(Envelope::attestCovered($envelope, 'devin')),
            $key
        ));
        // dropping the carriers from the covered set must break it
        $withoutCarriers = $envelope;
        unset($withoutCarriers['sig'], $withoutCarriers['cert']);
        $this->assertFalse(sodium_crypto_sign_verify_detached(
            base64_decode($attest),
            Envelope::canonical(Envelope::attestCovered($withoutCarriers, 'devin')),
            $key
        ));
    }

    // --- legacy level (docs/IDENTITY.md 13)

    public function testLegacyEnvelopeIsUnchangedAndValid(): void {
        $envelope = ['id' => 'M-1', 'from' => 'a', 'body' => 'b', 'ts' => '2026-10-21T00:00:00+00:00', 'to' => 'z'];
        $this->assertSame(
            ['level' => 'legacy', 'valid' => true, 'workstream' => null, 'session' => null, 'reason' => null],
            Verify::envelope($envelope, [])
        );
    }

    // --- attestation key material (docs/IDENTITY.md 10)

    public function testAttestorAcceptsBothSodiumKeyLayouts(): void {
        $keypair = $this->keypair(self::ATT_SEED);
        $fromKeypair = new Attestor($keypair);
        $fromSecret = new Attestor(sodium_crypto_sign_secretkey($keypair));
        $envelope = ['id' => 'M-1', 'from' => 'devin', 'body' => 'b', 'ts' => '2026-10-21T00:00:00+00:00', 'to' => 'sonya'];
        $this->assertSame(self::ATT_PUB, $fromKeypair->publicKey());
        $this->assertSame(self::ATT_PUB, $fromSecret->publicKey());
        $this->assertSame($fromKeypair->attest($envelope, 'devin'), $fromSecret->attest($envelope, 'devin'));
    }

    public function testAttestorRejectsWrongKeyLength(): void {
        $this->expectException(\RuntimeException::class);
        new Attestor('too short');
    }

    // --- worked vector reproduction (docs/IDENTITY.md 7)

    public function testWorkedExampleKeysSignaturesReproduce(): void {
        $this->assertSame(self::WS_PUB, base64_encode(sodium_crypto_sign_publickey($this->keypair(self::WS_SEED))));
        $this->assertSame(self::SESS_PUB, base64_encode(sodium_crypto_sign_publickey($this->keypair(self::SESS_SEED))));
        $this->assertSame(self::ATT_PUB, base64_encode(sodium_crypto_sign_publickey($this->keypair(self::ATT_SEED))));
        $this->assertSame(self::SIG_VALUE, base64_encode(sodium_crypto_sign_detached(
            self::SIG_CANON, sodium_crypto_sign_secretkey($this->keypair(self::SESS_SEED))
        )));
        $this->assertSame(self::WS_SIG_VALUE, base64_encode(sodium_crypto_sign_detached(
            self::CERT_CANON, sodium_crypto_sign_secretkey($this->keypair(self::WS_SEED))
        )));
        $this->assertSame(self::ATT_VALUE, base64_encode(sodium_crypto_sign_detached(
            self::ATT_CANON, sodium_crypto_sign_secretkey($this->keypair(self::ATT_SEED))
        )));
    }
}
