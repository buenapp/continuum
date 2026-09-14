<?php

namespace Continuum;

/**
 * Request-scoped MCP session identifier.
 *
 * One API key may back several concurrent agent sessions; presence must
 * not let them clobber each other. The HTTP transport hands each session
 * a `MCP-Session-Id` (available as HTTP_MCP_SESSION_ID on every
 * post-initialize request); stdio synthesizes one per process. Tools use
 * the id to scope presence records; null means an unidentified client
 * (legacy behavior: presence is agent-level only).
 *
 * Set by the entry points (public/index.php, bin/mcp-stdio). PHP request
 * isolation resets the static per request; long-lived transports
 * (stdio, subscription listen) keep one id for the process lifetime.
 */
final class SessionContext {

    private static ?string $id = null;

    public static function set(?string $id): void {
        self::$id = $id;
    }

    public static function id(): ?string {
        return self::$id;
    }
}
