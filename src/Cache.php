<?php

namespace Phord;

/**
 * Ergonomic cache client over the back-channel. The store itself lives in the
 * BEAM (ETS); this just translates calls into cache.* JSON-RPC messages.
 *
 * Framework-agnostic: the Laravel package wraps this (facade / future Cache
 * store driver), but it can be used directly: new Phord\Cache(new Phord\Channel()).
 */
class Cache
{
    public function __construct(private Channel $channel) {}

    /** Get a value, or $default on a miss. */
    public function get(string $key, mixed $default = null): mixed
    {
        $result = $this->channel->request('cache.get', ['key' => $key]);

        return ($result['hit'] ?? false) ? $result['value'] : $default;
    }

    /** Whether a live (non-expired) value exists for $key. */
    public function has(string $key): bool
    {
        $result = $this->channel->request('cache.get', ['key' => $key]);

        return (bool) ($result['hit'] ?? false);
    }

    /** Store a value; $ttl in seconds (null = no expiry). */
    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->channel->request('cache.put', ['key' => $key, 'value' => $value, 'ttl' => $ttl]);
    }

    /** Alias of put(). */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->put($key, $value, $ttl);
    }

    /** Store a value with no expiry. */
    public function forever(string $key, mixed $value): void
    {
        $this->put($key, $value, null);
    }

    /**
     * Atomically add $amount to a numeric key, returning the new value, or false
     * if the existing value isn't an integer.
     */
    public function increment(string $key, int $amount = 1): int|false
    {
        $result = $this->channel->request('cache.increment', ['key' => $key, 'amount' => $amount]);

        return ($result['ok'] ?? false) ? $result['value'] : false;
    }

    /** Atomically subtract $amount; see increment(). */
    public function decrement(string $key, int $amount = 1): int|false
    {
        $result = $this->channel->request('cache.decrement', ['key' => $key, 'amount' => $amount]);

        return ($result['ok'] ?? false) ? $result['value'] : false;
    }

    /**
     * Reset a live key's TTL without changing its value ($ttl seconds, or null
     * for no expiry). Returns true if the key existed and was live.
     */
    public function touch(string $key, ?int $ttl = null): bool
    {
        $result = $this->channel->request('cache.touch', ['key' => $key, 'ttl' => $ttl]);

        return (bool) ($result['ok'] ?? false);
    }

    /** Remove a key. */
    public function forget(string $key): void
    {
        $this->channel->request('cache.forget', ['key' => $key]);
    }

    /** Remove every key. */
    public function flush(): void
    {
        $this->channel->request('cache.flush');
    }

    // Locks -----------------------------------------------------------------
    //
    // Atomic named locks over the same back-channel (lock.* methods). These back
    // Laravel's Cache::lock(...) via the LockProvider the phord store implements.
    // NODE-LOCAL: correct on a single node (replicas:1), not cluster-wide — the
    // BEAM ETS lock table is per-pod. See Phord\Laravel\Cache\PhordLock and the
    // Phord.Lock moduledoc.

    /**
     * Attempt to atomically acquire $name for $owner. $seconds is the lock TTL
     * (0 = no expiry). Returns true iff acquired (exactly one of N racers wins).
     */
    public function lockAcquire(string $name, string $owner, int $seconds = 0): bool
    {
        $result = $this->channel->request('lock.acquire', [
            'name' => $name,
            'owner' => $owner,
            'ttl' => $seconds > 0 ? $seconds : null,
        ]);

        return (bool) ($result['acquired'] ?? false);
    }

    /** Release $name only if held by $owner (owner-checked). Returns true if removed. */
    public function lockRelease(string $name, string $owner): bool
    {
        $result = $this->channel->request('lock.release', ['name' => $name, 'owner' => $owner]);

        return (bool) ($result['released'] ?? false);
    }

    /** The current live owner of $name, or null if free/expired. */
    public function lockOwner(string $name): ?string
    {
        $result = $this->channel->request('lock.owner', ['name' => $name]);

        return $result['owner'] ?? null;
    }

    /** Release $name regardless of owner (Laravel forceRelease). */
    public function lockForceRelease(string $name): void
    {
        $this->channel->request('lock.forceRelease', ['name' => $name]);
    }
}
