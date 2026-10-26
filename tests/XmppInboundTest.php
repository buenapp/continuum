<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\XmppInbound;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class XmppInboundTest extends TestCase {

    private function hook(FakeRespClient $resp, array $couchScript): XmppInbound {
        return new XmppInbound(new ContinuumStorage(
            new ValKeyStore($resp),
            new FakeCouch($couchScript),
            new FakeArcade()
        ), 'bridge');
    }

    public function testRejectsNonBridgeCaller(): void {
        [$code, $resp] = $this->hook(new FakeRespClient([]), [])->handle('devin', []);
        $this->assertSame(403, $code);
        $this->assertArrayHasKey('error', $resp);
    }

    public function testRejectsWhenUnconfigured(): void {
        $hook = new XmppInbound(new ContinuumStorage(
            new ValKeyStore(new FakeRespClient([])), new FakeCouch([]), new FakeArcade()
        ), '');
        [$code] = $hook->handle('bridge', []);
        $this->assertSame(403, $code);
    }

    public function testRejectsMissingFields(): void {
        [$code1] = $this->hook(new FakeRespClient([]), [])->handle('bridge', ['from' => 'a@b', 'body' => 'x']);
        [$code2] = $this->hook(new FakeRespClient([]), [])->handle('bridge', ['agent' => 'devin', 'body' => 'x']);
        [$code3] = $this->hook(new FakeRespClient([]), [])->handle('bridge', ['agent' => 'devin', 'from' => 'a@b']);
        $this->assertSame([400, 400, 400], [$code1, $code2, $code3]);
    }

    public function testFilesStanzaIntoTargetInboxWithSonyaMapping(): void {
        $resp = new FakeRespClient(idempotentPushResponses());
        $hook = $this->hook($resp, [
            ['code' => 200, 'body' => ['uuids' => ['e']]],
            ['code' => 201, 'body' => []],
        ]);
        [$code, $out] = $hook->handle('bridge', [
            'agent' => 'devin',
            'from' => 'sonya@example.com/sess-1',
            'body' => 'plan ready',
            'thread' => 'M-THREAD1',
            'stanza_id' => 'st-99',
            'sonya' => ['kind' => 'directive', 'session' => 'sess-1', 'task' => 'T-1', 'priority' => 'urgent', 'preemption' => 'wakeup'],
        ]);
        $this->assertSame(200, $code);
        $this->assertTrue($out['queued']);
        $this->assertSame(1, $out['depth']);
        $this->assertFalse($out['duplicate']);
        $this->assertSame('devin', $out['to']);

        $rpush = current(array_filter($resp->calls, fn($call) => $call[0] === 'EVAL'));
        $this->assertSame('continuum:inbox:devin', $rpush[4]);
        $env = json_decode($rpush[6], true);
        $this->assertMatchesRegularExpression('/^M-[0-9A-F]{8}$/', $env['id']);
        $this->assertSame('xmpp:sonya@example.com/sess-1', $env['from']);
        $this->assertSame('plan ready', $env['body']);
        $this->assertSame('xmpp', $env['via']);
        $this->assertSame('M-THREAD1', $env['replyTo']);
        $this->assertSame('st-99', $env['stanza_id']);
        $this->assertSame('directive', $env['kind']);
        $this->assertSame('sess-1', $env['session']);
        $this->assertSame('T-1', $env['task']);
        $this->assertSame('urgent', $env['priority']);
        $this->assertSame('wakeup', $env['preemption']);
    }

    public function testDuplicateStanzaKeepsOneDurableCopyAndOriginalId(): void {
        $existing = ['id' => 'M-ABCDEF12', 'from' => 'xmpp:sonya@example.com/sess-1', 'body' => 'plan ready'];
        $resp = new FakeRespClient(idempotentDuplicateResponses($existing));
        $hook = $this->hook($resp, [
            ['code' => 200, 'body' => ['uuids' => ['e']]],
            ['code' => 201, 'body' => []],
        ]);
        [$code, $out] = $hook->handle('bridge', [
            'agent' => 'devin', 'from' => 'sonya@example.com/sess-1', 'body' => 'plan ready',
            'stanza_id' => 'M-ABCDEF12', 'sonya' => ['preemption' => 'steer'],
        ]);
        $this->assertSame(200, $code);
        $this->assertTrue($out['duplicate']);
        $this->assertSame('M-ABCDEF12', $out['id']);
        $this->assertSame(1, $out['depth']);
        $this->assertCount(2, $resp->calls);
        $this->assertSame('EVAL', $resp->calls[0][0]);
    }

    public function testSonyaIdentityFieldsRoundTripIntoTheEnvelope(): void {
        $resp = new FakeRespClient(idempotentPushResponses());
        $hook = $this->hook($resp, [
            ['code' => 200, 'body' => ['uuids' => ['e']]],
            ['code' => 201, 'body' => []],
        ]);
        [$code] = $hook->handle('bridge', [
            'agent' => 'devin',
            'from' => 'sonya@example.com/sess-1',
            'body' => 'signed and attested',
            'sonya' => ['sig' => 'c2ln', 'cert' => 'Y2VydA==', 'attest' => 'YXR0ZXN0'],
        ]);
        $this->assertSame(200, $code);
        $rpush = current(array_filter($resp->calls, fn($call) => $call[0] === 'EVAL'));
        $env = json_decode($rpush[6], true);
        $this->assertSame('c2ln', $env['sig']);
        $this->assertSame('Y2VydA==', $env['cert']);
        $this->assertSame('YXR0ZXN0', $env['attest']);
    }
}
