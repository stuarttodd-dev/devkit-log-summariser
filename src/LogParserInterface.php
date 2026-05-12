<?php

declare(strict_types=1);

namespace Devkit\LogSummariser;

interface LogParserInterface
{
    /**
     * @return \Generator<ParsedLogEntry>
     */
    public function parseFile(string $path): \Generator;

    /**
     * @return list<ParsedLogEntry>
     */
    public function parseString(string $content): array;
}
