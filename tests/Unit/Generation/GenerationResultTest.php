<?php

use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Statikbe\FilamentSolaris\Generation\GenerationResult;

it('exposes the parsed data, usage, conversation id and raw response', function () {
    $usage = new Usage;
    $raw = Mockery::mock(StructuredAgentResponse::class);

    $result = new GenerationResult(
        data: ['foo' => 'bar'],
        usage: $usage,
        conversationId: 'conv-123',
        response: $raw,
    );

    expect($result->data)->toBe(['foo' => 'bar'])
        ->and($result->usage)->toBe($usage)
        ->and($result->conversationId)->toBe('conv-123')
        ->and($result->response)->toBe($raw);
});

it('allows null usage and conversation id', function () {
    $raw = Mockery::mock(StructuredAgentResponse::class);

    $result = new GenerationResult(data: [], usage: null, conversationId: null, response: $raw);

    expect($result->usage)->toBeNull()
        ->and($result->conversationId)->toBeNull();
});
