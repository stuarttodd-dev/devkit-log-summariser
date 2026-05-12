<?php

declare(strict_types=1);

namespace Devkit\LogSummariser\Flow;

use Devkit\LogSummariser\ParsedLogEntry;

final class FlowSignalExtractor
{
    /** @var array<string, list<string>> */
    private const array CANONICAL_KEYS = [
        'requestId'     => ['request_id', 'requestid', 'request.id', 'requestId'],
        'correlationId' => ['correlation_id', 'correlationid', 'correlation.id', 'correlationId', 'x-correlation-id'],
        'traceId'       => ['trace_id', 'traceid', 'trace.id', 'traceId', 'x-trace-id'],
        'jobUuid'       => ['job.uuid', 'job_uuid', 'jobuuid', 'job.id', 'uuid'],
        'jobClass'      => ['job.name', 'job.class', 'jobname', 'jobclass', 'job'],
        'batchId'       => ['batch_id', 'batchid', 'batch.id', 'batchid'],
        'commandName'   => ['command', 'command.name', 'commandname'],
        'route'         => ['route', 'route.name', 'route.uri'],
        'url'           => ['url', 'request.url', 'uri'],
        'method'        => ['method', 'request.method', 'http.method'],
        'userId'        => ['user_id', 'userid', 'user.id', 'auth.user_id', 'userid'],
        'tenantId'      => ['tenant_id', 'tenantid', 'tenant.id', 'account_id', 'accountid', 'account.id'],
    ];

    public function extract(ParsedLogEntry $entry): FlowSignals
    {
        $context = $this->lowercaseKeys($entry->context);
        $picked = [];
        foreach (self::CANONICAL_KEYS as $field => $candidates) {
            $picked[$field] = $this->firstMatch($context, $candidates);
        }

        $message = $entry->message;

        $picked['jobUuid'] ??= $this->scanJobUuid($message);
        $picked['jobClass'] ??= $this->scanJobClass($message);
        $picked['commandName'] ??= $this->scanCommand($message);
        $picked['route'] ??= $this->scanRoute($message);
        $picked['url'] ??= $this->scanUrl($message);

        return new FlowSignals(
            requestId:     $picked['requestId'],
            correlationId: $picked['correlationId'],
            traceId:       $picked['traceId'],
            jobUuid:       $picked['jobUuid'],
            jobClass:      $picked['jobClass'],
            batchId:       $picked['batchId'],
            commandName:   $picked['commandName'],
            route:         $picked['route'],
            url:           $picked['url'],
            method:        $picked['method'],
            userId:        $picked['userId'],
            tenantId:      $picked['tenantId'],
            raw:           $this->displayMap($context, array_keys(self::CANONICAL_KEYS)),
        );
    }

    /**
     * @param array<string, scalar|null> $context
     * @return array<string, string>
     */
    private function lowercaseKeys(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if ($value === null) {
                continue;
            }

            $stringValue = (string) $value;
            if ($stringValue === '') {
                continue;
            }

            $out[strtolower($key)] = $stringValue;
        }

        return $out;
    }

    /**
     * @param array<string, string> $context
     * @param list<string> $candidates
     */
    private function firstMatch(array $context, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $lookup = strtolower($candidate);
            if (isset($context[$lookup])) {
                return $context[$lookup];
            }
        }

        return null;
    }

    private function scanJobUuid(string $message): ?string
    {
        if (
            preg_match(
                '/\b([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\b/i',
                $message,
                $captures
            ) === 1
        ) {
            return $captures[1];
        }

        return null;
    }

    private function scanJobClass(string $message): ?string
    {
        $patterns = [
            '/(?:Processing|Processed|Failed):\s*([A-Za-z_][\w\\\\]+)/',
            '/job\s+\[?([A-Za-z_][\w\\\\]+(?:\\\\[A-Za-z_][\w\\\\]*)+)\]?/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $captures) === 1) {
                return $captures[1];
            }
        }

        return null;
    }

    private function scanCommand(string $message): ?string
    {
        if (preg_match('/Running scheduled command:\s*[\'"]?([^\'"\s][^\'"]*)/i', $message, $captures) === 1) {
            return trim($captures[1]);
        }

        if (preg_match('/^Command\s+([\w:\-]+)\s/', $message, $captures) === 1) {
            return $captures[1];
        }

        return null;
    }

    private function scanRoute(string $message): ?string
    {
        if (preg_match('/Route\s+\[?([^\s\]]+)\]?/i', $message, $captures) === 1) {
            return $captures[1];
        }

        return null;
    }

    private function scanUrl(string $message): ?string
    {
        if (preg_match('/(https?:\/\/[^\s\'"]+)/i', $message, $captures) === 1) {
            return $captures[1];
        }

        return null;
    }

    /**
     * @param array<string, string> $context
     * @param list<string> $consumedFields
     * @return array<string, string>
     */
    private function displayMap(array $context, array $consumedFields): array
    {
        $consumed = [];
        foreach ($consumedFields as $field) {
            foreach (self::CANONICAL_KEYS[$field] ?? [] as $key) {
                $consumed[strtolower($key)] = true;
            }
        }

        $out = [];
        foreach ($context as $key => $value) {
            if (isset($consumed[$key])) {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
