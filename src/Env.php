<?php

namespace Phord;

/**
 * Discovers the back-channel coordinates that Elixir injects into the PHP
 * process, so nothing is hard-coded:
 *
 *   * worker mode — passed as `Port` env (visible to `getenv()`);
 *   * fpm mode    — passed as FastCGI params (visible in `$_SERVER`).
 *
 * Reads `getenv()` first, then falls back to `$_SERVER`, giving one code path
 * that works under both SAPIs.
 */
class Env
{
    /** Back-channel Unix socket path, or null if unset. */
    public static function socket(): ?string
    {
        return self::read('PHORD_CHANNEL_SOCKET');
    }

    /** Optional shared-secret token, or null if unset. */
    public static function token(): ?string
    {
        return self::read('PHORD_CHANNEL_TOKEN');
    }

    /** Runtime mode: "worker", "fpm", or "unknown". */
    public static function mode(): string
    {
        return self::read('PHORD_MODE') ?? 'unknown';
    }

    private static function read(string $key): ?string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $v = $_SERVER[$key] ?? null;
        }

        return ($v === '' || $v === false) ? null : $v;
    }
}
