<?php

declare(strict_types=1);

namespace Devkit\LogSummariser\Flow;

use DateTimeImmutable;
use Devkit\LogSummariser\ParsedLogEntry;

/**
 * Walks a list of ParsedLogEntry and produces LogFlow instances.
 * Pure data; no I/O.
 */
final class FlowGrouper
{
    public function __construct(
        private readonly FlowSignalExtractor $extractor = new FlowSignalExtractor(),
        private readonly FlowDetector $detector = new FlowDetector(),
    ) {
    }

    /**
     * @param list<ParsedLogEntry> $entries
     * @return list<LogFlow>
     */
    public function group(array $entries, ?string $forcedKey = null): array
    {
        /**
         * @var array<string, array{
         *   key: string,
         *   type: string,
         *   confidence: string,
         *   reason: string,
         *   entries: list<ParsedLogEntry>,
         *   signals: list<FlowSignals>
         * }> $bucketsByKey
         */
        $bucketsByKey = [];
        $unmatched = [];

        foreach ($entries as $entry) {
            $signals = $this->extractor->extract($entry);
            $detected = $forcedKey === null
                ? $this->detector->detect($signals)
                : $this->detector->detectForcedKey($signals, $forcedKey);

            if ($detected === null) {
                if ($forcedKey === null) {
                    $unmatched[] = ['entry' => $entry, 'signals' => $signals];
                }

                continue;
            }

            $bucketKey = $detected['key'];
            if (! isset($bucketsByKey[$bucketKey])) {
                $bucketsByKey[$bucketKey] = [
                    'key' => $bucketKey,
                    'type' => $detected['type'],
                    'confidence' => $detected['confidence'],
                    'reason' => $detected['reason'],
                    'entries' => [],
                    'signals' => [],
                ];
            }

            $bucketsByKey[$bucketKey]['entries'][] = $entry;
            $bucketsByKey[$bucketKey]['signals'][] = $signals;
        }

        if ($forcedKey === null && $unmatched !== []) {
            $this->absorbUnmatched($bucketsByKey, $unmatched);
        }

        $flows = [];
        $index = 0;
        foreach ($bucketsByKey as $bucket) {
            $flows[] = $this->buildFlow($bucket, $index++);
        }

        $sortByStart = static function (LogFlow $a, LogFlow $b): int {
            $left = $a->startedAt?->getTimestamp() ?? 0;
            $right = $b->startedAt?->getTimestamp() ?? 0;

            return $left <=> $right;
        };
        usort($flows, $sortByStart);

        return $flows;
    }

    /**
     * @param array<string, array{
     *   key: string, type: string, confidence: string, reason: string,
     *   entries: list<ParsedLogEntry>, signals: list<FlowSignals>
     * }> $bucketsByKey
     * @param list<array{entry: ParsedLogEntry, signals: FlowSignals}> $unmatched
     */
    private function absorbUnmatched(array &$bucketsByKey, array $unmatched): void
    {
        $window = 30;

        foreach ($unmatched as $item) {
            $entry = $item['entry'];
            $attached = false;
            foreach ($bucketsByKey as &$bucket) {
                if ($this->bucketContains($bucket, $entry, $window)) {
                    $bucket['entries'][] = $entry;
                    $bucket['signals'][] = $item['signals'];
                    if ($bucket['confidence'] === FlowDetector::CONFIDENCE_HIGH) {
                        $bucket['confidence'] = FlowDetector::CONFIDENCE_MEDIUM;
                        $bucket['reason'] .= '; absorbed nearby entry by time proximity';
                    }

                    $attached = true;
                    break;
                }
            }

            unset($bucket);

            if (! $attached) {
                $stub = $this->stubKey($entry);
                if (! isset($bucketsByKey[$stub])) {
                    $bucketsByKey[$stub] = [
                        'key' => $stub,
                        'type' => FlowDetector::TYPE_UNKNOWN,
                        'confidence' => FlowDetector::CONFIDENCE_LOW,
                        'reason' => 'grouped by exception fingerprint',
                        'entries' => [],
                        'signals' => [],
                    ];
                }

                $bucketsByKey[$stub]['entries'][] = $entry;
                $bucketsByKey[$stub]['signals'][] = $item['signals'];
            }
        }
    }

    /**
     * @param array{entries: list<ParsedLogEntry>, signals: list<FlowSignals>} $bucket
     */
    private function bucketContains(array $bucket, ParsedLogEntry $candidate, int $windowSeconds): bool
    {
        if (! $candidate->occurredAt instanceof DateTimeImmutable) {
            return false;
        }

        foreach ($bucket['entries'] as $existing) {
            if (! $existing->occurredAt instanceof DateTimeImmutable) {
                continue;
            }

            $delta = abs($existing->occurredAt->getTimestamp() - $candidate->occurredAt->getTimestamp());
            if ($delta <= $windowSeconds) {
                return true;
            }
        }

        return false;
    }

    private function stubKey(ParsedLogEntry $entry): string
    {
        return 'fingerprint:' . $entry->exceptionClass . '|' . substr(sha1($entry->message), 0, 12);
    }

    /**
     * @param array{
     *   key: string, type: string, confidence: string, reason: string,
     *   entries: list<ParsedLogEntry>, signals: list<FlowSignals>
     * } $bucket
     */
    private function buildFlow(array $bucket, int $index): LogFlow
    {
        $start = null;
        $end = null;
        $levels = [];
        $fingerprints = [];
        $mainError = null;
        $mainErrorSeverity = -1;

        foreach ($bucket['entries'] as $entry) {
            if ($entry->occurredAt instanceof DateTimeImmutable) {
                if ($start === null || $entry->occurredAt < $start) {
                    $start = $entry->occurredAt;
                }

                if ($end === null || $entry->occurredAt > $end) {
                    $end = $entry->occurredAt;
                }
            }

            if ($entry->level !== '') {
                $levels[$entry->level] = true;
            }

            if ($entry->exceptionClass !== 'Unknown' || $entry->message !== '(empty entry)') {
                $fingerprints[$entry->exceptionClass . "\0" . $this->shortMessage($entry->message)] = true;
            }

            $severity = $this->severityRank($entry->level);
            if ($severity > $mainErrorSeverity && $entry->stackTrace !== '') {
                $mainError = $entry;
                $mainErrorSeverity = $severity;
            }
        }

        if ($mainError === null) {
            foreach ($bucket['entries'] as $entry) {
                $severity = $this->severityRank($entry->level);
                if ($severity > $mainErrorSeverity) {
                    $mainError = $entry;
                    $mainErrorSeverity = $severity;
                }
            }
        }

        $duration = ($start instanceof DateTimeImmutable && $end instanceof DateTimeImmutable)
            ? $end->getTimestamp() - $start->getTimestamp()
            : null;

        return new LogFlow(
            id: 'flow-' . $index,
            type: $bucket['type'],
            confidence: $bucket['confidence'],
            confidenceReason: $bucket['reason'],
            startedAt: $start,
            endedAt: $end,
            durationSeconds: $duration,
            entryCount: count($bucket['entries']),
            levels: array_keys($levels),
            relatedFingerprints: array_keys($fingerprints),
            mainError: $mainError,
            contextValues: $this->mergeContext($bucket['signals'], $bucket['key']),
            entries: $bucket['entries'],
        );
    }

    /**
     * @param list<FlowSignals> $signalsList
     * @return array<string, string>
     */
    private function mergeContext(array $signalsList, string $bucketKey): array
    {
        $merged = [];
        foreach ($signalsList as $signals) {
            foreach (
                [
                    'requestId' => $signals->requestId,
                    'correlationId' => $signals->correlationId,
                    'traceId' => $signals->traceId,
                    'jobUuid' => $signals->jobUuid,
                    'jobClass' => $signals->jobClass,
                    'batchId' => $signals->batchId,
                    'commandName' => $signals->commandName,
                    'route' => $signals->route,
                    'url' => $signals->url,
                    'method' => $signals->method,
                    'userId' => $signals->userId,
                    'tenantId' => $signals->tenantId,
                ] as $field => $value
            ) {
                if ($value !== null && ! isset($merged[$field])) {
                    $merged[$field] = $value;
                }
            }

            foreach ($signals->raw as $key => $value) {
                if (! isset($merged[$key])) {
                    $merged[$key] = $value;
                }
            }
        }

        $merged['_bucketKey'] = $bucketKey;

        return $merged;
    }

    private function severityRank(string $level): int
    {
        return match (strtoupper($level)) {
            'EMERGENCY', 'EMERG' => 7,
            'ALERT'              => 6,
            'CRITICAL', 'CRIT'   => 5,
            'ERROR', 'ERR'       => 4,
            'WARNING', 'WARN'    => 3,
            'NOTICE'             => 2,
            'INFO'               => 1,
            'DEBUG'              => 0,
            default              => -1,
        };
    }

    private function shortMessage(string $message): string
    {
        return substr(preg_replace('/\s+/', ' ', $message) ?? $message, 0, 200);
    }
}
