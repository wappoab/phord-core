<?php

namespace Phord\Process;

use Phord\Channel;

/**
 * A lazy addressable handle to a running PhordProcess, obtained from
 * `BeamSupervisor::get($name)`. It sends inbound messages INTO the live process
 * over the back-channel (the `supervisor.call` / `supervisor.send` JSON-RPC verbs
 * → the process's Runner pump on the BEAM → the process's cooperative tick()/
 * listen()), which routes them to a public handler method by name:
 *
 *     $depth = BeamSupervisor::get('rabbitmq-4711')->call('queueDepth');
 *     BeamSupervisor::get('rabbitmq-4711')->send('reload', $configId);
 *
 * `call()` is request/reply (returns the handler's return value, or throws a
 * RuntimeException on a handler error / timeout / unknown process); `send()` is
 * fire-and-forget (the reply is discarded, but delivery is still acknowledged so a
 * missing process fails loudly).
 *
 * Obtaining a handle is FREE — no round-trip; it just binds a name. Nothing is
 * verified until the first call()/send(), which errors if the name isn't running.
 * The process is single-threaded: the BEAM feeds it one message at a time, so
 * concurrent callers are serialized, never interleaved.
 */
class ProcessHandle
{
    public function __construct(private Channel $channel, private string $name) {}

    /** The process name this handle addresses. */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Request/reply: invoke the named handler on the process and return its result.
     * Args are forwarded positionally, or as named args (they survive as string
     * keys, so `call('inc', articleId: 5)` binds by name). Throws (via the channel)
     * on an unknown process, an unknown/reserved handler, a handler exception, or a
     * call timeout (default 5s, ops-capped by PHORD_PROCESS_CALL_MAX_TIMEOUT_MS).
     */
    public function call(string $method, mixed ...$args): mixed
    {
        $result = $this->channel->request('supervisor.call', [
            'name' => $this->name,
            'method' => $method,
            'args' => $args,
        ]);

        return $result['result'] ?? null;
    }

    /**
     * Fire-and-forget: invoke the named handler and discard the reply. Delivery is
     * still acknowledged, so an unknown process throws rather than silently
     * vanishing — the return value of the handler is what's dropped, not the error.
     */
    public function send(string $method, mixed ...$args): void
    {
        $this->channel->request('supervisor.send', [
            'name' => $this->name,
            'method' => $method,
            'args' => $args,
        ]);
    }

    /**
     * Lifecycle status for this process (same shape as BeamSupervisor::status).
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return (array) $this->channel->request('supervisor.status', ['name' => $this->name]);
    }

    /** Stop this process (SIGTERM → grace → SIGKILL, no restart). */
    public function stop(): bool
    {
        $result = $this->channel->request('supervisor.stop', ['name' => $this->name]);

        return (bool) ($result['stopped'] ?? false);
    }
}
