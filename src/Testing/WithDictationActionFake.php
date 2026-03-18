<?php

namespace Statikbe\FilamentSolaris\Testing;

trait WithDictationActionFake
{
    /**
     * @after
     */
    protected function resetDictationActionFake(): void
    {
        DictationActionFake::reset();
        AiActionFake::reset();
    }
}
