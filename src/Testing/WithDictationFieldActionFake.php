<?php

namespace Statikbe\FilamentSolaris\Testing;

trait WithDictationFieldActionFake
{
    /**
     * @after
     */
    protected function resetDictationFieldActionFake(): void
    {
        DictationFieldActionFake::reset();
        AiActionFake::reset();
    }
}
