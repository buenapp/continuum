<?php

namespace Continuum\Bridge;

/**
 * Client seam for the continuum-xmpp-bridge sidecar's unix-socket control
 * API (issue #3). One request per call; the daemon holds the always-on c2s
 * streams - PHP stays request-scoped and stateless.
 */
interface XmppBridgeInterface {

    /**
     * Queue one message on the named account's stream.
     * $fields carries the sonya payload keys (kind/session/task/expires/
     * priority/epoch) plus thread/topic/id. Returns the bridge's JSON
     * answer (['queued' => bool, 'id' => string]); throws on failure.
     */
    public function send(string $account, string $to, string $body, array $fields): array;

    /** Account name -> ['established' => bool]. Throws on transport failure. */
    public function health(): array;
}
