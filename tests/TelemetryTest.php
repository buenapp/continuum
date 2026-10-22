<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;

/** Records EnchiladaOTLP emits without any network I/O. */
class FakeOtlpExporter extends \EnchiladaOTLP {
    /** @var array<int,array> */ public array $emits = [];
    public function __construct() {
        parent::__construct('http://never-reached', [], 'test', '1.0');
    }
    public function emit(string $body, int $severity = \EnchiladaOTLP::SEVERITY_INFO, array $attributes = [], ?string $traceId = null, ?string $spanId = null, ?int $timestampNs = null): void {
        $this->emits[] = ['body' => $body, 'severity' => $severity, 'attributes' => $attributes];
    }
    public function flush(): bool { return true; }
}

class TelemetryTest extends TestCase {

    public function testDisabledFromMissingSettings(): void {
        $t = \Continuum\Telemetry::fromSettings(null, '9.9.9');
        $this->assertFalse($t->enabled());
        $this->assertSame(['ok' => 1], $t->wrap(fn(array $r) => ['ok' => 1], 'test-agent')(['method' => 'ping', 'params' => []]));
    }

    public function testDisabledStillRethrows(): void {
        $t = \Continuum\Telemetry::disabled();
        $this->expectException(\RuntimeException::class);
        $t->wrap(function () { throw new \RuntimeException('boom'); }, 'test-agent')(['method' => 'tools/call']);
    }

    public function testEnabledFromSettings(): void {
        $ini = tempnam(sys_get_temp_dir(), 'tel-');
        file_put_contents($ini, "[otlp]\nenabled = true\n");
        $config = \Enchilada\Config\IniConfig::load($ini);
        $t = \Continuum\Telemetry::fromSettings($config, '9.9.9');
        unlink($ini);
        $this->assertTrue($t->enabled());
    }

    public function testWrapRecordsSuccess(): void {
        $exporter = new FakeOtlpExporter();
        $t = (new \ReflectionClass(\Continuum\Telemetry::class))->newInstanceArgs([$exporter]);
        $wrapped = $t->wrap(fn(array $r) => ['jsonrpc' => '2.0', 'result' => []], 'test-agent');
        $resp = $wrapped(['method' => 'tools/call', 'params' => ['name' => 'board_status']]);
        $this->assertSame('2.0', $resp['jsonrpc']);
        $emits = $exporter->emits;
        $this->assertCount(1, $emits);
        $e = $emits[0];
        $this->assertSame('tools/call', $e['attributes']['mcp.request.method']);
        $this->assertSame('board_status', $e['attributes']['mcp.tool.name']);
        $this->assertSame('test-agent', $e['attributes']['mcp.agent']);
        $this->assertSame('ok', $e['attributes']['mcp.outcome']);
        $this->assertArrayHasKey('duration_ms', $e['attributes']);
        $this->assertSame(\EnchiladaOTLP::SEVERITY_INFO, $e['severity']);
    }

    public function testWrapRecordsErrorAndRethrows(): void {
        $exporter = new FakeOtlpExporter();
        $t = (new \ReflectionClass(\Continuum\Telemetry::class))->newInstanceArgs([$exporter]);
        try {
            $t->wrap(function () { throw new \RuntimeException('kaboom'); }, 'alice')(['method' => 'tools/call', 'params' => []]);
            $this->fail('expected rethrow');
        } catch (\RuntimeException $e) {
            $this->assertSame('kaboom', $e->getMessage());
        }
        $this->assertCount(1, $exporter->emits);
        $e = $exporter->emits[0];
        $this->assertSame('error', $e['attributes']['mcp.outcome']);
        $this->assertSame('kaboom', $e['attributes']['mcp.error']);
        $this->assertSame(\EnchiladaOTLP::SEVERITY_ERROR, $e['severity']);
    }

    public function testNotificationsAreNotRecorded(): void {
        $exporter = new FakeOtlpExporter();
        $t = (new \ReflectionClass(\Continuum\Telemetry::class))->newInstanceArgs([$exporter]);
        $t->wrap(fn(array $r) => [], 'test-agent')(['method' => 'notifications/initialized', 'params' => []]);
        $this->assertCount(0, $exporter->emits);
    }
}
