<?php

declare(strict_types=1);

namespace Devkit\LogSummariser\Flow;

use DateTimeImmutable;
use Devkit\LogSummariser\ParsedLogEntry;

final class FlowRenderer
{
    public function __construct(
        private readonly FlowSummary $summary = new FlowSummary(),
    ) {
    }

    /**
     * @param list<LogFlow> $flows
     */
    public function renderText(array $flows, bool $withDetail): string
    {
        if ($flows === []) {
            return "No flows detected.\n";
        }

        $lines = ['Flows', '====='];
        foreach ($flows as $flow) {
            $lines[] = '';
            foreach ($this->renderOne($flow, $withDetail) as $line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    public function renderOne(LogFlow $flow, bool $withDetail): array
    {
        $lines = [];
        $lines[] = 'Flow: ' . $flow->type;
        $lines[] = $this->summary->headline($flow);

        foreach ($this->identityLines($flow) as $line) {
            $lines[] = $line;
        }

        $lines[] = sprintf(
            'Started: %s   Ended: %s   Duration: %s',
            $this->formatDate($flow->startedAt),
            $this->formatDate($flow->endedAt),
            $this->formatDuration($flow->durationSeconds),
        );
        $lines[] = sprintf(
            'Entries: %d   Levels: %s',
            $flow->entryCount,
            $flow->levels === [] ? '—' : implode(', ', $flow->levels),
        );
        $lines[] = sprintf('Confidence: %s   Reason: %s', $flow->confidence, $flow->confidenceReason);

        $mainIssue = $this->summary->mainIssue($flow);
        if ($mainIssue !== null) {
            $lines[] = 'Main issue: ' . $mainIssue;
        }

        $lines[] = 'Suggested action: ' . $this->summary->suggestedAction($flow);

        if ($withDetail) {
            $lines[] = 'Entries:';
            foreach ($flow->entries as $entry) {
                $lines[] = '  ' . $this->renderEntryLine($entry);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function identityLines(LogFlow $flow): array
    {
        $ctx = $flow->contextValues;
        $lines = [];
        $map = [
            'jobUuid'       => 'Job UUID',
            'batchId'       => 'Batch ID',
            'requestId'     => 'Request ID',
            'correlationId' => 'Correlation ID',
            'traceId'       => 'Trace ID',
            'userId'        => 'User ID',
            'tenantId'      => 'Tenant ID',
        ];
        foreach ($map as $field => $label) {
            if (isset($ctx[$field])) {
                $lines[] = $label . ': ' . $ctx[$field];
            }
        }

        return $lines;
    }

    private function renderEntryLine(ParsedLogEntry $entry): string
    {
        $time = $entry->occurredAt instanceof DateTimeImmutable
            ? $entry->occurredAt->format('Y-m-d H:i:s')
            : 'n/a';
        $level = $entry->level !== '' ? $entry->level : '-';
        $msg = preg_replace('/\s+/', ' ', $entry->message) ?? $entry->message;
        if (strlen($msg) > 160) {
            $msg = substr($msg, 0, 160) . '…';
        }

        return sprintf('[%s] %s: %s', $time, $level, $msg);
    }

    private function formatDate(?DateTimeImmutable $dateTime): string
    {
        return $dateTime instanceof DateTimeImmutable
            ? $dateTime->format('Y-m-d H:i:s')
            : 'n/a';
    }

    private function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return 'n/a';
        }

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $remainder = $seconds % 60;

        return sprintf('%dm %ds', $minutes, $remainder);
    }
}
