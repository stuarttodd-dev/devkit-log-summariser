<?php

declare(strict_types=1);

namespace Devkit\LogSummariser\Console;

use Devkit\LogSummariser\ErrorGroup;
use Devkit\LogSummariser\Flow\FlowGrouper;
use Devkit\LogSummariser\Flow\FlowRenderer;
use Devkit\LogSummariser\Flow\LogFlow;
use Devkit\LogSummariser\GenericPhpLogParser;
use Devkit\LogSummariser\LaravelStyleLogParser;
use Devkit\LogSummariser\LogParserInterface;
use Devkit\LogSummariser\ParsedLogEntry;
use Devkit\LogSummariser\Report\HtmlReportRenderer;
use Devkit\LogSummariser\Report\JsonReportRenderer;
use Devkit\LogSummariser\Report\MarkdownReportRenderer;
use Devkit\LogSummariser\Report\TextReportRenderer;
use Devkit\LogSummariser\Summariser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'summarise',
    description: 'Group and summarise errors from PHP log files (Laravel-style or generic)',
)]
final class SummariseCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('files', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'One or more log file paths')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write report to this file instead of stdout')
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format: text, json, md, or html',
                'text',
            )
            ->addOption(
                'parser',
                'p',
                InputOption::VALUE_REQUIRED,
                'Log parser: laravel (default) or generic (plain PHP errors, one event per line group)',
                'laravel',
            )
            ->addOption(
                'flows',
                null,
                InputOption::VALUE_NONE,
                'Include flow grouping in the report (requires --format=html)',
            )
            ->addOption(
                'flow-detail',
                null,
                InputOption::VALUE_NONE,
                'Include detailed flow entries in text output (ignored for other formats)',
            )
            ->addOption(
                'flow-type',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter flows by type: request, queue-job, command, webhook, import, unknown',
            )
            ->addOption(
                'group-by',
                null,
                InputOption::VALUE_REQUIRED,
                'Force grouping by a specific key: request_id, correlation_id, trace_id, ' .
                'job_uuid, batch_id, command, route, user_id, tenant_id',
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Limit the number of error groups shown (default: no limit)',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $files = $this->collectPaths($input->getArgument('files'));
        if ($files === []) {
            $output->writeln('<error>Provide at least one log file path.</error>');

            return Command::FAILURE;
        }

        $flowsRequested = $input->getOption('flows');
        $flowDetail = (bool) $input->getOption('flow-detail');
        $flowTypeFilter = $input->getOption('flow-type');
        $groupByKey = $input->getOption('group-by');
        $limit = $input->getOption('limit');

        $totalSize = $this->getTotalFileSize($files);
        $maxSafeSize = 50 * 1024 * 1024;
        if ($totalSize > $maxSafeSize) {
            $output->writeln(sprintf(
                '<comment>Warning: combined log size is %s (over ~50MB). '
                . 'Consider smaller files or --limit for a smaller report.</comment>',
                $this->formatBytes($totalSize),
            ));
            if ($limit === null || $limit === '') {
                $limit = '100';
                $output->writeln('<comment>Applying default --limit=100 for this run.</comment>');
            }
        }

        $rawFormat = $input->getOption('format');
        $format = strtolower(is_string($rawFormat) ? $rawFormat : 'text');
        if (! $this->isValidFormat($format)) {
            $output->writeln('<error>--format must be "text", "json", "md", or "html".</error>');

            return Command::FAILURE;
        }

        $rawOut = $input->getOption('output');
        $outPath = is_string($rawOut) && $rawOut !== '' ? $rawOut : null;
        if (! $this->areAllFilesReadable($files, $output)) {
            return Command::FAILURE;
        }

        $rawParser = $input->getOption('parser');
        $parserName = strtolower(is_string($rawParser) ? $rawParser : 'laravel');
        if (! $this->isValidParserName($parserName)) {
            $output->writeln('<error>--parser must be "laravel" or "generic".</error>');

            return Command::FAILURE;
        }


        if ($flowsRequested && $format !== 'html' && !$flowDetail) {
            $output->writeln('<error>--flows requires --format=html or --flow-detail for text output.</error>');
            return Command::FAILURE;
        }

        if (
            $flowTypeFilter !== null && !in_array($flowTypeFilter, [
                'request',
                'queue-job',
                'command',
                'webhook',
                'import',
                'unknown'
            ], true)
        ) {
            $output->writeln(
                '<error>--flow-type must be one of: request, queue-job, command, webhook, import, unknown.</error>'
            );
            return Command::FAILURE;
        }

        if (
            $groupByKey !== null && !in_array($groupByKey, [
                'request_id',
                'correlation_id',
                'trace_id',
                'job_uuid',
                'batch_id',
                'command',
                'route',
                'user_id',
                'tenant_id'
            ], true)
        ) {
            $output->writeln(
                '<error>--group-by must be one of: request_id, correlation_id, trace_id, ' .
                'job_uuid, batch_id, command, route, user_id, tenant_id.</error>'
            );
            return Command::FAILURE;
        }

        if ($limit !== null && !is_numeric($limit)) {
            $output->writeln('<error>--limit must be a number.</error>');
            return Command::FAILURE;
        }

        $parser = $this->createParser($parserName);
        $entries = $this->loadEntries($parser, $files);
        $groups = (new Summariser())->summarise($entries, $limit ? (int) $limit : 0);

        $flows = [];
        if ($flowsRequested) {
            $grouper = new FlowGrouper();
            $allFlows = $grouper->group($entries, $groupByKey);
            $flows = $flowTypeFilter !== null
                ? array_filter($allFlows, fn($flow): bool => $flow->type === $flowTypeFilter)
                : $allFlows;
        }

        $report = $flowDetail
            ? $this->renderTextReportWithFlowDetail($groups, $flows)
            : $this->renderReport($format, $groups, $flows);

        $result = $this->emitReport($output, $report, $outPath);

        if (
            $result === Command::SUCCESS
            && $format === 'html'
            && $outPath !== null
            && PHP_OS_FAMILY === 'Darwin'
        ) {
            exec('open ' . escapeshellarg($outPath));
        }

        return $result;
    }

    private function isValidFormat(string $format): bool
    {
        return in_array($format, ['text', 'json', 'md', 'markdown', 'html'], true);
    }

    private function isValidParserName(string $name): bool
    {
        return in_array($name, ['laravel', 'generic'], true);
    }

    /**
     * @param list<string> $files
     */
    private function areAllFilesReadable(array $files, OutputInterface $output): bool
    {
        foreach ($files as $file) {
            if (! is_readable($file)) {
                $output->writeln(sprintf('<error>Cannot read file: %s</error>', $file));

                return false;
            }
        }

        return true;
    }

    private function createParser(string $parserName): LogParserInterface
    {
        return $parserName === 'generic' ? new GenericPhpLogParser() : new LaravelStyleLogParser();
    }

    /**
     * @return list<string>
     */
    private function collectPaths(mixed $rawFiles): array
    {
        $files = [];
        if (! is_array($rawFiles)) {
            return $files;
        }

        foreach ($rawFiles as $path) {
            if (is_string($path) && $path !== '') {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @param list<string> $files
     * @return \Generator<ParsedLogEntry>
     */
    private function loadEntries(LogParserInterface $parser, array $files): \Generator
    {
        foreach ($files as $file) {
            foreach ($parser->parseFile($file) as $entry) {
                yield $entry;
            }
        }
    }

    /**
     * @param list<ErrorGroup> $groups
     * @param list<LogFlow> $flows
     */
    private function renderReport(string $format, array $groups, array $flows = []): string
    {
        if ($format === 'json') {
            return (new JsonReportRenderer())->render($groups);
        }

        if ($format === 'md' || $format === 'markdown') {
            return (new MarkdownReportRenderer())->render($groups);
        }

        if ($format === 'html') {
            return (new HtmlReportRenderer())->render($groups, $flows);
        }

        return $this->renderTextReport($groups, $flows);
    }

    /**
     * @param list<ErrorGroup> $groups
     * @param list<LogFlow> $flows
     */
    private function renderTextReport(array $groups, array $flows): string
    {
        $text = (new TextReportRenderer())->render($groups);
        if ($flows !== []) {
            $flowRenderer = new FlowRenderer();
            $text .= "\n\n" . $flowRenderer->renderText($flows, false);
        }

        return $text;
    }

    /**
     * @param list<ErrorGroup> $groups
     * @param list<LogFlow> $flows
     */
    private function renderTextReportWithFlowDetail(array $groups, array $flows): string
    {
        $text = (new TextReportRenderer())->render($groups);
        if ($flows !== []) {
            $flowRenderer = new FlowRenderer();
            $text .= "\n\n" . $flowRenderer->renderText($flows, true);
        }

        return $text;
    }

    private function emitReport(OutputInterface $output, string $report, ?string $outPath): int
    {
        if ($outPath === null) {
            $output->write($report);

            return Command::SUCCESS;
        }

        $written = file_put_contents($outPath, $report);
        if ($written === false) {
            $output->writeln(sprintf('<error>Cannot write to: %s</error>', $outPath));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $files
     */
    private function getTotalFileSize(array $files): int
    {
        $totalSize = 0;
        foreach ($files as $file) {
            if (! is_readable($file)) {
                continue;
            }

            $size = filesize($file);
            if ($size !== false) {
                $totalSize += $size;
            }
        }

        return $totalSize;
    }

    private function formatBytes(int $bytes, int $decimals = 2): string
    {
        if ($bytes == 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor(log($bytes, 1024));
        return sprintf('%.' . $decimals . 'f ' . $units[$factor], $bytes / 1024 ** $factor);
    }
}
