<?php

namespace Phord\Process;

use RuntimeException;

/**
 * A caller addressed a method on a PhordProcess that is not a callable handler —
 * it doesn't exist, isn't public, is static, or is part of the PhordProcess base
 * contract (handle/isRunning/tick/listen/…, which are reserved so a remote caller
 * can't drive the process's own machinery).
 *
 * This is the CALLER's mistake, not corrupt state, so the process reports it and
 * keeps running (its warm state is innocent) — unlike a handler that throws, which
 * lets the process crash.
 */
class UnknownMessageException extends RuntimeException
{
    public function __construct(string $method, string $class)
    {
        parent::__construct("Phord process: '{$method}' is not a callable handler on {$class}");
    }
}
