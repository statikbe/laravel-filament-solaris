<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Laravel\Ai\Files\Image;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Support\UserInput;

class AttachmentFormComponent extends FormsComponent
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'title' => '',
            'description' => '',
            'reference_image' => null,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                TextInput::make('title')->required(),
                Textarea::make('description'),
                FileUpload::make('reference_image')->image(),
                Textarea::make('summary'),
            ])
            ->statePath('data');
    }

    public function summarizeAction(): AiFormAction
    {
        return $this->aiAction('summarize')
            ->attachmentField('reference_image');
    }

    public function summarizeFromUserInputAction(): AiFormAction
    {
        // Modal field is a TextInput (not FileUpload) for testability — the
        // resolver reads `$userInput['extra_image']` regardless of which
        // component produced it. Live consumers would put a FileUpload here.
        return $this->aiAction('summarizeFromUserInput')
            ->userInput(
                UserInput::make()->fields([
                    TextInput::make('extra_image'),
                ])
            )
            ->attachmentFromUserInput('extra_image');
    }

    public function summarizeWithClosureAction(): AiFormAction
    {
        return $this->aiAction('summarizeWithClosure')
            ->attachments(fn () => [Image::fromUrl('https://example.com/logo.png')]);
    }

    public function summarizeAllChannelsAction(): AiFormAction
    {
        return $this->aiAction('summarizeAllChannels')
            ->attachmentField('reference_image')
            ->userInput(
                UserInput::make()->fields([
                    FileUpload::make('extra_image')->image(),
                ])
            )
            ->attachmentFromUserInput('extra_image')
            ->attachments(fn () => [Image::fromUrl('https://example.com/logo.png')]);
    }

    public function summarizeWithClosureFieldAction(): AiFormAction
    {
        return $this->aiAction('summarizeWithClosureField')
            ->attachmentField(fn () => 'reference_image');
    }

    public function summarizeWithClosureFieldArrayAction(): AiFormAction
    {
        return $this->aiAction('summarizeWithClosureFieldArray')
            ->attachmentField(fn () => ['reference_image']);
    }

    public function summarizeWithClosureUserInputAction(): AiFormAction
    {
        return $this->aiAction('summarizeWithClosureUserInput')
            ->userInput(
                UserInput::make()->fields([
                    TextInput::make('extra_image'),
                ])
            )
            ->attachmentFromUserInput(fn () => 'extra_image');
    }

    public function summarizeWithStaticAttachmentArrayAction(): AiFormAction
    {
        return $this->aiAction('summarizeWithStaticAttachmentArray')
            ->attachments([Image::fromUrl('https://example.com/static.png')]);
    }

    public function summarizeWithSingleFileInstanceAction(): AiFormAction
    {
        return $this->aiAction('summarizeWithSingleFileInstance')
            ->attachments(Image::fromUrl('https://example.com/single.png'));
    }

    public function summarizeWithSingleUploadedFileAction(): AiFormAction
    {
        return $this->aiAction('summarizeWithSingleUploadedFile')
            ->attachments(createTempUploadedFile('cover.png', 'image/png', 'fake-png'));
    }

    public function summarizeWithMixedArrayAction(): AiFormAction
    {
        return $this->aiAction('summarizeWithMixedArray')
            ->attachments([
                Image::fromUrl('https://example.com/logo.png'),
                createTempUploadedFile('clip.mp3', 'audio/mpeg', 'fake-audio'),
            ]);
    }

    public function summarizeWithClosureReturningSingleFileAction(): AiFormAction
    {
        return $this->aiAction('summarizeWithClosureReturningSingleFile')
            ->attachments(fn () => Image::fromUrl('https://example.com/closure-single.png'));
    }

    /**
     * Build the baseline AiFormAction used by every fixture method —
     * every action in this fixture shares the same source/target/prompt.
     */
    private function aiAction(string $name): AiFormAction
    {
        return AiFormAction::make($name)
            ->sourceFields(['title'])
            ->targetField('summary')
            ->prompt('Summarize.');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
