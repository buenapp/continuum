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
        $resp = new FakeRespClient([7]); // RPUSH -> depth 7
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
            'sonya' => ['kind' => 'directive', 'session' => 'sess-1', 'task' => 'T-1', 'priority' => 'urgent'],
        ]);
        $this->assertSame(200, $code);
        $this->assertTrue($out['queued']);
        $this->assertSame(7, $out['depth']);
        $this->assertSame('devin', $out['to']);

        $this->assertSame('RPUSH', $resp->calls[0][0]);
        $this->assertSame('continuum:inbox:devin', $resp->calls[0][1]);
        $env = json_decode($resp->calls[0][2], true);
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
    }
}
