<?php

declare(strict_types=1);

namespace Devkit\LogSummariser\Flow;

final readonly class FlowSignals
{
    /**
     * @param array<string, string> $raw extra notable context values for display
     */
    public function __construct(
        public ?string $requestId = null,
        public ?string $correlationId = null,
        public ?string $traceId = null,
        public ?string $jobUuid = null,
        public ?string $jobClass = null,
        public ?string $batchId = null,
        public ?string $commandName = null,
        public ?string $route = null,
        public ?string $url = null,
        public ?string $method = null,
        public ?string $userId = null,
        public ?string $tenantId = null,
        public array $raw = [],
    ) {
    }

    public function hasAnyStrongId(): bool
    {
        return $this->requestId !== null
            || $this->correlationId !== null
            || $this->traceId !== null
            || $this->jobUuid !== null
            || $this->batchId !== null;
    }
}
