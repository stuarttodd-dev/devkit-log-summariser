<?php

declare(strict_types=1);

namespace Devkit\LogSummariser\Flow;

/**
 * Decides which signal "wins" for an entry, the implied flow type,
 * and a confidence score. Pure logic — no state.
 */
final class FlowDetector
{
    public const string TYPE_REQUEST   = 'request';
    public const string TYPE_QUEUE_JOB = 'queue-job';
    public const string TYPE_COMMAND   = 'command';
    public const string TYPE_WEBHOOK   = 'webhook';
    public const string TYPE_IMPORT    = 'import';
    public const string TYPE_UNKNOWN   = 'unknown';

    public const string CONFIDENCE_HIGH   = 'high';
    public const string CONFIDENCE_MEDIUM = 'medium';
    public const string CONFIDENCE_LOW    = 'low';

    /**
     * @return array{key: string, type: string, confidence: string, reason: string}|null
     *         null when no usable signal is present.
     */
    public function detect(FlowSignals $signals): ?array
    {
        if ($signals->jobUuid !== null) {
            return $this->result(
                $this->keyFor('job_uuid', $signals->jobUuid),
                $this->classifyJob($signals),
                self::CONFIDENCE_HIGH,
                $signals->jobClass !== null
                    ? 'grouped by job.uuid (with matching job class)'
                    : 'grouped by job.uuid',
            );
        }

        if ($signals->batchId !== null) {
            return $this->result(
                $this->keyFor('batch_id', $signals->batchId),
                self::TYPE_QUEUE_JOB,
                self::CONFIDENCE_HIGH,
                'grouped by batch_id',
            );
        }

        if ($signals->requestId !== null) {
            return $this->result(
                $this->keyFor('request_id', $signals->requestId),
                $this->classifyRequest($signals),
                self::CONFIDENCE_HIGH,
                'grouped by request_id',
            );
        }

        if ($signals->correlationId !== null) {
            return $this->result(
                $this->keyFor('correlation_id', $signals->correlationId),
                $this->classifyRequest($signals),
                self::CONFIDENCE_HIGH,
                'grouped by correlation_id',
            );
        }

        if ($signals->traceId !== null) {
            return $this->result(
                $this->keyFor('trace_id', $signals->traceId),
                $this->classifyRequest($signals),
                self::CONFIDENCE_HIGH,
                'grouped by trace_id',
            );
        }

        if ($signals->commandName !== null) {
            return $this->result(
                $this->keyFor('command', $signals->commandName),
                self::TYPE_COMMAND,
                self::CONFIDENCE_HIGH,
                'grouped by command name',
            );
        }

        if ($signals->jobClass !== null) {
            return $this->result(
                $this->keyFor('job_class', $signals->jobClass),
                self::TYPE_QUEUE_JOB,
                self::CONFIDENCE_MEDIUM,
                'grouped by job class (no uuid)',
            );
        }

        if ($signals->route !== null) {
            return $this->result(
                $this->keyFor('route', $signals->route),
                $this->classifyRequest($signals),
                self::CONFIDENCE_MEDIUM,
                'grouped by route (no request_id)',
            );
        }

        if ($signals->url !== null) {
            return $this->result(
                $this->keyFor('url', $signals->url),
                $this->classifyRequest($signals),
                self::CONFIDENCE_MEDIUM,
                'grouped by url (no request_id)',
            );
        }

        return null;
    }

    /**
     * @return array{key: string, type: string, confidence: string, reason: string}
     */
    public function detectForcedKey(FlowSignals $signals, string $key): ?array
    {
        $value = match ($key) {
            'request_id'      => $signals->requestId,
            'correlation_id'  => $signals->correlationId,
            'trace_id'        => $signals->traceId,
            'job_uuid'        => $signals->jobUuid,
            'batch_id'        => $signals->batchId,
            'command'         => $signals->commandName,
            'route'           => $signals->route,
            'user_id'         => $signals->userId,
            'tenant_id'       => $signals->tenantId,
            default           => null,
        };

        if ($value === null) {
            return null;
        }

        return $this->result(
            $this->keyFor($key, $value),
            $this->classifyForKey($key, $signals),
            self::CONFIDENCE_HIGH,
            'pinned by --group-by=' . $key,
        );
    }

    private function classifyJob(FlowSignals $signals): string
    {
        $class = $signals->jobClass ?? '';
        if (stripos($class, 'Import') !== false || stripos($class, 'CsvImport') !== false) {
            return self::TYPE_IMPORT;
        }

        if (stripos($class, 'Webhook') !== false) {
            return self::TYPE_WEBHOOK;
        }

        return self::TYPE_QUEUE_JOB;
    }

    private function classifyRequest(FlowSignals $signals): string
    {
        $hay = strtolower(($signals->route ?? '') . ' ' . ($signals->url ?? ''));
        if ($hay !== ' ' && (str_contains($hay, 'webhook') || str_contains($hay, '/hook'))) {
            return self::TYPE_WEBHOOK;
        }

        if ($hay !== ' ' && str_contains($hay, 'import')) {
            return self::TYPE_IMPORT;
        }

        return self::TYPE_REQUEST;
    }

    private function classifyForKey(string $key, FlowSignals $signals): string
    {
        return match ($key) {
            'job_uuid', 'batch_id'       => $this->classifyJob($signals),
            'command'                     => self::TYPE_COMMAND,
            'request_id', 'correlation_id', 'trace_id', 'route' => $this->classifyRequest($signals),
            default                       => self::TYPE_UNKNOWN,
        };
    }

    private function keyFor(string $signalName, string $value): string
    {
        return $signalName . ':' . $value;
    }

    /**
     * @return array{key: string, type: string, confidence: string, reason: string}
     */
    private function result(string $key, string $type, string $confidence, string $reason): array
    {
        return ['key' => $key, 'type' => $type, 'confidence' => $confidence, 'reason' => $reason];
    }
}
