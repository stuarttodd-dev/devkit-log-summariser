# devkit-log-summariser

A small PHP tool that **groups and summarises** PHP log errors: exception type (when detectable), normalised message, **occurrence count**, **first/last** timestamps, and **collapsed duplicate stack traces** per group.

It supports two input styles:

1. **Laravel-style** (default) — Monolog lines like `[YYYY-MM-DD HH:MM:SS] channel.LEVEL: …` with multi-line exception bodies. Best for `storage/logs/laravel*.log`.
2. **Generic PHP** — Plain `PHP Warning:`, `PHP Fatal error:`, `Uncaught …`, `SQLSTATE[…]`, etc. Lines are batched from each “error” line until the next error. Handy for php-fpm/CLI or mixed `error_log` output that is **not** Laravel-blocked.

Reports: **text** (default), **JSON**, **Markdown**, or **HTML**. Write to a file with **`-o`**.

## Flow Grouping (New in v2)

The tool can group related log entries into "flows" — sequences of log entries that belong to the same request, job, command, or process. Flows help identify the full lifecycle of issues rather than isolated errors.

### Flow Detection

Flows are detected using identifiers like:
- `request_id`, `correlation_id`, `trace_id`
- `job_uuid`, `batch_id`, `job.class`
- `command` name
- `route` or `url`
- Close timestamps for related entries

Each flow includes:
- Flow type: `request`, `queue-job`, `command`, `webhook`, `import`, `unknown`
- Start/end times and duration
- Entry count and log levels
- Main error and suggested action
- Confidence score (high/medium/low)

### Flow Options

| Option | Description |
|--------|-------------|
| `--flows` | Include flow grouping (requires `--format=html`) |
| `--flow-detail` | Include detailed flow entries in text output |
| `--flow-type` | Filter flows by type (request, queue-job, command, webhook, import, unknown) |
| `--group-by` | Force grouping by a specific key (request_id, correlation_id, trace_id, job_uuid, batch_id, command, route, user_id, tenant_id) |

### Flow Examples

```bash
# HTML report with flows
vendor/bin/devkit-log-summarise --flows --format=html -o report.html storage/logs/laravel.log

# Only queue job flows
vendor/bin/devkit-log-summarise --flows --format=html --flow-type=queue-job storage/logs/laravel.log

# Force grouping by user_id
vendor/bin/devkit-log-summarise --flows --format=html --group-by=user_id storage/logs/laravel.log

# Text output with flow details
vendor/bin/devkit-log-summarise --flows --flow-detail storage/logs/laravel.log
```

The HTML report provides interactive filtering, searching, and flow expansion with full details.

## Requirements

- PHP 8.3+
- Composer

## Try it in this repository

From the project root (after dependencies are installed):

```bash
cd /path/to/devkit-log-summariser
composer install
```

**1. Laravel-style sample** (timestamp blocks, stacks):

```bash
php bin/devkit-log-summarise tests/fixtures/logs/typeerror_repeat.log
php bin/devkit-log-summarise tests/fixtures/logs/sqlstate_repeat.log
```

**2. Generic PHP sample** (no `local.ERROR` prefix):

```bash
php bin/devkit-log-summarise --parser=generic tests/fixtures/logs/generic_php.log
```

**3. JSON / Markdown** (e.g. for scripts or a ticket):

```bash
php bin/devkit-log-summarise -f json tests/fixtures/logs/sqlstate_repeat.log
php bin/devkit-log-summarise --parser=generic -f md -o /tmp/summary.md tests/fixtures/logs/generic_php.log
```

**4. Run the test suite / quality checks** (optional):

```bash
composer run tests
composer run standards:check
```

## Install as a dependency

```bash
composer require devkit/log-summariser
```

The binary is published as `vendor/bin/devkit-log-summarise` when this package is required in another project.

## Usage (any install)

```bash
vendor/bin/devkit-log-summarise [options] <log-file> [<log-file> ...]
```

### Main options

| Option | Short | Description |
|--------|-------|-------------|
| `--parser` | `-p` | `laravel` (default) or `generic` |
| `--format` | `-f` | `text` (default), `json`, `md`, `markdown`, or `html` |
| `--output` | `-o` | Write the report to this file instead of stdout |
| `--flows` | | Include flow grouping (requires `--format=html` or `--flow-detail`) |
| `--flow-detail` | | Include detailed flow entries in text output |
| `--flow-type` | | Filter flows by type |
| `--group-by` | | Force grouping by a specific key |

Examples:

```bash
vendor/bin/devkit-log-summarise storage/logs/laravel.log
vendor/bin/devkit-log-summarise -p generic /var/log/php-fpm-error.log
vendor/bin/devkit-log-summarise -f json -o report.json app1.log app2.log
vendor/bin/devkit-log-summarise --flows --format=html -o flows.html storage/logs/laravel.log
```

## Log format notes

- **Laravel / Monolog**: each entry is expected to start with a line beginning `[YYYY-MM-DD HH:MM:SS]`. The following lines belong to the same entry until the next such line.
- **Generic**: a new group starts on lines that look like PHP or framework errors (e.g. `PHP Warning:`, `PHP Fatal error:`, `SQLSTATE[`, `Uncaught`, `*Exception:`, or messages containing `Undefined array key` / `Undefined index` when not prefixed with `PHP Warning`). Non-matching lines are skipped until the next error starter; continuation lines (stack `#0`, `thrown in`, etc.) are kept with the preceding error.

## Example output (text)

```
TypeError: Return value must be of type int, string returned in /app/Foo.php:10 — 3 occurrences
  First: 2024-01-10 10:00:01  Last: 2024-01-10 12:00:00
  Stack (most common duplicate):
    #0 /app/Bar.php(5): Foo->x()
    …
```

## Development

```bash
composer install
composer run tests
composer run standards:check
```

## Licence

MIT
