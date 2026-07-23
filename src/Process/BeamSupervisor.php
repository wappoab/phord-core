<?php

namespace Phord\Process;

use Phord\Channel;

/**
 * The app-facing client for BEAM-supervised PHP processes: start/stop/status/list
 * long-running PhordProcess instances BY NAME, at runtime, over the back-channel
 * (the `supervisor.*` JSON-RPC domain → Phord.Process.BeamSupervisor on the BEAM).
 *
 *     $sup = new BeamSupervisor($channel);
 *     $sup->start(RabbitMQListenerProcess::class, [$connId], 'rabbitmq-4711');
 *     $sup->status('rabbitmq-4711');   // running? uptime? restarts?
 *     $sup->stop('rabbitmq-4711');
 *     $sup->list();
 *
 * PHP assigns the NAME and addresses everything by it; PHP never sees a BEAM pid.
 * A restarted process keeps its name (the stable identity) with a new pid. In a
 * Laravel app the same calls are available through the BeamSupervisor facade.
 *
 * Framework-agnostic (phord/core), Channel-backed like Phord\Cache /
 * Phord\Queue\Client.
 */
class BeamSupervisor
{
    public function __construct(private Channel $channel) {}

    /**
     * Start a PhordProcess subclass under BEAM supervision, registered as $name.
     * Returns true once placed. Throws (via the channel) if $name is already
     * running — start is strict, like the queue topology: reconcile checks
     * status() first.
     *
     * @param  class-string<PhordProcess>  $class
     * @param  array<int|string, mixed>     $args   passed to handle(...$args)
     */
    public function start(string $class, array $args, string $name): bool
    {
        $result = $this->channel->request('supervisor.start', [
            'class' => $class,
            'args' => array_values($args),
            'name' => $name,
        ]);

        return (bool) ($result['started'] ?? false);
    }

    /**
     * Start a pool of $size FUNGIBLE warm replicas of $class under one $name
     * (docs/design-records/process-pool.md): N interchangeable resident processes,
     * each with its own warm state, with get($name)->call/send load-balanced across
     * them for concurrency. Use this — not start() — when the warm thing is
     * expensive to boot and calls are slow, so several can run at once.
     *
     * Two caveats vs a single process, by design: ordering is DROPPED (calls
     * interleave across replicas; no read-your-write), and per-instance state is
     * not queryable (status() reports the aggregate). Any replica serves any call,
     * so the replicas must be interchangeable — for identity-bearing per-entity
     * state use a keyed actor instead.
     *
     * @param  class-string<PhordProcess>  $class
     * @param  array<int|string, mixed>     $args   passed to each replica's handle()
     */
    public function startPool(string $class, array $args, string $name, int $size): bool
    {
        $result = $this->channel->request('supervisor.start_pool', [
            'class' => $class,
            'args' => array_values($args),
            'name' => $name,
            'size' => $size,
        ]);

        return (bool) ($result['started'] ?? false);
    }

    /**
     * A lazy handle for messaging a running process by name:
     *
     *     $sup->get('rabbitmq-4711')->call('queueDepth');   // request/reply
     *     $sup->get('rabbitmq-4711')->send('reload', $id);  // fire-and-forget
     *
     * Free — no round-trip; the process is contacted on the first call()/send().
     */
    public function get(string $name): ProcessHandle
    {
        return new ProcessHandle($this->channel, $name);
    }

    /** Stop a supervised process by name (SIGTERM → grace → SIGKILL). No restart. */
    public function stop(string $name): bool
    {
        $result = $this->channel->request('supervisor.stop', ['name' => $name]);

        return (bool) ($result['stopped'] ?? false);
    }

    /**
     * Status for one process: ['found' => bool, 'name', 'class', 'status',
     * 'running' => bool, 'os_pid', 'restarts', 'uptime_ms', 'last_exit'].
     * `found => false` when no process is registered under $name.
     *
     * @return array<string, mixed>
     */
    public function status(string $name): array
    {
        return (array) $this->channel->request('supervisor.status', ['name' => $name]);
    }

    /**
     * All supervised processes (the same per-process maps as status()).
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $result = $this->channel->request('supervisor.list', []);

        return $result['processes'] ?? [];
    }
}
