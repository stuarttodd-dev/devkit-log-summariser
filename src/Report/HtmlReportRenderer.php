<?php

declare(strict_types=1);

namespace Devkit\LogSummariser\Report;

use DateTimeImmutable;
use Devkit\LogSummariser\ErrorGroup;
use Devkit\LogSummariser\Flow\FlowSummary;
use Devkit\LogSummariser\Flow\LogFlow;
use Devkit\LogSummariser\ParsedLogEntry;

/**
 * Renders a self-contained HTML report with two tabs:
 *  - Errors  (existing ErrorGroup data)
 *  - Flows   (new LogFlow data with client-side filtering)
 *
 * No external assets; everything (CSS, JS, JSON payload) is inlined.
 */
final readonly class HtmlReportRenderer
{
    public function __construct(
        private FlowSummary $flowSummary = new FlowSummary(),
    ) {
    }

    /**
     * @param list<ErrorGroup> $errorGroups
     * @param list<LogFlow> $flows
     */
    public function render(array $errorGroups, array $flows): string
    {
        $payload = $this->jsonPayload($errorGroups, $flows);
        $generatedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->document($generatedAt, $payload, count($errorGroups), count($flows));
    }

    private function document(string $generatedAt, string $payload, int $errorCount, int $flowCount): string
    {
         $css = $this->css();
         $javascript = $this->javascript();

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>devkit log report</title>
<style>{$css}</style>
</head>
<body>
<header>
  <h1>devkit log summary</h1>
  <p class="meta">Generated {$generatedAt} · {$errorCount} error groups · {$flowCount} flows</p>
</header>
<nav class="tabs" role="tablist">
  <button class="tab active" data-tab="errors" role="tab">Errors</button>
  <button class="tab" data-tab="flows" role="tab">Flows</button>
</nav>
<section class="panel active" id="errors-panel" role="tabpanel"></section>
<section class="panel" id="flows-panel" role="tabpanel">
  <div class="filters">
    <label>Type
      <select id="flow-type-filter">
        <option value="all">all</option>
        <option value="request">request</option>
        <option value="queue-job">queue-job</option>
        <option value="command">command</option>
        <option value="webhook">webhook</option>
        <option value="import">import</option>
        <option value="unknown">unknown</option>
      </select>
    </label>
    <label>Severity
      <select id="flow-severity-filter">
        <option value="all">all</option>
        <option value="error">&ge; ERROR</option>
        <option value="warning">&ge; WARNING</option>
      </select>
    </label>
    <label>Search
      <input type="search" id="flow-search" placeholder="route, job, command, user, tenant, exception...">
    </label>
    <label class="checkbox"><input type="checkbox" id="show-ignored"> show ignored</label>
  </div>
  <table class="flows">
    <thead><tr>
      <th>Type</th>
      <th>Headline</th>
      <th>Duration</th>
      <th>Entries</th>
      <th>Max level</th>
      <th>Confidence</th>
      <th>Main error</th>
      <th></th>
    </tr></thead>
    <tbody></tbody>
  </table>
 </section>
 <script type="application/json" id="report-data">{$payload}</script>
 <script>{$javascript}</script>
 </body>
 </html>
HTML;
    }

    private function css(): string
    {
        return <<<CSS
*{box-sizing:border-box}
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;margin:0;color:#222;background:#f7f7f9}
header{padding:16px 24px;background:#1e293b;color:#fff}
header h1{margin:0;font-size:18px}
header .meta{margin:4px 0 0;font-size:12px;color:#cbd5e1}
.tabs{display:flex;gap:4px;padding:8px 24px;background:#fff;border-bottom:1px solid #e5e7eb}
.tab{background:transparent;border:0;padding:8px 16px;font-size:14px;cursor:pointer;border-radius:6px}
.tab.active{background:#1e293b;color:#fff}
.panel{display:none;padding:16px 24px}
.panel.active{display:block}
.filters{display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:12px;font-size:13px}
.filters label{display:flex;flex-direction:column;gap:4px;color:#475569}
.filters input,.filters select{padding:6px 8px;border:1px solid #cbd5e1;border-radius:6px;font:inherit}
.filters .checkbox{flex-direction:row;align-items:center;gap:6px}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb}
th,td{padding:8px 10px;text-align:left;font-size:13px;border-bottom:1px solid #e5e7eb;vertical-align:top}
th{background:#f1f5f9;font-weight:600}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;text-transform:uppercase}
.badge.high{background:#dcfce7;color:#166534}
.badge.medium{background:#fef9c3;color:#854d0e}
.badge.low{background:#fee2e2;color:#991b1b}
.level{font-family:ui-monospace,Menlo,monospace;font-size:11px}
.level.error,.level.critical,.level.alert,.level.emergency{color:#991b1b}
.level.warning{color:#854d0e}
.flow-row.hidden{display:none}
.flow-detail{background:#f8fafc;padding:12px 16px;border-top:1px dashed #cbd5e1}
.flow-detail h3{margin:0 0 8px;font-size:14px}
.flow-detail pre{background:#0f172a;color:#e2e8f0;padding:10px;border-radius:6px;overflow:auto;
  font-size:12px;max-height:240px}
.flow-detail .ctx{display:grid;grid-template-columns:max-content 1fr;gap:2px 12px;font-size:12px}
.flow-detail .ctx dt{color:#64748b}
.flow-detail .entries{margin-top:8px}
.flow-detail .entries li{font-family:ui-monospace,Menlo,monospace;font-size:12px;list-style:none;padding:2px 0}
.flow-detail button{margin-right:8px;border:1px solid #cbd5e1;background:#fff;border-radius:6px;
  padding:6px 10px;cursor:pointer;font-size:12px}
.flow-detail button:hover{background:#f1f5f9}
details summary{cursor:pointer}
.errors-section h2{font-size:15px;margin:16px 0 8px}
.errors-section pre{background:#0f172a;color:#e2e8f0;padding:10px;border-radius:6px;overflow:auto;
  font-size:12px;max-height:240px}
CSS;
    }

    private function javascript(): string
    {
         return <<<'JS_WRAP'
(function(){
  var data = JSON.parse(document.getElementById('report-data').textContent);
  var IGNORED_KEY = 'devkitLogSummariserIgnoredFlows';
  function loadIgnored(){ try { return JSON.parse(localStorage.getItem(IGNORED_KEY) || '[]'); } catch(e){ return []; } }
  function saveIgnored(list){ try { localStorage.setItem(IGNORED_KEY, JSON.stringify(list)); } catch(e){} }

  var SEVERITY_RANK = {EMERGENCY:7,ALERT:6,CRITICAL:5,ERROR:4,WARNING:3,NOTICE:2,INFO:1,DEBUG:0};
  function maxLevel(levels){
    var best = null, bestRank = -1;
    (levels||[]).forEach(function(l){
      var rank = SEVERITY_RANK[(l||'').toUpperCase()] || 0;
      if (rank > bestRank){ best = l; bestRank = rank; }
    });
    return {label: best || '-', rank: bestRank};
  }
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
  }); }

  // Tabs
  document.querySelectorAll('.tab').forEach(function(btn){
    btn.addEventListener('click', function(){
      document.querySelectorAll('.tab').forEach(function(b){ b.classList.toggle('active', b===btn); });
      document.querySelectorAll('.panel').forEach(function(p){
        p.classList.toggle('active', p.id === btn.dataset.tab + '-panel');
      });
    });
  });

  // Errors panel
  var errorsPanel = document.getElementById('errors-panel');
  errorsPanel.className = 'panel active errors-section';
  if (!data.errors || !data.errors.length){
    errorsPanel.innerHTML = '<p>No error groups.</p>';
  } else {
    var parts = [];
    data.errors.forEach(function(g){
      parts.push('<section><h2>' + esc(g.headline) + ' &mdash; ' + g.occurrenceCount +
        ' occurrence' + (g.occurrenceCount === 1 ? '' : 's') + '</h2>');
      parts.push('<p><strong>First:</strong> ' + esc(g.firstOccurredAt || 'n/a') +
        ' &nbsp; <strong>Last:</strong> ' + esc(g.lastOccurredAt || 'n/a') + '</p>');
      if (g.topStack){ parts.push('<pre>' + esc(g.topStack) + '</pre>'); }
      parts.push('</section>');
    });
    errorsPanel.innerHTML = parts.join('');
  }

  // Flows
  var flowsBody = document.querySelector('#flows-panel tbody');
  var typeFilter = document.getElementById('flow-type-filter');
  var sevFilter = document.getElementById('flow-severity-filter');
  var search = document.getElementById('flow-search');
  var showIgnoredCb = document.getElementById('show-ignored');

  function renderRows(){
    var rows = [];
    var ignored = loadIgnored();
    var ignoredSet = {};
    ignored.forEach(function(id){ ignoredSet[id] = true; });

    var typeVal = typeFilter.value;
    var sevVal = sevFilter.value;
    var searchVal = (search.value || '').toLowerCase().trim();
    var sevThreshold = sevVal === 'error' ? SEVERITY_RANK.ERROR : (sevVal === 'warning' ? SEVERITY_RANK.WARNING : -1);
    var showIgnored = showIgnoredCb.checked;

    (data.flows || []).forEach(function(flow){
      var isIgnored = !!ignoredSet[flow.id];
      if (isIgnored && !showIgnored) return;
      if (typeVal !== 'all' && flow.type !== typeVal) return;
      var lvl = maxLevel(flow.levels);
      if (sevThreshold > -1 && lvl.rank < sevThreshold) return;
      if (searchVal){
        var hay = (
          flow.type+' '+flow.headline+' '+(flow.mainIssue||'')+' '+
          JSON.stringify(flow.contextValues||{})+' '+
          (flow.relatedFingerprints||[]).join(' ')
        ).toLowerCase();
        if (hay.indexOf(searchVal) === -1) return;
      }
      rows.push(rowHtml(flow, lvl, isIgnored));
    });

    flowsBody.innerHTML = rows.join('') ||
      '<tr><td colspan="8" style="padding:16px;color:#64748b">No flows match the current filters.</td></tr>';
    attachDetailHandlers();
  }

  function rowHtml(flow, lvl, isIgnored){
    var ignoredLabel = isIgnored ? ' (ignored)' : '';
    return ''+
      '<tr class="flow-row" data-id="'+esc(flow.id)+'">'+
        '<td>'+esc(flow.type)+'</td>'+
        '<td>'+esc(flow.headline)+ignoredLabel+'</td>'+
        '<td>'+esc(flow.duration||'n/a')+'</td>'+
        '<td>'+flow.entryCount+'</td>'+
        '<td class="level '+esc((lvl.label||'').toLowerCase())+'">'+esc(lvl.label)+'</td>'+
        '<td><span class="badge '+esc(flow.confidence)+'">'+esc(flow.confidence)+'</span></td>'+
        '<td>'+esc(flow.mainIssue||'')+'</td>'+
        '<td><button class="toggle">details</button></td>'+
      '</tr>'+
      '<tr class="flow-detail-row" data-id="'+esc(flow.id)+'" style="display:none"><td colspan="8">'+
        detailHtml(flow)+
      '</td></tr>';
  }

  function detailHtml(flow){
    var ctx = flow.contextValues || {};
    var ctxLines = Object.keys(ctx).filter(function(k){ return k.charAt(0) !== '_'; }).map(function(k){
      return '<dt>'+esc(k)+'</dt><dd>'+esc(ctx[k])+'</dd>';
    }).join('');
    var entries = (flow.entries || []).map(function(e){
      return '<li>['+esc(e.time||'n/a')+'] '+esc(e.level||'-')+': '+esc(e.message)+'</li>';
    }).join('');
    return '<div class="flow-detail">'+
      '<h3>'+esc(flow.headline)+'</h3>'+
      '<p><strong>Started:</strong> '+esc(flow.startedAt||'n/a')+
        ' &nbsp; <strong>Ended:</strong> '+esc(flow.endedAt||'n/a')+
        ' &nbsp; <strong>Duration:</strong> '+esc(flow.duration||'n/a')+'</p>'+
      '<p><strong>Confidence reason:</strong> '+esc(flow.confidenceReason)+'</p>'+
      (ctxLines ? '<dl class="ctx">'+ctxLines+'</dl>' : '')+
      (flow.mainIssue ? '<p><strong>Main issue:</strong> '+esc(flow.mainIssue)+'</p>' : '')+
      (flow.mainStack ? '<pre>'+esc(flow.mainStack)+'</pre>' : '')+
      '<p><strong>Suggested action:</strong> '+esc(flow.suggestedAction||'')+'</p>'+
      '<details><summary>'+flow.entryCount+' entries</summary><ul class="entries">'+entries+'</ul></details>'+
      '<div style="margin-top:8px">'+
        '<button class="copy-summary">Copy summary</button>'+
        '<button class="ignore-flow">Ignore this flow</button>'+
      '</div>'+
    '</div>';
  }

  function attachDetailHandlers(){
    flowsBody.querySelectorAll('.flow-row .toggle').forEach(function(btn){
      btn.onclick = function(){
        var row = btn.closest('tr');
        var id = row.dataset.id;
        var d = flowsBody.querySelector('tr.flow-detail-row[data-id="'+CSS.escape(id)+'"]');
        if (!d) return;
        d.style.display = d.style.display === 'none' ? '' : 'none';
      };
    });
    flowsBody.querySelectorAll('.ignore-flow').forEach(function(btn){
      btn.onclick = function(){
        var row = btn.closest('tr.flow-detail-row');
        var id = row && row.dataset.id;
        if (!id) return;
        var ignored = loadIgnored();
        if (ignored.indexOf(id) === -1){
          ignored.push(id);
          saveIgnored(ignored);
        }
        renderRows();
      };
    });
    flowsBody.querySelectorAll('.copy-summary').forEach(function(btn){
      btn.onclick = function(){
        var row = btn.closest('tr.flow-detail-row');
        var id = row && row.dataset.id;
        var flow = (data.flows || []).find(function(f){ return f.id === id; });
        if (!flow) return;
        var text = renderTicketBlock(flow);
        var done = function(ok){ btn.textContent = ok ? 'Copied!' : 'Copy failed';
          setTimeout(function(){ btn.textContent = 'Copy summary'; }, 1200); };
        if (navigator.clipboard && navigator.clipboard.writeText){
          navigator.clipboard.writeText(text).then(function(){ done(true); }, function(){ done(false); });
        } else {
          done(false);
        }
      };
    });
  }

  function renderTicketBlock(flow){
    var ctx = flow.contextValues || {};
    var ctxLines = Object.keys(ctx).filter(function(k){ return k.charAt(0) !== '_'; }).map(function(k){
      return '- '+k+': '+ctx[k];
    }).join('\n');
    var entries = (flow.entries || []).map(function(e){
      return '  ['+(e.time||'n/a')+'] '+(e.level||'-')+': '+e.message;
    }).join('\n');
    return [
      'Flow: '+flow.type,
      flow.headline,
      'Started: '+(flow.startedAt||'n/a')+'   Ended: '+(flow.endedAt||'n/a')+'   Duration: '+(flow.duration||'n/a'),
      'Entries: '+flow.entryCount+'   Levels: '+(flow.levels||[]).join(', '),
      'Confidence: '+flow.confidence+'   Reason: '+flow.confidenceReason,
      ctxLines ? 'Context:\n'+ctxLines : '',
      flow.mainIssue ? 'Main issue: '+flow.mainIssue : '',
      'Suggested action: '+(flow.suggestedAction||''),
      entries ? 'Entries:\n'+entries : ''
    ].filter(Boolean).join('\n');
  }

  [typeFilter, sevFilter, search, showIgnoredCb].forEach(function(el){
    el.addEventListener('input', renderRows);
    el.addEventListener('change', renderRows);
  });
  renderRows();
})();
JS_WRAP;
    }

    /**
     * @param list<ErrorGroup> $errorGroups
     * @param list<LogFlow> $flows
     */
    private function jsonPayload(array $errorGroups, array $flows): string
    {
        $errors = [];
        foreach ($errorGroups as $group) {
            $top = $group->stackSamples[0]['trace'] ?? '';
            $errors[] = [
                'headline' => $group->exceptionClass === 'Unknown'
                    ? $group->message
                    : $group->exceptionClass . ': ' . $group->message,
                'occurrenceCount' => $group->occurrenceCount,
                'firstOccurredAt' => $group->firstOccurredAt?->format('Y-m-d H:i:s'),
                'lastOccurredAt' => $group->lastOccurredAt?->format('Y-m-d H:i:s'),
                'topStack' => $top,
            ];
        }

        $flowPayload = [];
        foreach ($flows as $flow) {
            $flowPayload[] = $this->flowToArray($flow);
        }

        $json = json_encode(
            ['errors' => $errors, 'flows' => $flowPayload],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return str_replace('</', '<\\/', $json);
    }

    /**
     * @return array<string, mixed>
     */
    private function flowToArray(LogFlow $flow): array
    {
        $entries = [];
        foreach ($flow->entries as $entry) {
            $entries[] = [
                'time' => $entry->occurredAt?->format('Y-m-d H:i:s'),
                'level' => $entry->level,
                'message' => $this->oneLineMessage($entry),
            ];
        }

         return [
             'id' => $flow->identifier,
             'type' => $flow->type,
             'confidence' => $flow->confidence,
             'confidenceReason' => $flow->confidenceReason,
             'startedAt' => $flow->startedAt?->format('Y-m-d H:i:s'),
             'endedAt' => $flow->endedAt?->format('Y-m-d H:i:s'),
             'duration' => $this->formatDuration($flow->durationSeconds),
             'entryCount' => $flow->entryCount,
             'levels' => $flow->levels,
             'relatedFingerprints' => $flow->relatedFingerprints,
             'headline' => $this->flowSummary->headline($flow),
             'mainIssue' => $this->flowSummary->mainIssue($flow),
             'mainStack' => $flow->mainError?->stackTrace ?? '',
             'suggestedAction' => $this->flowSummary->suggestedAction($flow),
             'contextValues' => $flow->contextValues,
             'entries' => $entries,
         ];
    }

    private function oneLineMessage(ParsedLogEntry $entry): string
    {
        $msg = preg_replace('/\s+/', ' ', $entry->message) ?? $entry->message;
        if (strlen($msg) > 200) {
            return substr($msg, 0, 200) . '…';
        }

        return $msg;
    }

    private function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return 'n/a';
        }

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $remainder = $seconds % 60;

        return sprintf('%dm %ds', $minutes, $remainder);
    }
}
