<?php

namespace Continuum;

/**
 * Read-only HTML dashboard over board_status() output. Pure renderer:
 * no storage access here. Escapes everything.
 */
class Dashboard {

    private static function e(mixed $v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function render(array $status): string {
        $rows = fn(array $cells) => '<tr>' . implode('', array_map(fn($c) => '<td>' . self::e($c) . '</td>', $cells)) . '</tr>';

        $agents = '';
        foreach ($status['agents'] ?? [] as $a) {
            $agents .= $rows([$a['agent'], $a['label'] ?? '', is_array($a['capabilities'] ?? null) ? implode(', ', $a['capabilities']) : '', $a['working_on'] ?? '', $a['last_seen_s_ago'] === null ? 'never' : $a['last_seen_s_ago'] . 's']);
        }
        if ($agents === '') { $agents = '<tr><td colspan="5" class="empty">no registered agents</td></tr>'; }

        $tasks = '';
        foreach ($status['open_tasks'] ?? [] as $t) {
            $tasks .= $rows([$t['task'], 'p' . $t['priority'], $t['status'], $t['owner'] ?? '—', $t['scope'], $t['title']]);
        }
        if ($tasks === '') { $tasks = '<tr><td colspan="6" class="empty">no open tasks</td></tr>'; }

        $locks = '';
        foreach ($status['locks'] ?? [] as $name => $held) {
            $locks .= $rows([$name, $held['owner'] ?? '?', round(($held['ttl_ms'] ?? -1) / 1000) . 's']);
        }
        if ($locks === '') { $locks = '<tr><td colspan="3" class="empty">no locks held</td></tr>'; }

        $boards = '';
        foreach (($status['boards'] ?? []) as $scope => $count) {
            $boards .= $rows([$scope, $count]);
        }
        if ($boards === '') { $boards = '<tr><td colspan="2" class="empty">no board entries</td></tr>'; }

        $events = '';
        foreach ($status['recent_events'] ?? [] as $ev) {
            $events .= $rows([$ev['ts'] ?? '', $ev['agent'] ?? '', $ev['type'] ?? '', json_encode($ev['data'] ?? [], JSON_UNESCAPED_SLASHES)]);
        }
        if ($events === '') { $events = '<tr><td colspan="4" class="empty">no events</td></tr>'; }

        $queues = '';
        foreach (($status['queues'] ?? []) as $scope => $depth) {
            $queues .= $rows([$scope, $depth]);
        }
        if ($queues === '') { $queues = '<tr><td colspan="2" class="empty">no queues</td></tr>'; }

        $at = self::e($status['now'] ?? '');
        return <<<HTML
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta http-equiv="refresh" content="30">
<title>Continuum board status</title>
<style>
body{font:14px/1.4 system-ui,sans-serif;margin:2rem;color:#1a1a1a}
h1{font-size:1.3rem} h2{font-size:1rem;margin-top:1.5rem}
table{border-collapse:collapse;width:100%}
td,th{border:1px solid #ccc;padding:.25rem .5rem;text-align:left;font-size:.85rem}
th{background:#eee} .empty{color:#888;font-style:italic}
.muted{color:#666;font-size:.8rem}
</style></head><body>
<h1>Continuum — board status <span class="muted">({$at}, refreshes every 30s)</span></h1>
<h2>Agents</h2>
<table><tr><th>agent</th><th>label</th><th>capabilities</th><th>working on</th><th>last seen</th></tr>{$agents}</table>
<h2>Open tasks</h2>
<table><tr><th>id</th><th>pri</th><th>status</th><th>owner</th><th>scope</th><th>title</th></tr>{$tasks}</table>
<h2>Advisory locks</h2>
<table><tr><th>name</th><th>owner</th><th>ttl</th></tr>{$locks}</table>
<h2>Board scopes</h2>
<table><tr><th>scope</th><th>keys</th></tr>{$boards}</table>
<h2>Queues</h2>
<table><tr><th>scope</th><th>pending</th></tr>{$queues}</table>
<h2>Recent events</h2>
<table><tr><th>ts</th><th>agent</th><th>type</th><th>data</th></tr>{$events}</table>
</body></html>
HTML;
    }
}
