<?php

declare(strict_types=1);

namespace Devkit\LogSummariser;

final readonly class BlockToParsedEntryBuilder
{
    private const string LARAVEL_HEADER
        = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+([^\s]+)\.(\w+):\s*(.*)$/s';

    private const string BRACKET_TIMESTAMP_MSG
        = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(.+)$/s';

    public function __construct(
        private ExceptionExtractor $exceptionExtractor = new ExceptionExtractor(),
        private LogTimestampParser $timestampParser = new LogTimestampParser(),
        private StackTraceSeparator $stackTraceSeparator = new StackTraceSeparator(),
        private LogContextExtractor $contextExtractor = new LogContextExtractor(),
    ) {
    }

    public function buildFromBlock(string $block): ParsedLogEntry
    {
        $lines = preg_split('/\r\n|\r|\n/', $block) ?: [];
        $firstLine = $lines[0] ?? '';

        $occurredAt = null;
        $headMessage = $firstLine;
        $level = '';
        $channel = '';

        if (preg_match(self::LARAVEL_HEADER, $firstLine, $captures) === 1) {
            $occurredAt = $this->timestampParser->parseUtc($captures[1]);
            $channel = $captures[2];
            $level = strtoupper($captures[3]);
            $headMessage = $captures[4];
        } elseif (preg_match(self::BRACKET_TIMESTAMP_MSG, $firstLine, $captures) === 1) {
            $occurredAt = $this->timestampParser->parseUtc($captures[1]);
            $headMessage = $captures[2];
        }

        [$context, $headMessage] = $this->contextExtractor->extract($headMessage);

        $bodyLines = array_slice($lines, 1);
        $body = implode("\n", $bodyLines);
        $stackR = $this->stackTraceSeparator->separate($body);
        $stack = $stackR[0];
        $bodyWithoutStack = $stackR[1];
        $fullText = $headMessage . ($bodyWithoutStack !== '' ? "\n" . $bodyWithoutStack : '');

        [$exceptionClass, $message] = $this->exceptionExtractor->extract($fullText, $headMessage);

        return new ParsedLogEntry(
            $occurredAt,
            $exceptionClass,
            $message,
            $stack,
            $level,
            $channel,
            $context,
        );
    }
}
