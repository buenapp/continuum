<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\Bridge\XmppBridgeInterface;
use Continuum\Identity\Cert;
use Continuum\Identity\Envelope;
use Continuum\Injection\InjectionManager;
use Continuum\Injection\InjectionTransportInterface;
use Continuum\MessageTools;
use Continuum\Storage\ContinuumStorage;
use Continuum\Storage\ValKeyStore;

class InjectionXmppBridge implements XmppBridgeInterface {
    public array $calls = [];
    public bool $fail = false;
    public ?\Closure $onSend = null;
    public function send(string $account, string $to, string $body, array $fields): array {
        $this->calls[] = ['account' => $account, 'to' => $to, 'body' => $body, 'fields' => $fields];
        if ($this->onSend !== null) { ($this->onSend)($to); }
        if ($this->fail) { throw new \RuntimeException('xmpp unavailable'); }
        return ['queued' => true, 'id' => $fields['id'] ?? 'M-XMPP'];
    }
    public function health(): array { return []; }
}

class MessageInjectionTest extends TestCase {
    protected function setUp(): void {
        \Continuum\SessionContext::set(null);
        \Continuum\SessionContext::setDeclared(null);
    }

    protected function tearDown(): void {
        \Continuum\SessionContext::set(null);
        \Continuum\SessionContext::setDeclared(null);
    }

    private function couch(): FakeCouch {
        return new FakeCouch([
            ['code' => 200, 'body' => ['uuids' => ['event-1']]],
            ['code' => 201, 'body' => []],
        ]);
    }

    private function tools(FakeRespClient $resp, ?InjectionManager $manager = null, ?XmppBridgeInterface $bridge = null, ?string $keyFile = null, ?FakeCouch $couch = null): MessageTools {
        return new MessageTools(new ContinuumStorage(
            new ValKeyStore($resp), $couch ?? $this->couch(), new FakeArcade()
        ), $bridge, $bridge !== null ? 'xmpp.example.com' : null, $keyFile, $manager);
    }

    private function manager(FakeInjectionTransport $transport, ?ContinuumStorage $storage = null, ?bool $requireSigned = null): InjectionManager {
        $target = ['transport' => $transport];
        if ($requireSigned !== null) { $target['require_signed'] = $requireSigned; }
        foreach (['wakeup', 'steer', 'interrupt'] as $mode) {
            $target[$mode . '_from'] = ['test-agent'];
        }
        return new InjectionManager(['bob' => $target], $storage);
    }

    /** Presence plus a first idempotent inbox insert for a held session. */
    private function heldSessionResponses(): array {
        return array_merge([
            ['bob'], ['heartbeat', '1'], ['sess-1'], ['heartbeat', '1'], '1',
        ], idempotentPushResponses());
    }

    /** Presence plus a first idempotent inbox insert with no session cards. */
    private function unheldSessionResponses(): array {
        return array_merge([
            ['bob'], ['heartbeat', '1'], [], '1',
        ], idempotentPushResponses());
    }

    public function testDirectPushAlsoWritesInboxAndAuditsDelivery(): void {
        $resp = new FakeRespClient($this->heldSessionResponses());
        $transport = new FakeInjectionTransport('fake-rest');
        $result = $this->tools($resp, $this->manager($transport))->message_send(
            'bob', 'hello', session: 'sess-1', preemption: 'wakeup'
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued']);
        $this->assertTrue($data['pushed']);
        $this->assertSame('inbox', $data['via']);
        $this->assertSame('fake-rest', $data['injection_via']);
        $this->assertSame('fake-rest', $data['preemption_via']);
        $this->assertSame(1, $data['depth']);
        $this->assertSame('wakeup', $data['preemption']);
        $this->assertSame(InjectionTransportInterface::IDLE, $data['liveness']);
        $this->assertCount(2, $transport->calls);
        $this->assertSame('hello', $transport->calls[1]['envelope']['body']);
        $this->assertSame('wakeup', $transport->calls[1]['envelope']['preemption']);
        $this->assertContains('RPUSH', array_column($resp->calls, 0));
        $this->assertStringContainsString('Supplemental direct injection delivered', $result->toArray()['content'][0]['text']);
    }

    public function testRemoteSavedButNotResumedKeepsInbox(): void {
        $resp = new FakeRespClient($this->heldSessionResponses());
        $transport = new FakeInjectionTransport('openhands');
        $transport->ack = ['http_status' => 429, 'resumed' => false];
        $result = $this->tools($resp, $this->manager($transport))->message_send(
            'bob', 'hello', session: 'sess-1', preemption: 'wakeup'
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued']);
        $this->assertTrue($data['pushed']);
        $this->assertFalse($data['resumed']);
        $this->assertSame(429, $data['remote_status']);
        $this->assertContains('RPUSH', array_column($resp->calls, 0));
        $this->assertStringContainsString('could not resume', $result->toArray()['content'][0]['text']);
    }

    public function testLegacySendDoesNotAttemptDirectInjection(): void {
        $resp = new FakeRespClient([1]);
        $transport = new FakeInjectionTransport();
        $manager = $this->manager($transport);
        $result = $this->tools($resp, $manager)->message_send('bob', 'hello');
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued']);
        $this->assertArrayNotHasKey('pushed', $data);
        $this->assertSame([], $transport->calls);
    }

    public function testUnreachableSessionFallsBackToInboxWithReason(): void {
        $resp = new FakeRespClient($this->unheldSessionResponses());
        $transport = new FakeInjectionTransport();
        $transport->state = InjectionTransportInterface::UNREACHABLE;
        $result = $this->tools($resp, $this->manager($transport, null, false))->message_send(
            'bob', 'hello', session: 'sess-1', kind: 'control', preemption: 'interrupt'
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued']);
        $this->assertFalse($data['pushed']);
        $this->assertSame('inbox', $data['via']);
        $this->assertSame(InjectionTransportInterface::UNREACHABLE, $data['liveness']);
        $this->assertSame('session unreachable', $data['fallback_reason']);
        $this->assertCount(1, $transport->calls, 'unreachable sessions must not be interrupted or injected');
        $rpush = current(array_filter($resp->calls, fn($call) => $call[0] === 'RPUSH'));
        $this->assertSame('hello', json_decode($rpush[2], true)['body']);
        $this->assertStringContainsString('Direct injection was not delivered', $result->toArray()['content'][0]['text']);
    }

    public function testInjectionFailureFallsBackToInbox(): void {
        $resp = new FakeRespClient($this->unheldSessionResponses());
        $transport = new FakeInjectionTransport();
        $transport->fail = 'inject';
        $result = $this->tools($resp, $this->manager($transport))->message_send(
            'bob', 'hello', session: 'sess-1', preemption: 'steer'
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued']);
        $this->assertFalse($data['pushed']);
        $this->assertSame('fake: inject failed', $data['fallback_reason']);
        $this->assertSame(InjectionTransportInterface::IDLE, $data['liveness']);
    }

    public function testXmppRemainsPrimaryWhenDirectUnavailable(): void {
        $resp = new FakeRespClient($this->unheldSessionResponses());
        $bridge = new InjectionXmppBridge();
        $result = $this->tools($resp, new InjectionManager(), $bridge)->message_send(
            'bob', 'hello', session: 'sess-1', preemption: 'wakeup'
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued']);
        $this->assertFalse($data['pushed']);
        $this->assertSame('xmpp-bridge', $data['via']);
        $this->assertSame('none', $data['preemption_via']);
        $this->assertSame('no direct transport configured', $data['injection_reason']);
        $this->assertArrayNotHasKey('fallback_reason', $data);
        $this->assertSame(1, $data['depth']);
        $this->assertSame('bob@xmpp.example.com', $bridge->calls[0]['to']);
        $this->assertSame('sess-1', $bridge->calls[0]['fields']['session']);
        $this->assertSame('wakeup', $bridge->calls[0]['fields']['preemption']);
        $this->assertContains('RPUSH', array_column($resp->calls, 0));
        $this->assertStringContainsString('XMPP delivery succeeded', $result->toArray()['content'][0]['text']);
    }

    public function testXmppAndDirectPushAreBothDeliveredInThatOrder(): void {
        $resp = new FakeRespClient($this->heldSessionResponses());
        $events = [];
        $bridge = new InjectionXmppBridge();
        $bridge->onSend = function () use (&$events): void { $events[] = 'xmpp'; };
        $transport = new FakeInjectionTransport('fake-rest');
        $transport->onCall = function (string $op) use (&$events): void { $events[] = $op; };
        $result = $this->tools($resp, $this->manager($transport), $bridge)->message_send(
            'bob', 'hello', session: 'sess-1', preemption: 'wakeup'
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued'], 'XMPP acceptance remains visible');
        $this->assertTrue($data['pushed'], 'the supplemental push also succeeded');
        $this->assertSame('xmpp-bridge', $data['via']);
        $this->assertSame('fake-rest', $data['injection_via']);
        $this->assertSame('fake-rest', $data['preemption_via']);
        $this->assertSame(1, $data['depth']);
        $this->assertArrayNotHasKey('fallback_reason', $data);
        $this->assertSame(['xmpp', 'liveness', 'inject'], $events);
        $this->assertCount(1, $bridge->calls);
        $this->assertCount(2, $transport->calls);
        $this->assertSame($bridge->calls[0]['fields']['id'], $transport->calls[1]['envelope']['id'], 'both transports must share the deduplication ID');
        $this->assertContains('RPUSH', array_column($resp->calls, 0));
        $this->assertStringContainsString('sent over XMPP', $result->toArray()['content'][0]['text']);
        $this->assertStringContainsString('Supplemental direct injection delivered', $result->toArray()['content'][0]['text']);
    }

    public function testXmppFailureLeavesDurableInboxAndDoesNotHideFailure(): void {
        $resp = new FakeRespClient($this->heldSessionResponses());
        $bridge = new InjectionXmppBridge();
        $bridge->fail = true;
        $transport = new FakeInjectionTransport();
        try {
            $this->tools($resp, $this->manager($transport, null, false), $bridge)->message_send(
                'bob', 'hello', session: 'sess-1', kind: 'control', preemption: 'interrupt'
            );
            $this->fail('expected the XMPP failure to remain fatal');
        } catch (\RuntimeException $e) {
            $this->assertSame('xmpp unavailable', $e->getMessage());
        }
        $this->assertSame([], $transport->calls, 'direct injection must not replace or conceal XMPP failure');
        $this->assertContains('RPUSH', array_column($resp->calls, 0), 'the durable copy is written before XMPP');
    }

    public function testDirectPushCarriesAttestationUnchangedIntoTransport(): void {
        $keypair = sodium_crypto_sign_keypair();
        $keyFile = tempnam(sys_get_temp_dir(), 'continuum-injection-key-');
        file_put_contents($keyFile, $keypair);
        $couch = new FakeCouch([
            ['code' => 200, 'body' => [
                '_id' => 'continuum',
                'pubkey' => base64_encode(sodium_crypto_sign_publickey($keypair)),
                'status' => 'active',
            ]],
            ['code' => 200, 'body' => ['uuids' => ['event-1']]],
            ['code' => 201, 'body' => []],
        ]);
        $resp = new FakeRespClient($this->heldSessionResponses());
        $storage = new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade());
        $transport = new FakeInjectionTransport();
        $manager = $this->manager($transport, $storage);
        $tools = new MessageTools($storage, null, null, $keyFile, $manager);
        try {
            $tools->message_send('bob', 'signed', session: 'sess-1', preemption: 'wakeup');
            $envelope = $transport->calls[1]['envelope'];
            $this->assertArrayHasKey('attest', $envelope);
            $this->assertSame('signed', $envelope['body']);
            $this->assertSame('sess-1', $envelope['session']);
            $this->assertSame('wakeup', $envelope['preemption']);
        } finally {
            @unlink($keyFile);
        }
    }

    public function testDirectPolicyIsDenyByDefault(): void {
        $resp = new FakeRespClient($this->unheldSessionResponses());
        $transport = new FakeInjectionTransport();
        $manager = new InjectionManager(['bob' => ['transport' => $transport]]);
        $result = $this->tools($resp, $manager)->message_send(
            'bob', 'hello', session: 'sess-1', preemption: 'wakeup'
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued']);
        $this->assertFalse($data['pushed']);
        $this->assertSame('none', $data['preemption_via']);
        $this->assertStringContainsString('denied by policy', $data['fallback_reason']);
        $this->assertSame([], $transport->calls, 'denied policy must not make a network request');
    }

    public function testNativeReceiverIsReportedWithoutDirectTransport(): void {
        $resp = new FakeRespClient($this->unheldSessionResponses());
        $bridge = new InjectionXmppBridge();
        $manager = new InjectionManager([], null, ['bob']);
        $result = $this->tools($resp, $manager, $bridge)->message_send(
            'bob', 'hello', session: 'sess-1', preemption: 'wakeup'
        );
        $data = $result->getStructuredContent();
        $this->assertSame('native', $data['preemption_via']);
        $this->assertSame('wakeup', $bridge->calls[0]['fields']['preemption']);
    }

    public function testInterruptRequiresControlKindBeforeStorage(): void {
        $resp = new FakeRespClient([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/interrupt requires kind=control/');
        $this->tools($resp)->message_send('bob', 'hello', session: 'sess-1', preemption: 'interrupt');
    }

    public function testSignedControlInterruptIsVerifiedBeforeDirectDelivery(): void {
        $keypair = sodium_crypto_sign_keypair();
        $keyFile = tempnam(sys_get_temp_dir(), 'continuum-injection-key-');
        file_put_contents($keyFile, $keypair);
        $registry = [
            '_id' => 'continuum',
            'pubkey' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'status' => 'active',
        ];
        $couch = new FakeCouch([
            ['code' => 200, 'body' => $registry],
            ['code' => 200, 'body' => ['uuids' => ['event-1']]],
            ['code' => 201, 'body' => []],
        ]);
        $resp = new FakeRespClient($this->heldSessionResponses());
        $storage = new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade());
        $transport = new FakeInjectionTransport('fake-control');
        $transport->state = InjectionTransportInterface::RUNNING;
        $transport->ack = ['accepted' => true, 'interrupted' => true];
        $manager = $this->manager($transport, $storage);
        $tools = new MessageTools($storage, null, null, $keyFile, $manager);
        try {
            $result = $tools->message_send('bob', 'stop safely', session: 'sess-1', kind: 'control', preemption: 'interrupt');
            $data = $result->getStructuredContent();
            $this->assertTrue($data['pushed']);
            $this->assertTrue($data['interrupted']);
            $this->assertSame('fake-control', $data['preemption_via']);
            $this->assertSame('interrupt', $transport->calls[1]['envelope']['preemption']);
            $this->assertSame('control', $transport->calls[1]['envelope']['kind']);
        } finally {
            @unlink($keyFile);
        }
    }

    public function testExternallySignedControlInterruptIsVerifiedWithProvidedIdAndTimestamp(): void {
        $wsKeypair = sodium_crypto_sign_keypair();
        $sessKeypair = sodium_crypto_sign_keypair();
        $cert = [
            'not_after' => '2126-10-25T00:00:00+00:00',
            'session' => 'sess-1',
            'session_pubkey' => base64_encode(sodium_crypto_sign_publickey($sessKeypair)),
            'workstream' => 'test-agent',
        ];
        $cert['ws_sig'] = base64_encode(sodium_crypto_sign_detached(
            Envelope::canonical(Cert::covered($cert)),
            sodium_crypto_sign_secretkey($wsKeypair)
        ));
        $certJson = json_encode($cert, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signed = [
            'id' => 'M-ABCDEF12', 'from' => 'test-agent', 'body' => 'stop safely',
            'ts' => '2026-10-25T00:00:00+00:00', 'session' => 'sess-1',
            'kind' => 'control', 'preemption' => 'interrupt',
        ];
        $sig = base64_encode(sodium_crypto_sign_detached(
            Envelope::canonical(Envelope::sigCovered($signed + ['to' => 'bob'])),
            sodium_crypto_sign_secretkey($sessKeypair)
        ));
        $couch = new FakeCouch([
            ['code' => 200, 'body' => [
                '_id' => 'test-agent',
                'pubkey' => base64_encode(sodium_crypto_sign_publickey($wsKeypair)),
                'status' => 'active',
            ]],
            ['code' => 200, 'body' => ['uuids' => ['event-1']]],
            ['code' => 201, 'body' => []],
        ]);
        $resp = new FakeRespClient($this->heldSessionResponses());
        $storage = new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade());
        $transport = new FakeInjectionTransport('fake-control');
        $transport->state = InjectionTransportInterface::RUNNING;
        $transport->ack = ['accepted' => true, 'interrupted' => true];
        $manager = $this->manager($transport, $storage);
        $tools = new MessageTools($storage, null, null, null, $manager);
        $result = $tools->message_send(
            'bob', 'stop safely', session: 'sess-1', kind: 'control', preemption: 'interrupt',
            id: 'M-ABCDEF12', ts: '2026-10-25T00:00:00+00:00', sig: $sig, cert: $certJson
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['pushed']);
        $this->assertSame('fake-control', $data['preemption_via']);
        $this->assertSame('M-ABCDEF12', $transport->calls[1]['envelope']['id']);
        $this->assertSame('2026-10-25T00:00:00+00:00', $transport->calls[1]['envelope']['ts']);
        $this->assertSame('interrupt', $transport->calls[1]['envelope']['preemption']);
    }

    public function testInvalidAttestationDoesNotAuthorizeDirectInterrupt(): void {
        $keyFile = tempnam(sys_get_temp_dir(), 'continuum-injection-key-');
        file_put_contents($keyFile, sodium_crypto_sign_keypair());
        $registry = [
            '_id' => 'continuum',
            'pubkey' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
            'status' => 'active',
        ];
        $couch = new FakeCouch([
            ['code' => 200, 'body' => $registry],
            ['code' => 200, 'body' => ['uuids' => ['event-1']]],
            ['code' => 201, 'body' => []],
        ]);
        $resp = new FakeRespClient($this->unheldSessionResponses());
        $storage = new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade());
        $transport = new FakeInjectionTransport();
        $manager = $this->manager($transport, $storage);
        $tools = new MessageTools($storage, null, null, $keyFile, $manager);
        try {
            $result = $tools->message_send('bob', 'stop safely', session: 'sess-1', kind: 'control', preemption: 'interrupt');
            $data = $result->getStructuredContent();
            $this->assertFalse($data['pushed']);
            $this->assertSame([], $transport->calls);
            $this->assertStringContainsString('signature verification failed', $data['fallback_reason']);
        } finally {
            @unlink($keyFile);
        }
    }

    public function testProvidedTimestampSurvivesExpiryValidation(): void {
        $resp = new FakeRespClient(idempotentPushResponses());
        $expires = gmdate('c', time() + 3600);
        $this->tools($resp)->message_send(
            'bob', 'hello', expires: $expires, id: 'M-ABCDEF12', ts: '2026-10-25T00:00:00+00:00'
        );
        $rpush = current(array_filter($resp->calls, fn($call) => $call[0] === 'RPUSH'));
        $envelope = json_decode($rpush[2], true);
        $this->assertSame('2026-10-25T00:00:00+00:00', $envelope['ts']);
        $this->assertSame($expires, $envelope['expires']);
    }

    public function testDuplicateEnvelopeIdDoesNotRepeatDelivery(): void {
        $existing = ['id' => 'M-ABCDEF12', 'from' => 'test-agent', 'body' => 'old'];
        $resp = new FakeRespClient(array_merge([
            ['bob'], ['heartbeat', '1'], [], '1',
        ], idempotentDuplicateResponses($existing)));
        $transport = new FakeInjectionTransport();
        $result = $this->tools($resp, $this->manager($transport))->message_send(
            'bob', 'new', session: 'sess-1', preemption: 'wakeup', id: 'M-ABCDEF12'
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['duplicate']);
        $this->assertTrue($data['queued']);
        $this->assertFalse($data['pushed']);
        $this->assertSame([], $transport->calls);
        $this->assertNotContains('RPUSH', array_column($resp->calls, 0));
        $this->assertStringContainsString('no duplicate delivery', $result->toArray()['content'][0]['text']);
    }

    public function testPreemptionRequiresSession(): void {
        $resp = new FakeRespClient([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires an explicit session/');
        $this->tools($resp)->message_send('bob', 'hello', preemption: 'wakeup');
    }

    public function testInvalidPreemptionIsRejectedBeforeStorage(): void {
        $resp = new FakeRespClient([]);
        try {
            $this->tools($resp)->message_send('bob', 'hello', preemption: 'later');
            $this->fail('expected invalid preemption to be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid preemption', $e->getMessage());
        }
        $this->assertSame([], $resp->calls);
    }
}
