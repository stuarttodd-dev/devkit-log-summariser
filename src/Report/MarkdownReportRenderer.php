<?php

declare(strict_types=1);

namespace Devkit\LogSummariser\Report;

use Devkit\LogSummariser\ErrorGroup;
use Devkit\LogSummariser\Flow\FlowRenderer;
use Devkit\LogSummariser\Flow\LogFlow;

final readonly class MarkdownReportRenderer
{
    public function __construct(
        private FlowRenderer $flowRenderer = new FlowRenderer(),
    ) {
    }

    /**
     * @param list<ErrorGroup> $groups
     * @param list<LogFlow>|null $flows
     */
    public function render(array $groups, ?array $flows = null): string
    {
        $base = $this->renderGroups($groups);
        if ($flows === null) {
            return $base;
        }

        $lines = ['', '# Flows', ''];
        if ($flows === []) {
            $lines[] = 'No flows detected.';

            return $base . "\n" . implode("\n", $lines) . "\n";
        }

        foreach ($flows as $flow) {
            foreach ($this->flowRenderer->renderOne($flow, false) as $line) {
                $lines[] = $line;
            }

            $lines[] = '';
        }

        return $base . "\n" . rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * @param list<ErrorGroup> $groups
     * @param list<LogFlow> $flows
     */
    public function renderWithFlowDetail(array $groups, array $flows): string
    {
        $base = $this->renderGroups($groups);

        $lines = ['', '# Flows', ''];
        if ($flows === []) {
            $lines[] = 'No flows detected.';

            return $base . "\n" . implode("\n", $lines) . "\n";
        }

        foreach ($flows as $flow) {
            foreach ($this->flowRenderer->renderOne($flow, true) as $line) {
                $lines[] = $line;
            }

            $lines[] = '';
        }

        return $base . "\n" . rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * @param list<ErrorGroup> $groups
     */
    private function renderGroups(array $groups): string
    {
        $lines = ['# Log summary', ''];
        foreach ($groups as $group) {
            $label = $this->formatHeadline($group);
            $occurrenceWord = $group->occurrenceCount === 1 ? 'occurrence' : 'occurrences';
            $lines[] = sprintf('## %s — %d %s', $this->escapeInline($label), $group->occurrenceCount, $occurrenceWord);
            $lines[] = '';
            $lines[] = sprintf(
                '**First:** %s  **Last:** %s',
                $this->formatDate($group->firstOccurredAt),
                $this->formatDate($group->lastOccurredAt),
            );
            $lines[] = '';
            if ($group->stackSamples !== []) {
                $top = $group->stackSamples[0];
                $lines[] = 'Stack (most common duplicate):';
                $lines[] = '';
                $stackLines = preg_split('/\r\n|\r|\n/', $top['trace']) ?: [];
                $snippet = implode("\n", array_slice($stackLines, 0, 12));
                if (count($stackLines) > 12) {
                    $snippet .= "\n…";
                }

                $lines[] = '```';
                $lines[] = $snippet;
                $lines[] = '```';
                $lines[] = '';
                if ($top['count'] < $group->occurrenceCount) {
                    $lines[] = sprintf('*(%d entries shared this stack)*', $top['count']);
                    $lines[] = '';
                }
            }
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    private function escapeInline(string $text): string
    {
        return str_replace(['\\', '`'], ['\\\\', '\\`'], $text);
    }

    private function formatHeadline(ErrorGroup $group): string
    {
        if (str_starts_with($group->message, 'SQLSTATE[')) {
            return $group->message;
        }

        if ($group->exceptionClass === 'Unknown') {
            return $group->message;
        }

        return $group->exceptionClass . ': ' . $group->message;
    }

    private function formatDate(?\DateTimeImmutable $dateTime): string
    {
        if (!$dateTime instanceof \DateTimeImmutable) {
            return 'n/a';
        }

        return $dateTime->format('Y-m-d H:i:s');
    }
}
