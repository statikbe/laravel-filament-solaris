<?php

namespace Statikbe\FilamentSolaris\Jobs;

use Closure;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Events\SolarisBatchProgressed;
use Statikbe\FilamentSolaris\Support\Batch\BatchGenerationException;
use Statikbe\FilamentSolaris\Support\Batch\BatchOutcome;
use Statikbe\FilamentSolaris\Support\Batch\BatchProcessor;
use Statikbe\FilamentSolaris\Support\Batch\BatchResponse;
use Statikbe\FilamentSolaris\Support\Batch\BatchRunConfig;
use Statikbe\FilamentSolaris\Support\Batch\FailedRecord;
use Statikbe\FilamentSolaris\Support\Batch\RecordsSchemaBuilder;
use Statikbe\FilamentSolaris\Support\Batch\RecordWriter;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\CompositeBatchSink;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\DatabaseBatchSink;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\InMemoryBatchSink;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;

/**
 * One queued chunk of a records-loop run: rebuild the agent from BatchRunConfig
 * (no closures), generate synchronously, reconcile + write through the shared
 * BatchProcessor, persist outcomes via DatabaseBatchSink. tries = 1 — createRecords
 * is not idempotent, so we never silently re-create on retry (spec 30 §7).
 */
class ProcessChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<int, array<string, mixed>>  $rowDescriptors
     * @param  array<int, array<string, mixed>>  $attachments  serialized File::toArray() payloads
     */
    public function __construct(
        public BatchRunConfig $config,
        public string $prompt,
        public array $rowDescriptors,
        public array $attachments = [],
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $collector = new InMemoryBatchSink;
        $sink = new CompositeBatchSink([$collector, new DatabaseBatchSink($this->config->runId)]);

        if ($this->rowDescriptors === []) {
            $this->processFromScratch($sink);
        } else {
            $processor = new BatchProcessor(
                $this->config->identifierKey,
                fn (array $batch): BatchResponse => $this->generate(),
                fn (mixed $row, array $attrs) => $this->writeRow($row, $attrs),
                $sink,
            );

            // One job == one chunk: a batchSize >= count keeps it a single chunk.
            $processor->process($this->rowDescriptors, max(1, count($this->rowDescriptors)));
        }

        SolarisBatchProgressed::dispatch(
            $this->config->runId,
            $this->config->actionName,
            $collector->succeeded(),
            count($collector->failures()),
            count($collector->discarded()),
        );
    }

    /**
     * Single-call / from-scratch (no input rows): generate once and create every
     * returned record. Mirrors AiGenerateAction::handleSingleCallResponse's
     * create loop, but emits the outcome to the sink instead of notifying.
     */
    private function processFromScratch(CompositeBatchSink $sink): void
    {
        try {
            $response = $this->generate();
        } catch (BatchGenerationException $e) {
            $sink->record(new BatchOutcome(0, [new FailedRecord(null, $e->getMessage(), null)], []));

            return;
        }

        $succeeded = 0;
        $failures = $response->failed;
        $key = $this->config->identifierKey;

        foreach ($response->records as $index => $record) {
            $attrs = $record;
            unset($attrs[$key]);

            try {
                $this->writeRow($record, $attrs);
                $succeeded++;
            } catch (\Throwable $e) {
                $failures[] = new FailedRecord($record[$key] ?? $index, 'write error: '.$e->getMessage(), $record);
            }
        }

        $sink->record(new BatchOutcome($succeeded, $failures, []));
    }

    private function generate(): BatchResponse
    {
        if (AiGenerateActionFake::isActive()) {
            $fake = AiGenerateActionFake::getInstance();
            $raw = $fake->getResponse();
            $fake->recordCall($this->config->actionName, $raw, [], [], $this->rowDescriptors);

            if ($fake->shouldSimulateError()) {
                throw new BatchGenerationException($fake->getErrorMessage());
            }

            return BatchResponse::fromArray($raw);
        }

        $agent = (new SolarisAgent)->configure($this->prompt, [], $this->schemaResolver());
        $agent->withTemperature($this->config->temperature)
            ->withMaxTokens($this->config->maxTokens)
            ->withMaxSteps($this->config->maxSteps)
            ->withTopP($this->config->topP);

        try {
            $response = $agent->prompt($this->prompt, $this->rehydrateAttachments(), $this->config->provider, $this->config->model, $this->config->timeout);
        } catch (\Throwable $e) {
            throw new BatchGenerationException($e->getMessage());
        }

        // Structured-output agents resolve to a StructuredAgentResponse; guard the contract.
        if (! $response instanceof StructuredAgentResponse) {
            throw new BatchGenerationException('AI call error');
        }

        return BatchResponse::fromArray($response->toArray());
    }

    /**
     * @return array<int, File>
     */
    private function rehydrateAttachments(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $data): ?File => File::fromArray($data),
            $this->attachments,
        )));
    }

    /** @param  array<string, mixed>  $attrs */
    private function writeRow(mixed $row, array $attrs): void
    {
        $modelClass = $this->config->modelClass;
        $terminal = $this->config->writeTerminal;

        if ($modelClass === null || $terminal === null) {
            throw new \RuntimeException('ProcessChunkJob requires a model class + write terminal for write-back.');
        }

        (new RecordWriter($modelClass, $terminal, $this->config->sanitizers))->write($row, $attrs);
    }

    /** @return Closure(JsonSchemaTypeFactory): array<string, mixed> */
    private function schemaResolver(): Closure
    {
        $config = $this->config;

        return function (JsonSchemaTypeFactory $schema) use ($config): array {
            /** @var class-string<Model> $modelClass */
            $modelClass = $config->modelClass;

            return (new RecordsSchemaBuilder)->build(
                $schema,
                $modelClass,
                $config->identifierKey,
                $config->onlyColumns,
                $config->exceptColumns,
                $config->columnHints,
                $config->columnEnums,
            );
        };
    }
}
