<?php

declare(strict_types=1);

namespace Devkit\LogSummariser;

use RuntimeException;

/**
 * Line-oriented parser for mixed PHP error output (e.g. php-fpm, CLI, or plain error_log lines)
 * without Laravel channel.LEVEL prefix. Groups lines from an "error" line until the next error.
 */
final readonly class GenericPhpLogParser implements LogParserInterface
{
    public function __construct(
        private BlockToParsedEntryBuilder $entryBuilder = new BlockToParsedEntryBuilder(),
    ) {
    }

    #[\Override]
    public function parseFile(string $path): \Generator
    {
        if (! is_readable($path)) {
            throw new RuntimeException('Cannot read log file: ' . $path);
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Cannot read log file: ' . $path);
        }

        try {
            $currentBlock = [];
            $inBlock = false;

            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                $isError = $this->isGenericErrorLine($line);

                if ($isError && $inBlock) {
                    $block = implode("\n", $currentBlock);
                    if (trim($block) !== '') {
                        yield $this->entryBuilder->buildFromBlock($block);
                    }

                    $currentBlock = [$line];
                    continue;
                }

                if ($isError) {
                    $inBlock = true;
                    $currentBlock = [$line];
                    continue;
                }

                if ($inBlock) {
                    $currentBlock[] = $line;
                }
            }

            if ($inBlock) {
                $block = implode("\n", $currentBlock);
                if (trim($block) !== '') {
                    yield $this->entryBuilder->buildFromBlock($block);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    #[\Override]
    public function parseString(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $errorLineIndices = $this->findErrorLineIndices($lines);
        if ($errorLineIndices === []) {
            return [];
        }

        $entries = [];
        $count = count($errorLineIndices);
        for ($i = 0; $i < $count; $i++) {
            $start = $errorLineIndices[$i];
            $end = ($i + 1 < $count) ? $errorLineIndices[$i + 1] : count($lines);
            $blockLines = array_slice($lines, $start, $end - $start);
            $block = implode("\n", $blockLines);
            if (trim($block) === '') {
                continue;
            }

            $entries[] = $this->entryBuilder->buildFromBlock($block);
        }

        return $entries;
    }

    /**
     * @param list<string> $lines
     * @return list<int>
     */
    private function findErrorLineIndices(array $lines): array
    {
        $indices = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }

            if ($this->isGenericErrorLine($line)) {
                $indices[] = (int) $i;
            }
        }

        return $indices;
    }

    private function isGenericErrorLine(string $line): bool
    {
        $trimmed = ltrim($line);
        if ($trimmed === '') {
            return false;
        }

        $tests = [
            '/\bPHP (?:Fatal|Parse) error:/i',
            '/\bPHP (?:Warning|Notice|Deprecated)\s*:/i',
            '/^Uncaught /',
            '/SQLSTATE\[/',
            "/^exception '/i",
        ];

        foreach ($tests as $p) {
            if (preg_match($p, $trimmed) === 1) {
                return true;
            }
        }

        $exceptionOrBuiltin = '/^(?:[A-Za-z_][\w\\\\]*Exception|TypeError|Error|ParseError|'
            . 'ArgumentCountError|ValueError|PDOException):/i';
        if (preg_match($exceptionOrBuiltin, $trimmed) === 1) {
            return true;
        }

        return str_contains($trimmed, 'Undefined array key') || str_contains($trimmed, 'Undefined index');
    }
}
