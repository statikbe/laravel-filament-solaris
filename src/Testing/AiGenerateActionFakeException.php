<?php

namespace Statikbe\FilamentSolaris\Testing;

use LogicException;

/**
 * Signals test-config misuse of {@see AiGenerateActionFake} (e.g. a fakeEach()
 * queue that runs out before the loop finishes). Distinct from runtime
 * exceptions so per-row loops can re-throw it instead of swallowing it as a
 * "row failed" — a test-config bug should fail the test, not silently count.
 */
class AiGenerateActionFakeException extends LogicException {}
