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
 *       ── then, if the process cooperatively serves inbound calls: ──
 *     <- deliver   : {"id":N,"method":"depth","args":[...]}   (BEAM → PHP)
 *     -> result    : {"id":N,"result":<json>}                 (PHP → BEAM)
 *     -> error     : {"id":N,"error":"<msg>"}                 (PHP → BEAM)
 *
 * Stop is still delivered by SIGTERM (a process blocked inside its own handle()
 * loop can't service a socket read). The deliver/result frames are the inbound
 * message channel a PhordProcess drains cooperatively via tick()/listen(): the
 * BEAM (Phord.Process.Runner) feeds exactly ONE deliver frame at a time and waits
 * for its result/error before feeding the next, so PHP stays single-threaded.
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

    /**
     * True if at least one byte of an inbound deliver frame is already buffered —
     * a non-blocking poll (stream_select, 0 timeout) so tick() can drain pending
     * calls without ever blocking the developer's loop. Once bytes are ready the
     * whole frame follows immediately (the BEAM sends it atomically), so a
     * subsequent readDeliver() completes without a stall.
     */
    public function hasPendingMessage(): bool
    {
        $read = [$this->socket];
        $write = null;
        $except = null;
        $n = @stream_select($read, $write, $except, 0);

        return $n !== false && $n > 0;
    }

    /**
     * Block up to $timeout seconds for an inbound deliver frame; true if one is
     * readable, false on timeout OR signal interruption (EINTR) — so a blocking
     * listen() loop wakes periodically to re-check isRunning() and honour SIGTERM.
     */
    public function awaitMessage(float $timeout): bool
    {
        $read = [$this->socket];
        $write = null;
        $except = null;
        $sec = (int) floor($timeout);
        $usec = (int) round(($timeout - $sec) * 1_000_000);
        $n = @stream_select($read, $write, $except, $sec, $usec);

        return $n !== false && $n > 0;
    }

    /**
     * Read one inbound deliver frame: which handler method to invoke and the args.
     * Returns ['id' => int, 'method' => string, 'args' => array], or null on a
     * clean EOF (Runner closed the socket → the process should stop).
     *
     * @return array{id: int, method: string, args: array<int|string, mixed>}|null
     */
    public function readDeliver(): ?array
    {
        $raw = Frame::read($this->socket);
        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['id'], $data['method'])) {
            throw new RuntimeException('Phord process: malformed deliver frame');
        }

        return [
            'id' => (int) $data['id'],
            'method' => (string) $data['method'],
            'args' => is_array($data['args'] ?? null) ? $data['args'] : [],
        ];
    }

    /**
     * Reply to a delivered call with its result. The frame doubles as the BEAM's
     * "PHP is idle again, feed the next message" signal, so it is written for
     * every delivered message (a send()'s result is discarded on the BEAM side,
     * but the frame must still be sent).
     */
    public function sendResult(int $id, mixed $result): void
    {
        Frame::write($this->socket, json_encode(['id' => $id, 'result' => $result]));
    }

    /** Reply that a delivered call failed (so the caller gets an error, not a hang). */
    public function sendError(int $id, string $error): void
    {
        Frame::write($this->socket, json_encode(['id' => $id, 'error' => $error]));
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
