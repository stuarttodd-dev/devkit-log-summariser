<?php

declare(strict_types=1);

namespace Devkit\LogSummariser\Report;

use Devkit\LogSummariser\ErrorGroup;
use Devkit\LogSummariser\Flow\FlowRenderer;
use Devkit\LogSummariser\Flow\LogFlow;

final class TextReportRenderer
{
    public function __construct(
        private readonly FlowRenderer $flowRenderer = new FlowRenderer(),
    ) {
    }

    /**
     * @param list<ErrorGroup> $groups
     * @param list<LogFlow>|null $flows
     */
    public function render(array $groups, ?array $flows = null, bool $flowDetail = false): string
    {
        $base = $this->renderGroups($groups);
        if ($flows === null) {
            return $base;
        }

        return $base . "\n" . $this->flowRenderer->renderText($flows, $flowDetail);
    }

    /**
     * @param list<ErrorGroup> $groups
     */
    private function renderGroups(array $groups): string
    {
        $lines = [];
        foreach ($groups as $group) {
            $label = $this->formatHeadline($group);
            $occurrenceWord = $group->occurrenceCount === 1 ? 'occurrence' : 'occurrences';
            $lines[] = sprintf('%s — %d %s', $label, $group->occurrenceCount, $occurrenceWord);
            $lines[] = sprintf(
                '  First: %s  Last: %s',
                $this->formatDate($group->firstOccurredAt),
                $this->formatDate($group->lastOccurredAt),
            );
            if ($group->stackSamples !== []) {
                $top = $group->stackSamples[0];
                $lines[] = '  Stack (most common duplicate):';
                $stackLines = preg_split('/\r\n|\r|\n/', $top['trace']) ?: [];
                foreach (array_slice($stackLines, 0, 12) as $sl) {
                    $lines[] = '    ' . $sl;
                }

                if (count($stackLines) > 12) {
                    $lines[] = '    …';
                }

                if ($top['count'] < $group->occurrenceCount) {
                    $lines[] = sprintf('  (%d entries shared this stack)', $top['count']);
                }
            }

            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
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
