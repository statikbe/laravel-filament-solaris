<?php

namespace Statikbe\FilamentSolaris\Agents;

use Closure;
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

    protected ?Closure $schemaResolver = null;

    /** @var iterable<Tool|ProviderTool> */
    protected iterable $tools = [];

    protected ?float $temperature = null;

    protected ?int $maxTokens = null;

    protected ?int $maxSteps = null;

    protected ?float $topP = null;

    /**
     * Configure the agent for a specific AI call.
     *
     * @param  string  $systemPrompt  The composed prompt
     * @param  array<string, ComponentFactory>  $factories  Target field factories (form actions)
     * @param  ?Closure  $schemaResolver  fn(JsonSchema): array<string, Type> — overrides factory-derived schema (AiGenerateAction)
     */
    public function configure(string $systemPrompt, array $factories = [], ?Closure $schemaResolver = null): static
    {
        $this->systemPrompt = $systemPrompt;
        $this->factories = $factories;
        $this->schemaResolver = $schemaResolver;

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

    public function withTemperature(?float $temperature): static
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function withMaxTokens(?int $maxTokens): static
    {
        $this->maxTokens = $maxTokens;

        return $this;
    }

    public function withMaxSteps(?int $maxSteps): static
    {
        $this->maxSteps = $maxSteps;

        return $this;
    }

    public function withTopP(?float $topP): static
    {
        $this->topP = $topP;

        return $this;
    }

    /**
     * Read by laravel/ai's TextGenerationOptions::forAgent() before falling back to #[Temperature].
     */
    public function temperature(): ?float
    {
        return $this->temperature;
    }

    /**
     * Read by laravel/ai's TextGenerationOptions::forAgent() before falling back to #[MaxTokens].
     */
    public function maxTokens(): ?int
    {
        return $this->maxTokens;
    }

    /**
     * Read by laravel/ai's TextGenerationOptions::forAgent() before falling back to #[MaxSteps].
     */
    public function maxSteps(): ?int
    {
        return $this->maxSteps;
    }

    /**
     * Read by laravel/ai's TextGenerationOptions::forAgent() before falling back to #[TopP].
     */
    public function topP(): ?float
    {
        return $this->topP;
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

        if ($this->schemaResolver !== null) {
            return ($this->schemaResolver)($schema);
        }

        return collect($this->factories)
            ->map(fn (ComponentFactory $factory) => $factory->responseSchema($schema))
            ->all();
    }
}
