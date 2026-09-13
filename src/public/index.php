<?php
/**
 * Continuum - HTTP Endpoint
 *
 * Handles MCP protocol requests over Streamable HTTP transport.
 * Implements the MCP specification (2025-03-26) for HTTP-based communication.
 *
 * All requests require a per-agent API key configured in settings.ini [agents].
 *
 * @see https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http
 */

chdir(__DIR__ . '/..');
require_once 'includes/bootstrap.inc.php';

use EnchiladaMCP\McpServer;
use Enchilada\Tortilla\HttpSseTransport;
use Continuum\ServerTools;
use Continuum\BoardTools;
use Continuum\TaskTools;
use Continuum\LockTools;
use Continuum\ContextTools;
use Continuum\AgentTools;
use Continuum\MessageTools;
use Continuum\EventTools;
use Continuum\StatusTools;
use Continuum\MemoryTools;
use Continuum\Dashboard;
use Continuum\Storage\ContinuumStorage;
use Continuum\Bridge\NullMilestoneSyncAdapter;
use Continuum\Bridge\HeliofaneMcpBridge;
use Continuum\Bridge\EmbeddingProvider;
use Continuum\Bridge\EmbeddingRanker;
use Continuum\Storage\MetricsStore;
use Continuum\Storage\MetricsCollector;
use Continuum\Storage\PrometheusExporter;
use Continuum\Storage\RespClient;

// Health probe (unauthenticated; no sensitive data)
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($requestUri === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'server' => APPLICATION_NAME, 'version' => APPLICATION_VERSION]);
    exit;
}

// Per-agent API-key auth. Accepts "X-Api-Key: <key>" or "Authorization: Bearer <key>".
global $SETTINGS;
$agents = $SETTINGS ? ($SETTINGS->toArray()['agents'] ?? []) : [];
$presented = $_SERVER['HTTP_X_API_KEY'] ?? null;
if ($presented === null && preg_match('/^Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $m)) {
    $presented = $m[1];
}
$agentName = null;
if ($presented !== null && $presented !== '') {
    foreach ($agents as $name => $key) {
        if (is_string($key) && hash_equals($key, $presented)) { $agentName = $name; break; }
    }
}
if ($agentName === null) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'missing or invalid API key']);
    exit;
}
define('CONTINUUM_AGENT', $agentName);

// Create server and register tools
$server = new McpServer(APPLICATION_NAME, APPLICATION_VERSION);

$instructionsFile = APPLICATION_CONFDIR . 'instructions.txt';
if (file_exists($instructionsFile)) {
    $server->setInstructions(file_get_contents($instructionsFile));
}

$server->setTitle(APPLICATION_NAME)
    ->setDescription(APPLICATION_DESCRIPTION)
    ->setWebsiteUrl(APPLICATION_WEBSITE);

// Tool surface rides on the storage facade. ensureSchema is idempotent
// and runs per request (PHP userland state resets between requests).
$storage = ContinuumStorage::fromSettings($SETTINGS);
$storage->ensureSchema();

$metricsStore = new MetricsStore(new RespClient(
    $SETTINGS->getString('valkey', 'host', '127.0.0.1'),
    $SETTINGS->getInt('valkey', 'port', 6379)
));

// Prometheus scrape endpoint (authenticated; no secrets in the payload).
if ($requestUri === '/metrics' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
    echo (new PrometheusExporter($storage, $metricsStore))->render();
    exit;
}

// Read-only human dashboard (authenticated like everything else).
if ($requestUri === '/' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo Dashboard::render((new StatusTools($storage))->board_status());
    exit;
}

// Milestone sync is adapter-based; only the no-op adapter exists until a
// tracker integration ships. Unknown values are a hard config error.
$milestoneAdapter = new NullMilestoneSyncAdapter();
$milestoneName = $SETTINGS?->getString('milestones', 'adapter', 'none') ?? 'none';
if ($milestoneName !== 'none') {
    throw new \RuntimeException("unknown milestones adapter '{$milestoneName}' (only 'none' is built)");
}

// Optional bridges: semantic ranking + long-term memory promotion.
$ranker = null;
$embeddingsUrl = trim($SETTINGS?->getString('embeddings', 'url', '') ?? '');
if ($embeddingsUrl !== '') {
    $ranker = new EmbeddingRanker(
        new EmbeddingProvider($embeddingsUrl),
        $SETTINGS->getString('embeddings', 'model', 'all-MiniLM-L6-v2'),
        $SETTINGS->getString('embeddings', 'api_key', '')
    );
}
$heliofaneBridge = null;
$heliofaneUrl = trim($SETTINGS?->getString('heliofane', 'url', '') ?? '');
if ($heliofaneUrl !== '') {
    $heliofaneBridge = new HeliofaneMcpBridge(
        $heliofaneUrl,
        $SETTINGS->getString('heliofane', 'api_key', ''),
        $SETTINGS->getString('heliofane', 'mcp_path', 'mcp')
    );
}

$server->register(new ServerTools());
$server->register(new BoardTools($storage));
$server->register(new TaskTools($storage, $milestoneAdapter));
$server->register(new LockTools($storage));
$server->register(new ContextTools($storage, $ranker));
$server->register(new AgentTools($storage));
$server->register(new MessageTools($storage));
$server->register(new EventTools($storage));
$server->register(new StatusTools($storage));
$server->register(new MemoryTools($storage, $heliofaneBridge));

// Request metrics: per-tool call counter + duration, flushed to ValKey
// at end of request (flush failures never propagate).
$requestStart = MetricsCollector::startTimer();
$rpcBody = json_decode((string)file_get_contents('php://input'), true);
MetricsCollector::increment('mcp_requests_total');
if (($rpcBody['method'] ?? null) === 'tools/call' && isset($rpcBody['params']['name'])) {
    MetricsCollector::increment('tool_calls_total', ['tool' => (string)$rpcBody['params']['name']]);
}

$transport = new HttpSseTransport(
    $server->handleRequest(...),
    $server->modernVersions(),
    $server->legacyVersions(),
);

register_shutdown_function(function () use ($requestStart, $metricsStore) {
    MetricsCollector::observeDuration('http_request_duration', $requestStart);
    MetricsCollector::flush($metricsStore);
});

// DNS-rebinding defense: browser-originated requests are refused unless
// their Origin is allow-listed here. Non-browser MCP clients are unaffected.
$allowedOriginsRaw = $SETTINGS ? $SETTINGS->getString('server', 'allowed_origins', '') : '';
if ($allowedOriginsRaw !== '') {
    $transport->setAllowedOrigins(array_map('trim', explode(',', $allowedOriginsRaw)));
}

$transport->handle();
