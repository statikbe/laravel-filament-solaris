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
use Laravel\Ai\Responses\StructuredAgentResponse;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Events\SolarisBatchProgressed;
use Statikbe\FilamentSolaris\Support\Batch\BatchGenerationException;
use Statikbe\FilamentSolaris\Support\Batch\BatchProcessor;
use Statikbe\FilamentSolaris\Support\Batch\BatchResponse;
use Statikbe\FilamentSolaris\Support\Batch\BatchRunConfig;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\CompositeBatchSink;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\DatabaseBatchSink;
use Statikbe\FilamentSolaris\Support\Batch\Sinks\InMemoryBatchSink;
use Statikbe\FilamentSolaris\Support\ModelSchemaResolver;
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

    /** @param  array<int, array<string, mixed>>  $rowDescriptors */
    public function __construct(
        public BatchRunConfig $config,
        public string $prompt,
        public array $rowDescriptors,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $collector = new InMemoryBatchSink;
        $sink = new CompositeBatchSink([$collector, new DatabaseBatchSink($this->config->runId)]);

        $processor = new BatchProcessor(
            $this->config->identifierKey,
            fn (array $batch): BatchResponse => $this->generate(),
            fn (mixed $row, array $attrs) => $this->writeRow($row, $attrs),
            $sink,
        );

        // One job == one chunk: a batchSize >= count keeps it a single chunk.
        $processor->process($this->rowDescriptors, max(1, count($this->rowDescriptors)));

        SolarisBatchProgressed::dispatch(
            $this->config->runId,
            $this->config->actionName,
            $collector->succeeded(),
            count($collector->failures()),
            count($collector->discarded()),
        );
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
            $response = $agent->prompt($this->prompt, [], $this->config->provider, $this->config->model, $this->config->timeout);
        } catch (\Throwable $e) {
            throw new BatchGenerationException($e->getMessage());
        }

        // Structured-output agents resolve to a StructuredAgentResponse; guard the contract.
        if (! $response instanceof StructuredAgentResponse) {
            throw new BatchGenerationException('AI call error');
        }

        return BatchResponse::fromArray($response->toArray());
    }

    /** @param  array<string, mixed>  $attrs */
    private function writeRow(mixed $row, array $attrs): void
    {
        $modelClass = $this->config->modelClass;

        if ($modelClass === null) {
            throw new \RuntimeException('ProcessChunkJob requires a model class for write-back.');
        }

        if ($this->config->writeTerminal === AiGenerateAction::WRITE_CREATE) {
            $modelClass::create($attrs);

            return;
        }

        $key = is_array($row) ? ($row[$this->config->identifierKey] ?? null) : null;
        $model = $key === null ? null : $modelClass::find($key);

        if ($model === null) {
            throw new \RuntimeException('updateRecords target no longer exists for identifier '.json_encode($key));
        }

        $model->update($attrs);
    }

    /** @return Closure(JsonSchemaTypeFactory): array<string, mixed> */
    private function schemaResolver(): Closure
    {
        $config = $this->config;

        return function (JsonSchemaTypeFactory $schema) use ($config): array {
            /** @var class-string<Model> $modelClass */
            $modelClass = $config->modelClass;

            $properties = (new ModelSchemaResolver)->resolve(
                $schema,
                $modelClass,
                $config->onlyColumns,
                $config->exceptColumns,
                $config->columnHints,
                $config->columnEnums,
            );

            $properties[$config->identifierKey] = $config->identifierKey === '_index'
                ? $schema->integer()->description('The _index field from the input record. Echo unchanged.')
                : $schema->integer()->description('The primary key. Echo unchanged.');

            return [
                AiGenerateAction::RECORDS_KEY => $schema->array()->items($schema->object($properties)),
                AiGenerateAction::FAILED_KEY => $schema->array()->items($schema->object([
                    'identifier' => $schema->string()->description('Identifier of the failed input row.'),
                    'reason' => $schema->string()->description('Short reason for the failure (max 200 chars).'),
                ])),
            ];
        };
    }
}
