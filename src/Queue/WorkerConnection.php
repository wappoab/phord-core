<?php

namespace Phord\Queue;

use Phord\Frame;
use RuntimeException;

/**
 * Execute side of the queue: the wire spoken by the dedicated PHP queue-worker
 * with Elixir's Phord.Queue.Worker. Framework-agnostic (socket + Frame + JSON);
 * the Laravel job execution lives in phord/laravel.
 *
 * Elixir drives (PUSH): it sends a job (header frame + raw payload frame) or a
 * terminate frame; the worker replies with one result frame carrying the
 * drain/lifecycle signal.
 *
 *     <- handshake : {"phord":"ready","role":"queue-worker"} | {"phord":"boot_error",...}
 *     -> job       : frame1 {"job_id","queue"} ; frame2 <raw payload>
 *     -> terminate : frame1 {"action":"terminate"} (no frame2)
 *     <- result    : {"job_id","status":"done"|"failed","error":null|"...","error_class":null|"App\\...","state":"ready"|"draining"}
 */
class WorkerConnection
{
    /**
     * @param  resource  $socket  connected Unix stream socket to Elixir
     */
    public function __construct(private $socket) {}

    public static function connect(string $socketPath, float $timeout = 5.0): self
    {
        $socket = stream_socket_client('unix://' . $socketPath, $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new RuntimeException("Phord queue worker: connect to {$socketPath} failed: {$errstr} ({$errno})");
        }

        return new self($socket);
    }

    public function sendReady(): void
    {
        Frame::write($this->socket, json_encode(['phord' => 'ready', 'role' => 'queue-worker']));
    }

    public function sendBootError(string $message): void
    {
        Frame::write($this->socket, json_encode(['phord' => 'boot_error', 'error' => $message]));
    }

    /**
     * Receive the next job, or null to stop the loop (terminate signal / EOF).
     * Returns ['job_id', 'queue', 'payload', 'attempts']. `attempts` is the
     * durable adapter's reserve count (1-based); the Memory adapter sends 1.
     */
    public function readJob(): ?array
    {
        $header = Frame::read($this->socket);
        if ($header === null) {
            return null; // socket closed
        }

        $data = json_decode($header, true);
        if (! is_array($data) || ($data['action'] ?? null) === 'terminate') {
            return null; // drain/terminate (or malformed) → stop
        }

        $payload = Frame::read($this->socket);
        if ($payload === null) {
            return null;
        }

        return [
            'job_id' => (string) ($data['job_id'] ?? ''),
            'queue' => $data['queue'] ?? 'default',
            'payload' => $payload,
            'attempts' => (int) ($data['attempts'] ?? 1),
        ];
    }

    /**
     * Report a job result plus the worker's drain/lifecycle state
     * ("ready" = feed me the next job, "draining" = I'm stopping).
     *
     * `$outcome` is HOW the run ended so Elixir can apply the right retry rule
     * (docs/design-records/queue-retry-semantics.md): "success" | "exception" | "released" |
     * "failed". `$releaseDelay` is the seconds from a manual release($delay), only
     * meaningful for "released". `$errorClass` is the exception's class name (for
     * the indexed exception_class column / root-cause grouping —
     * docs/design-records/job-filters.md), set on the exception/failed paths, null otherwise.
     * `status` is kept as a legacy alias for older readers (done/failed).
     */
    public function writeResult(string $jobId, string $outcome, ?string $error, ?int $releaseDelay = null, string $state = 'ready', ?string $errorClass = null): void
    {
        Frame::write($this->socket, json_encode([
            'job_id' => $jobId,
            'outcome' => $outcome,
            'status' => $outcome === 'success' ? 'done' : 'failed',
            'error' => $error,
            'error_class' => $errorClass,
            'release_delay' => $releaseDelay,
            'state' => $state,
        ]));
    }
}
