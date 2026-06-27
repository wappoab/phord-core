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
