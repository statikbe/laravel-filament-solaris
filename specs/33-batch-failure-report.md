# Spec 33 — Batch failure report (download)

> Piece #6 of the **Queued Batch Execution** roadmap
> (`docs/superpowers/specs/2026-06-08-queued-batch-execution-design.md`).
> Builds on spec 29 (persistence), spec 31 (completion handlers), spec 32 (FK +
> pruning). **Date:** 2026-06-16.

## 1. Goal

Let an operator **download the failures of a batch run** as a CSV or XLSX, surfaced
as a **"Download failures" action inside the completion notification** (in the
Filament notification bell). The file is **generated on click** from
`solaris_batch_problems` — nothing is stored on disk.

## 2. Approach (decisions already made)

- **No Filament `Exporter`/`ExportAction`.** Filament's `Exporter` is abstract and
  bound to its `Export` model + export-table pipeline + a column-picker modal — it
  doesn't decompose for standalone, headless use. We use the library underneath it,
  **openspout**, directly.
- **openspout is a guaranteed dependency** (hard `require` of `filament/actions`,
  which `filament/filament` pulls in) — used directly, **not** added to our
  `composer.json`. openspout writes both CSV and XLSX through one row API.
- **Generate-on-click, no stored files.** A signed download route streams the report
  freshly from the DB each time. No file storage → no file lifecycle/pruning, always
  current, valid until the run's problems are pruned (spec 32).
- **No `DescribesBatchFailure`.** Deferred. v1 columns are the problem-row fields.

## 3. Components

### 3.1 `BatchFailureReport` writer — `src/Support/Batch/BatchFailureReport.php`
Pure generator. Given a `SolarisBatchRun` + a `BatchReportFormat` (Csv|Xlsx),
streams the run's `solaris_batch_problems` rows to an openspout writer.

- Columns (header → value): `identifier`, `type` (failure|discard), `reason`,
  `input` (the stored row snapshot, `json_encode`d).
- Query is **chunked** (`->where('batch_run_id', $run->id)`, `lazy()`/`chunk()`),
  so a run with thousands of problems streams without loading all rows.
- Writes to an openspout `Writer` opened on a stream (`php://output` for download).
  CSV → `OpenSpout\Writer\CSV\Writer`; XLSX → `OpenSpout\Writer\XLSX\Writer`.
- A tiny `BatchReportFormat` enum (`Csv='csv'`, `Xlsx='xlsx'`) maps to the writer +
  the download MIME/extension. Unknown `?format=` falls back to the config default.

### 3.2 Signed download route + controller
- Route (registered by the service provider): `GET` a signed URL, e.g.
  `filament-solaris/batch-failures/{run}` with `?format=csv|xlsx`, named
  `filament-solaris.batch-failures.download`, behind `signed` middleware.
- Controller: resolve the `SolarisBatchRun` (404 if gone/pruned), pick the format
  (query param → config default), return a `StreamedResponse` whose callback runs
  `BatchFailureReport` against `php://output` with the right `Content-Type` +
  `Content-Disposition: attachment; filename="failures-{runId}.{ext}"`.
- **Security:** the URL is **signed** (unforgeable). Apps that want per-user
  authorization add their own middleware/gate; documented. (A signed, non-expiring
  URL is acceptable because the notification that carries it is itself per-user — a
  database notification on the run's user.)

### 3.3 Notification integration — extend `NotifyOnBatchCompletion`
When a finished run has failures and the report is enabled, attach a download action
to the completion notification and make it persistent:

- Add the **"Download failures"** action (Filament `Notification` action → the signed
  route URL for `summary->runId`, format = config default) **iff**
  `summary->failed > 0` **and** `config('filament-solaris.batch_tracking.attach_failure_report')`
  **and** `summary->runId !== null`.
- **Delivery rule:**
  - `summary->runId !== null` and `run->getUser()` resolves → **`->sendToDatabase($user)`**
    (persists in the bell, carries the download action). If the run is **inline**
    (`! summary->queued`), **also** `->send()` (flash toast) for immediacy.
  - No resolvable user → current behaviour: inline `->send()` (flash), queued → log
    fallback.

This supersedes piece-#4's "inline = flash only" for tracked/queued runs (they now
also land in the bell). Untracked inline is unchanged (flash only; no report link —
there are no persisted problems to export).

## 4. Config

Add to the `batch_tracking` block:
```php
// Attach a "Download failures" action to the completion notification (generate-on-click).
'attach_failure_report' => true,
// Default report format: 'csv' or 'xlsx'.
'report_format' => 'csv',
```

## 5. Out of scope

- `DescribesBatchFailure` / a human-readable `description` column (deferred).
- Storing report files on a disk; a standalone export button/table action.
- Auth beyond the signed URL (apps add their own middleware).
- Exporting discards-only / column selection.

## 6. Testing

- **`BatchFailureReport` writer:** generate CSV for a run with mixed
  failure/discard problems → assert header + a row per problem with the right
  `identifier`/`type`/`reason`/`input`. Generate XLSX → assert it's a non-empty,
  openspout-readable file with the same rows (read back via openspout reader).
- **`BatchReportFormat`:** maps to the right writer/extension/MIME; unknown → default.
- **Download route:** a signed request streams the file with the right
  `Content-Disposition`/`Content-Type`; an **unsigned** request is rejected (403); a
  missing/pruned run → 404; `?format=xlsx` switches format.
- **Notification integration:** a queued run with failures + `attach_failure_report`
  on → the database notification carries a download action whose URL is the signed
  route for the run; with the config off, or zero failures, or no `runId` → no
  action. Inline-tracked run → both flashed and persisted.
- **Chunking:** more problems than the chunk size all appear in the output.
