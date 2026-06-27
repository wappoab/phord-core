<?php

namespace Phord\Worker;

use Phord\Frame;
use RuntimeException;

/**
 * The PHP↔BEAM **worker HTTP transport**: the request/response wire spoken over
 * the Unix socket that Elixir's `Phord.Backend.Worker` drives. Framework-agnostic
 * (just socket + Frame + JSON) — the Laravel bootstrapping lives in phord/laravel.
 *
 * Wire (two length-prefixed frames per message, plus a startup handshake):
 *
 *     <- handshake : {"phord":"ready"} | {"phord":"boot_error","error":...}
 *     -> frame 1   : JSON header  {method, uri, headers}
 *     -> frame 2   : raw request body bytes
 *     <- frame 1   : JSON header  {status, headers}
 *     <- frame 2   : raw response body bytes
 */
class Connection
{
    /**
     * @param  resource  $socket  connected Unix stream socket to Elixir
     */
    public function __construct(private $socket) {}

    /** Connect to Elixir's worker listen socket at $socketPath. */
    public static function connect(string $socketPath, float $timeout = 5.0): self
    {
        $socket = stream_socket_client('unix://' . $socketPath, $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new RuntimeException("Phord worker: connect to {$socketPath} failed: {$errstr} ({$errno})");
        }

        return new self($socket);
    }

    /** The underlying stream resource. */
    public function socket()
    {
        return $this->socket;
    }

    /** Handshake: tell Elixir the worker booted and is ready to serve. */
    public function sendReady(): void
    {
        Frame::write($this->socket, json_encode(['phord' => 'ready']));
    }

    /** Handshake: report a boot failure so Elixir can back off. */
    public function sendBootError(string $message): void
    {
        Frame::write($this->socket, json_encode(['phord' => 'boot_error', 'error' => $message]));
    }

    /**
     * Read one request — two frames (JSON header, then raw body). Returns
     * ['method','uri','headers','body'] or null on a clean EOF (socket closed).
     * A malformed header frame is skipped and the next request is read.
     */
    public function readRequest(): ?array
    {
        $headerJson = Frame::read($this->socket);
        if ($headerJson === null) {
            return null; // socket closed
        }

        $body = Frame::read($this->socket);
        if ($body === null) {
            return null;
        }

        $data = json_decode($headerJson, true);
        if (! is_array($data)) {
            // Skip a malformed message and read the next (matches the prior loop's `continue`).
            return $this->readRequest();
        }

        // The raw body frame is fed in alongside the JSON header.
        $data['body'] = $body;

        return $data;
    }

    /** Write one response: a JSON header frame, then the raw body frame. */
    public function writeResponse(int $status, array $headers, string $body): void
    {
        $header = json_encode(
            ['status' => $status, 'headers' => $headers],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        Frame::write($this->socket, $header);
        Frame::write($this->socket, $body);
    }
}
