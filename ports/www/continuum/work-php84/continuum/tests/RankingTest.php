<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\Bridge\LexicalRanker;
use Continuum\Bridge\EmbeddingRanker;
use Continuum\Bridge\EmbeddingProvider;
use Continuum\Storage\ValKeyStore;
use Continuum\Storage\ContinuumStorage;

/** Deterministic vectors keyed by text content. */
class FakeEmbeddingProvider extends EmbeddingProvider {
    public function __construct(private array $vectorsByText) {}
    public function embed(array $inputs, string $model, string $apiKey = ''): array {
        return array_map(fn($t) => $this->vectorsByText[$t] ?? [0.0, 0.0], $inputs);
    }
}

class RankingTest extends TestCase {

    public function testLexicalRanksByTokenOverlapWithStableTies(): void {
        $items = [
            ['text' => 'deploy the release pipeline', 'id' => 'a'],
            ['text' => 'fix parser bug', 'id' => 'b'],
            ['text' => 'pipeline credentials rotation', 'id' => 'c'],
        ];
        $ranked = (new LexicalRanker())->rank($items, 'release pipeline');
        $this->assertSame('a', $ranked[0]['id']);   // both query tokens
        $this->assertSame('c', $ranked[1]['id']);   // one token
        $this->assertSame('b', $ranked[2]['id']);   // none
        $this->assertArrayHasKey('_score', $ranked[0]);
    }

    public function testLexicalStopwordsEmptyQueryFallsToStableOrder(): void {
        $items = [
            ['text' => 'alpha task', 'id' => 'a'],
            ['text' => 'beta task', 'id' => 'b'],
        ];
        $ranked = (new LexicalRanker())->rank($items, 'the and of'); // all stopwords
        $this->assertSame(['a', 'b'], array_column($ranked, 'id'));
    }

    public function testEmbeddingRankerByCosine(): void {
        $provider = new FakeEmbeddingProvider([
            'auth work' => [1.0, 0.0],
            'totally unrelated thing' => [0.0, 1.0],
            'login session handling' => [0.9, 0.1],
        ]);
        $ranker = new EmbeddingRanker($provider, 'test-model');
        $ranked = $ranker->rank([
            ['text' => 'totally unrelated thing'],
            ['text' => 'login session handling'],
        ], 'auth work');
        $this->assertSame('login session handling', $ranked[0]['text']);
        $this->assertGreaterThan($ranked[1]['_score'], $ranked[0]['_score']);
    }

    public function testContextPackUsesRankerWhenQueryGiven(): void {
        $provider = new FakeEmbeddingProvider([
            'auth' => [1.0, 0.0],
            'login flow' => [1.0, 0.0],
            'css polish' => [0.0, 1.0],
        ]);
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['rows' => [
                (object)['id' => 'T-1', 'doc' => (object)['title' => 'css polish', 'scope' => 'global', 'status' => 'pending', 'priority' => 1, 'updated_at' => '2026-09-13T01:00:00Z']],
                (object)['id' => 'T-2', 'doc' => (object)['title' => 'login flow', 'scope' => 'global', 'status' => 'pending', 'priority' => 2, 'updated_at' => '2026-09-13T02:00:00Z']],
            ]]],
            ['code' => 200, 'body' => ['rows' => []]],
        ]);
        // Without query: priority dominates, css polish first
        $plain = new \Continuum\ContextTools(new ContinuumStorage(new ValKeyStore(new FakeRespClient([])), $couch, new FakeArcade()));
        $this->assertStringContainsString('css polish', explode("\n", $plain->context_pack(null, null, 2000)['pack'])[4] ?? '');

        // With query + ranker: login flow outranks css polish
        $ranked = new \Continuum\ContextTools(
            new ContinuumStorage(new ValKeyStore(new FakeRespClient([])), $couch->replay(), new FakeArcade()),
            new EmbeddingRanker($provider, 'test-model')
        );
        $pack = $ranked->context_pack(null, null, 2000, 'auth')['pack'];
        $this->assertLessThan(strpos($pack, 'css polish'), strpos($pack, 'login flow'));
    }

    public function testContextPackSwallowsRankerFailure(): void {
        $failing = new class implements \Continuum\Bridge\RankingProviderInterface {
            public function rank(array $items, string $query): array { throw new \RuntimeException('embeddings down'); }
        };
        $couch = new FakeCouch([
            ['code' => 200, 'body' => ['rows' => [
                (object)['id' => 'T-1', 'doc' => (object)['title' => 'anything', 'scope' => 'global', 'status' => 'pending', 'priority' => 2, 'updated_at' => 'now']],
            ]]],
            ['code' => 200, 'body' => ['rows' => []]],
        ]);
        $tools = new \Continuum\ContextTools(
            new ContinuumStorage(new ValKeyStore(new FakeRespClient([])), $couch, new FakeArcade()),
            $failing
        );
        $this->assertStringContainsString('anything', $tools->context_pack(null, null, 2000, 'q')['pack']);
    }
}
