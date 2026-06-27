<?php

namespace Phord;

use RuntimeException;

/**
 * Client for the PHP→BEAM back-channel: a dedicated Unix socket to Elixir
 * carrying JSON-RPC messages (cache.* etc.), completely separate from the HTTP
 * worker socket. Reuses the same 4-byte length framing (Frame).
 *
 * Connection lifecycle mirrors a Redis driver:
 *   - worker mode: connect once (persistent), reuse for the worker's whole life;
 *   - fpm mode:    connect lazily per request, close at request end.
 *
 * Coordinates (socket path, token, mode) are injected by Elixir via env /
 * $_SERVER (see Phord\Env), so PHP never hard-codes them.
 */
class Channel
{
    /** @var resource|null */
    private $socket = null;

    private int $nextId = 1;

    private ?string $path;

    private ?string $token;

    private bool $persistent;

    public function __construct(?string $path = null, ?string $token = null, ?bool $persistent = null)
    {
        $this->path = $path ?? Env::socket();
        $this->token = $token ?? Env::token();
        // Worker mode keeps the socket open for the whole process; fpm closes per request.
        $this->persistent = $persistent ?? (Env::mode() === 'worker');
    }

    /**
     * Send a request and block until the reply with the matching id arrives.
     * Returns the JSON-RPC `result` (or null), throws on a JSON-RPC `error`.
     */
    public function request(string $method, array $params = []): mixed
    {
        $this->connect();
        $id = $this->nextId++;
        Frame::write($this->socket, json_encode(['id' => $id, 'method' => $method, 'params' => $params]));

        // Read frames until ours comes back (buffer/skip any others). In V1 PHP
        // is single-threaded, so at most one call is outstanding, but matching by
        // id is the correct, future-proof form.
        while (true) {
            $raw = Frame::read($this->socket);
            if ($raw === null) {
                $this->close();
                throw new RuntimeException('Phord channel: connection closed mid-request');
            }

            $msg = json_decode($raw, true);
            if (! is_array($msg) || ($msg['id'] ?? null) !== $id) {
                continue;
            }

            if (isset($msg['error'])) {
                throw new RuntimeException('Phord channel error: ' . ($msg['error']['message'] ?? 'unknown'));
            }

            return $msg['result'] ?? null;
        }
    }

    /** Fire-and-forget: send a notification (no id), expect no reply. */
    public function notify(string $method, array $params = []): void
    {
        $this->connect();
        Frame::write($this->socket, json_encode(['method' => $method, 'params' => $params]));
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    private function connect(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        if (! $this->path) {
            throw new RuntimeException('Phord channel: socket path not configured (PHORD_CHANNEL_SOCKET)');
        }

        $sock = stream_socket_client('unix://' . $this->path, $errno, $errstr, 5);
        if ($sock === false) {
            throw new RuntimeException("Phord channel: connect to {$this->path} failed: {$errstr} ({$errno})");
        }

        $this->socket = $sock;

        if ($this->token !== null) {
            Frame::write($sock, json_encode(['phord' => 'hello', 'token' => $this->token]));
            $resp = Frame::read($sock);
            $ok = $resp !== null && (json_decode($resp, true)['phord'] ?? null) === 'ok';
            if (! $ok) {
                $this->close();
                throw new RuntimeException('Phord channel: authentication failed');
            }
        }
    }

    public function __destruct()
    {
        if (! $this->persistent) {
            $this->close();
        }
    }
}
