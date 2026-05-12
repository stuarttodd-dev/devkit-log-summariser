<?php

declare(strict_types=1);

namespace Devkit\LogSummariser\Flow;

use Devkit\LogSummariser\ParsedLogEntry;

final class SuggestedActionRules
{
    public function suggest(?ParsedLogEntry $mainError): string
    {
        if (! $mainError instanceof ParsedLogEntry) {
            return 'Investigate the entries in this flow; no exception was identified.';
        }

        $class = $mainError->exceptionClass;
        $message = $mainError->message;
        $hay = $class . ' ' . $message;

        if (
            $this->matches($hay, [
            'ProcessTimedOutException',
            'Maximum execution time',
            'pcntl_alarm',
            ])
        ) {
            return 'Check PHP `max_execution_time`, queue worker `--timeout`, and any external service timeouts.';
        }

        if ($this->matches($hay, ['QueryException', 'PDOException'])) {
            if ($this->matches($hay, ['57014', 'statement timeout', 'lock wait timeout', 'deadlock'])) {
                return 'Check DB statement timeout, slow query, connection pool, and locking.';
            }

            return 'Investigate the failing SQL; check connection, schema, and slow-query log.';
        }

        if ($this->matches($hay, ['cURL error 28', 'ConnectException', 'ConnectionException', 'Timeout was reached'])) {
            return 'Check upstream availability and HTTP client timeout.';
        }

        if ($this->matches($hay, ['MaxAttemptsExceededException', 'Max attempts'])) {
            return 'Check queue retry limits and downstream failure mode.';
        }

        if ($this->matches($hay, ['PDF', 'pdftk', 'snappy', 'wkhtmltopdf', 'image generation'])) {
            return 'Check PDF/image service timeout, memory limit, and queue worker timeout settings.';
        }

        if ($this->matches($hay, ['Allowed memory size', 'Out of memory'])) {
            return 'Check `memory_limit`, payload size, and any unbounded loops or queries.';
        }

        if ($this->matches($hay, ['ValidationException', 'invalid argument'])) {
            return 'Check the incoming payload schema and upstream caller assumptions.';
        }

        return 'Investigate the main error and its stack trace; correlate with recent deploy or config changes.';
    }

    /**
     * @param list<string> $needles
     */
    private function matches(string $haystack, array $needles): bool
    {
        $lowerHay = strtolower($haystack);
        foreach ($needles as $needle) {
            if (str_contains($lowerHay, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
