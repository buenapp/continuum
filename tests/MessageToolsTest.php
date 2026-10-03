<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\MessageTools;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

/** Records bridge sends; scripted answers, throws when told. */
class FakeXmppBridge implements \Continuum\Bridge\XmppBridgeInterface {
    /** @var array<int,array> */ public array $calls = [];
    public ?array $answer = null;
    public ?string $failFor = null;
    public function send(string $account, string $to, string $body, array $fields): array {
        $this->calls[] = ['account' => $account, 'to' => $to, 'body' => $body, 'fields' => $fields];
        if ($this->failFor !== null && str_contains($to, $this->failFor)) {
            throw new \RuntimeException('bridge unreachable');
        }
        return $this->answer ?? ['queued' => true, 'id' => $fields['id'] ?? 'M-BRIDGE01'];
    }
    public function health(): array { return []; }
}

class MessageToolsTest extends TestCase {

    protected function setUp(): void {
        \Continuum\SessionContext::set(null);
        \Continuum\SessionContext::setDeclared(null);
    }

    protected function tearDown(): void {
        \Continuum\SessionContext::set(null);
        \Continuum\SessionContext::setDeclared(null);
    }

    private function tools(FakeRespClient $resp): MessageTools {
        return new MessageTools(new ContinuumStorage(
            new ValKeyStore($resp),
            new FakeCouch([['code' => 200, 'body' => ['uuids' => ['e']]], ['code' => 201, 'body' => []]]),
            new FakeArcade()
        ));
    }

    public function testSendPushesJsonEnvelope(): void {
        $resp = new FakeRespClient([2]); // RPUSH -> depth 2
        $result = $this->tools($resp)->message_send('bob', 'hello', 'coord');
        $this->assertTrue($result->getStructuredContent()['queued']);
        $this->assertStringContainsString("queued to 'bob' (inbox depth 2)", $result->toArray()['content'][0]['text']);
        $rpush = $resp->calls[0];
        $this->assertSame('RPUSH', $rpush[0]);
        $this->assertSame('continuum:inbox:bob', $rpush[1]);
        $envelope = json_decode($rpush[2], true);
        $this->assertSame('test-agent', $envelope['from']);
        $this->assertSame('coord', $envelope['topic']);
        $this->assertSame('hello', $envelope['body']);
    }

    public function testPullReadsOldestUntargetedAndDeletesThem(): void {
        $resp = new FakeRespClient([
            'OK',                                                          // WATCH
            ['{"from":"a","body":"one"}', '{"from":"b","body":"two"}'],    // LRANGE
            'OK',                                                          // MULTI
            'QUEUED',                                                      // DEL
            [0],                                                           // EXEC
        ]);
        $result = $this->tools($resp)->message_inbox_pull(5);
        $data = $result->getStructuredContent();
        $this->assertSame(2, $data['count']);
        $this->assertSame('one', $data['messages'][0]['body']);
        $this->assertSame('two', $data['messages'][1]['body']);
        $text = $result->toArray()['content'][0]['text'];
        $this->assertStringContainsString('# Inbox for test-agent (2)', $text);
        $this->assertStringContainsString('from a:', $text);
        $this->assertStringContainsString('  one', $text);
        // emptied inbox is dropped wholesale: MULTI + DEL + EXEC, no RPUSH
        $this->assertSame('WATCH', $resp->calls[0][0]);
        $this->assertSame('MULTI', $resp->calls[2][0]);
        $this->assertSame('DEL', $resp->calls[3][0]);
        $this->assertSame('EXEC', $resp->calls[4][0]);
    }

    public function testPullEmptyInboxPerformsNoWrite(): void {
        $resp = new FakeRespClient(['OK', []]); // WATCH, LRANGE empty
        $result = $this->tools($resp)->message_inbox_pull();
        $this->assertSame(0, $result->getStructuredContent()['count']);
        $this->assertStringContainsString('(empty)', $result->toArray()['content'][0]['text']);
        $this->assertSame('UNWATCH', $resp->calls[2][0]);
        $this->assertCount(3, $resp->calls);
    }

    public function testPullSkipsTargetedLeasedAndExpiredMessages(): void {
        $now = time();
        $resp = new FakeRespClient([
            'OK',
            [
                json_encode(['from' => 'a', 'body' => 'open']),
                json_encode(['from' => 'a', 'body' => 'for-s1', 'session' => 's1']),
                json_encode(['from' => 'a', 'body' => 'leased', 'lease' => 'L-OLD', 'lease_until' => $now + 60]),
                json_encode(['from' => 'a', 'body' => 'stale', 'expires' => gmdate('c', $now - 10)]),
            ],
            'OK', 'QUEUED', 'QUEUED', [1, 3], // MULTI, DEL, RPUSH, EXEC
        ]);
        $result = $this->tools($resp)->message_inbox_pull(10);
        $data = $result->getStructuredContent();
        $this->assertSame(1, $data['count']);
        $this->assertSame('open', $data['messages'][0]['body']);
        // rewrite keeps targeted + leased, drops the expired one
        $this->assertSame('RPUSH', $resp->calls[4][0]);
        $kept = array_map(fn($j) => json_decode($j, true), array_slice($resp->calls[4], 2));
        $this->assertSame(['for-s1', 'leased'], array_column($kept, 'body'));
    }

    public function testLeaseScopesToDeclaredSession(): void {
        \Continuum\SessionContext::setDeclared('sess-1');
        $now = time();
        $resp = new FakeRespClient([
            'OK',
            [
                json_encode(['id' => 'M-A1', 'from' => 'x', 'body' => 'open']),
                json_encode(['id' => 'M-A2', 'from' => 'x', 'body' => 'for-s1', 'session' => 'sess-1']),
                json_encode(['id' => 'M-A3', 'from' => 'x', 'body' => 'for-s2', 'session' => 'sess-2']),
                json_encode(['id' => 'M-A4', 'from' => 'x', 'body' => 'dead-lease', 'session' => 'sess-1', 'lease' => 'L-OLD', 'lease_until' => $now - 5]),
                json_encode(['id' => 'M-A5', 'from' => 'x', 'body' => 'live-lease', 'lease' => 'L-LIVE', 'lease_until' => $now + 60]),
            ],
            'OK', 'QUEUED', 'QUEUED', [1, 5],
        ]);
        $result = $this->tools($resp)->message_lease(10, 60);
        $data = $result->getStructuredContent();
        $this->assertMatchesRegularExpression('/^L-[0-9A-F]{8}$/', $data['lease']);
        $this->assertSame(3, $data['count']);
        $this->assertSame(['open', 'for-s1', 'dead-lease'], array_column($data['messages'], 'body'));
        // the stored rewrite marks exactly the leased three, in list order
        $this->assertSame('RPUSH', $resp->calls[4][0]);
        $kept = array_map(fn($j) => json_decode($j, true), array_slice($resp->calls[4], 2));
        $byId = array_column($kept, null, 'id');
        $this->assertSame($data['lease'], $byId['M-A1']['lease']);
        $this->assertSame($data['lease'], $byId['M-A2']['lease']);
        $this->assertSame($data['lease'], $byId['M-A4']['lease']);
        $this->assertGreaterThan($now, $byId['M-A1']['lease_until']);
        $this->assertArrayNotHasKey('lease', $byId['M-A3']);
        $this->assertSame('L-LIVE', $byId['M-A5']['lease']);
        // lease (and its session scope) is audited
        $text = $result->toArray()['content'][0]['text'];
        $this->assertStringContainsString('for session sess-1', $text);
    }

    public function testLeaseWithoutSessionTakesUntargetedOnly(): void {
        $resp = new FakeRespClient([
            'OK',
            [
                json_encode(['id' => 'M-B1', 'from' => 'x', 'body' => 'open']),
                json_encode(['id' => 'M-B2', 'from' => 'x', 'body' => 'for-s1', 'session' => 's1']),
            ],
            'OK', 'QUEUED', 'QUEUED', [1, 2],
        ]);
        $result = $this->tools($resp)->message_lease();
        $data = $result->getStructuredContent();
        $this->assertSame(1, $data['count']);
        $this->assertSame('open', $data['messages'][0]['body']);
    }

    public function testLeaseEmptyInboxWritesNothing(): void {
        $resp = new FakeRespClient(['OK', []]);
        $result = $this->tools($resp)->message_lease();
        $data = $result->getStructuredContent();
        $this->assertSame(0, $data['count']);
        $this->assertMatchesRegularExpression('/^L-[0-9A-F]{8}$/', $data['lease']);
        // WATCH, LRANGE, UNWATCH — no inbox write; the only further call
        // is the event-log change notification.
        $this->assertSame('UNWATCH', $resp->calls[2][0]);
        $this->assertSame('PUBLISH', $resp->calls[3][0]);
        $this->assertCount(4, $resp->calls);
    }

    public function testLeaseRetriesOnceThenThrowsOnConcurrentWrites(): void {
        $entry = json_encode(['id' => 'M-C1', 'from' => 'x', 'body' => 'open']);
        $resp = new FakeRespClient([
            'OK', [$entry], 'OK', 'QUEUED', 'QUEUED', null,   // EXEC lost the race
            'OK', [$entry], 'OK', 'QUEUED', 'QUEUED', null,   // and again
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/concurrently/');
        $this->tools($resp)->message_lease();
    }

    public function testAckDeletesByIdAndReportsUnknown(): void {
        $resp = new FakeRespClient([
            'OK',
            [
                json_encode(['id' => 'M-D1', 'from' => 'x', 'body' => 'one', 'lease' => 'L-1', 'lease_until' => time() + 60]),
                json_encode(['id' => 'M-D2', 'from' => 'x', 'body' => 'two', 'lease' => 'L-1', 'lease_until' => time() + 60]),
            ],
            'OK', 'QUEUED', 'QUEUED', [1, 1],
        ]);
        $result = $this->tools($resp)->message_ack(['M-D2', 'M-GONE']);
        $data = $result->getStructuredContent();
        $this->assertSame(['M-D2'], $data['acked']);
        $this->assertSame(['M-GONE'], $data['unknown']);
        $this->assertStringContainsString('Acknowledged 1 message(s)', $result->toArray()['content'][0]['text']);
        $kept = array_map(fn($j) => json_decode($j, true), array_slice($resp->calls[4], 2));
        $this->assertSame(['M-D1'], array_column($kept, 'id'));
    }

    public function testAckRejectsEmptyIdList(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->tools(new FakeRespClient([]))->message_ack([]);
    }

    public function testSendCarriesTargetingFieldsAndId(): void {
        $resp = new FakeRespClient([2]); // RPUSH -> depth 2
        $expires = gmdate('c', time() + 3600);
        $result = $this->tools($resp)->message_send(
            'bob', 'hello', 'coord',
            session: 'sess-1', kind: 'directive', priority: 'urgent',
            expires: $expires, replyTo: 'M-ABCDEF12'
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued']);
        $this->assertMatchesRegularExpression('/^M-[0-9A-F]{8}$/', $data['id']);
        $envelope = json_decode($resp->calls[0][2], true);
        $this->assertSame($data['id'], $envelope['id']);
        $this->assertSame('sess-1', $envelope['session']);
        $this->assertSame('directive', $envelope['kind']);
        $this->assertSame('urgent', $envelope['priority']);
        $this->assertSame($expires, $envelope['expires']);
        $this->assertSame('M-ABCDEF12', $envelope['replyTo']);
    }

    public function testSendRejectsUnknownKindAndPriority(): void {
        $resp = new FakeRespClient([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->tools($resp)->message_send('bob', 'hi', null, kind: 'nonsense');
    }

    public function testSendRejectsBadPriority(): void {
        $resp = new FakeRespClient([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->tools($resp)->message_send('bob', 'hi', null, priority: 'whenever');
    }

    public function testSendRejectsPastExpiry(): void {
        $resp = new FakeRespClient([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->tools($resp)->message_send('bob', 'hi', null, expires: '2020-01-01T00:00:00Z');
    }

    public function testSendRejectsUnparseableExpiry(): void {
        $resp = new FakeRespClient([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->tools($resp)->message_send('bob', 'hi', null, expires: 'not-a-date');
    }

    public function testSendWithTaskResolvesBoundSession(): void {
        $resp = new FakeRespClient([1]); // RPUSH
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '1-a', 'title' => 'T', 'session' => 'sess-bound']],
            ['code' => 200, 'body' => ['uuids' => ['ev']]],
            ['code' => 201, 'body' => []],
        ]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade()));
        $result = $tools->message_send('bob', 'for the task', null, task: 'T-1');
        $envelope = json_decode($resp->calls[0][2], true);
        $this->assertSame('sess-bound', $envelope['session']);
        $this->assertSame('T-1', $envelope['task']);
        $this->assertTrue($result->getStructuredContent()['queued']);
    }

    public function testSendWithUnknownTaskFails(): void {
        $couch = new FakeCouch([
            ['code' => 404, 'body' => ['error' => 'not_found']],
        ]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore(new FakeRespClient([])), $couch, new FakeArcade()));
        $this->expectException(\RuntimeException::class);
        $tools->message_send('bob', 'for the task', null, task: 'T-NOPE');
    }

    public function testSendWithUnboundTaskFails(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '1-a', 'title' => 'T']], // no session key
        ]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore(new FakeRespClient([])), $couch, new FakeArcade()));
        $this->expectException(\RuntimeException::class);
        $tools->message_send('bob', 'for the task', null, task: 'T-1');
    }

    public function testSendControlCarriesMasterEpoch(): void {
        $resp = new FakeRespClient([1]);
        $this->tools($resp)->message_send('sonya', 'drain node-2', null, kind: 'control', epoch: '7');
        $envelope = json_decode($resp->calls[0][2], true);
        $this->assertSame('control', $envelope['kind']);
        $this->assertSame('7', $envelope['epoch']);
    }

    public function testSendEpochRequiresControlKind(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->tools(new FakeRespClient([]))->message_send('sonya', 'hi', null, kind: 'notice', epoch: '7');
    }

    public function testSendViaBridgeBuildsJidAndSonyaFields(): void {
        $resp = new FakeRespClient([]);
        $bridge = new FakeXmppBridge();
        $tools = new MessageTools(new ContinuumStorage(
            new ValKeyStore($resp),
            new FakeCouch([['code' => 200, 'body' => ['uuids' => ['e']]], ['code' => 201, 'body' => []]]),
            new FakeArcade()
        ), $bridge, 'xmpp.example.com');
        $expires = gmdate('c', time() + 600);
        $result = $tools->message_send('sonya', 'ready', 'coord',
            session: 'sess-7', kind: 'directive', priority: 'urgent',
            expires: $expires, replyTo: 'M-11111111');
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued']);
        $this->assertSame('xmpp-bridge', $data['via']);
        $call = $bridge->calls[0];
        // bare-JID addressing: the session rides in the sonya payload; a
        // session-named resource would not exist on a bridged account.
        $this->assertSame('sonya@xmpp.example.com', $call['to']);
        $this->assertSame('test-agent', $call['account']);
        $this->assertSame('ready', $call['body']);
        $this->assertSame('directive', $call['fields']['kind']);
        $this->assertSame('sess-7', $call['fields']['session']);
        $this->assertSame('urgent', $call['fields']['priority']);
        $this->assertSame($expires, $call['fields']['expires']);
        $this->assertSame('M-11111111', $call['fields']['thread']);
        $this->assertMatchesRegularExpression('/^M-[0-9A-F]{8}$/', $call['fields']['id']);
        // no local inbox write on the bridge path (only the audit publish)
        $this->assertNotContains('RPUSH', array_column($resp->calls, 0));
    }

    public function testSendViaBridgeWithoutDomainFails(): void {
        $tools = new MessageTools(new ContinuumStorage(
            new ValKeyStore(new FakeRespClient([])),
            new FakeCouch([['code' => 200, 'body' => ['uuids' => ['e']]], ['code' => 201, 'body' => []]]),
            new FakeArcade()
        ), new FakeXmppBridge(), null);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/without a domain/');
        $tools->message_send('sonya', 'hi');
    }

    public function testBroadcastViaBridgeReportsFailuresIndividually(): void {
        $resp = new FakeRespClient([['test-agent', 'alice', 'bob']]); // agents
        $bridge = new FakeXmppBridge();
        $bridge->failFor = 'bob';
        $tools = new MessageTools(new ContinuumStorage(
            new ValKeyStore($resp),
            new FakeCouch([['code' => 200, 'body' => ['uuids' => ['e']]], ['code' => 201, 'body' => []]]),
            new FakeArcade()
        ), $bridge, 'xmpp.example.com');
        $result = $tools->message_broadcast('freeze');
        $data = $result->getStructuredContent();
        $this->assertSame(['alice'], $data['delivered']);
        $this->assertSame(['bob'], $data['failed']);
        $this->assertStringContainsString('Failed for: bob', $result->toArray()['content'][0]['text']);
        $this->assertSame('alice@xmpp.example.com', $bridge->calls[0]['to']);
    }

    public function testBroadcastSkipsSelfAndLogsRecipientCount(): void {
        $resp = new FakeRespClient([
            ['test-agent', 'alice', 'bob'],  // SMEMBERS agents
            1,                                // RPUSH alice
            3,                                // RPUSH bob
        ]);
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['uuids' => ['ev']]],
            ['code' => 201, 'body' => []],
        ]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade()));
        $result = $tools->message_broadcast('deploy freeze');
        $this->assertSame(['alice', 'bob'], $result->getStructuredContent()['delivered']);
        $this->assertStringContainsString('Broadcast to 2 agent(s): alice, bob', $result->toArray()['content'][0]['text']);
        // event records recipients, not sender
        $this->assertSame(2, $couch->calls[1]['data']['data']['recipients']);
    }
}
