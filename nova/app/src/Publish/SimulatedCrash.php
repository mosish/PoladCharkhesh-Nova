<?php

declare(strict_types=1);

namespace Nova\Publish;

use RuntimeException;

/** Thrown by a test fault injector to stand for a killed process: the engine does no cleanup for it. */
final class SimulatedCrash extends RuntimeException {}
