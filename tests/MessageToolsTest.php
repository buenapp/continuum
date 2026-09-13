<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\MessageTools;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

class MessageToolsTest extends TestCase {

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
        $this->assertStringContainsString("Message queued to 'bob' (inbox depth 2)", $result->toArray()['content'][0]['text']);
        $rpush = $resp->calls[0];
        $this->assertSame('RPUSH', $rpush[0]);
        $this->assertSame('continuum:inbox:bob', $rpush[1]);
        $envelope = json_decode($rpush[2], true);
        $this->assertSame('test-agent', $envelope['from']);
        $this->assertSame('coord', $envelope['topic']);
        $this->assertSame('hello', $envelope['body']);
    }

    public function testPullReadsOldestAndTrims(): void {
        $resp = new FakeRespClient([
            ['{"from":"a","body":"one"}', '{"from":"b","body":"two"}'],  // LRANGE
            'OK',                                                        // LTRIM
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
        $this->assertSame('LTRIM', $resp->calls[1][0]);
        $this->assertSame('2', $resp->calls[1][2]);
    }

    public function testPullEmptyInboxPerformsNoTrim(): void {
        $resp = new FakeRespClient([[]]); // LRANGE empty
        $result = $this->tools($resp)->message_inbox_pull();
        $this->assertSame(0, $result->getStructuredContent()['count']);
        $this->assertStringContainsString('(empty)', $result->toArray()['content'][0]['text']);
        $this->assertCount(1, $resp->calls);
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
