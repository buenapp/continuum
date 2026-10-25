<?php

namespace Continuum\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use EnchiladaMCP\ToolRegistry;
use Continuum\Identity\Envelope;
use Continuum\Identity\Verify;
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

    /** Fresh attestation keypair per test, written raw to a temp file. */
    private string $signKeypair;
    private string $signKeyFile;

    protected function setUp(): void {
        \Continuum\SessionContext::set(null);
        \Continuum\SessionContext::setDeclared(null);
        $this->signKeypair = sodium_crypto_sign_keypair();
        $this->signKeyFile = tempnam(sys_get_temp_dir(), 'continuum-sign-');
        file_put_contents($this->signKeyFile, $this->signKeypair);
    }

    protected function tearDown(): void {
        \Continuum\SessionContext::set(null);
        \Continuum\SessionContext::setDeclared(null);
        @unlink($this->signKeyFile);
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

    public function testLeaseUntargetedFalseScopesToDeclaredSession(): void {
        \Continuum\SessionContext::setDeclared('sess-a');
        $resp = new FakeRespClient([
            'OK',
            [
                json_encode(['id' => 'M-U1', 'from' => 'x', 'body' => 'for-a', 'session' => 'sess-a']),
                json_encode(['id' => 'M-U2', 'from' => 'x', 'body' => 'for-b', 'session' => 'sess-b']),
                json_encode(['id' => 'M-U3', 'from' => 'x', 'body' => 'open']),
            ],
            'OK', 'QUEUED', 'QUEUED', [1, 3],
        ]);
        $result = $this->tools($resp)->message_lease(10, 60, untargeted: false);
        $data = $result->getStructuredContent();
        $this->assertSame(1, $data['count']);
        $this->assertSame('for-a', $data['messages'][0]['body']);
        // rewrite keeps the other two in place, unmarked
        $this->assertSame('RPUSH', $resp->calls[4][0]);
        $kept = array_map(fn($j) => json_decode($j, true), array_slice($resp->calls[4], 2));
        $byId = array_column($kept, null, 'id');
        $this->assertSame($data['lease'], $byId['M-U1']['lease']);
        $this->assertArrayNotHasKey('lease', $byId['M-U2']);
        $this->assertArrayNotHasKey('lease', $byId['M-U3']);
    }

    public function testLeaseDefaultStillTakesUntargetedForSession(): void {
        \Continuum\SessionContext::setDeclared('sess-a');
        $resp = new FakeRespClient([
            'OK',
            [
                json_encode(['id' => 'M-V1', 'from' => 'x', 'body' => 'for-a', 'session' => 'sess-a']),
                json_encode(['id' => 'M-V2', 'from' => 'x', 'body' => 'for-b', 'session' => 'sess-b']),
                json_encode(['id' => 'M-V3', 'from' => 'x', 'body' => 'open']),
            ],
            'OK', 'QUEUED', 'QUEUED', [1, 3],
        ]);
        $result = $this->tools($resp)->message_lease(10, 60);
        $data = $result->getStructuredContent();
        $this->assertSame(['for-a', 'open'], array_column($data['messages'], 'body'));
        $kept = array_map(fn($j) => json_decode($j, true), array_slice($resp->calls[4], 2));
        $byId = array_column($kept, null, 'id');
        $this->assertArrayNotHasKey('lease', $byId['M-V2']);
    }

    public function testLeaseUntargetedFalseRequiresDeclaredSession(): void {
        $resp = new FakeRespClient([]);
        try {
            $this->tools($resp)->message_lease(10, 60, untargeted: false);
            $this->fail('expected InvalidArgumentException without a declared session');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('declared session', $e->getMessage());
        }
        // validation runs before any storage command
        $this->assertSame([], $resp->calls);
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
        $resp = new FakeRespClient([
            ['bob'],                 // SMEMBERS agents
            ['heartbeat', '1'],      // HGETALL agent:bob
            ['sess-1'],              // SMEMBERS agent-sessions:bob
            ['heartbeat', '1'],      // HGETALL session:bob:sess-1
            '1',                     // HGET agent:bob heartbeat
            2,                       // RPUSH -> depth 2
        ]);
        $expires = gmdate('c', time() + 3600);
        $result = $this->tools($resp)->message_send(
            'bob', 'hello', 'coord',
            session: 'sess-1', kind: 'directive', priority: 'urgent',
            expires: $expires, replyTo: 'M-ABCDEF12'
        );
        $data = $result->getStructuredContent();
        $this->assertTrue($data['queued']);
        $this->assertMatchesRegularExpression('/^M-[0-9A-F]{8}$/', $data['id']);
        $envelope = json_decode($resp->calls[5][2], true);
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

    public function testSendWithTaskTargetsOwnerSession(): void {
        $resp = new FakeRespClient([
            ['bob'],                 // SMEMBERS agents
            ['heartbeat', '1'],      // HGETALL agent:bob
            ['sess-bound'],          // SMEMBERS agent-sessions:bob
            ['heartbeat', '1'],      // HGETALL session:bob:sess-bound
            '1',                     // HGET agent:bob heartbeat
            1,                       // RPUSH -> depth 1
        ]);
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '1-a', 'title' => 'T', 'owner' => 'bob', 'session' => 'sess-bound']],
            ['code' => 200, 'body' => ['uuids' => ['ev']]],
            ['code' => 201, 'body' => []],
        ]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade()));
        $result = $tools->message_send('bob', 'for the task', null, task: 'T-1');
        $envelope = json_decode($resp->calls[5][2], true);
        $this->assertSame('sess-bound', $envelope['session']);
        $this->assertSame('T-1', $envelope['task']);
        $this->assertTrue($result->getStructuredContent()['queued']);
        $this->assertStringContainsString("queued to 'bob', session sess-bound (inbox depth 1)", $result->toArray()['content'][0]['text']);
    }

    public function testSendWithTaskRidesAsReferenceForNonOwner(): void {
        $resp = new FakeRespClient([1]); // RPUSH; no session derived, no reachability scan
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '1-a', 'title' => 'T', 'owner' => 'claude', 'session' => 'sess-claude-1']],
            ['code' => 200, 'body' => ['uuids' => ['ev']]],
            ['code' => 201, 'body' => []],
        ]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade()));
        $result = $tools->message_send('bob', 'for the task', null, task: 'T-1');
        $envelope = json_decode($resp->calls[0][2], true);
        $this->assertSame('T-1', $envelope['task']);
        $this->assertArrayNotHasKey('session', $envelope);
        $this->assertTrue($result->getStructuredContent()['queued']);
        $text = $result->toArray()['content'][0]['text'];
        $this->assertStringContainsString("queued to 'bob' (inbox depth 1)", $text);
        $this->assertStringNotContainsString('session', $text);
    }

    public function testSendWithTaskHonorsExplicitSessionForNonOwner(): void {
        $resp = new FakeRespClient([
            ['claude'],              // SMEMBERS agents
            ['heartbeat', '1'],      // HGETALL agent:claude
            ['sess-claude-1'],       // SMEMBERS agent-sessions:claude
            ['heartbeat', '1'],      // HGETALL session:claude:sess-claude-1
            '1',                     // HGET agent:claude heartbeat
            1,                       // RPUSH -> depth 1
        ]);
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '1-a', 'title' => 'T', 'owner' => 'devin', 'session' => 'sess-devin-1']],
            ['code' => 200, 'body' => ['uuids' => ['ev']]],
            ['code' => 201, 'body' => []],
        ]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade()));
        $tools->message_send('claude', 'for the task', null, session: 'sess-claude-1', task: 'T-1');
        $envelope = json_decode($resp->calls[5][2], true);
        $this->assertSame('sess-claude-1', $envelope['session']);
        $this->assertSame('T-1', $envelope['task']);
    }

    public function testSendRejectsSessionUnreachableInRecipientInbox(): void {
        $resp = new FakeRespClient([
            ['claude'],              // SMEMBERS agents
            ['heartbeat', '1'],      // HGETALL agent:claude
            ['sess-claude-9'],       // SMEMBERS agent-sessions:claude
            ['heartbeat', '1'],      // HGETALL session:claude:sess-claude-9
            '1',                     // HGET agent:claude heartbeat
        ]);
        $couch = new FakeCouch([]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade()));
        try {
            $tools->message_send('claude', 'hi', null, session: 'sess-claude-1');
            $this->fail('expected InvalidArgumentException for an unreachable session target');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("not held by 'claude'", $e->getMessage());
        }
        // the guard fires before any inbox write or audit entry
        $this->assertNotContains('RPUSH', array_column($resp->calls, 0));
        $this->assertSame([], $couch->calls);
    }

    public function testSendAllowsSessionWhenRecipientHasNoSessionPresence(): void {
        $resp = new FakeRespClient([
            ['claude'],              // SMEMBERS agents
            ['heartbeat', '1'],      // HGETALL agent:claude
            [],                      // SMEMBERS agent-sessions:claude (none published)
            '1',                     // HGET agent:claude heartbeat
            1,                       // RPUSH -> depth 1
        ]);
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['uuids' => ['ev']]],
            ['code' => 201, 'body' => []],
        ]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade()));
        $result = $tools->message_send('claude', 'hi', null, session: 'sess-claude-1');
        $envelope = json_decode($resp->calls[4][2], true);
        $this->assertSame('sess-claude-1', $envelope['session']);
        $this->assertTrue($result->getStructuredContent()['queued']);
    }

    public function testSendWithTaskViaBridgeOmitsSessionForNonOwner(): void {
        $resp = new FakeRespClient(idempotentPushResponses());
        $bridge = new FakeXmppBridge();
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '1-a', 'title' => 'T', 'owner' => 'devin', 'session' => 'sess-devin-1']],
            ['code' => 200, 'body' => ['uuids' => ['e']]],
            ['code' => 201, 'body' => []],
        ]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade()), $bridge, 'xmpp.example.com');
        $tools->message_send('claude', 'for the task', null, task: 'T-1');
        $call = $bridge->calls[0];
        $this->assertSame('claude@xmpp.example.com', $call['to']);
        $this->assertSame('T-1', $call['fields']['task']);
        $this->assertArrayNotHasKey('session', $call['fields']);
    }

    public function testSendWithUnknownTaskFails(): void {
        $couch = new FakeCouch([
            ['code' => 404, 'body' => ['error' => 'not_found']],
        ]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore(new FakeRespClient([])), $couch, new FakeArcade()));
        $this->expectException(\RuntimeException::class);
        $tools->message_send('bob', 'for the task', null, task: 'T-NOPE');
    }

    public function testSendWithUnboundTaskFailsForOwner(): void {
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['_rev' => '1-a', 'title' => 'T', 'owner' => 'bob']], // no session key
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
        $resp = new FakeRespClient(array_merge([
            ['sonya'], ['heartbeat', '1'], [], '1',
        ], idempotentPushResponses()));
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
        // the durable inbox write happens once before the bridge send
        $this->assertContains('RPUSH', array_column($resp->calls, 0));
        $this->assertSame(1, $data['depth']);
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
        $resp = new FakeRespClient(array_merge(
            [['test-agent', 'alice', 'bob']],
            idempotentPushResponses(),
            idempotentPushResponses()
        )); // agents, then one idempotent inbox write per recipient
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

    // --- identity fields (issue #6, docs/IDENTITY.md)

    public function testSendCarriesSigAndCertUnchanged(): void {
        $resp = new FakeRespClient([1]); // RPUSH
        $sig = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES));
        $cert = '{"stub":"cert"}';
        $this->tools($resp)->message_send('bob', 'hello', null, sig: $sig, cert: $cert);
        $envelope = json_decode($resp->calls[0][2], true);
        $this->assertSame($sig, $envelope['sig']);
        $this->assertSame($cert, $envelope['cert']);
    }

    #[DataProvider('malformedCarrierCases')]
    public function testSendRejectsMalformedIdentityMaterial(?string $sig, ?string $cert): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->tools(new FakeRespClient([]))->message_send('bob', 'hi', null, sig: $sig, cert: $cert);
    }

    public static function malformedCarrierCases(): array {
        return [
            'empty sig' => ['' , null],
            'non-base64 sig' => ['not base64!!', null],
            'empty cert' => [null, ''],
            'non-JSON cert' => [null, '***'],
            'JSON list cert' => [null, '["not","an","object"]'],
            'JSON scalar cert' => [null, '"scalar"'],
        ];
    }

    public function testSendWithoutKeyFileLeavesEnvelopeUnchanged(): void {
        $resp = new FakeRespClient([1]);
        $this->tools($resp)->message_send('bob', 'hello', 'coord');
        $envelope = json_decode($resp->calls[0][2], true);
        $this->assertSame(['id', 'from', 'body', 'ts', 'topic'], array_keys($envelope));
        foreach (['sig', 'cert', 'attest'] as $field) {
            $this->assertArrayNotHasKey($field, $envelope);
        }
    }

    public function testSendAttestsWhenKeyFileConfigured(): void {
        $resp = new FakeRespClient([1]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore($resp), $this->couch(), new FakeArcade()), null, null, $this->signKeyFile);
        $tools->message_send('bob', 'hello', 'coord');
        $envelope = json_decode($resp->calls[0][2], true);
        $this->assertArrayHasKey('attest', $envelope);
        $result = Verify::envelope($envelope + ['to' => 'bob'], $this->continuumDoc());
        $this->assertSame(
            ['level' => 'attested', 'valid' => true, 'workstream' => 'test-agent', 'session' => null, 'reason' => null],
            $result
        );
    }

    public function testSendAttestationClaimsDeclaredSession(): void {
        $resp = new FakeRespClient([
            ['bob'],                 // SMEMBERS agents
            ['heartbeat', '1'],      // HGETALL agent:bob
            ['sess-1'],              // SMEMBERS agent-sessions:bob
            ['heartbeat', '1'],      // HGETALL session:bob:sess-1
            '1',                     // HGET agent:bob heartbeat
            1,                       // RPUSH
        ]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore($resp), $this->couch(), new FakeArcade()), null, null, $this->signKeyFile);
        $tools->message_send('bob', 'hello', null, session: 'sess-1');
        $envelope = json_decode($resp->calls[5][2], true);
        $result = Verify::envelope($envelope + ['to' => 'bob'], $this->continuumDoc());
        $this->assertTrue($result['valid']);
        $this->assertSame('sess-1', $result['session']);
    }

    public function testSendAttestationCoversSigAndCert(): void {
        $resp = new FakeRespClient([1]);
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore($resp), $this->couch(), new FakeArcade()), null, null, $this->signKeyFile);
        $sig = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES));
        $cert = '{"stub":"cert"}';
        $tools->message_send('bob', 'hello', null, sig: $sig, cert: $cert);
        $envelope = json_decode($resp->calls[0][2], true);
        $key = sodium_crypto_sign_publickey($this->signKeypair);
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($envelope['attest']),
            Envelope::canonical(Envelope::attestCovered($envelope + ['to' => 'bob'], 'test-agent')),
            $key
        ));
        // the carriers participate: the same attest fails without them
        $stripped = $envelope;
        unset($stripped['sig'], $stripped['cert']);
        $this->assertFalse(sodium_crypto_sign_verify_detached(
            base64_decode($envelope['attest']),
            Envelope::canonical(Envelope::attestCovered($stripped + ['to' => 'bob'], 'test-agent')),
            $key
        ));
    }

    public function testSendViaBridgeCarriesSigCertAttest(): void {
        $resp = new FakeRespClient(idempotentPushResponses());
        $bridge = new FakeXmppBridge();
        $tools = new MessageTools(new ContinuumStorage(new ValKeyStore($resp), $this->couch(), new FakeArcade()), $bridge, 'xmpp.example.com', $this->signKeyFile);
        $sig = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES));
        $cert = '{"stub":"cert"}';
        $tools->message_send('sonya', 'ready', null, sig: $sig, cert: $cert);
        $fields = $bridge->calls[0]['fields'];
        $this->assertSame($sig, $fields['sig']);
        $this->assertSame($cert, $fields['cert']);
        $this->assertArrayHasKey('attest', $fields);
    }

    public function testMessageSchemaCarriesIdentityFieldsAndStaysLegacyValid(): void {
        $registry = new ToolRegistry();
        $registry->register(new MessageTools(new ContinuumStorage(
            new ValKeyStore(new FakeRespClient([])), new FakeCouch([]), new FakeArcade()
        )));
        $checked = 0;
        foreach ($registry->listTools() as $tool) {
            if (!in_array($tool['name'], ['message_inbox_pull', 'message_lease'], true)) { continue; }
            $checked++;
            $items = $tool['outputSchema']['properties']['messages']['items'];
            foreach (['sig', 'cert', 'attest'] as $field) {
                $this->assertIsArray($items['properties'][$field], "{$tool['name']}.{$field} must be an object subschema");
                $this->assertSame('string', $items['properties'][$field]['type']);
            }
            $this->assertArrayHasKey('preemption', $items['properties']);
            // a legacy envelope keeps validating against the message schema
            foreach (['id' => 'M-1', 'from' => 'a', 'body' => 'b', 'ts' => 'now'] as $key => $value) {
                $this->assertIsString($value);
                $this->assertArrayHasKey($key, $items['properties']);
                $this->assertSame('string', $items['properties'][$key]['type']);
            }
        }
        $this->assertSame(2, $checked);
    }

    private function couch(): FakeCouch {
        return new FakeCouch([['code' => 200, 'body' => ['uuids' => ['e']]], ['code' => 201, 'body' => []]]);
    }

    /** Registry record for the attestation key, in the contract shape. */
    private function continuumDoc(): array {
        return [
            '_id' => 'continuum',
            '_rev' => '1-2b',
            'pubkey' => base64_encode(sodium_crypto_sign_publickey($this->signKeypair)),
            'status' => 'active',
            'created_at' => '2026-10-20T00:00:00+00:00',
            'rotated_at' => null,
            'revoked_at' => null,
        ];
    }
}
