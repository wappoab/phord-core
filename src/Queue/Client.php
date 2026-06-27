<?php

namespace Phord\Queue;

use Phord\Channel;

/**
 * Enqueue side of the queue: pushes an opaque job payload to the BEAM over the
 * existing back-channel (`queue.push`). Framework-agnostic — the Laravel queue
 * driver wraps this, mirroring how the cache driver wraps Phord\Cache.
 */
class Client
{
    public function __construct(private Channel $channel) {}

    /**
     * Enqueue an opaque payload. Returns the job id assigned by the BEAM.
     *
     * `$delay` is seconds until the job becomes runnable; `$maxAttempts` (Laravel
     * `$tries`) caps reserves before `failed`. `$retry` carries the rest of the
     * retry config read off the Laravel payload — keys: `backoff` (the `$backoff`
     * comma-spec string), `max_exceptions` (int), `retry_until` (unix ts int).
     * `$filters` is the optional scalar metadata map a job projects via
     * phordFilters() (stored/indexed alongside — never inside — the opaque
     * payload; see docs/design-records/job-filters.md). `$jobClass` is the job's class name,
     * read PHP-side from the payload envelope, stored as a queryable column so
     * findability can group/facet on it without the BEAM parsing the payload
     * (docs/design-records/job-findability-query.md §0). All are honored by the durable Postgres
     * adapter and ignored by the Memory adapter. nulls/empties are omitted so the
     * BEAM applies its defaults. The core stays framework-agnostic — it forwards
     * values it does not interpret.
     */
    public function push(string $payload, string $queue = 'default', int $delay = 0, ?int $maxAttempts = null, array $retry = [], ?array $filters = null, ?string $jobClass = null): string
    {
        $params = [
            'queue' => $queue,
            'payload' => $payload,
            'delay' => $delay,
        ];

        if ($maxAttempts !== null) {
            $params['max_attempts'] = $maxAttempts;
        }

        foreach (['backoff', 'max_exceptions', 'retry_until'] as $key) {
            if (($retry[$key] ?? null) !== null) {
                $params[$key] = $retry[$key];
            }
        }

        if (! empty($filters)) {
            $params['filters'] = $filters;
        }

        if ($jobClass !== null) {
            $params['job_class'] = $jobClass;
        }

        $result = $this->channel->request('queue.push', $params);

        return $result['job_id'] ?? '';
    }

    /**
     * Number of ready (runnable) jobs in a queue, from the BEAM store. Backs
     * Laravel's PhordQueue::size() so the queue reports a real depth.
     */
    public function size(string $queue = 'default'): int
    {
        $result = $this->channel->request('queue.depth', ['queue' => $queue]);

        return (int) ($result['depth'] ?? 0);
    }
}
