<?php

namespace Phord\Tests\Process;

use Phord\Frame;
use Phord\Process\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The process control-socket wire, exercised with an in-process socket pair: one
 * end is the Connection (the PHP process), the other plays Elixir's
 * Phord.Process.Runner. The Runner sends the boot frame (class + args) FIRST,
 * then the process replies ready/boot_error — the direction flip from the worker
 * handshake (a process must be told what to run before it can boot).
 */
class ProcessConnectionTest extends TestCase
{
    /** @var resource */
    private $runner;

    private Connection $conn;

    protected function setUp(): void
    {
        [$runner, $php] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->runner = $runner;
        $this->conn = new Connection($php);
    }

    public function test_read_boot_parses_class_and_args(): void
    {
        Frame::write($this->runner, json_encode(['class' => 'App\\Listener', 'args' => ['conn-4711', 2]]));

        $boot = $this->conn->readBoot();
        $this->assertSame('App\\Listener', $boot['class']);
        $this->assertSame(['conn-4711', 2], $boot['args']);
    }

    public function test_read_boot_defaults_args_to_empty_array(): void
    {
        Frame::write($this->runner, json_encode(['class' => 'App\\Listener']));

        $boot = $this->conn->readBoot();
        $this->assertSame([], $boot['args']);
    }

    public function test_read_boot_returns_null_on_eof(): void
    {
        fclose($this->runner);
        $this->assertNull($this->conn->readBoot());
    }

    public function test_read_boot_throws_on_a_frame_without_a_class(): void
    {
        Frame::write($this->runner, json_encode(['args' => []]));

        $this->expectException(\RuntimeException::class);
        $this->conn->readBoot();
    }

    public function test_send_ready_handshake(): void
    {
        $this->conn->sendReady();
        $this->assertSame(['phord' => 'ready'], json_decode(Frame::read($this->runner), true));
    }

    public function test_send_boot_error_handshake(): void
    {
        $this->conn->sendBootError('class not found');
        $this->assertSame(
            ['phord' => 'boot_error', 'error' => 'class not found'],
            json_decode(Frame::read($this->runner), true)
        );
    }

    public function test_read_deliver_parses_id_method_and_args(): void
    {
        Frame::write($this->runner, json_encode(['id' => 7, 'method' => 'inc', 'args' => [5]]));

        $msg = $this->conn->readDeliver();
        $this->assertSame(7, $msg['id']);
        $this->assertSame('inc', $msg['method']);
        $this->assertSame([5], $msg['args']);
    }

    public function test_read_deliver_defaults_args_to_empty_array(): void
    {
        Frame::write($this->runner, json_encode(['id' => 1, 'method' => 'ping']));

        $this->assertSame([], $this->conn->readDeliver()['args']);
    }

    public function test_read_deliver_returns_null_on_eof(): void
    {
        fclose($this->runner);
        $this->assertNull($this->conn->readDeliver());
    }

    public function test_read_deliver_throws_on_a_frame_without_a_method(): void
    {
        Frame::write($this->runner, json_encode(['id' => 1]));

        $this->expectException(\RuntimeException::class);
        $this->conn->readDeliver();
    }

    public function test_send_result_frame(): void
    {
        $this->conn->sendResult(9, ['ok' => true]);
        $this->assertSame(
            ['id' => 9, 'result' => ['ok' => true]],
            json_decode(Frame::read($this->runner), true)
        );
    }

    public function test_send_error_frame(): void
    {
        $this->conn->sendError(9, 'boom');
        $this->assertSame(
            ['id' => 9, 'error' => 'boom'],
            json_decode(Frame::read($this->runner), true)
        );
    }

    public function test_has_pending_message_is_false_when_idle_true_when_buffered(): void
    {
        $this->assertFalse($this->conn->hasPendingMessage());

        Frame::write($this->runner, json_encode(['id' => 1, 'method' => 'ping', 'args' => []]));
        $this->assertTrue($this->conn->hasPendingMessage());
    }
}
