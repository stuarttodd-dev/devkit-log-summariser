<?php

declare(strict_types=1);

namespace Devkit\LogSummariser;

use DateTimeImmutable;

final class Summariser
{
    /**
     * @param iterable<ParsedLogEntry> $entries
     * @return list<ErrorGroup>
     */
    public function summarise(iterable $entries, int $limit = 0): array
    {
        $groups = $this->summariseWithoutLimit($entries);
        if ($limit > 0) {
            return array_slice($groups, 0, $limit);
        }

        return $groups;
    }

    /**
     * @param iterable<ParsedLogEntry> $entries
     * @return list<ErrorGroup>
     */
    private function summariseWithoutLimit(iterable $entries): array
    {
        /** @var array<string, array{exceptionClass: string, message: string, count: int, first: ?DateTimeImmutable, last: ?DateTimeImmutable, stacks: array<string, int>}> $buckets */
        $buckets = [];

        foreach ($entries as $entry) {
            $key = $this->groupKey($entry);
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'exceptionClass' => $entry->exceptionClass,
                    'message' => $entry->message,
                    'count' => 0,
                    'first' => $entry->occurredAt,
                    'last' => $entry->occurredAt,
                    'stacks' => [],
                ];
            }

            $buckets[$key]['count']++;
            $buckets[$key]['first'] = $this->minTime($buckets[$key]['first'], $entry->occurredAt);
            $buckets[$key]['last'] = $this->maxTime($buckets[$key]['last'], $entry->occurredAt);

            $normStack = $this->normaliseStack($entry->stackTrace);
            if ($normStack !== '') {
                $buckets[$key]['stacks'][$normStack] = ($buckets[$key]['stacks'][$normStack] ?? 0) + 1;
            }
        }

        $groups = [];
        foreach ($buckets as $key => $data) {
            arsort($data['stacks']);
            $stackSamples = [];
            foreach ($data['stacks'] as $trace => $cnt) {
                $stackSamples[] = ['trace' => $trace, 'count' => $cnt];
            }

            $groups[] = new ErrorGroup(
                $key,
                $data['exceptionClass'],
                $data['message'],
                $data['count'],
                $data['first'],
                $data['last'],
                $stackSamples,
            );
        }

        $sortByCount = static fn (
            ErrorGroup $first,
            ErrorGroup $second,
        ): int => $second->occurrenceCount <=> $first->occurrenceCount;
        usort($groups, $sortByCount);

        return $groups;
    }

    private function groupKey(ParsedLogEntry $entry): string
    {
        $msg = $this->normaliseMessage($entry->message);
        $class = trim($entry->exceptionClass);

        return $class . "\0" . $msg;
    }

    private function normaliseMessage(string $message): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($message)) ?? '';

        return mb_strlen($collapsed) > 500 ? mb_substr($collapsed, 0, 500) : $collapsed;
    }

    private function normaliseStack(string $stack): string
    {
        $stack = trim($stack);
        if ($stack === '') {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $stack) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $out[] = rtrim($line);
        }

        return implode("\n", $out);
    }

    private function minTime(?DateTimeImmutable $left, ?DateTimeImmutable $right): ?DateTimeImmutable
    {
        if (! $left instanceof DateTimeImmutable) {
            return $right;
        }

        if (! $right instanceof DateTimeImmutable) {
            return $left;
        }

        return $left <= $right ? $left : $right;
    }

    private function maxTime(?DateTimeImmutable $left, ?DateTimeImmutable $right): ?DateTimeImmutable
    {
        if (! $left instanceof DateTimeImmutable) {
            return $right;
        }

        if (! $right instanceof DateTimeImmutable) {
            return $left;
        }

        return $left >= $right ? $left : $right;
    }
}
