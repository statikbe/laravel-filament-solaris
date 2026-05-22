<?php

namespace Statikbe\FilamentSolaris\Testing;

trait WithDictationToolbarActionFake
{
    /**
     * @after
     */
    protected function resetDictationToolbarActionFake(): void
    {
        DictationToolbarActionFake::reset();
    }
}
