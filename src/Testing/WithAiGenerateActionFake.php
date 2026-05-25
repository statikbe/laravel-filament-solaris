<?php

namespace Statikbe\FilamentSolaris\Testing;

trait WithAiGenerateActionFake
{
    /**
     * @after
     */
    protected function resetAiGenerateActionFake(): void
    {
        AiGenerateActionFake::reset();
    }
}
