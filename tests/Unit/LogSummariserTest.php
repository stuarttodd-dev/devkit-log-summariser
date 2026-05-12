<?php

declare(strict_types=1);

use Devkit\LogSummariser\GenericPhpLogParser;
use Devkit\LogSummariser\LaravelStyleLogParser;
use Devkit\LogSummariser\Report\JsonReportRenderer;
use Devkit\LogSummariser\Report\MarkdownReportRenderer;
use Devkit\LogSummariser\Summariser;

test('groups repeated type errors with first and last timestamps and dedupes stacks', function (): void {
    $path = __DIR__ . '/../fixtures/logs/typeerror_repeat.log';
    $parser = new LaravelStyleLogParser();
    $entries = $parser->parseFile($path);
    $groups = (new Summariser())->summarise($entries);

    expect($groups)->toHaveCount(1);

    $top = $groups[0];
    expect($top->exceptionClass)->toBe('TypeError');
    expect($top->occurrenceCount)->toBe(3);
    expect($top->firstOccurredAt?->format('Y-m-d H:i:s'))->toBe('2024-01-10 10:00:01');
    expect($top->lastOccurredAt?->format('Y-m-d H:i:s'))->toBe('2024-01-10 12:00:00');
    expect($top->stackSamples)->toHaveCount(2);
    expect($top->stackSamples[0]['count'])->toBe(2);
    expect($top->stackSamples[1]['count'])->toBe(1);
});

test('generic parser groups plain PHP warnings and fatals', function (): void {
    $path = __DIR__ . '/../fixtures/logs/generic_php.log';
    $parser = new GenericPhpLogParser();
    $entries = iterator_to_array($parser->parseFile($path));
    expect($entries)->toHaveCount(3);

    $groups = (new Summariser())->summarise($entries);
    expect($groups)->toHaveCount(2);
    expect($groups[0]->occurrenceCount)->toBe(2);
    expect($groups[0]->message)->toContain('Undefined array key');
    expect($groups[1]->occurrenceCount)->toBe(1);
    expect($groups[1]->message)->toContain('Call to undefined function');
});

test('groups sqlstate errors', function (): void {
    $path = __DIR__ . '/../fixtures/logs/sqlstate_repeat.log';
    $parser = new LaravelStyleLogParser();
    $entries = $parser->parseFile($path);
    $groups = (new Summariser())->summarise($entries);

    expect($groups)->toHaveCount(1);
    expect($groups[0]->occurrenceCount)->toBe(2);
    expect($groups[0]->message)->toContain('SQLSTATE[42P01]');
});

test('markdown report contains headings and fenced stack', function (): void {
    $path = __DIR__ . '/../fixtures/logs/sqlstate_repeat.log';
    $parser = new LaravelStyleLogParser();
    $groups = (new Summariser())->summarise($parser->parseFile($path));
    $md = (new MarkdownReportRenderer())->render($groups);

    expect($md)->toContain('# Log summary');
    expect($md)->toContain('## ');
    expect($md)->toContain('```');
    expect($md)->toContain('SQLSTATE[42P01]');
});

test('json report is valid and lists groups', function (): void {
    $path = __DIR__ . '/../fixtures/logs/sqlstate_repeat.log';
    $parser = new LaravelStyleLogParser();
    $groups = (new Summariser())->summarise($parser->parseFile($path));
    $json = (new JsonReportRenderer())->render($groups);
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect($data)->toHaveKey('groups');
    expect($data['groups'])->toHaveCount(1);
    expect($data['groups'][0]['occurrenceCount'])->toBe(2);
});
