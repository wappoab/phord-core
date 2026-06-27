<?php

namespace Phord\Tests;

use Phord\Frame;
use Phord\Worker\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the worker wire protocol with an in-process socket pair: one end is
 * the Connection (the PHP worker), the other plays Elixir's Phord.Backend.Worker.
 */
class WorkerConnectionTest extends TestCase
{
    /** @var resource */
    private $elixir;

    private Connection $conn;

    protected function setUp(): void
    {
        [$elixir, $php] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->elixir = $elixir;
        $this->conn = new Connection($php);
    }

    public function test_send_ready_handshake(): void
    {
        $this->conn->sendReady();
        $this->assertSame(['phord' => 'ready'], json_decode(Frame::read($this->elixir), true));
    }

    public function test_send_boot_error_handshake(): void
    {
        $this->conn->sendBootError('boom');
        $this->assertSame(
            ['phord' => 'boot_error', 'error' => 'boom'],
            json_decode(Frame::read($this->elixir), true)
        );
    }

    public function test_read_request_assembles_header_and_body(): void
    {
        Frame::write($this->elixir, json_encode(['method' => 'POST', 'uri' => '/x', 'headers' => ['host' => 'h']]));
        Frame::write($this->elixir, 'raw-body-bytes');

        $req = $this->conn->readRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/x', $req['uri']);
        $this->assertSame(['host' => 'h'], $req['headers']);
        $this->assertSame('raw-body-bytes', $req['body']);
    }

    public function test_read_request_skips_a_malformed_header(): void
    {
        Frame::write($this->elixir, 'not-json');
        Frame::write($this->elixir, 'ignored-body');
        Frame::write($this->elixir, json_encode(['method' => 'GET', 'uri' => '/ok', 'headers' => []]));
        Frame::write($this->elixir, '');

        $req = $this->conn->readRequest();
        $this->assertSame('/ok', $req['uri']);
    }

    public function test_read_request_returns_null_on_eof(): void
    {
        fclose($this->elixir);
        $this->assertNull($this->conn->readRequest());
    }

    public function test_write_response_emits_two_frames(): void
    {
        $this->conn->writeResponse(201, ['content-type' => ['text/plain']], 'out');

        $header = json_decode(Frame::read($this->elixir), true);
        $this->assertSame(201, $header['status']);
        $this->assertSame(['content-type' => ['text/plain']], $header['headers']);
        $this->assertSame('out', Frame::read($this->elixir));
    }
}
