<?php

namespace Continuum;

use Continuum\Storage\RespClient;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

/**
 * Subscription feed: relays resource-change signals to a listening MCP
 * client (2026-07-28 subscriptions/listen).
 *
 * ContinuumStorage mutations publish changed resource URIs on the ValKey
 * changes channel; the listener worker subscribes on a DEDICATED
 * connection (pub/sub blocks the socket) and filters incoming payloads
 * against the URIs the client subscribed to. The generator yields MCP
 * notification arrays; null ticks pace keepalives and give the transport
 * a chance to notice client disconnects. A dead subscription socket ends
 * the stream gracefully rather than erroring the request.
 */
class SubscriptionFeed {

    public function __construct(
        private string $host,
        private int $port,
        private float $tickSeconds = 10.0,
        private ?RespClient $client = null,
    ) {}

    /**
     * Stream notifications matching the honored subscription filter.
     *
     * @param  array<string,mixed> $filter Honored filter (resourceSubscriptions URIs)
     * @return \Generator<int, array{method:string,params:array}|null>
     */
    public function stream(array $filter): \Generator {
        $uris = [];
        foreach ((array)($filter['resourceSubscriptions'] ?? []) as $uri) {
            if (is_string($uri) && $uri !== '') { $uris[] = $uri; }
        }
        $client = $this->client ?? new RespClient($this->host, $this->port);
        try {
            $client->subscribe(ValKeyStore::signalChannel(ContinuumStorage::CHANGES_CHANNEL));
            while (true) {
                $push = $client->readPush($this->tickSeconds);
                if ($push === null) { yield null; continue; }
                if (($push[0] ?? '') !== 'message') { continue; }
                $payload = json_decode((string)($push[2] ?? ''), true);
                if (!is_array($payload)) { continue; }
                foreach (array_intersect((array)($payload['uris'] ?? []), $uris) as $uri) {
                    yield ['method' => 'notifications/resources/updated', 'params' => ['uri' => $uri]];
                }
            }
        } catch (\Throwable) {
            // a dead socket ends the stream; the transport closes gracefully
        } finally {
            $client->close();
        }
    }
}
