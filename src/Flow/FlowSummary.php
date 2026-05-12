<?php

declare(strict_types=1);

namespace Devkit\LogSummariser\Flow;

final readonly class FlowSummary
{
    public function __construct(
        private SuggestedActionRules $rules = new SuggestedActionRules(),
    ) {
    }

    public function headline(LogFlow $flow): string
    {
        $ctx = $flow->contextValues;

        if ($flow->type === FlowDetector::TYPE_QUEUE_JOB || $flow->type === FlowDetector::TYPE_IMPORT) {
            if (isset($ctx['jobClass'])) {
                return 'Job: ' . $ctx['jobClass'];
            }

            return 'Queue job (unidentified)';
        }

        if ($flow->type === FlowDetector::TYPE_COMMAND) {
            return 'Command: ' . ($ctx['commandName'] ?? 'unknown');
        }

        if ($flow->type === FlowDetector::TYPE_REQUEST || $flow->type === FlowDetector::TYPE_WEBHOOK) {
            $verb = $ctx['method'] ?? null;
            $where = $ctx['route'] ?? $ctx['url'] ?? null;
            if ($where !== null) {
                return trim(($verb !== null ? $verb . ' ' : '') . $where);
            }

            return $flow->type === FlowDetector::TYPE_WEBHOOK ? 'Webhook request' : 'HTTP request';
        }

        return 'Untyped flow';
    }

    public function mainIssue(LogFlow $flow): ?string
    {
        if (! $flow->mainError instanceof \Devkit\LogSummariser\ParsedLogEntry) {
            return null;
        }

        $main = $flow->mainError;
        if ($main->exceptionClass === 'Unknown') {
            return $main->message;
        }

        return $main->exceptionClass . ': ' . $main->message;
    }

    public function suggestedAction(LogFlow $flow): string
    {
        return $this->rules->suggest($flow->mainError);
    }
}
