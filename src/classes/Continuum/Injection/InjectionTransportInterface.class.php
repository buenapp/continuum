<?php

namespace Continuum\Injection;

/**
 * A live session delivery transport.
 *
 * Implementations must confirm the remote accepted an injection before
 * returning from inject(). Liveness is deliberately separate so the manager
 * never interrupts or posts to a session which is known to be unreachable.
 */
interface InjectionTransportInterface {
    public const RUNNING = 'running';
    public const IDLE = 'idle';
    public const UNREACHABLE = 'unreachable';

    public function transportName(): string;

    /** @return self::RUNNING|self::IDLE|self::UNREACHABLE */
    public function liveness(string $sessionId): string;

    /**
     * @param array $envelope The unchanged Continuum message envelope.
     * @param string $preemption wakeup|steer|interrupt
     * @param string $liveness The state returned by liveness().
     * @return array Transport-specific, non-secret acknowledgement metadata.
     * @throws TransportException When the remote did not accept the message.
     */
    public function inject(string $sessionId, array $envelope, string $preemption, string $liveness): array;
}
