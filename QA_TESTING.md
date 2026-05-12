# QA Testing Guide for devkit-log-summariser

This guide helps you test the log summarizer tool, including the new flow grouping features.

## Prerequisites

- PHP 8.1+
- Composer
- The tool is installed via Composer

## Basic Usage

### Summarize Errors (Existing Feature)

```bash
php bin/devkit-log-summarise tests/fixtures/logs/typeerror_repeat.log
```

Expected output: Groups repeated TypeError entries with counts and timestamps.

### Test Different Formats

```bash
# Text (default)
php bin/devkit-log-summarise tests/fixtures/logs/typeerror_repeat.log

# JSON
php bin/devkit-log-summarise tests/fixtures/logs/typeerror_repeat.log --format=json

# Markdown
php bin/devkit-log-summarise tests/fixtures/logs/typeerror_repeat.log --format=md

# HTML (new)
php bin/devkit-log-summarise tests/fixtures/logs/typeerror_repeat.log --format=html --output report.html
```

## Flow Grouping Tests (New Feature)

Flows group related log entries by request_id, job_uuid, etc.

### Basic Flow Detection

```bash
php bin/devkit-log-summarise tests/fixtures/logs/flows_sample.log --flows --format=html --output flows_report.html
```

Open `flows_report.html` in a browser. You should see:
- An "Errors" tab (may be empty or minimal)
- A "Flows" tab with grouped entries

Expected flows:
1. **Request flow**: "POST /api/checkout" with request_id "req-123"
2. **Queue job flow**: "App\Jobs\ProcessInvoicePdf" with job_uuid "job-789"
3. **Command flow**: "invoice:generate"
4. **Webhook flow**: "POST /webhook/stripe" with request_id "web-111"
5. **Import flow**: "App\Jobs\CsvImport" with job_uuid "import-333"

### Flow Filtering

```bash
# Only queue jobs
php bin/devkit-log-summarise tests/fixtures/logs/flows_sample.log --flows --format=html --flow-type=queue-job --output queue_jobs.html

# Only requests
php bin/devkit-log-summarise tests/fixtures/logs/flows_sample.log --flows --format=html --flow-type=request --output requests.html
```

### Forced Grouping

```bash
# Group by user_id (force grouping even if not detected automatically)
php bin/devkit-log-summarise tests/fixtures/logs/flows_sample.log --flows --format=html --group-by=user_id --output user_grouped.html
```

### Text Output with Flows

```bash
# Text with flow details
php bin/devkit-log-summarise tests/fixtures/logs/flows_sample.log --flows --flow-detail
```

## Validation Tests

### Error Cases

1. **Flows without HTML**:
   ```bash
   php bin/devkit-log-summarise tests/fixtures/logs/flows_sample.log --flows
   ```
   Should show: `<error>--flows requires --format=html.</error>`

2. **Invalid flow-type**:
   ```bash
   php bin/devkit-log-summarise tests/fixtures/logs/flows_sample.log --flows --format=html --flow-type=invalid
   ```
   Should show: `<error>--flow-type must be one of: request, queue-job, command, webhook, import, unknown.</error>`

3. **Invalid group-by**:
   ```bash
   php bin/devkit-log-summarise tests/fixtures/logs/flows_sample.log --flows --format=html --group-by=invalid
   ```
   Should show: `<error>--group-by must be one of: request_id, correlation_id, trace_id, job_uuid, batch_id, command, route, user_id, tenant_id.</error>`

### HTML Report Features

In the HTML report (`flows_report.html`):

1. **Tabs**: Switch between Errors and Flows tabs
2. **Flow Filtering**:
   - Filter by type (all, request, queue-job, etc.)
   - Filter by severity (all, ≥ERROR, ≥WARNING)
   - Search by route, job, command, user, tenant, exception
3. **Flow Details**:
   - Click "details" to expand a flow
   - See start/end times, duration, confidence, main issue, suggested action
   - View all entries in the flow
   - Copy summary to clipboard
   - Ignore flows (persisted in localStorage)

## Confidence Scoring

Flows should show confidence levels:
- **High**: Grouped by request_id, job_uuid, etc.
- **Medium**: Grouped by route or close timestamps
- **Low**: Grouped by exception fingerprint only

## Sample Log Structure

The `flows_sample.log` contains:
- Laravel-style log entries with JSON context
- Various identifiers: request_id, job_uuid, command, route, user_id
- Different flow types: request, queue-job, command, webhook, import
- Errors and info levels

## Running Tests

```bash
composer test
```

Existing tests should pass, and you can add new tests for flow features.

## Edge Cases to Test

1. Logs without any flow identifiers
2. Mixed log formats
3. Very large log files (performance)
4. Flows with many entries
5. Flows spanning long time periods
