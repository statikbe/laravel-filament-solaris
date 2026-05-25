<?php

namespace Statikbe\FilamentSolaris\Testing;

trait WithAiFormActionFake
{
    /**
     * @after
     */
    protected function resetAiFormActionFake(): void
    {
        AiFormActionFake::reset();
        DictationFieldActionFake::reset();
        ImageGenerationActionFake::reset();
    }
}
