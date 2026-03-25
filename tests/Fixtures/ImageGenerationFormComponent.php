<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\Actions\ImageGenerationAction;
use Statikbe\FilamentSolaris\Enums\ImageQuality;
use Statikbe\FilamentSolaris\Enums\ImageSize;
use Statikbe\FilamentSolaris\Support\UserInput;

class ImageGenerationFormComponent extends FormsComponent
{
    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * Mount the component with initial form data.
     */
    public function mount(): void
    {
        $this->form->fill([
            'title' => '',
            'description' => '',
            'featured_image' => '',
        ]);
    }

    /**
     * Define the form schema.
     */
    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                TextInput::make('title')
                    ->label('Title')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label('Description'),
                TextInput::make('featured_image')
                    ->label('Featured Image'),
            ])
            ->statePath('data');
    }

    /**
     * Basic image generation — generates and sets on target field.
     */
    public function generateImageAction(): ImageGenerationAction
    {
        return ImageGenerationAction::make('generateImage')
            ->prompt('Generate a product photo')
            ->targetField('featured_image');
    }

    /**
     * Image generation with source fields.
     */
    public function generateWithSourceAction(): ImageGenerationAction
    {
        return ImageGenerationAction::make('generateWithSource')
            ->prompt('Generate an image based on the product details')
            ->sourceFields(['title', 'description'])
            ->targetField('featured_image');
    }

    /**
     * Image generation with all options.
     */
    public function generateFullOptionsAction(): ImageGenerationAction
    {
        return ImageGenerationAction::make('generateFullOptions')
            ->prompt('Generate a hero banner')
            ->sourceFields(['title'])
            ->targetField('featured_image')
            ->imageSize(ImageSize::Landscape)
            ->imageQuality(ImageQuality::High)
            ->disk('public')
            ->directory('hero-images')
            ->provider('openai', 'gpt-image-1.5')
            ->timeout(120);
    }

    /**
     * Image generation with user input.
     */
    public function generateWithUserInputAction(): ImageGenerationAction
    {
        return ImageGenerationAction::make('generateWithUserInput')
            ->prompt('Generate an image')
            ->targetField('featured_image')
            ->userInput(UserInput::make());
    }

    /**
     * Invalid — no target field.
     */
    public function generateNoTargetAction(): ImageGenerationAction
    {
        return ImageGenerationAction::make('generateNoTarget')
            ->prompt('Generate something');
    }

    /**
     * Invalid — no prompt.
     */
    public function generateNoPromptAction(): ImageGenerationAction
    {
        return ImageGenerationAction::make('generateNoPrompt')
            ->targetField('featured_image');
    }

    /**
     * Render the component.
     */
    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
