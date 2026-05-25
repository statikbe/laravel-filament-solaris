<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\AiFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
});

afterEach(function () {
    AiFormActionFake::reset();
});

it('runs the per-action sanitizer on every target field', function () {
    AiFormAction::fake([
        'summary' => '<p>Hello <script>alert(1)</script> world</p>',
        'category' => 'tech',
    ]);

    Livewire::test(AiFormComponent::class)
        ->fillForm(['title' => 'X', 'body' => 'Y'])
        ->callAction('generateWithSanitizer')
        ->assertFormSet([
            // <p> and <script> stripped by strip_tags
            'summary' => 'Hello alert(1) world',
            // Category passes through strip_tags unchanged (no tags to strip)
            'category' => 'tech',
        ]);
});

it('lets sanitizeField override the per-action sanitizer for that field', function () {
    AiFormAction::fake([
        'summary' => 'lowercase summary',
        'category' => 'tech',
    ]);

    Livewire::test(AiFormComponent::class)
        ->fillForm(['title' => 'X', 'body' => 'Y'])
        ->callAction('generateWithFieldSanitizer')
        ->assertFormSet([
            // sanitizeField('summary', strtoupper) wins over the per-action strip_tags
            'summary' => 'LOWERCASE SUMMARY',
            // category falls back to the per-action strip_tags (no tags here)
            'category' => 'tech',
        ]);
});

it('treats sanitizer exceptions as field failures and reports them', function () {
    AiFormAction::fake(['summary' => 'whatever']);

    Livewire::test(AiFormComponent::class)
        ->fillForm(['title' => 'X', 'body' => 'Y'])
        ->callAction('generateWithThrowingSanitizer')
        // summary should NOT be set — the throwing sanitizer routes the
        // value into failedLabels exactly like a toFormValue failure.
        ->assertFormSet(['summary' => '']);
});

it('does not affect output when no sanitizer is configured', function () {
    AiFormAction::fake(['summary' => '<p>untouched</p>']);

    Livewire::test(AiFormComponent::class)
        ->fillForm(['title' => 'X', 'body' => 'Y'])
        ->callAction('generateSummary')
        ->assertFormSet(['summary' => '<p>untouched</p>']);
});
