<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\McpServer;
use Continuum\SubscriptionFeed;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;
use Continuum\Storage\RespClient;

/**
 * Subscribe-and-Notify (2026-07-28): listen handshake, change
 * publication on mutations, and the feed's URI matching.
 */
class SubscriptionsTest extends TestCase {

    private const MARK = '__subscription_stream';

    private function listenRequest(array $notifications, bool $modern = true): array {
        $request = [
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'subscriptions/listen',
            'params' => ['notifications' => $notifications],
        ];
        if ($modern) {
            $request['params']['_meta'] = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'];
        }
        return $request;
    }

    public function testListenHandsOffStreamMarkerWithHonoredSubset(): void {
        $server = new McpServer('test', '0.0.0');
        $server->setSupportedSubscriptions(['resourceSubscriptions']);
        $response = $server->handleRequest($this->listenRequest([
            'resourceSubscriptions' => ['continuum://tasks', 'continuum://tasks', ''],
            'toolsListChanged' => true,   // unsupported here -> dropped
        ]));
        $mark = $response[self::MARK] ?? null;
        $this->assertNotNull($mark, 'expected stream marker');
        $this->assertSame(5, $mark['subscriptionId']);
        $this->assertSame(['resourceSubscriptions' => ['continuum://tasks']], $mark['notifications']);
    }

    public function testListenWithNothingSupportedAcksEmptyFilter(): void {
        $server = new McpServer('test', '0.0.0');
        $response = $server->handleRequest($this->listenRequest(['resourceSubscriptions' => ['continuum://tasks']]));
        $this->assertSame([], $response[self::MARK]['notifications']);
    }

    public function testListenRejectsLegacyRequests(): void {
        $server = new McpServer('test', '0.0.0');
        $response = $server->handleRequest($this->listenRequest([], false));
        $this->assertSame(-32601, $response['error']['code']);
    }

    public function testUnknownSubscriptionTypeRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        (new McpServer('test', '0.0.0'))->setSupportedSubscriptions(['telemetry']);
    }

    private function storage(FakeRespClient $resp, FakeCouch $couch): ContinuumStorage {
        return new ContinuumStorage(new ValKeyStore($resp), $couch, new FakeArcade());
    }

    /** Decode (PUBLISH, channel, payload) calls recorded on the fake. */
    private function publishedChanges(FakeRespClient $resp): array {
        $out = [];
        foreach ($resp->calls as $call) {
            if (($call[0] ?? null) === 'PUBLISH' && ($call[1] ?? null) === 'continuum:signal:changes') {
                $out[] = json_decode((string)$call[2], true)['uris'] ?? [];
            }
        }
        return $out;
    }

    public function testTaskSavePublishesListAndCardUris(): void {
        $resp = new FakeRespClient([]);
        $this->storage($resp, new FakeCouch([['code' => 201, 'body' => ['id' => 'T-1', 'rev' => '1-a']]]))
            ->saveTask('T-1', ['status' => 'pending', 'title' => 'demo']);
        $this->assertSame([['continuum://tasks', 'continuum://tasks/T-1']], $this->publishedChanges($resp));
    }

    public function testBoardWritePublishesIndexEntryAndSnapshotUris(): void {
        $resp = new FakeRespClient([]);
        $this->storage($resp, new FakeCouch([['code' => 201, 'body' => ['id' => 'global/k', 'rev' => '1-a']]]))
            ->saveBoardEntry('global', 'k', ['value' => 1]);
        $this->assertSame([[
            'continuum://board/index',
            'continuum://board/global/k',
            'continuum://snapshot/global',
        ]], $this->publishedChanges($resp));
    }

    public function testLockAcquirePublishesOnlyOnSuccess(): void {
        $resp = new FakeRespClient(['OK']);
        $this->storage($resp, new FakeCouch([]))->acquireLock('build', 'test-agent', 60);
        $this->assertSame([['continuum://locks', 'continuum://locks/build']], $this->publishedChanges($resp));

        $resp2 = new FakeRespClient([null]); // lock held: SET NX failed
        $this->storage($resp2, new FakeCouch([]))->acquireLock('build', 'test-agent', 60);
        $this->assertSame([], $this->publishedChanges($resp2));
    }

    public function testBareHeartbeatDoesNotPublish(): void {
        $resp = new FakeRespClient([]);
        $this->storage($resp, new FakeCouch([]))->heartbeat('test-agent');
        $this->assertSame([], $this->publishedChanges($resp));

        $this->storage($resp, new FakeCouch([]))->heartbeat('test-agent', ['working_on' => 'T-1']);
        $this->assertSame([['continuum://agents', 'continuum://agents/test-agent']], $this->publishedChanges($resp));
    }

    public function testEventAppendPublishesTailUri(): void {
        $resp = new FakeRespClient([]);
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['uuids' => ['e1']]],
            ['code' => 201, 'body' => ['id' => 'e1', 'rev' => '1-a']],
        ]);
        $this->storage($resp, $couch)->appendLog('test-agent', 'blackboard_write', ['key' => 'k']);
        $this->assertSame([['continuum://events']], $this->publishedChanges($resp));
    }

    public function testFeedMatchesSubscriptionUrisAndEndsOnSocketClose(): void {
        $channel = ValKeyStore::signalChannel(ContinuumStorage::CHANGES_CHANNEL);
        $bulk = fn(string $s) => "\$" . strlen($s) . "\r\n" . $s . "\r\n";
        $urilist = json_encode(['uris' => ['continuum://tasks', 'continuum://board/index']]);
        $other = json_encode(['uris' => ['continuum://locks']]);
        $confirmation = "*3\r\n" . $bulk('subscribe') . $bulk($channel) . ":1\r\n";
        $frame = fn(string $payload) => "*3\r\n" . $bulk('message') . $bulk($channel) . $bulk($payload);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], $confirmation . $frame($urilist) . $frame($other));

        $feed = new SubscriptionFeed('127.0.0.1', 0, 0.05, new RespClient('127.0.0.1', 0, 5.0, $pair[0]));
        $generator = $feed->stream(['resourceSubscriptions' => ['continuum://tasks', 'continuum://tasks/T-9']]);

        $events = [];
        $this->assertTrue($generator->valid());
        $events[] = $generator->current(); // matching URI from the first message
        $generator->next();
        $events[] = $generator->current(); // idle tick (nothing matched the locks message)
        fclose($pair[1]);                  // socket death ends the stream
        $generator->next();
        $this->assertFalse($generator->valid());

        $this->assertSame(
            ['notifications/resources/updated', 'continuum://tasks'],
            [$events[0]['method'], $events[0]['params']['uri']]
        );
        $this->assertNull($events[1]);
    }
}
