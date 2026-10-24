<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * A business rule refused the request. Rendered as JSON: {"message", "code", ...meta}.
 * The machine-readable `code` is what the apps switch on; the message is for humans and logs.
 */
class DomainRuleViolation extends RuntimeException
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $meta */
    public static function tooManyAttempts(string $errorCode, string $message, int $retryAfterSeconds, array $meta = []): self
    {
        return new self($errorCode, $message, 429, ['retry_after' => $retryAfterSeconds] + $meta);
    }
}
