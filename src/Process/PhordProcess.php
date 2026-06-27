<?php

namespace Phord\Process;

/**
 * The base contract for a Phord-supervised long-running PHP process — a RabbitMQ
 * listener, an FTP/mail poller, a v8js "ingester". Framework-agnostic (lives in
 * phord/core, no Laravel dependency); the Laravel-aware boot lives in
 * phord/laravel.
 *
 * A PhordProcess is blissfully SINGLE-THREADED PHP: its `handle()` loop does one
 * thing. Concurrency is the BEAM running MANY such processes as separate OS
 * processes — never PHP threads. Want ten listeners → start ten processes; the
 * BEAM (Phord.Process.Supervisor) keeps each alive.
 *
 * The contract is exactly three things:
 *
 *   - handle(...$args): void — the long-running body (developer-implemented).
 *   - isRunning(): bool      — the cooperative-stop check; true normally, flips
 *                              false when a stop is requested (SIGTERM).
 *   - installSignalHandler() — wires the SIGTERM trap that flips isRunning().
 *                              Called by the boot entry (Runtime) before handle().
 *
 * ## Stop-ability is the developer's responsibility
 *
 * Phord delivers stop by **signal** (SIGTERM), not by closing a socket: a process
 * blocked inside its own `handle()` loop (e.g. a blocking `$conn->consume()`)
 * cannot be woken by an EOF on the BEAM control socket — it isn't reading it. A
 * signal is the only thing that reaches into a process blocked in an arbitrary
 * syscall. So:
 *
 *   - Build a STOP-ABLE loop — timeout'd blocking calls + a `while
 *     ($this->isRunning())` check each iteration — and SIGTERM flips the flag,
 *     the loop exits, your teardown runs, `handle()` returns: a clean shutdown.
 *   - Build an INFINITELY-blocking loop that never checks `isRunning()` and Phord
 *     can only SIGKILL it after the grace window, possibly mid-work. Phord cannot
 *     force a blocked PHP thread to exit cleanly; it offers the mechanism and, as
 *     a last resort, kills hard.
 *
 * ## Layer-2 seam (documented, NOT built)
 *
 * A future bidirectional `BEAM.call(name, 'handle', [request])` would deliver a
 * request INTO a live process and return a response. The attach point is the
 * control socket (see Connection) and an OPTIONAL `handleMessage($msg)` this
 * contract could grow — a cooperative read point the loop polls between
 * iterations. It is deliberately absent in v1: a process blocked in `handle()`
 * cannot service an inbound message until its loop yields.
 */
abstract class PhordProcess
{
    private bool $stopping = false;

    /** The long-running body. Loop on isRunning(); return to exit cleanly. */
    abstract public function handle(...$args): void;

    /**
     * Cooperative-stop check. Returns true normally; flips to false once a stop
     * has been requested (the SIGTERM handler). Developers loop on this.
     */
    final public function isRunning(): bool
    {
        return ! $this->stopping;
    }

    /**
     * Wire the SIGTERM trap: an async signal handler that flips the stopping flag
     * so the next isRunning() returns false. Idempotent. Called by the boot entry
     * before handle(); a no-op on builds without ext-pcntl (the process can then
     * only be SIGKILL'd, honestly).
     */
    final public function installSignalHandler(): void
    {
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void {
                $this->stopping = true;
            });
        }
    }

    /**
     * Request a stop programmatically (e.g. from a developer's own message
     * handler). Mirrors what the SIGTERM trap does.
     */
    final public function requestStop(): void
    {
        $this->stopping = true;
    }
}
