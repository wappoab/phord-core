<?php

namespace Phord\Tests\Process;

use Phord\Channel;
use Phord\Process\BeamSupervisor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The app-facing BeamSupervisor client threads start/stop/status/list to the
 * `supervisor.*` back-channel domain, addressing everything BY NAME (PHP never
 * sends or receives a BEAM pid). Framework-agnostic: it forwards params it does
 * not interpret, like Phord\Queue\Client.
 */
class BeamSupervisorTest extends TestCase
{
    public function test_start_threads_class_args_and_name(): void
    {
        $channel = $this->recordingChannel(['started' => true, 'name' => 'rabbitmq-4711']);
        $sup = new BeamSupervisor($channel);

        $ok = $sup->start('App\\RabbitListener', ['conn-4711'], 'rabbitmq-4711');

        $this->assertTrue($ok);
        $this->assertSame('supervisor.start', $channel->lastMethod);
        $this->assertSame('App\\RabbitListener', $channel->lastParams['class']);
        $this->assertSame(['conn-4711'], $channel->lastParams['args']);
        $this->assertSame('rabbitmq-4711', $channel->lastParams['name']);
    }

    public function test_start_reindexes_args_to_a_json_list(): void
    {
        // A non-sequential PHP array must cross the wire as a JSON array (so the
        // BEAM gets a list to pass handle(...$args)), not a JSON object.
        $channel = $this->recordingChannel(['started' => true]);
        (new BeamSupervisor($channel))->start('App\\P', [2 => 'x', 5 => 'y'], 'n');

        $this->assertSame(['x', 'y'], $channel->lastParams['args']);
    }

    public function test_stop_addresses_by_name(): void
    {
        $channel = $this->recordingChannel(['stopped' => true, 'name' => 'rabbitmq-4711']);

        $this->assertTrue((new BeamSupervisor($channel))->stop('rabbitmq-4711'));
        $this->assertSame('supervisor.stop', $channel->lastMethod);
        $this->assertSame('rabbitmq-4711', $channel->lastParams['name']);
    }

    public function test_status_returns_the_process_map(): void
    {
        $channel = $this->recordingChannel(['found' => true, 'name' => 'n', 'running' => true, 'restarts' => 2]);

        $status = (new BeamSupervisor($channel))->status('n');
        $this->assertSame('supervisor.status', $channel->lastMethod);
        $this->assertSame('n', $channel->lastParams['name']);
        $this->assertTrue($status['running']);
        $this->assertSame(2, $status['restarts']);
    }

    public function test_list_returns_all_processes(): void
    {
        $channel = $this->recordingChannel(['processes' => [['name' => 'a'], ['name' => 'b']]]);

        $list = (new BeamSupervisor($channel))->list();
        $this->assertSame('supervisor.list', $channel->lastMethod);
        $this->assertCount(2, $list);
        $this->assertSame('a', $list[0]['name']);
    }

    public function test_start_surfaces_the_process_limit_error_loudly(): void
    {
        // Over the cap the BEAM returns a JSON-RPC error; the Channel turns that
        // into a RuntimeException. start() does NOT swallow it — a hit cap is a
        // loud, actionable failure PHP can log/alarm on, never a silent no-op.
        $channel = $this->throwingChannel('Phord channel error: process limit reached: 100/100');
        $sup = new BeamSupervisor($channel);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('process limit reached: 100/100');

        $sup->start('App\\RabbitListener', [], 'rabbitmq-9999');
    }

    private function throwingChannel(string $message): Channel
    {
        return new class($message) extends Channel {
            public function __construct(private string $message) {}

            public function __destruct() {}

            public function request(string $method, array $params = []): mixed
            {
                throw new RuntimeException($this->message);
            }
        };
    }

    private function recordingChannel(array $result): Channel
    {
        return new class($result) extends Channel {
            public ?string $lastMethod = null;

            public array $lastParams = [];

            public function __construct(private array $result) {}

            // Skip the parent destructor — it touches uninitialized typed
            // properties on this socket-less double.
            public function __destruct() {}

            public function request(string $method, array $params = []): mixed
            {
                $this->lastMethod = $method;
                $this->lastParams = $params;

                return $this->result;
            }
        };
    }
}
