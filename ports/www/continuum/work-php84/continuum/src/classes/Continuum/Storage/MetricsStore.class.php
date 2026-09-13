<?php

namespace Continuum\Storage;

/**
 * Metrics persistence on ValKey hashes (ephemeral engine; metrics need no
 * durability):
 *   continuum:metrics:counters            HINCRBYFLOAT name|label=value ...
 *   continuum:metrics:histograms:{name}   field = bucket le | sum | count
 *   continuum:metrics:gauges              plain SET (last write wins)
 */
class MetricsStore {

    private const NS = 'continuum:metrics:';

    public function __construct(private RespClient $client) {}

    public function incrementCounter(string $name, string $labels, float $amount = 1.0): void {
        $this->client->command('HINCRBYFLOAT', self::NS . 'counters', $name . '|' . $labels, (string)$amount);
    }

    public function observeHistogram(string $name, float $value, array $buckets): void {
        $key = self::NS . 'histograms:' . $name;
        $le = '+Inf';
        foreach ($buckets as $bound) {
            if ($value <= $bound) { $le = (string)$bound; break; }
        }
        $this->client->command('HINCRBYFLOAT', $key, 'bucket_le=' . $le, '1');
        $this->client->command('HINCRBYFLOAT', $key, 'sum', (string)$value);
        $this->client->command('HINCRBYFLOAT', $key, 'count', '1');
    }

    public function setGauge(string $name, float $value): void {
        $this->client->command('HSET', self::NS . 'gauges', $name, (string)$value);
    }

    /** name|labels => cumulative value */
    public function counters(): array {
        return $this->hashToAssoc(self::NS . 'counters');
    }

    /** name => [field => value] */
    public function histograms(): array {
        $histograms = [];
        $cursor = '0';
        do {
            $result = $this->client->command('SCAN', $cursor, 'MATCH', self::NS . 'histograms:*', 'COUNT', '100');
            if (!is_array($result) || count($result) < 2) { break; }
            $cursor = (string)$result[0];
            foreach ((array)$result[1] as $key) {
                $name = substr($key, strlen(self::NS . 'histograms:'));
                $histograms[$name] = $this->hashToAssoc($key);
            }
        } while ($cursor !== '0');
        return $histograms;
    }

    public function gauges(): array {
        return $this->hashToAssoc(self::NS . 'gauges');
    }

    private function hashToAssoc(string $key): array {
        $fields = $this->client->command('HGETALL', $key) ?: [];
        $assoc = [];
        for ($i = 0; $i + 1 < count($fields); $i += 2) { $assoc[$fields[$i]] = (float)$fields[$i + 1]; }
        return $assoc;
    }
}
