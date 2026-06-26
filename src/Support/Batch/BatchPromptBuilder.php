<?php

namespace Statikbe\FilamentSolaris\Support\Batch;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentSolaris\Support\ModelSchemaResolver;

/**
 * Filament-free per-batch prompt assembly for the records loop: resolves the
 * instruction (string, View, or plain closure receiving the context rows +
 * userInput), then appends the `## User context`, `## Records`, and
 * `## Instructions` blocks with the synthetic identifier the AI echoes back.
 *
 * The action pre-wraps any Filament-DI instruction closure into a plain
 * `fn(array $rows, array $userInput)` before handing it here, so this stays free
 * of Filament's evaluate(). Reused by the inline batch path and the queued
 * dispatch render.
 */
class BatchPromptBuilder
{
    /**
     * @param  array<string>  $promptContextColumns  whitelist of columns sent into the ## Records block (default: all attributes minus auto-exclusions)
     */
    public function __construct(
        private string $identifierKey,
        private array $promptContextColumns = [],
    ) {}

    /**
     * @param  string|View|Closure(array<int, array<string, mixed>>, array<string, mixed>): (string|View)  $instruction
     * @param  array<int, array<string, mixed>|Model>  $batch
     * @param  array<string, mixed>  $userInput
     */
    public function build(string|View|Closure $instruction, array $batch, array $userInput): string
    {
        if ($instruction instanceof Closure) {
            // Same filtered view the AI gets in the ## Records block
            // (promptContextColumns + auto-exclusions), without the synthetic
            // identifier key — so a closure echoing $rows can't leak columns the
            // dev deliberately withheld via ->promptContextColumns().
            $rows = array_map(fn ($row): array => $this->contextForRow($row), $batch);
            $instruction = $instruction($rows, $userInput);
        }

        if ($instruction instanceof View) {
            $instruction = $instruction->render();
        }

        $instruction = (string) $instruction;
        $instruction = self::appendUserContext($instruction, $userInput);
        $instruction = $this->appendRecordsBlock($instruction, $batch);

        return $this->appendBatchInstructions($instruction);
    }

    /**
     * Enrich a batch into the keyed rows the AI sees: each context row plus its
     * identifier (positional `_index`, or the model key for updates).
     *
     * @param  array<int, array<string, mixed>|Model>  $batch
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    public function enrich(array $batch): array
    {
        if ($this->identifierKey !== '_index') {
            // updateRecords: PK echo. Source rows are always Models (validated upstream).
            $rows = array_map(function ($row): array {
                assert($row instanceof Model);
                $attrs = $this->contextForRow($row);
                $attrs[$this->identifierKey] = $row->getKey();

                return $attrs;
            }, $batch);

            return [$this->identifierKey, $rows];
        }

        $rows = [];
        foreach ($batch as $index => $row) {
            $attrs = $this->contextForRow($row);
            $attrs[$this->identifierKey] = $index;
            $rows[] = $attrs;
        }

        return [$this->identifierKey, $rows];
    }

    /**
     * Assemble the from-scratch (seed) prompt for a forModel single call: base
     * instruction (string, View, or plain closure receiving $userInput) + "Generate
     * N records" + ## User context + the single-call ## Instructions block.
     *
     * @param  string|View|Closure(array<string, mixed>): (string|View)  $instruction
     * @param  array<string, mixed>  $userInput
     */
    public static function fromScratch(string|View|Closure $instruction, int $count, array $userInput): string
    {
        if ($instruction instanceof Closure) {
            $instruction = $instruction($userInput);
        }

        if ($instruction instanceof View) {
            $instruction = $instruction->render();
        }

        $instruction = trim((string) $instruction."\n\nGenerate {$count} records.");
        $instruction = self::appendUserContext($instruction, $userInput);

        $boilerplate = <<<'TXT'
## Instructions
Return generated records in the `records` array.
For any input you cannot process (e.g., malformed line, ambiguous source data), add an entry to `failed` with an `identifier` describing the failed input (line number, source excerpt) and a short `reason`.
TXT;

        return trim($instruction)."\n\n".$boilerplate;
    }

    /**
     * Append a `## User context` JSON block when the user-input modal yielded any
     * filled values. No-op for empty input. Shared with the single-call path.
     *
     * @param  array<string, mixed>  $userInput
     */
    public static function appendUserContext(string $instruction, array $userInput): string
    {
        $filtered = array_filter($userInput, static fn ($v): bool => filled($v));

        if ($filtered === []) {
            return $instruction;
        }

        $json = json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return trim($instruction)."\n\n## User context\n```json\n{$json}\n```";
    }

    /**
     * @param  array<string, mixed>|Model  $row
     * @return array<string, mixed>
     */
    private function contextForRow(array|Model $row): array
    {
        $attrs = $row instanceof Model ? $row->getAttributes() : $row;

        if ($row instanceof Model) {
            $excluded = (new ModelSchemaResolver)->autoExcludedColumns($row);
            $attrs = array_diff_key($attrs, array_flip($excluded));
        }

        if ($this->promptContextColumns !== []) {
            $attrs = array_intersect_key($attrs, array_flip($this->promptContextColumns));
        }

        return $attrs;
    }

    /**
     * @param  array<int, array<string, mixed>|Model>  $batch
     */
    private function appendRecordsBlock(string $instruction, array $batch): string
    {
        [, $rows] = $this->enrich($batch);

        $json = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return trim($instruction)."\n\n## Records\n```json\n{$json}\n```";
    }

    private function appendBatchInstructions(string $instruction): string
    {
        $identifierKey = $this->identifierKey;

        $boilerplate = <<<TXT
## Instructions
For each record above, return an entry in `records` echoing the `{$identifierKey}` field unchanged with the processed fields.
For any record you cannot process, add an entry to `failed` with the `identifier` set to the `{$identifierKey}` value and a short `reason` (max 200 chars).
Preserve input order in the `records` array.
TXT;

        return trim($instruction)."\n\n".$boilerplate;
    }
}
