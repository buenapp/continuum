<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\Storage\MetricsStore;
use Continuum\Storage\MetricsCollector;
use Continuum\Storage\PrometheusExporter;
use Continuum\Storage\ContinuumStorage;
use Continuum\Storage\ValKeyStore;

class MetricsTest extends TestCase {

    public function testStoreCountersAndHistogramsRoundTrip(): void {
        $resp = new FakeRespClient([]);
        $store = new MetricsStore($resp);
        $store->incrementCounter('tool_calls_total', 'tool=blackboard_write', 1);
        $store->observeHistogram('http_request_duration', 0.04, [0.05, 0.5, 1.0]);
        // HINCRBYFLOAT counter + (bucket, sum, count)
        $commands = array_column($resp->calls, 0);
        $this->assertSame(['HINCRBYFLOAT', 'HINCRBYFLOAT', 'HINCRBYFLOAT', 'HINCRBYFLOAT'], $commands);
        $this->assertStringContainsString('counters', $resp->calls[0][1]);
        $this->assertSame('tool_calls_total|tool=blackboard_write', $resp->calls[0][2]);
        $this->assertSame('0.04', $resp->calls[2][3]);
        $this->assertSame('bucket_le=0.05', $resp->calls[1][2]);
    }

    public function testCollectorFlushPersistsAndResets(): void {
        MetricsCollector::increment('mcp_requests_total');
        MetricsCollector::increment('tool_calls_total', ['tool' => 'task_claim']);
        MetricsCollector::observe('http_request_duration', 0.02);
        MetricsCollector::gauge('demo_gauge', 7);

        $resp = new FakeRespClient([]);
        $store = new MetricsStore($resp);
        MetricsCollector::flush($store);

        $commands = array_column($resp->calls, 0);
        $this->assertContains('HSET', $commands);       // gauge
        $this->assertGreaterThanOrEqual(4, count($resp->calls));

        // Second flush writes nothing
        $resp2 = new FakeRespClient([]);
        MetricsCollector::flush(new MetricsStore($resp2));
        $this->assertCount(0, $resp2->calls);
    }

    public function testCollectorFlushSwallowsStoreFailures(): void {
        MetricsCollector::increment('x');
        $store = new MetricsStore(new FakeRespClient([]));
        // command() throwing: override via anonymous class with exploding client
        $exploding = new class extends MetricsStore {
            public function __construct() {}
            public function incrementCounter(string $n, string $l, float $a = 1.0): void { throw new \RuntimeException('redis down'); }
        };
        MetricsCollector::flush($exploding);
        $this->assertTrue(true); // no exception bubbled
    }

    public function testExporterRendersAllFamilies(): void {
        // Metrics content from ValKey
        $metricsResp = new FakeRespClient([
            ['agents_registered', '3'],                                  // HGETALL gauges
            ['tool_calls_total|tool=bb_x', '5'],                         // HGETALL counters
            ['0', ['continuum:metrics:histograms:http_request_duration']], // SCAN histograms
            ['bucket_le=0.05', '2', 'bucket_le=+Inf', '1', 'sum', '0.1', 'count', '3'], // HGETALL hist
        ]);
        // Storage content for computed gauges
        $storageResp = new FakeRespClient([
            ['alice', 'bob'],                        // SMEMBERS agents
            ['0', ['continuum:lock:build']],         // SCAN locks
            'alice',                                 // GET lock
            5000,                                    // PTTL
        ]);
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['rows' => [
                (object)['id' => 'T-1', 'doc' => (object)['status' => 'pending']],
                (object)['id' => 'T-2', 'doc' => (object)['status' => 'done']],
            ]]],
        ]);
        $storage = new ContinuumStorage(new ValKeyStore($storageResp), $couch, new FakeArcade());
        $out = (new PrometheusExporter($storage, new MetricsStore($metricsResp)))->render();

        $this->assertStringContainsString("continuum_tasks_open 1\n", $out);
        $this->assertStringContainsString("continuum_agents_registered 2\n", $out);
        $this->assertStringContainsString("continuum_locks_held 1\n", $out);
        $this->assertStringContainsString('# TYPE continuum_tool_calls_total counter', $out);
        $this->assertStringContainsString('continuum_tool_calls_total{tool="bb_x"} 5', $out);
        $this->assertStringContainsString('# TYPE continuum_http_request_duration histogram', $out);
        $this->assertStringContainsString('continuum_http_request_duration_bucket{le="+Inf"} 3', $out);
        $this->assertStringContainsString('continuum_http_request_duration_sum 0.1', $out);
    }
}
