<?php

namespace Statikbe\FilamentSolaris\Support;

use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;

class UserInput
{
    protected string|Closure|null $prompt = null;

    protected string|Closure|null $placeholder = null;

    /**
     * @var array<Component>|Closure|null
     */
    protected array|Closure|null $fields = null;

    /**
     * Create a new UserInput instance.
     */
    public static function make(): static
    {
        return new static;
    }

    /**
     * Set the prompt label for the default textarea.
     */
    public function prompt(string|Closure $prompt): static
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function getPrompt(): ?string
    {
        return value($this->prompt);
    }

    /**
     * Set the placeholder for the default textarea.
     */
    public function placeholder(string|Closure $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function getPlaceholder(): ?string
    {
        return value($this->placeholder);
    }

    /**
     * Set custom Filament form fields.
     *
     * @param  array<Component>|Closure  $fields
     */
    public function fields(array|Closure $fields): static
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * @return array<Component>|null
     */
    public function getFields(): ?array
    {
        return value($this->fields);
    }

    /**
     * Build the Filament form schema for the user input modal.
     *
     * @return array<Component>
     */
    public function toFormSchema(): array
    {
        $fields = $this->getFields();

        if ($fields !== null) {
            return $fields;
        }

        $prompt = $this->getPrompt();
        $placeholder = $this->getPlaceholder();

        $textarea = Textarea::make('user_instructions')
            ->label($prompt ?? filament_solaris_trans('user_input.additional_instructions'))
            ->rows(3);

        if ($placeholder !== null) {
            $textarea = $textarea->placeholder($placeholder);
        }

        return [$textarea];
    }
}
