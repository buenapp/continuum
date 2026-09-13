<?php

namespace Continuum\Tests;

use PHPUnit\Framework\TestCase;
use Continuum\Dashboard;

class DashboardTest extends TestCase {

    public function testRendersAllSectionsAndEscapes(): void {
        $html = Dashboard::render([
            'now' => '2026-09-13T03:00:00Z',
            'agents' => [['agent' => '<b>evil</b>', 'label' => 'Lab', 'capabilities' => ['php'], 'working_on' => 'T-9', 'last_seen_s_ago' => 12, 'registered_at' => null]],
            'open_tasks' => [['task' => 'T-9', 'title' => 'Fix <parser>', 'scope' => 'proj', 'status' => 'claimed', 'owner' => 'alice', 'priority' => 1]],
            'locks' => ['build' => ['owner' => 'alice', 'ttl_ms' => 45000]],
            'boards' => ['global' => 3],
            'queues' => ['proj' => 2],
            'recent_events' => [['ts' => '2026-09-13T02:59:00.000Z', 'agent' => 'alice', 'type' => 'task_claim', 'data' => ['task' => 'T-9']]],
        ]);
        $this->assertStringContainsString('<title>Continuum board status</title>', $html);
        $this->assertStringContainsString('&lt;b&gt;evil&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>evil</b>', $html);
        $this->assertStringContainsString('Fix &lt;parser&gt;', $html);
        $this->assertStringContainsString('45s', $html);
        $this->assertStringContainsString('task_claim', $html);
    }

    public function testEmptyStateMessages(): void {
        $html = Dashboard::render(['now' => 'x']);
        $this->assertStringContainsString('no registered agents', $html);
        $this->assertStringContainsString('no open tasks', $html);
        $this->assertStringContainsString('no locks held', $html);
    }
}
