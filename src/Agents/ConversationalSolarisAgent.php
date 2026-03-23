<?php

namespace Statikbe\FilamentSolaris\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Conversational;

class ConversationalSolarisAgent extends SolarisAgent implements Conversational
{
    use RemembersConversations;

    /**
     * {@inheritDoc}
     *
     * Prepends a `_message` field so the AI provides a brief natural-language
     * explanation alongside its structured field values.
     */
    public function schema(JsonSchema $schema): array
    {
        assert($schema instanceof JsonSchemaTypeFactory);

        return ['_message' => $schema->string()->description('A brief, friendly explanation of what you did and why.')]
            + parent::schema($schema);
    }

    /**
     * Maximum number of conversation messages to include in context.
     */
    protected function maxConversationMessages(): int
    {
        return 50;
    }
}
