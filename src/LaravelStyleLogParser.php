<?php

declare(strict_types=1);

namespace Devkit\LogSummariser;

use RuntimeException;

final readonly class LaravelStyleLogParser implements LogParserInterface
{
    private const string TIMESTAMP_LINE = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/';

    public function __construct(
        private BlockToParsedEntryBuilder $entryBuilder = new BlockToParsedEntryBuilder(),
    ) {
    }

    #[\Override]
    public function parseFile(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException('Cannot read log file: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Cannot read log file: ' . $path);
        }

        return $this->parseString($content);
    }

    #[\Override]
    public function parseString(string $content): array
    {
        $blocks = $this->splitIntoBlocks($content);
        $entries = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $entries[] = $this->entryBuilder->buildFromBlock($block);
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function splitIntoBlocks(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $blocks = [];
        $current = [];

        foreach ($lines as $line) {
            if (preg_match(self::TIMESTAMP_LINE, $line) === 1 && $current !== []) {
                $blocks[] = implode("\n", $current);
                $current = [$line];
                continue;
            }

            $current[] = $line;
        }

        if ($current !== []) {
            $blocks[] = implode("\n", $current);
        }

        return $blocks;
    }
}
