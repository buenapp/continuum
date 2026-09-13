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
use Continuum\Storage\ContinuumStorage;

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

$server->register(new ServerTools());

// Tool surface rides on the storage facade. ensureSchema is idempotent
// and runs per request (PHP userland state resets between requests).
$storage = ContinuumStorage::fromSettings($SETTINGS);
$storage->ensureSchema();

$server->register(new BoardTools($storage));
$server->register(new TaskTools($storage));
$server->register(new LockTools($storage));
$server->register(new ContextTools($storage));

$transport = new HttpSseTransport(
    $server->handleRequest(...),
    $server->modernVersions(),
    $server->legacyVersions(),
);

// DNS-rebinding defense: browser-originated requests are refused unless
// their Origin is allow-listed here. Non-browser MCP clients are unaffected.
$allowedOriginsRaw = $SETTINGS ? $SETTINGS->getString('server', 'allowed_origins', '') : '';
if ($allowedOriginsRaw !== '') {
    $transport->setAllowedOrigins(array_map('trim', explode(',', $allowedOriginsRaw)));
}

$transport->handle();
