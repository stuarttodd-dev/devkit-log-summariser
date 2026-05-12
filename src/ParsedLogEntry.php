<?php

declare(strict_types=1);

namespace Devkit\LogSummariser;

use DateTimeImmutable;

final readonly class ParsedLogEntry
{
    /**
     * @param array<string, scalar|null> $context flattened, dotted keys
     */
    public function __construct(
        public ?DateTimeImmutable $occurredAt,
        public string $exceptionClass,
        public string $message,
        public string $stackTrace,
        public string $level = '',
        public string $channel = '',
        public array $context = [],
    ) {
    }
}
