<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Laravel\Ai\Files\Image;
use Statikbe\FilamentSolaris\Actions\AiAction;
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

    public function summarizeAction(): AiAction
    {
        return AiAction::make('summarize')
            ->sourceFields(['title'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->attachmentField('reference_image');
    }

    public function summarizeFromUserInputAction(): AiAction
    {
        // Modal field is a TextInput (not FileUpload) for testability — the
        // resolver reads `$userInput['extra_image']` regardless of which
        // component produced it. Live consumers would put a FileUpload here.
        return AiAction::make('summarizeFromUserInput')
            ->sourceFields(['title'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->userInput(
                UserInput::make()->fields([
                    TextInput::make('extra_image'),
                ])
            )
            ->attachmentFromUserInput('extra_image');
    }

    public function summarizeWithClosureAction(): AiAction
    {
        return AiAction::make('summarizeWithClosure')
            ->sourceFields(['title'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->attachments(fn () => [Image::fromUrl('https://example.com/logo.png')]);
    }

    public function summarizeAllChannelsAction(): AiAction
    {
        return AiAction::make('summarizeAllChannels')
            ->sourceFields(['title'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->attachmentField('reference_image')
            ->userInput(
                UserInput::make()->fields([
                    FileUpload::make('extra_image')->image(),
                ])
            )
            ->attachmentFromUserInput('extra_image')
            ->attachments(fn () => [Image::fromUrl('https://example.com/logo.png')]);
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
