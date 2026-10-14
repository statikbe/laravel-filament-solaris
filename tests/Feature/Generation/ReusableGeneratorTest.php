<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Event;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Events\SolarisResponseReceived;
use Statikbe\FilamentSolaris\Generation\AiGenerator;

class ReusableTitleGenerator extends AiGenerator
{
    protected function setUp(): void
    {
        $this->prompt('Generate a title.')
            ->provider('openrouter', 'setup-model')
            ->schema(fn (JsonSchemaTypeFactory $s) => ['title' => $s->string()]);
    }
}

it('runs setUp() when built with make()', function () {
    SolarisAgent::fake([['title' => 'Hello']]);

    $result = ReusableTitleGenerator::make()->runInline();

    expect($result->data)->toBe(['title' => 'Hello']);
    SolarisAgent::assertPrompted(fn ($prompt) => $prompt->contains('Generate a title.'));
});

it('runs setUp() when built with new', function () {
    SolarisAgent::fake([['title' => 'Hello']]);

    $result = (new ReusableTitleGenerator)->runInline();

    expect($result->data)->toBe(['title' => 'Hello']);
});

it('runs setUp() when resolved from the container', function () {
    SolarisAgent::fake([['title' => 'Hello']]);

    $result = app(ReusableTitleGenerator::class)->runInline();

    expect($result->data)->toBe(['title' => 'Hello']);
    SolarisAgent::assertPrompted(fn ($prompt) => $prompt->contains('Generate a title.'));
});

it('lets a call-site setter override a setUp() default', function () {
    Event::fake([SolarisResponseReceived::class]);
    SolarisAgent::fake([['title' => 'Hello']]);

    ReusableTitleGenerator::make()->provider('anthropic', 'call-site-model')->runInline();

    Event::assertDispatched(
        SolarisResponseReceived::class,
        fn (SolarisResponseReceived $e) => $e->provider === 'anthropic' && $e->model === 'call-site-model',
    );
});

it('gives each instance its own configuration', function () {
    Event::fake([SolarisResponseReceived::class]);
    SolarisAgent::fake([['title' => 'A'], ['title' => 'B']]);

    ReusableTitleGenerator::make()->provider('anthropic', 'call-site-model')->runInline();
    ReusableTitleGenerator::make()->runInline();

    Event::assertDispatched(
        SolarisResponseReceived::class,
        fn (SolarisResponseReceived $e) => $e->provider === 'openrouter' && $e->model === 'setup-model',
    );
});

it('leaves a plain AiGenerator unconfigured', function () {
    SolarisAgent::fake([['title' => 'X']]);

    $result = AiGenerator::make()
        ->prompt('p')
        ->schema(fn (JsonSchemaTypeFactory $s) => ['title' => $s->string()])
        ->runInline();

    expect($result->data)->toBe(['title' => 'X']);
});
