<?php

namespace Phord\Process;

use Phord\Frame;
use RuntimeException;

/**
 * The PHP↔BEAM **process control transport**: the Unix socket a supervised
 * PhordProcess speaks over while it boots. Driven by Elixir's
 * `Phord.Process.Runner`. Framework-agnostic (socket + Frame + JSON); the Laravel
 * bootstrapping lives in phord/laravel.
 *
 * Wire (length-prefixed frames):
 *
 *     <- boot      : {"class":"App\\…Process","args":[...]}   (BEAM → PHP)   handshake
 *     -> ready     : {"phord":"ready"} | {"phord":"boot_error","error":...}
 *
 * A PhordProcess uses only the boot handshake: after it, the process is busy
 * inside handle() and does NOT read this socket again — stop is delivered by
 * SIGTERM (a blocked process can't service a socket read).
 */
class Connection
{
    /** @param  resource  $socket  connected Unix stream socket to Elixir */
    public function __construct(private $socket) {}

    /** Connect to the Runner's listen socket at $socketPath. */
    public static function connect(string $socketPath, float $timeout = 5.0): self
    {
        $socket = stream_socket_client('unix://' . $socketPath, $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new RuntimeException("Phord process: connect to {$socketPath} failed: {$errstr} ({$errno})");
        }

        return new self($socket);
    }

    /**
     * Read the boot frame Elixir sends right after accept: which class to run and
     * the args to pass handle(). Returns ['class' => string, 'args' => array], or
     * null on a clean EOF (Runner gone before boot).
     */
    public function readBoot(): ?array
    {
        $raw = Frame::read($this->socket);
        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['class'])) {
            throw new RuntimeException('Phord process: malformed boot frame');
        }

        return [
            'class' => (string) $data['class'],
            'args' => is_array($data['args'] ?? null) ? $data['args'] : [],
        ];
    }

    /** Handshake: tell Elixir the process booted and is entering handle(). */
    public function sendReady(): void
    {
        Frame::write($this->socket, json_encode(['phord' => 'ready']));
    }

    /** Handshake: report a boot failure so the Runner can back off. */
    public function sendBootError(string $message): void
    {
        Frame::write($this->socket, json_encode(['phord' => 'boot_error', 'error' => $message]));
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
