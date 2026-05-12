<?php

declare(strict_types=1);

namespace Devkit\LogSummariser;

final readonly class StackTraceSeparator
{
    /**
     * @return array{0: string, 1: string} stack, bodyWithoutStack
     */
    public function separate(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $stackStart = null;

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*#\d+\s+/', $line) === 1) {
                $stackStart = $i;
                break;
            }

            if (stripos($line, 'Stack trace:') !== false) {
                $stackStart = $i + 1;
                break;
            }
        }

        if ($stackStart === null) {
            return ['', $body];
        }

        $before = array_slice($lines, 0, $stackStart);
        $stackLines = array_slice($lines, $stackStart);
        $stack = trim(implode("\n", $stackLines));
        $remainder = trim(implode("\n", $before));

        return [$stack, $remainder];
    }
}
