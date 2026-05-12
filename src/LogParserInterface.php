<?php

declare(strict_types=1);

namespace Devkit\LogSummariser;

interface LogParserInterface
{
    /**
     * @return list<ParsedLogEntry>
     */
    public function parseFile(string $path): array;

    /**
     * @return list<ParsedLogEntry>
     */
    public function parseString(string $content): array;
}
