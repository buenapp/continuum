<?php

namespace Continuum\Storage;

/**
 * Request-scope metric accumulator; flush() persists to MetricsStore at
 * end of request. Lossy by design: metrics never break a request.
 */
class MetricsCollector {

    /** @var array<string,float> "name|labels" => amount */
    private static array $counters = [];
    /** @var array<string,array<int,float>> name => values */
    private static array $histograms = [];
    /** @var array<string,float> name => latest value */
    private static array $gauges = [];
    /** @var array<string,array<int,float>> */
    private static array $bucketDefs = [];

    private const DEFAULT_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    /** Register histogram bucket boundaries; call at bootstrap. */
    public static function init(array $bucketDefs = []): void {
        self::$bucketDefs = $bucketDefs + ['http_request_duration' => self::DEFAULT_BUCKETS];
    }

    public static function increment(string $name, array $labels = [], int $amount = 1): void {
        $key = $name . '|' . self::formatLabels($labels);
        self::$counters[$key] = (self::$counters[$key] ?? 0) + $amount;
    }

    public static function observe(string $name, float $value): void {
        self::$histograms[$name][] = $value;
    }

    public static function gauge(string $name, float $value): void {
        self::$gauges[$name] = $value;
    }

    public static function startTimer(): float {
        return microtime(true);
    }

    public static function observeDuration(string $name, float $start): void {
        self::observe($name, microtime(true) - $start);
    }

    public static function flush(MetricsStore $store): void {
        self::init();
        try {
            foreach (self::$counters as $key => $amount) {
                [$name, $labels] = explode('|', $key, 2);
                $store->incrementCounter($name, $labels, $amount);
            }
            foreach (self::$histograms as $name => $values) {
                foreach ($values as $v) {
                    $store->observeHistogram($name, $v, self::$bucketDefs[$name] ?? self::DEFAULT_BUCKETS);
                }
            }
            foreach (self::$gauges as $name => $value) {
                $store->setGauge($name, $value);
            }
        } catch (\Throwable) {
            // Non-fatal: metrics loss is acceptable
        }
        self::$counters = [];
        self::$histograms = [];
        self::$gauges = [];
    }

    private static function formatLabels(array $labels): string {
        if (empty($labels)) { return ''; }
        ksort($labels);
        return implode(',', array_map(fn($k, $v) => $k . '=' . $v, array_keys($labels), $labels));
    }
}
