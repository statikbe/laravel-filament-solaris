<?php

use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\GenerateFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

beforeEach(fn () => AiGenerateActionFake::reset());
afterEach(fn () => AiGenerateActionFake::reset());

it('records attachments from a static ->attachments() closure', function () {
    AiGenerateAction::fake(['description' => 'ok']);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('staticAttachments');

    AiGenerateAction::assertCalledWithAttachments(function (array $attachments): bool {
        return count($attachments) === 1
            && $attachments[0] instanceof Image;
    });
});

it('records attachments from a UserInput modal key', function () {
    AiGenerateAction::fake(['ok' => 'done']);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('userInputAttachment', data: ['csv_path' => 'attachments/sample.csv']);

    AiGenerateAction::assertCalledWithAttachments(function (array $attachments): bool {
        return count($attachments) === 1
            && $attachments[0] instanceof File;
    });
});

it('records attachments from a parent-form FileUpload field', function () {
    AiGenerateAction::fake(['ok' => 'done']);

    Livewire::test(GenerateFormComponent::class)
        ->fillForm(['upload' => 'attachments/parent.png'])
        ->callAction('parentFormAttachment');

    AiGenerateAction::assertCalledWithAttachments(function (array $attachments): bool {
        return count($attachments) === 1
            && $attachments[0] instanceof File;
    });
});

it('threads the same attachments to every per-row call in the records loop', function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->timestamps();
    });

    AiGenerateAction::fakeEach([
        ['name' => 'A', 'slug' => 'a'],
        ['name' => 'B', 'slug' => 'b'],
        ['name' => 'C', 'slug' => 'c'],
    ]);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('userInputAttachmentRecordsLoop', data: ['csv_path' => 'attachments/matrix.csv']);

    // Verify every captured call carried the same one-File attachments array.
    $fake = AiGenerateActionFake::getInstance();
    $calls = (function () {
        return $this->calls;
    })->call($fake);

    expect($calls)->toHaveCount(3);

    foreach ($calls as $call) {
        expect($call['attachments'])->toHaveCount(1)
            ->and($call['attachments'][0])->toBeInstanceOf(File::class);
    }

    expect(SeedCategory::count())->toBe(3);

    Schema::dropIfExists('seed_categories');
});

it('records an empty attachments array when none configured', function () {
    AiGenerateAction::fake(['x' => 'ok']);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('noUserInput');

    AiGenerateAction::assertCalledWithAttachments(fn (array $attachments) => $attachments === []);
});
