<?php

namespace Continuum\Storage;

/**
 * Renders /metrics in Prometheus text exposition format. All metrics are
 * namespaced `continuum_`. Gauges computed on demand from live engine
 * state; counters/histograms read from MetricsStore.
 */
class PrometheusExporter {

    public function __construct(
        private ContinuumStorage $storage,
        private MetricsStore $metrics,
    ) {}

    public function render(): string {
        $lines = [];

        // Computed gauges (live state)
        $openTasks = 0;
        foreach ($this->storage->listTaskDocs() as $doc) {
            if (!in_array($doc['status'] ?? 'pending', ['done', 'cancelled'], true)) { $openTasks++; }
        }
        $this->gauge($lines, 'continuum_tasks_open', 'Open (non-terminal) tasks', $openTasks);
        $this->gauge($lines, 'continuum_agents_registered', 'Registered agents', count($this->storage->agents()));
        $this->gauge($lines, 'continuum_locks_held', 'Advisory locks currently held', count($this->storage->listLocks()));
        $this->gauge($lines, 'continuum_info', 'Continuum server information', 1, ['version' => APPLICATION_VERSION]);

        foreach ($this->metrics->gauges() as $name => $value) {
            $this->metric($lines, "continuum_{$name}", 'gauge', "Gauge {$name}", $value);
        }
        foreach ($this->metrics->counters() as $key => $value) {
            [$name, $labels] = str_contains($key, '|') ? explode('|', $key, 2) : [$key, ''];
            $this->metric($lines, "continuum_{$name}", 'counter', "Counter {$name}", $value, self::parseLabels($labels));
        }
        foreach ($this->metrics->histograms() as $name => $fields) {
            $this->histogram($lines, "continuum_{$name}", $fields);
        }

        return implode("\n", $lines) . "\n";
    }

    private function gauge(array &$lines, string $name, string $help, float $value, array $labels = []): void {
        $this->metric($lines, $name, 'gauge', $help, $value, $labels);
    }

    private function metric(array &$lines, string $name, string $type, string $help, float $value, array $labels = []): void {
        $lines[] = "# HELP {$name} {$help}";
        $lines[] = "# TYPE {$name} {$type}";
        $lines[] = $name . self::renderLabels($labels) . ' ' . $value;
    }

    private function histogram(array &$lines, string $name, array $fields): void {
        $lines[] = "# HELP {$name} {$name}";
        $lines[] = "# TYPE {$name} histogram";
        $buckets = [];
        foreach ($fields as $field => $value) {
            if (str_starts_with($field, 'bucket_le=')) {
                $buckets[substr($field, 10)] = $value;
            }
        }
        uksort($buckets, fn($a, $b) => $a === '+Inf' ? 1 : ($b === '+Inf' ? -1 : (float)$a <=> (float)$b));
        $cumulative = 0.0;
        foreach ($buckets as $le => $count) {
            $cumulative += $count;
            $lines[] = "{$name}_bucket" . self::renderLabels(['le' => $le]) . ' ' . $cumulative;
        }
        $lines[] = "{$name}_sum " . ($fields['sum'] ?? 0);
        $lines[] = "{$name}_count " . ($fields['count'] ?? 0);
    }

    private static function parseLabels(string $raw): array {
        if ($raw === '') { return []; }
        $labels = [];
        foreach (explode(',', $raw) as $pair) {
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $labels[$k] = $v;
            }
        }
        return $labels;
    }

    private static function renderLabels(array $labels): string {
        if (empty($labels)) { return ''; }
        $parts = array_map(fn($k, $v) => $k . '="' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string)$v) . '"',
            array_keys($labels), $labels);
        return '{' . implode(',', $parts) . '}';
    }
}
