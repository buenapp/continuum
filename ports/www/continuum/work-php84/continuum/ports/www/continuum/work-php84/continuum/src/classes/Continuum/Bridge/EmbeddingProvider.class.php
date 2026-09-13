<?php

namespace Continuum\Bridge;

use EnchiladaHTTP;

/**
 * llama.cpp (OpenAI-compatible) embeddings endpoint client: POST
 * {base}/v1/embeddings. Pluggable via config ([embeddings] url/model/key).
 */
class EmbeddingProvider extends EnchiladaHTTP {

    /** @return array<int, array<int, float>> embeddings aligned with input */
    public function embed(array $inputs, string $model, string $apiKey = ''): array {
        $headers = $apiKey !== '' ? ['Authorization: Bearer ' . $apiKey] : [];
        $result = $this->call('v1/embeddings', ['model' => $model, 'input' => array_values($inputs)], 'POST', $headers);
        if (!is_array($result) || !isset($result['data'])) {
            throw new \RuntimeException('embeddings endpoint returned no data (HTTP ' . $this->getHttpCode() . ')');
        }
        usort($result['data'], fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
        return array_map(fn($d) => $d['embedding'], $result['data']);
    }
}
