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

    /** Session id declared by the client as `params._meta.session` (the
     *  agent's own logical session, e.g. Sonya Core's sess_<hex>; takes
     *  precedence over the transport id for message targeting). */
    private static ?string $declared = null;

    public static function set(?string $id): void {
        self::$id = $id;
    }

    public static function id(): ?string {
        return self::$id;
    }

    public static function setDeclared(?string $id): void {
        self::$declared = $id;
    }

    public static function declared(): ?string {
        return self::$declared;
    }

    /** Effective session for message targeting and task binding. */
    public static function session(): ?string {
        return self::$declared ?? self::$id;
    }
}
