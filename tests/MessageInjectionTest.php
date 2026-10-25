<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\Bridge\XmppBridgeInterface;
use Continuum\Injection\InjectionManager;
use Continuum\Injection\InjectionTransportInterface;
use Continuum\MessageTools;
use Continuum\Storage\ContinuumStorage;
use Continuum\Storage\ValKeyStore;

class InjectionXmppBridge implements XmppBridgeInterface {
    public array $calls = [];
    public function send(string $account, string $to, string $body, array $fields): array {
        $this->calls[] = ['account' => $account, 'to' => $to, 'body' => $body, 'fields' => $fields];
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

    private function tools(FakeRespClient $resp, ?InjectionManager $manager = null, ?XmppBridgeInterface $bridge = null, ?string $keyFile = null): MessageTools {
        return new MessageTools(new ContinuumStorage(
            new ValKeyStore($resp), $this->couch(), new FakeArcade()
        ), $bridge, $bridge !== null ? 'xmpp.example.com' : null, $keyFile, $manager);
    }

    /** Presence responses for a recipient with one live session. */
    private function heldSessionResponses(): array {
        return [
            ['bob'], ['heartbeat', '1'], ['sess-1'], ['heartbeat', '1'], '1',
        ];
    }

    public function testDirectPushSkipsInboxAndAuditsDelivery(): void {
        $resp = new FakeRespClient($this->heldSessionResponses());
        $transport = new FakeInjectionTransport('fake-rest');
        $manager = new InjectionManager(['bob' => ['transport' => $transport]]);
        $result = $this->tools($resp, $manager)->message_send(
            'bob', 'hello', session: 'sess-1', preemption: 'wakeup'
        );
        $data = $result->getStructuredContent();
        $this->assertFalse($data['queued']);
        $this->assertTrue($data['pushed']);
        $this->assertSame('fake-rest', $data['via']);
        $this->assertSame(0, $data['depth']);
        $this->assertSame('wakeup', $data['preemption']);
        $this->assertSame(InjectionTransportInterface::IDLE, $data['liveness']);
        $this->assertCount(2, $transport->calls);
        $this->assertSame('hello', $transport->calls[1]['envelope']['body']);
        $this->assertNotContains('RPUSH', array_column($resp->calls, 0));
        $this->assertStringContainsString('injected into', $result->toArray()['content'][0]['text']);
    }

    public function testRemoteSavedButNotResumedIsReportedWithoutInboxFallback(): void {
        $resp = new FakeRespClient($this->heldSessionResponses());
        $transport = new FakeInjectionTransport('openhands');
        $transport->ack = ['http_status' => 429, 'resumed' => false];
        $manager = new InjectionManager(['bob' => ['transport' => $transport]]);
        $result = $this->tools($resp, $manager)->message_send(
            'bob', 'hello', session: 'sess-1', preemption: 'wakeup'
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['pushed']);
        $this->assertFalse($data['resumed']);
        $this->assertSame(429, $data['remote_status']);
        $this->assertNotContains('RPUSH', array_column($resp->calls, 0));
        $this->assertStringContainsString('could not resume', $result->toArray()['content'][0]['text']);
    }

    public function testLegacySendDoesNotAttemptDirectInjection(): void {
        $resp = new FakeRespClient([1]);
        $transport = new FakeInjectionTransport();
        $manager = new InjectionManager(['bob' => ['transport' => $transport]]);
        $result = $this->tools($resp, $manager)->message_send('bob', 'hello');
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued']);
        $this->assertArrayNotHasKey('pushed', $data);
        $this->assertSame([], $transport->calls);
    }

    public function testUnreachableSessionFallsBackToInboxWithReason(): void {
        $resp = new FakeRespClient([
            ['bob'], ['heartbeat', '1'], [], '1', 1,
        ]);
        $transport = new FakeInjectionTransport();
        $transport->state = InjectionTransportInterface::UNREACHABLE;
        $manager = new InjectionManager(['bob' => ['transport' => $transport]]);
        $result = $this->tools($resp, $manager)->message_send(
            'bob', 'hello', session: 'sess-1', preemption: 'interrupt'
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
        $resp = new FakeRespClient([
            ['bob'], ['heartbeat', '1'], [], '1', 1,
        ]);
        $transport = new FakeInjectionTransport();
        $transport->fail = 'inject';
        $manager = new InjectionManager(['bob' => ['transport' => $transport]]);
        $result = $this->tools($resp, $manager)->message_send(
            'bob', 'hello', session: 'sess-1', preemption: 'steer'
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued']);
        $this->assertFalse($data['pushed']);
        $this->assertSame('fake: inject failed', $data['fallback_reason']);
        $this->assertSame(InjectionTransportInterface::IDLE, $data['liveness']);
    }

    public function testFallbackUsesConfiguredXmppWithoutLocalCopy(): void {
        $resp = new FakeRespClient([
            ['bob'], ['heartbeat', '1'], [], '1',
        ]);
        $bridge = new InjectionXmppBridge();
        $result = $this->tools($resp, new InjectionManager(), $bridge)->message_send(
            'bob', 'hello', session: 'sess-1', preemption: 'wakeup'
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued']);
        $this->assertFalse($data['pushed']);
        $this->assertSame('xmpp-bridge', $data['via']);
        $this->assertSame('no direct transport configured', $data['fallback_reason']);
        $this->assertSame('bob@xmpp.example.com', $bridge->calls[0]['to']);
        $this->assertSame('sess-1', $bridge->calls[0]['fields']['session']);
        $this->assertNotContains('RPUSH', array_column($resp->calls, 0));
    }

    public function testDirectPushCarriesAttestationUnchangedIntoTransport(): void {
        $keyFile = tempnam(sys_get_temp_dir(), 'continuum-injection-key-');
        file_put_contents($keyFile, sodium_crypto_sign_keypair());
        try {
            $resp = new FakeRespClient($this->heldSessionResponses());
            $transport = new FakeInjectionTransport();
            $manager = new InjectionManager(['bob' => ['transport' => $transport]]);
            $this->tools($resp, $manager, null, $keyFile)->message_send(
                'bob', 'signed', session: 'sess-1', preemption: 'wakeup'
            );
            $envelope = $transport->calls[1]['envelope'];
            $this->assertArrayHasKey('attest', $envelope);
            $this->assertSame('signed', $envelope['body']);
            $this->assertSame('sess-1', $envelope['session']);
        } finally {
            @unlink($keyFile);
        }
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
