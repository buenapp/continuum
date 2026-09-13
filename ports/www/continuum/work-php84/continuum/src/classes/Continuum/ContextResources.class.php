<?php

namespace Continuum;

use EnchiladaMCP\McpResource;
use Continuum\Storage\ContinuumStorage;

/**
 * Context resources: the session-bootstrap brief as an attachable read.
 *
 * Same builder as the context_pack tool (default lexical ranking); the
 * resource form lets clients pin the pack as context instead of calling
 * a tool and copying text out of the result.
 */
class ContextResources {

    public function __construct(private ContinuumStorage $storage) {}

    /**
     * Curated markdown brief for a scope (continuum://context/pack/global).
     */
    #[McpResource(
        uriTemplate: 'continuum://context/pack/{scope}',
        description: 'Curated, ranked, token-budgeted markdown brief of the blackboard for a scope. Attach at session start.',
        mimeType: 'text/markdown',
        annotations: ['audience' => ['assistant'], 'priority' => 0.9]
    )]
    public function context_pack(string $scope): array {
        $pack = (new ContextTools($this->storage))->context_pack(scope: $scope);
        return [
            'text' => $pack['pack'],
            'annotations' => ['audience' => ['assistant'], 'priority' => 0.9],
        ];
    }
}
