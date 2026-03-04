<?php

namespace Statikbe\FilamentSolaris\Testing;

trait WithAiActionFake
{
    /**
     * @after
     */
    protected function resetAiActionFake(): void
    {
        AiActionFake::reset();
    }
}
