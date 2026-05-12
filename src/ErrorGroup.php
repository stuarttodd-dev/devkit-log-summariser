<?php

declare(strict_types=1);

namespace Devkit\LogSummariser;

use DateTimeImmutable;

final readonly class ErrorGroup
{
    /**
     * @param list<array{trace: string, count: int}> $stackSamples
     */
    public function __construct(
        public string $groupKey,
        public string $exceptionClass,
        public string $message,
        public int $occurrenceCount,
        public ?DateTimeImmutable $firstOccurredAt,
        public ?DateTimeImmutable $lastOccurredAt,
        public array $stackSamples,
    ) {
    }
}
