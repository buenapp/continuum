<?php

namespace Continuum\Tests;

use Continuum\Storage\RespClient;
use Continuum\Storage\CouchDBStore;
use Continuum\Storage\ArcadeDBStore;

/** Fake RESP client recording issued commands and returning scripted replies. */
class FakeRespClient extends RespClient {
    /** @var array<int,array<int,string>> */ public array $calls = [];
    /** @var array<int,mixed> */ private array $script;
    public function __construct(array $script = []) { $this->script = $script; }
    public function command(string ...$args): mixed {
        $this->calls[] = $args;
        return array_shift($this->script) ?? null;
    }
}

/** Fake CouchDB store: scripts (code, body) pairs, records calls. */
class FakeCouch extends CouchDBStore {
    /** @var array<int,array> */ public array $calls = [];
    /** @var array<int,array{code:int, body:mixed}> */ private array $script;
    /** @var array<int,array{code:int, body:mixed}> */ private array $scriptOriginal;
    public function __construct(array $script = []) {
        parent::__construct('127.0.0.1', 5984, 'test', 'test');
        $this->script = $script;
        $this->scriptOriginal = $script;
    }
    /** Fresh instance replaying the same script (for multi-assert tests). */
    public function replay(): self {
        return new self($this->scriptOriginal);
    }
    public function call($method, $data = NULL, $http_verb = 'GET', $extra_headers = array(), $timeout = NULL, $format = 'json') {
        $this->calls[] = ['path' => $method, 'data' => $data, 'verb' => $http_verb];
        $next = array_shift($this->script);
        if ($next === null) { throw new \RuntimeException('FakeCouch: script exhausted for ' . $method); }
        $this->last_http_code = $next['code'];
        return $next['body'];
    }
}

/** Fake ArcadeDB store: scripts query result rows, records calls. */
class FakeArcade extends ArcadeDBStore {
    /** @var array<int,array> */ public array $calls = [];
    /** @var array<int,array> */ private array $script;
    public function __construct(array $script = []) {
        parent::__construct('127.0.0.1', 2480, 'root', '');
        $this->script = $script;
    }
    public function call($method, $data = NULL, $http_verb = 'GET', $extra_headers = array(), $timeout = NULL, $format = 'json') {
        $this->calls[] = ['endpoint' => $method, 'body' => $data];
        $this->last_http_code = 200;
        return array_shift($this->script) ?? ['result' => []];
    }
}

/** Records milestone syncs. */
class FakeMilestoneSync implements \Continuum\Bridge\MilestoneSyncAdapterInterface {
    /** @var array<int,array> */ public array $syncs = [];
    public function syncMilestone(string $taskId, string $milestone, array $data): void {
        $this->syncs[] = ['task' => $taskId, 'milestone' => $milestone, 'data' => $data];
    }
}

/** Scripted Heliofane bridge: no HTTP. */
class FakeHeliofaneBridge extends \Continuum\Bridge\HeliofaneMcpBridge {
    /** @var array<int,array> */ public array $calls = [];
    /** @var array<int,array> */ private array $script;
    public function __construct(array $script = []) { $this->script = $script; }
    public function callTool(string $tool, array $arguments): array {
        $this->calls[] = ['tool' => $tool, 'arguments' => $arguments];
        return array_shift($this->script) ?? ['ok' => true, 'result' => [], 'error' => null];
    }
}

/** Scripted live-delivery transport; no network or storage involved. */
class FakeInjectionTransport implements \Continuum\Injection\InjectionTransportInterface {
    /** @var array<int,array> */ public array $calls = [];
    public string $state = self::IDLE;
    public ?string $fail = null;
    public array $ack = ['accepted' => true];
    public ?\Closure $onCall = null;
    public function __construct(private string $name = 'fake') {}
    public function transportName(): string { return $this->name; }
    public function liveness(string $sessionId): string {
        $this->calls[] = ['op' => 'liveness', 'session' => $sessionId];
        if ($this->onCall !== null) { ($this->onCall)('liveness'); }
        if ($this->fail === 'liveness') { throw new \Continuum\Injection\TransportException('liveness failed'); }
        return $this->state;
    }
    public function inject(string $sessionId, array $envelope, string $preemption, string $liveness): array {
        $this->calls[] = ['op' => 'inject', 'session' => $sessionId, 'envelope' => $envelope,
            'preemption' => $preemption, 'liveness' => $liveness];
        if ($this->onCall !== null) { ($this->onCall)('inject'); }
        if ($this->fail === 'inject') { throw new \Continuum\Injection\TransportException('inject failed'); }
        return $this->ack;
    }
}
