<?php

declare(strict_types=1);

namespace Devkit\LogSummariser\Report;

use Devkit\LogSummariser\ErrorGroup;
use Devkit\LogSummariser\Flow\FlowSummary;
use Devkit\LogSummariser\Flow\LogFlow;

final class JsonReportRenderer
{
    public function __construct(
        private readonly FlowSummary $flowSummary = new FlowSummary(),
    ) {
    }

    /**
     * @param list<ErrorGroup> $groups
     * @param list<LogFlow>|null $flows null means: do not emit a flows block at all
     */
    public function render(array $groups, ?array $flows = null): string
    {
        $payload = ['groups' => $this->groupsPayload($groups)];
        if ($flows !== null) {
            $payload['flows'] = $this->flowsPayload($flows);
        }

        $json = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $json . "\n";
    }

    /**
     * @param list<ErrorGroup> $groups
     * @return list<array<string, mixed>>
     */
    private function groupsPayload(array $groups): array
    {
        $payload = [];
        foreach ($groups as $group) {
            $payload[] = [
                'exceptionClass' => $group->exceptionClass,
                'message' => $group->message,
                'occurrenceCount' => $group->occurrenceCount,
                'firstOccurredAt' => $group->firstOccurredAt?->format(\DateTimeInterface::ATOM),
                'lastOccurredAt' => $group->lastOccurredAt?->format(\DateTimeInterface::ATOM),
                'stackSamples' => $group->stackSamples,
            ];
        }

        return $payload;
    }

    /**
     * @param list<LogFlow> $flows
     * @return list<array<string, mixed>>
     */
    private function flowsPayload(array $flows): array
    {
        $payload = [];
        foreach ($flows as $flow) {
            $entries = [];
            foreach ($flow->entries as $entry) {
                $entries[] = [
                    'occurredAt' => $entry->occurredAt?->format(\DateTimeInterface::ATOM),
                    'level' => $entry->level,
                    'channel' => $entry->channel,
                    'exceptionClass' => $entry->exceptionClass,
                    'message' => $entry->message,
                ];
            }

            $payload[] = [
                'id' => $flow->id,
                'type' => $flow->type,
                'confidence' => $flow->confidence,
                'confidenceReason' => $flow->confidenceReason,
                'startedAt' => $flow->startedAt?->format(\DateTimeInterface::ATOM),
                'endedAt' => $flow->endedAt?->format(\DateTimeInterface::ATOM),
                'durationSeconds' => $flow->durationSeconds,
                'entryCount' => $flow->entryCount,
                'levels' => $flow->levels,
                'relatedFingerprints' => $flow->relatedFingerprints,
                'headline' => $this->flowSummary->headline($flow),
                'mainIssue' => $this->flowSummary->mainIssue($flow),
                'suggestedAction' => $this->flowSummary->suggestedAction($flow),
                'contextValues' => $flow->contextValues,
                'entries' => $entries,
            ];
        }

        return $payload;
    }
}
