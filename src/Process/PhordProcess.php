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
 * The contract is:
 *
 *   - handle(...$args): void — the long-running body (developer-implemented).
 *   - isRunning(): bool      — the cooperative-stop check; true normally, flips
 *                              false when a stop is requested (SIGTERM).
 *   - installSignalHandler() — wires the SIGTERM trap that flips isRunning().
 *                              Called by the boot entry (Runtime) before handle().
 *   - tick() / listen()      — drain inbound calls addressed at this process (see
 *                              "Talking to a process" below). Optional.
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
 * ## Talking to a process — inbound calls
 *
 * A process can serve inbound calls addressed at it by name from anywhere in the
 * app: `BeamSupervisor::get('rabbitmq-4711')->call('queueDepth')`. Each call is
 * routed to a PUBLIC method of the same name declared on YOUR subclass — the base
 * contract methods (handle/isRunning/tick/listen/…) are reserved and never
 * callable. The return value is the reply.
 *
 *     class RabbitListener extends PhordProcess {
 *         private int $seen = 0;
 *         public function handle(...$args): void {
 *             while ($this->isRunning()) {
 *                 if ($msg = $conn->consume(timeout: 1)) { $this->seen++; }
 *                 $this->tick();          // drain any inbound calls, non-blocking
 *             }
 *         }
 *         public function seenCount(): int { return $this->seen; }   // callable
 *     }
 *
 * Because a process owns its own loop, it must YIELD to read the control socket:
 *
 *   - tick()  — non-blocking; serves every currently-pending call, then returns.
 *               Call it once per iteration of a loop that also does its own work.
 *   - listen() — blocking; serves calls until stop/EOF. For a PURE responder whose
 *               only job is answering calls (no other loop) — `handle()` is just
 *               `$this->listen();`.
 *
 * Delivery is strictly one-at-a-time (single-threaded PHP): the BEAM feeds one
 * call and waits for the reply before the next, so handlers never interleave. A
 * handler that THROWS reports the error to the caller and then lets the process
 * crash (the Runner backoff-respawns a fresh instance) — so don't swallow errors
 * you can't recover from; guard inside the handler if the process must survive.
 * Inside a handler you may freely use the cache/queue back-channel or message
 * ANOTHER process — those travel a separate channel, never this control socket.
 */
abstract class PhordProcess
{
    private bool $stopping = false;

    /** The control transport for inbound calls; wired by the boot entry (Runtime). */
    private ?Connection $connection = null;

    /**
     * How a routed handler is invoked. null → a plain `$this->$method(...$args)`
     * (raw core boot). The Laravel boot sets this to a container invoker so handler
     * arguments get dependency-injected. @var (callable(string, array): mixed)|null
     */
    private $invoker = null;

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

    /**
     * Wire the inbound-call control transport. Called by the boot entry (Runtime)
     * after the boot handshake, before handle(). The optional $invoker decides how
     * a routed handler is called (the Laravel boot passes a container invoker so
     * handler arguments are dependency-injected); without it, handlers are invoked
     * plainly. Not for developer use.
     *
     * @param  (callable(string, array<int|string, mixed>): mixed)|null  $invoker
     */
    final public function bindControlChannel(Connection $connection, ?callable $invoker = null): void
    {
        $this->connection = $connection;
        $this->invoker = $invoker;
    }

    /**
     * Serve every currently-pending inbound call, then return immediately —
     * non-blocking. Call this once per iteration of a handle() loop that also does
     * its own work, so calls are answered promptly between work units. A no-op if
     * no control channel is wired.
     */
    final public function tick(): void
    {
        if ($this->connection === null) {
            return;
        }

        while ($this->connection->hasPendingMessage()) {
            if (! $this->serveOne()) {
                // EOF on the control socket → treat as a stop request.
                $this->stopping = true;

                return;
            }
        }
    }

    /**
     * Block serving inbound calls until a stop is requested (SIGTERM) or the
     * control socket closes (EOF). For a PURE responder whose only job is answering
     * calls — its handle() is simply `$this->listen();`. Wakes every $pollSeconds
     * to re-check isRunning() so SIGTERM is honoured between calls.
     */
    final public function listen(float $pollSeconds = 1.0): void
    {
        if ($this->connection === null) {
            return;
        }

        while ($this->isRunning()) {
            if (! $this->connection->awaitMessage($pollSeconds)) {
                continue; // timeout or signal interruption — re-check isRunning()
            }

            if (! $this->serveOne()) {
                return; // EOF → clean stop
            }
        }
    }

    /**
     * Read one delivered call, route it to a handler, reply with the result or the
     * error. Returns false on EOF (the caller should stop). An UNKNOWN handler is
     * the caller's mistake → error reply, keep running. A handler that THROWS
     * reports the error then re-throws → the process crashes (let it crash).
     */
    private function serveOne(): bool
    {
        $message = $this->connection->readDeliver();
        if ($message === null) {
            return false;
        }

        try {
            $this->assertHandler($message['method']);
        } catch (UnknownMessageException $e) {
            $this->connection->sendError($message['id'], $e->getMessage());

            return true;
        }

        try {
            $result = $this->invoke($message['method'], $message['args']);
            $this->connection->sendResult($message['id'], $result);
        } catch (\Throwable $e) {
            $this->connection->sendError($message['id'], $e->getMessage());

            throw $e;
        }

        return true;
    }

    /** Invoke a validated handler — via the container invoker if one is wired. */
    private function invoke(string $method, array $args): mixed
    {
        if ($this->invoker !== null) {
            return ($this->invoker)($method, $args);
        }

        return $this->$method(...$args);
    }

    /**
     * A method is a callable handler iff it is a PUBLIC, non-static, concrete
     * method declared on the SUBCLASS (not on PhordProcess), and is not the
     * developer-overridden handle() body. Walking up to but excluding PhordProcess
     * keeps the base contract (isRunning/tick/listen/installSignalHandler/…)
     * unreachable while still admitting an intermediate abstract class's shared
     * handlers; handle() is declared below the base (the developer overrides it)
     * so it is excluded by name.
     */
    private function assertHandler(string $method): void
    {
        if ($method === '' || $method === 'handle' || str_starts_with($method, '__')) {
            throw new UnknownMessageException($method, static::class);
        }

        if (! method_exists($this, $method)) {
            throw new UnknownMessageException($method, static::class);
        }

        $reflection = new \ReflectionMethod($this, $method);

        if (! $reflection->isPublic() || $reflection->isStatic() || $reflection->isAbstract()) {
            throw new UnknownMessageException($method, static::class);
        }

        if ($reflection->getDeclaringClass()->getName() === self::class) {
            throw new UnknownMessageException($method, static::class);
        }
    }
}
