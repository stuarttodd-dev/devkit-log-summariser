<?php

declare(strict_types=1);

namespace Devkit\LogSummariser;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Parses Laravel-style `Y-m-d H:i:s` timestamps for log entries.
 */
final class LogTimestampParser
{
    public function parseUtc(string $ymdHis): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($ymdHis, new DateTimeZone('UTC'));
        } catch (DateMalformedStringException) {
            return null;
        }
    }
}
