<?php

declare(strict_types=1);

namespace Devkit\LogSummariser\Flow;

use DateTimeImmutable;
use Devkit\LogSummariser\ParsedLogEntry;

final readonly class LogFlow
{
    /**
     * @param list<string> $levels
     * @param list<string> $relatedFingerprints
     * @param array<string, string> $contextValues
     * @param list<ParsedLogEntry> $entries
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $confidence,
        public string $confidenceReason,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
        public ?int $durationSeconds,
        public int $entryCount,
        public array $levels,
        public array $relatedFingerprints,
        public ?ParsedLogEntry $mainError,
        public array $contextValues,
        public array $entries,
    ) {
    }
}
