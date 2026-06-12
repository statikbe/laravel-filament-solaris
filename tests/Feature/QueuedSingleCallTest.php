<?php

use Laravel\Ai\Files\Document;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Jobs\ProcessChunkJob;
use Statikbe\FilamentSolaris\Support\Batch\BatchRunConfig;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

it('round-trips attachments through File::toArray()/fromArray()', function () {
    $file = Document::fromStorage('invoices/list.pdf', 'local');

    $serialized = array_map(fn ($f) => $f->toArray(), [$file]);

    $job = new ProcessChunkJob(
        config: new BatchRunConfig(
            actionName: 'importCategories',
            modelClass: SeedCategory::class,
            onlyColumns: [], exceptColumns: [], columnHints: [], columnEnums: [],
            identifierKey: '_index',
            writeTerminal: 'create',
            provider: null, model: null, timeout: null,
            runId: 'run-x',
            temperature: null, maxTokens: null, maxSteps: null, topP: null,
        ),
        prompt: 'Extract products from the PDF.',
        rowDescriptors: [],
        attachments: $serialized,
    );

    $rebuilt = (new ReflectionMethod(ProcessChunkJob::class, 'rehydrateAttachments'))->invoke($job);

    expect($rebuilt)->toHaveCount(1)
        ->and($rebuilt[0])->toBeInstanceOf(Document::class);
});

it('rejects a local-path attachment when queued (worker cannot reach it)', function () {
    $action = (new ReflectionClass(AiGenerateAction::class))->newInstanceWithoutConstructor();
    $serialize = (new ReflectionMethod(AiGenerateAction::class, 'serializeAttachments'))->getClosure($action);

    expect(fn () => $serialize([Document::fromPath('/tmp/x.pdf')]))
        ->toThrow(RuntimeException::class, 'disk-backed');
});
