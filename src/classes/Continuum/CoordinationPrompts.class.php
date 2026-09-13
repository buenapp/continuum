<?php

namespace Continuum;

use EnchiladaMCP\McpPrompt;
use EnchiladaMCP\McpComplete;
use Continuum\Storage\ContinuumStorage;

/**
 * Coordination prompt templates: the server's working contract as
 * discoverable prompts.
 *
 * These mirror docs/AGENT-SETUP.md — the instructions string is a blob,
 * where prompts are selectable, argued, and versioned with the release.
 * Completion providers suggest live values (scopes, task ids, agent ids,
 * lock names, board keys) from the storage facade.
 */
class CoordinationPrompts {

    /**
     * @param bool $memoryBridge Whether a long-term memory bridge is
     *                           configured; prompts name
     *                           promote_to_memory only when it exists.
     */
    public function __construct(
        private ContinuumStorage $storage,
        private bool $memoryBridge = false,
    ) {}

    /**
     * Session start: register, load context age, pull your inbox.
     */
    #[McpPrompt(
        name: 'session_bootstrap',
        title: 'Session bootstrap',
        description: 'Onboard onto the Continuum coordination board: register your identity, load the context pack, pull your inbox, and orient on open work.',
        arguments: [
            ['name' => 'scope', 'description' => 'Board scope to focus (default: global)'],
        ]
    )]
    public function session_bootstrap(?string $scope = null): array {
        $scope = ($scope === null || $scope === '') ? 'global' : $scope;
        return [
            $this->userMessage(
                "Bootstrap this session on the Continuum coordination board (you are agent '" . CONTINUUM_AGENT . "'):\n"
                . "1. Call agent_register with your capabilities (and a human label if unset).\n"
                . "2. Read the linked context pack resource for scope '{$scope}' (or call context_pack with that scope if resources are unavailable).\n"
                . "3. Call message_inbox_pull to claim anything addressed to you.\n"
                . "4. Review continuum://tasks for claimable work in your scope.\n"
                . "Do not start work until all four steps are done."
            ),
            [
                'role' => 'user',
                'content' => [
                    'type' => 'resource_link',
                    'uri' => "continuum://context/pack/{$scope}",
                    'name' => "context pack ({$scope})",
                    'mimeType' => 'text/markdown',
                ],
            ],
        ];
    }

    /**
     * Claim the next task and serve it through the state machine.
     */
    #[McpPrompt(
        name: 'claim_and_serve',
        title: 'Claim and serve',
        description: 'Pick open work, claim it atomically, and drive it through the task state machine with heartbeats.',
        arguments: [
            ['name' => 'scope', 'description' => 'Board scope to scan for work (default: global)'],
        ]
    )]
    public function claim_and_serve(?string $scope = null): array {
        $scope = ($scope === null || $scope === '') ? 'global' : $scope;
        return [$this->userMessage(
            "Find and serve work on the Continuum board (scope '{$scope}'):\n"
            . "1. Read continuum://tasks (or task_list with scope '{$scope}') and pick the highest-priority pending task you are suited to.\n"
            . "2. Claim it with task_claim — claims are MVCC-atomic; a raced claim loses cleanly, so re-read and pick again on conflict.\n"
            . "3. Call agent_heartbeat with working_on set to the task id.\n"
            . "4. Work it; move status via task_update_status (in_progress when active, blocked with a note when stuck, review/done with a summary note).\n"
            . "5. If you cannot finish it, hand it back with task_handoff rather than abandoning the claim.\n"
            . "Remember: every mutation is attributed to your agent identity and written to the event log."
        )];
    }

    /**
     * Hand off a task: summarize, release, promote what is durable.
     */
    #[McpPrompt(
        name: 'handoff',
        title: 'Handoff a task',
        description: 'Close your involvement with a task properly: structured handoff document, claim release, and memory promotion for durable outcomes.',
        arguments: [
            ['name' => 'task_id', 'description' => 'Task id (T-XXXXXXXX)', 'required' => true],
        ]
    )]
    public function handoff(string $task_id): array {
        $task = $this->storage->loadTask($task_id)
            ?? throw new \InvalidArgumentException("task {$task_id} not found");
        $title = $task['title'] ?? '';
        // Name promote_to_memory only when a memory bridge exists
        // (otherwise the tool is not registered at all).
        $promoteStep = $this->memoryBridge
            ? "3. If anything in this session is a durable fact (a decision made, an invariant discovered), call promote_to_memory so it reaches long-term memory. The board owns what happens NEXT; memory owns what is TRUE.\n"
            . "4. Final agent_heartbeat with working_on cleared."
            : '3. Final agent_heartbeat with working_on cleared.';
        return [$this->userMessage(
            "Prepare a handoff for {$task_id} (\"{$title}\"):\n"
            . "1. Write the handoff fields honestly: summary (what changed and where), next_steps (the smallest concrete actions remaining), blockers (anything waiting on someone else).\n"
            . "2. Call task_handoff with those fields — it releases your claim atomically and re-queues the task.\n"
            . $promoteStep
        )];
    }

    /**
     * What belongs in milestone sync vs. the board vs. long-term memory.
     */
    #[McpPrompt(
        name: 'milestone_sync',
        title: 'Milestone sync boundary',
        description: 'Explain the record boundaries: what syncs to the canonical tracker, what lives on the board, and what belongs in long-term memory.'
    )]
    public function milestone_sync(): array {
        $memoryLine = $this->memoryBridge
            ? "- Long-term memory (Heliofane via promote_to_memory) owns durable TRUTH: decisions, invariants, and outcomes that must survive sessions.\n"
            . "If you are about to record something, ask: is it coordination (board), a milestone (automatic), or a permanent fact (promote_to_memory)?"
            : "If you are about to record something, ask: is it coordination (board) or a milestone (automatic)?";
        return [$this->userMessage(
            "Continuum sits between two durable systems; keep the boundaries straight:\n"
            . "- The canonical tracker (Phorge side) receives only MILESTONES: task started (on claim), blocked, and resolved. These fire automatically from task_claim/task_update_status — never hand-edit tracker state.\n"
            . "- The board (Continuum) owns live coordination: task claims, advisory locks, working state, inboxes, and the append-only event log. This is the \"what happens next\" layer.\n"
            . $memoryLine
        )];
    }

    /** One user message with a single text content block. */
    private function userMessage(string $text): array {
        return [
            'role' => 'user',
            'content' => ['type' => 'text', 'text' => $text],
        ];
    }

    // --- Completion providers ----------------------------------------------

    /** Scope names from boards + tasks (global always present). */
    private function scopes(): array {
        $scopes = ['global' => true];
        foreach (array_keys($this->storage->listBoardDocsAll()) as $id) {
            $scope = explode('/', (string)$id, 2)[0] ?? null;
            if ($scope !== null && $scope !== '') { $scopes[$scope] = true; }
        }
        foreach ($this->storage->listTaskDocs() as $doc) {
            $scope = $doc['scope'] ?? 'global';
            $scopes[$scope] = true;
        }
        $list = array_keys($scopes);
        sort($list);
        return $list;
    }

    private function filterPrefix(array $candidates, string $value): array {
        if ($value === '') { return $candidates; }
        return array_values(array_filter(
            $candidates,
            fn($candidate) => stripos((string)$candidate, $value) === 0
        ));
    }

    /**
     * Scope argument completion for prompts and the scope-keyed resources.
     */
    #[McpComplete(refType: 'ref/prompt', refName: 'session_bootstrap', argument: 'scope')]
    #[McpComplete(refType: 'ref/prompt', refName: 'claim_and_serve', argument: 'scope')]
    #[McpComplete(refType: 'ref/resource', refName: 'continuum://board/{scope}/{key}', argument: 'scope')]
    #[McpComplete(refType: 'ref/resource', refName: 'continuum://snapshot/{scope}', argument: 'scope')]
    #[McpComplete(refType: 'ref/resource', refName: 'continuum://context/pack/{scope}', argument: 'scope')]
    public function complete_scope(string $value, array $context): array {
        return $this->filterPrefix($this->scopes(), $value);
    }

    /**
     * Board key completion within the scope already provided.
     */
    #[McpComplete(refType: 'ref/resource', refName: 'continuum://board/{scope}/{key}', argument: 'key')]
    public function complete_board_key(string $value, array $context): array {
        $scope = (string)($context['scope'] ?? 'global');
        return $this->filterPrefix($this->storage->listBoardKeys($scope), $value);
    }

    /**
     * Task id completion (non-terminal tasks first).
     */
    #[McpComplete(refType: 'ref/prompt', refName: 'handoff', argument: 'task_id')]
    #[McpComplete(refType: 'ref/resource', refName: 'continuum://tasks/{id}', argument: 'id')]
    public function complete_task_id(string $value, array $context): array {
        $open = [];
        $closed = [];
        foreach ($this->storage->listTaskDocs() as $id => $doc) {
            $status = $doc['status'] ?? 'pending';
            ($status === 'done' || $status === 'cancelled') ? $closed[] = (string)$id : $open[] = (string)$id;
        }
        sort($open);
        sort($closed);
        return $this->filterPrefix(array_merge($open, $closed), strtoupper($value));
    }

    /**
     * Agent id completion from the presence directory.
     */
    #[McpComplete(refType: 'ref/resource', refName: 'continuum://agents/{id}', argument: 'id')]
    public function complete_agent_id(string $value, array $context): array {
        $agents = $this->storage->agents();
        sort($agents);
        return $this->filterPrefix($agents, $value);
    }

    /**
     * Lock name completion from currently held locks.
     */
    #[McpComplete(refType: 'ref/resource', refName: 'continuum://locks/{name}', argument: 'name')]
    public function complete_lock_name(string $value, array $context): array {
        $names = array_keys($this->storage->listLocks());
        sort($names);
        return $this->filterPrefix($names, $value);
    }
}
