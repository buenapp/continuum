<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\Injection\InjectionManager;
use Continuum\Injection\InjectionTransportInterface;
use Continuum\Injection\OpenCodeTransport;
use Continuum\Injection\OpenHandsTransport;
use Continuum\Injection\TcpJsonTransport;
use Continuum\Injection\TransportException;
use Enchilada\Config\IniConfig;
use Enchilada\Tortilla\HttpClient;

/** Records Tortilla calls and replays response arrays or exceptions. */
class FakeInjectionHttpClient extends HttpClient {
    public array $calls = [];
    private int $status = 0;
    private string $error = '';
    public function __construct(private array $script = []) {
        parent::__construct(new \EnchiladaMultiHTTP('http://localhost'));
    }
    public function call(string $method, $data = null, string $httpVerb = 'GET', array $extraHeaders = [], ?int $timeout = null, string $format = 'json', ?callable $writeCallback = null) {
        $this->calls[] = ['method' => $httpVerb, 'path' => '/' . ltrim($method, '/'), 'payload' => $data, 'headers' => $extraHeaders];
        $next = array_shift($this->script);
        if ($next instanceof \Throwable) { throw $next; }
        if (!is_array($next)) { throw new \RuntimeException('FakeInjectionHttpClient script exhausted'); }
        $this->status = (int)($next['status'] ?? 0);
        $this->error = (string)($next['error'] ?? '');
        return $next['body'] ?? false;
    }
    public function getHttpCode(): int { return $this->status; }
    public function getLastCurlError(): string { return $this->error; }
}

class InjectionTest extends TestCase {
    private function ok(array $body = [], int $status = 200): array {
        return ['status' => $status, 'body' => $body];
    }

    private function target(FakeInjectionTransport $transport, array $extra = []): array {
        return array_merge([
            'transport' => $transport,
            'require_signed' => false,
            'wakeup_from' => ['test-agent'],
            'steer_from' => ['test-agent'],
            'interrupt_from' => ['test-agent'],
        ], $extra);
    }

    public function testOpenHandsInjectsBodyAndLosslessEnvelope(): void {
        $http = new FakeInjectionHttpClient([
            $this->ok(['execution_status' => 'idle']),
            $this->ok(['success' => true]),
        ]);
        $transport = new OpenHandsTransport('http://localhost', [
            'scheme' => 'header', 'header' => 'X-Session-API-Key', 'credential' => 'secret',
        ], http: $http);
        $this->assertSame(InjectionTransportInterface::IDLE, $transport->liveness('conv/1'));
        $envelope = ['id' => 'M-1', 'from' => 'alice', 'body' => 'hello', 'sig' => 'c2ln', 'cert' => '{"x":1}', 'attest' => 'YXR0ZXN0'];
        $transport->inject('conv/1', $envelope, 'wakeup', InjectionTransportInterface::IDLE);
        $this->assertSame('/api/conversations/conv%2F1', $http->calls[0]['path']);
        $this->assertSame('/api/conversations/conv%2F1/events', $http->calls[1]['path']);
        $payload = $http->calls[1]['payload'];
        $this->assertTrue($payload['run']);
        $this->assertSame('hello', $payload['content'][0]['text']);
        $this->assertSame($envelope, json_decode($payload['content'][1]['text'], true));
        $this->assertContains('X-Session-API-Key: secret', $http->calls[1]['headers']);
    }

    public function testOpenHandsInterruptsRunningSessionBeforeInjection(): void {
        $http = new FakeInjectionHttpClient([$this->ok(), $this->ok(['success' => true])]);
        $transport = new OpenHandsTransport('http://localhost', http: $http);
        $transport->inject('c1', ['id' => 'M-1', 'body' => 'stop'], 'interrupt', InjectionTransportInterface::RUNNING);
        $this->assertSame('/api/conversations/c1/interrupt', $http->calls[0]['path']);
        $this->assertSame('/api/conversations/c1/events', $http->calls[1]['path']);
        $this->assertTrue($http->calls[1]['payload']['run']);
    }

    public function testOpenHandsMapsTerminalAndBlockedStates(): void {
        foreach (['finished', 'error', 'stuck', 'paused'] as $state) {
            $http = new FakeInjectionHttpClient([$this->ok(['execution_status' => $state])]);
            $transport = new OpenHandsTransport('http://localhost', http: $http);
            $this->assertSame(InjectionTransportInterface::IDLE, $transport->liveness('c1'), $state);
        }
        foreach (['waiting_for_confirmation', 'deleting'] as $state) {
            $http = new FakeInjectionHttpClient([$this->ok(['execution_status' => $state])]);
            $transport = new OpenHandsTransport('http://localhost', http: $http);
            $this->assertSame(InjectionTransportInterface::UNREACHABLE, $transport->liveness('c1'), $state);
        }
    }

    public function testRestInterruptFailureReportsInterruptedState(): void {
        $http = new FakeInjectionHttpClient([
            $this->ok(),
            $this->ok(['error' => 'failed'], 500),
        ]);
        $transport = new OpenHandsTransport('http://localhost', http: $http);
        try {
            $transport->inject('c1', ['id' => 'M-1', 'body' => 'stop'], 'interrupt', InjectionTransportInterface::RUNNING);
            $this->fail('expected the injection failure');
        } catch (TransportException $e) {
            $this->assertTrue($e->context()['interrupted'] ?? false);
        }
    }

    public function testExpiredDeadlineStopsBeforeAnyHttpCall(): void {
        $http = new FakeInjectionHttpClient([]);
        $transport = new OpenHandsTransport('http://localhost', http: $http);
        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/deadline/');
        $transport->liveness('c1', microtime(true) - 1);
    }

    public function testOpenHandsSteerDoesNotResume(): void {
        $http = new FakeInjectionHttpClient([$this->ok(['success' => true])]);
        $transport = new OpenHandsTransport('http://localhost', http: $http);
        $transport->inject('c1', ['id' => 'M-1', 'body' => 'context'], 'steer', InjectionTransportInterface::RUNNING);
        $this->assertFalse($http->calls[0]['payload']['run']);
        $this->assertCount(1, $http->calls);
    }

    public function testOpenHandsSavedButNotResumedIsAcceptedWithoutFallback(): void {
        $http = new FakeInjectionHttpClient([$this->ok(['error' => 'run capacity full'], 429)]);
        $transport = new OpenHandsTransport('http://localhost', http: $http);
        $ack = $transport->inject('c1', ['id' => 'M-1', 'body' => 'hello'], 'wakeup', InjectionTransportInterface::IDLE);
        $this->assertSame(429, $ack['http_status']);
        $this->assertFalse($ack['resumed']);
    }

    public function testOpenCodeUsesCurrentTextPromptAndMetadata(): void {
        $http = new FakeInjectionHttpClient([
            $this->ok(['data' => ['id' => 'ses-1']]),
            $this->ok(['data' => []]),
            $this->ok(['data' => ['id' => 'input-1']]),
        ]);
        $transport = new OpenCodeTransport('http://localhost', [
            'scheme' => 'basic', 'username' => 'opencode', 'credential' => 'password',
        ], http: $http);
        $this->assertSame(InjectionTransportInterface::IDLE, $transport->liveness('ses-1'));
        $envelope = ['id' => 'M-1', 'from' => 'alice', 'body' => 'hello', 'session' => 'logical-1', 'attest' => 'YXR0ZXN0'];
        $transport->inject('ses-1', $envelope, 'steer', InjectionTransportInterface::IDLE);
        $this->assertSame('/api/session/ses-1/prompt', $http->calls[2]['path']);
        $payload = $http->calls[2]['payload'];
        $this->assertSame('hello', $payload['text']);
        $this->assertFalse($payload['resume']);
        $this->assertSame('steer', $payload['delivery']);
        $this->assertSame($envelope, $payload['metadata']['continuum']);
        $this->assertArrayNotHasKey('parts', $payload);
        $this->assertContains('Authorization: Basic ' . base64_encode('opencode:password'), $http->calls[2]['headers']);
    }

    public function testOpenCodeUnknownLivenessFailsClosed(): void {
        $http = new FakeInjectionHttpClient([
            $this->ok(['data' => ['id' => 'ses-1']]),
            $this->ok([], 404),
        ]);
        $transport = new OpenCodeTransport('http://localhost', http: $http);
        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/cannot determine/');
        $transport->liveness('ses-1');
    }

    public function testOpenCodeActiveSessionIsRunningAndInterrupts(): void {
        $http = new FakeInjectionHttpClient([
            $this->ok(['data' => ['id' => 'ses-1']]),
            $this->ok(['data' => ['ses-1' => ['status' => 'busy']]]),
            $this->ok(),
            $this->ok(['data' => ['id' => 'input-1']]),
        ]);
        $transport = new OpenCodeTransport('http://localhost', http: $http);
        $this->assertSame(InjectionTransportInterface::RUNNING, $transport->liveness('ses-1'));
        $transport->inject('ses-1', ['id' => 'M-1', 'body' => 'stop'], 'interrupt', InjectionTransportInterface::RUNNING);
        $this->assertSame('/api/session/ses-1/interrupt', $http->calls[2]['path']);
        $this->assertSame('/api/session/ses-1/prompt', $http->calls[3]['path']);
        $this->assertTrue($http->calls[3]['payload']['resume']);
    }

    public function testOpenCodeNestedPayloadUsesTextCarrierWithoutMetadata(): void {
        $http = new FakeInjectionHttpClient([$this->ok(['data' => ['id' => 'input-1']])]);
        $transport = new OpenCodeTransport('http://localhost', http: $http, payloadStyle: 'nested');
        $envelope = ['id' => 'M-1', 'body' => 'hello', 'sig' => 'c2ln'];
        $transport->inject('ses-1', $envelope, 'wakeup', InjectionTransportInterface::IDLE);
        $payload = $http->calls[0]['payload'];
        $this->assertArrayNotHasKey('metadata', $payload);
        $this->assertArrayNotHasKey('text', $payload);
        $this->assertStringStartsWith("hello\n\n<continuum-envelope>\n", $payload['prompt']['text']);
        $json = explode("\n", $payload['prompt']['text'])[3];
        $this->assertSame($envelope, json_decode($json, true));
    }

    public function testOpenCodeLegacyPartsUsesAsyncEndpoint(): void {
        $http = new FakeInjectionHttpClient([$this->ok([], 204)]);
        $transport = new OpenCodeTransport('http://localhost', http: $http, payloadStyle: 'parts');
        $envelope = ['id' => 'M-1', 'body' => 'hello', 'sig' => 'c2ln'];
        $transport->inject('ses-1', $envelope, 'wakeup', InjectionTransportInterface::IDLE);
        $this->assertSame('/api/session/ses-1/prompt_async', $http->calls[0]['path']);
        $this->assertSame('hello', $http->calls[0]['payload']['parts'][0]['text']);
        $this->assertSame($envelope, json_decode($http->calls[0]['payload']['parts'][1]['text'], true));
        $this->assertFalse($http->calls[0]['payload']['noReply']);
    }

    public function testRestHttpErrorIsNotReportedAsAccepted(): void {
        $http = new FakeInjectionHttpClient([$this->ok(['error' => 'busy'], 409)]);
        $transport = new OpenCodeTransport('http://localhost', http: $http);
        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/HTTP 409/');
        $transport->inject('ses-1', ['id' => 'M-1', 'body' => 'hello'], 'wakeup', InjectionTransportInterface::IDLE);
    }

    public function testTcpJsonCarriesPreemptionAndWholeEnvelope(): void {
        $requests = [];
        $transport = new TcpJsonTransport('localhost', 4444, connector: function (array $request, ?float $deadline = null) use (&$requests): array {
            $requests[] = $request;
            return $request['op'] === 'liveness' ? ['state' => 'running'] : ['accepted' => true, 'id' => 'remote-1'];
        });
        $this->assertSame(InjectionTransportInterface::RUNNING, $transport->liveness('s1'));
        $envelope = ['id' => 'M-1', 'body' => 'hello', 'cert' => '{"x":1}'];
        $result = $transport->inject('s1', $envelope, 'interrupt', InjectionTransportInterface::RUNNING);
        $this->assertSame('remote-1', $result['remote']);
        $this->assertSame(['liveness', 'inject'], array_column($requests, 'op'));
        $this->assertSame($envelope, $requests[1]['envelope']);
        $this->assertSame('interrupt', $requests[1]['preemption']);
    }

    public function testTcpJsonRequiresExplicitAcceptance(): void {
        $transport = new TcpJsonTransport('localhost', 4444, connector: fn(array $request): array => ['ok' => false]);
        $this->expectException(TransportException::class);
        $transport->inject('s1', ['id' => 'M-1', 'body' => 'hello'], 'wakeup', InjectionTransportInterface::IDLE);
    }

    public function testManagerMapsSessionAndFallsBackWithoutThrowing(): void {
        $transport = new FakeInjectionTransport('test-transport');
        $manager = new InjectionManager([
            'bob' => $this->target($transport, ['session_resolution' => 'map', 'session_map' => ['logical' => 'harness']]),
        ]);
        $result = $manager->deliver('bob', 'logical', ['id' => 'M-1', 'from' => 'test-agent', 'body' => 'hello'], 'wakeup');
        $this->assertTrue($result['pushed']);
        $this->assertSame('harness', $transport->calls[0]['session']);
        $this->assertSame('harness', $transport->calls[1]['session']);
        $missing = $manager->deliver('bob', 'other', ['id' => 'M-2', 'from' => 'test-agent', 'body' => 'hello'], 'wakeup');
        $this->assertFalse($missing['pushed']);
        $this->assertStringContainsString('no harness session mapping', $missing['reason']);
        $unconfigured = $manager->deliver('nobody', 's', [], 'wakeup');
        $this->assertFalse($unconfigured['pushed']);
        $this->assertSame('no direct transport configured', $unconfigured['reason']);
    }

    public function testManagerSkipsInjectionWhenSessionUnreachable(): void {
        $transport = new FakeInjectionTransport();
        $transport->state = InjectionTransportInterface::UNREACHABLE;
        $manager = new InjectionManager(['bob' => $this->target($transport)]);
        $result = $manager->deliver('bob', 's', ['id' => 'M-1', 'from' => 'test-agent', 'kind' => 'control'], 'interrupt');
        $this->assertFalse($result['pushed']);
        $this->assertSame(InjectionTransportInterface::UNREACHABLE, $result['liveness']);
        $this->assertCount(1, $transport->calls);
    }

    public function testManagerSharesOneDeadlineAcrossLivenessAndInjection(): void {
        $transport = new FakeInjectionTransport();
        $manager = new InjectionManager(['bob' => $this->target($transport, ['timeout' => 5])]);
        $start = microtime(true);
        $result = $manager->deliver('bob', 's', ['id' => 'M-1', 'from' => 'test-agent', 'body' => 'hello'], 'wakeup');
        $this->assertTrue($result['pushed']);
        $this->assertCount(2, $transport->deadlines);
        $this->assertSame($transport->deadlines[0], $transport->deadlines[1]);
        $this->assertGreaterThanOrEqual($start + 4.9, $transport->deadlines[0]);
        $this->assertLessThanOrEqual($start + 5.1, $transport->deadlines[0]);
    }

    public function testSettingsParseTargetsAndNeverRequireNetwork(): void {
        $file = tempnam(sys_get_temp_dir(), 'continuum-injection-');
        $ini = <<<'INI'
[injection]
enabled = true
timeout = 7
native_receivers = "sonya"
[injection.opencode]
transport = "opencode"
base_url = "http://127.0.0.1:4555"
auth_scheme = "basic"
credential_file = "/var/db/continuum/opencode.password"
payload = "text"
session_resolution = "map"
session_map = '{"logical":"harness"}'
wakeup_from = "opencode,devin"
steer_from = "opencode"
interrupt_from = ""
require_signed = false
[injection.quiet]
transport = "null"
INI;
        file_put_contents($file, $ini);
        try {
            $manager = InjectionManager::fromSettings(IniConfig::load($file));
            $reflection = new \ReflectionProperty(InjectionManager::class, 'targets');
            $targets = $reflection->getValue($manager);
            $this->assertArrayHasKey('opencode', $targets);
            $this->assertInstanceOf(OpenCodeTransport::class, $targets['opencode']['transport']);
            $this->assertSame('map', $targets['opencode']['session_resolution']);
            $this->assertSame(['opencode', 'devin'], $targets['opencode']['wakeup_from']);
            $this->assertSame(['opencode'], $targets['opencode']['steer_from']);
            $this->assertSame([], $targets['opencode']['interrupt_from']);
            $this->assertFalse($targets['opencode']['require_signed']);
            $this->assertTrue($manager->nativePreemption('sonya'));
            $this->assertArrayNotHasKey('quiet', $targets);
        } finally {
            @unlink($file);
        }
    }

    public function testDisabledDirectTargetsKeepNativePreemptionDeclaration(): void {
        $file = tempnam(sys_get_temp_dir(), 'continuum-injection-');
        file_put_contents($file, "[injection]\nenabled = false\nnative_receivers = \"sonya\"\n");
        try {
            $manager = InjectionManager::fromSettings(IniConfig::load($file));
            $this->assertTrue($manager->nativePreemption('sonya'));
            $result = $manager->deliver('sonya', 's', ['id' => 'M-1', 'from' => 'test-agent'], 'wakeup');
            $this->assertFalse($result['pushed']);
            $this->assertSame('native', $result['preemption_via']);
        } finally {
            @unlink($file);
        }
    }

    public function testSettingsRejectUnsupportedAcpInsteadOfPretendingToPush(): void {
        $file = tempnam(sys_get_temp_dir(), 'continuum-injection-');
        file_put_contents($file, "[injection.devin]\ntransport = \"acp\"\n");
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/future ACP/');
            InjectionManager::fromSettings(IniConfig::load($file));
        } finally {
            @unlink($file);
        }
    }
}
