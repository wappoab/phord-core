<?php

namespace Phord\Tests;

use Phord\Frame;
use Phord\Queue\WorkerConnection;
use PHPUnit\Framework\TestCase;

/**
 * Queue-worker wire over an in-process socket pair: one end is the
 * WorkerConnection (the PHP worker), the other plays Elixir's Phord.Queue.Worker.
 */
class QueueWorkerConnectionTest extends TestCase
{
    /** @var resource */
    private $elixir;

    private WorkerConnection $conn;

    protected function setUp(): void
    {
        [$elixir, $php] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->elixir = $elixir;
        $this->conn = new WorkerConnection($php);
    }

    public function test_ready_handshake(): void
    {
        $this->conn->sendReady();
        $this->assertSame(
            ['phord' => 'ready', 'role' => 'queue-worker'],
            json_decode(Frame::read($this->elixir), true)
        );
    }

    public function test_read_job_assembles_header_and_payload(): void
    {
        Frame::write($this->elixir, json_encode(['job_id' => 'job-1', 'queue' => 'default']));
        Frame::write($this->elixir, 'opaque-payload');

        $job = $this->conn->readJob();
        $this->assertSame('job-1', $job['job_id']);
        $this->assertSame('default', $job['queue']);
        $this->assertSame('opaque-payload', $job['payload']);
    }

    public function test_terminate_signals_stop(): void
    {
        Frame::write($this->elixir, json_encode(['action' => 'terminate']));
        $this->assertNull($this->conn->readJob());
    }

    public function test_read_job_returns_null_on_eof(): void
    {
        fclose($this->elixir);
        $this->assertNull($this->conn->readJob());
    }

    public function test_write_result_carries_outcome_and_drain_state(): void
    {
        $this->conn->writeResult('job-1', 'success', null, null, 'ready');
        $this->assertSame(
            [
                'job_id' => 'job-1',
                'outcome' => 'success',
                'status' => 'done',      // legacy alias
                'error' => null,
                'error_class' => null,
                'release_delay' => null,
                'state' => 'ready',
            ],
            json_decode(Frame::read($this->elixir), true)
        );
    }

    public function test_write_result_carries_release_delay(): void
    {
        $this->conn->writeResult('job-2', 'released', null, 30, 'ready');
        $this->assertSame(
            [
                'job_id' => 'job-2',
                'outcome' => 'released',
                'status' => 'failed',    // legacy alias: anything non-success
                'error' => null,
                'error_class' => null,
                'release_delay' => 30,
                'state' => 'ready',
            ],
            json_decode(Frame::read($this->elixir), true)
        );
    }

    public function test_write_result_carries_exception_class(): void
    {
        // The exception path threads the class name for the indexed exception_class
        // column / root-cause grouping (docs/design-records/job-filters.md).
        $this->conn->writeResult('job-3', 'exception', 'kaboom', null, 'ready', 'App\\TimeoutException');
        $this->assertSame(
            [
                'job_id' => 'job-3',
                'outcome' => 'exception',
                'status' => 'failed',
                'error' => 'kaboom',
                'error_class' => 'App\\TimeoutException',
                'release_delay' => null,
                'state' => 'ready',
            ],
            json_decode(Frame::read($this->elixir), true)
        );
    }
}
