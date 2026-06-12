<?php

use Laravel\Ai\Files\Document;
use Statikbe\FilamentSolaris\Jobs\ProcessChunkJob;

it('round-trips attachments through File::toArray()/fromArray()', function () {
    $file = Document::fromStorage('invoices/list.pdf', 'local');

    $serialized = array_map(fn ($f) => $f->toArray(), [$file]);

    $job = new ProcessChunkJob(
        config: makeRunConfig('run-x'),
        prompt: 'Extract products from the PDF.',
        rowDescriptors: [],
        attachments: $serialized,
    );

    $rebuilt = (new ReflectionMethod(ProcessChunkJob::class, 'rehydrateAttachments'))->invoke($job);

    expect($rebuilt)->toHaveCount(1)
        ->and($rebuilt[0])->toBeInstanceOf(Document::class);
});
