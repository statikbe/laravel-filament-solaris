<?php

namespace Statikbe\FilamentSolaris\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Statikbe\FilamentSolaris\Contracts\ComponentFactory;

class SolarisAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    protected string $systemPrompt = '';

    /**
     * @var array<string, ComponentFactory>
     */
    protected array $factories = [];

    /** @var iterable<Tool|ProviderTool> */
    protected iterable $tools = [];

    /**
     * Configure the agent for a specific AI call.
     *
     * @param  string  $systemPrompt  The composed prompt from the PromptBuilder
     * @param  array<string, ComponentFactory>  $factories  Target field factories
     */
    public function configure(string $systemPrompt, array $factories): static
    {
        $this->systemPrompt = $systemPrompt;
        $this->factories = $factories;

        return $this;
    }

    /**
     * Set the tools available to the agent for this call.
     *
     * @param  iterable<Tool|ProviderTool>  $tools
     */
    public function withTools(iterable $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @return iterable<Tool|ProviderTool>
     */
    public function tools(): iterable
    {
        return $this->tools;
    }

    /**
     * {@inheritDoc}
     */
    public function instructions(): string
    {
        return $this->systemPrompt;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        assert($schema instanceof JsonSchemaTypeFactory);

        return collect($this->factories)
            ->map(fn (ComponentFactory $factory) => $factory->responseSchema($schema))
            ->all();
    }
}
