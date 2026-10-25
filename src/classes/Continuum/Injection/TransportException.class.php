<?php

namespace Continuum\Injection;

/** An operational direct-injection failure which is safe to report as a fallback. */
class TransportException extends \RuntimeException {
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, private array $context = []) {
        parent::__construct($message, $code, $previous);
    }

    /** Non-secret facts about the failed operation, such as interrupted=true. */
    public function context(): array { return $this->context; }
}
