<?php

namespace Phord;

use RuntimeException;

/**
 * 4-byte (big-endian / network order) length-prefix framing over a stream
 * socket. Matches Elixir's `{:packet, 4}` option: each frame is a self-contained
 * message, so bodies need no escaping and are fully binary-safe.
 */
class Frame
{
    /**
     * Read one frame. Returns the payload, '' for an empty frame, or null on a
     * clean EOF / closed socket (so the caller can exit its loop).
     *
     * @param  resource  $socket
     */
    public static function read($socket): ?string
    {
        $header = self::readExactly($socket, 4);
        if ($header === null) {
            return null;
        }

        $len = unpack('N', $header)[1];

        return $len === 0 ? '' : self::readExactly($socket, $len);
    }

    /**
     * Write one frame (4-byte length prefix + payload), looping until the whole
     * packet is flushed.
     *
     * @param  resource  $socket
     */
    public static function write($socket, string $data): void
    {
        $packet = pack('N', strlen($data)) . $data;
        $total = strlen($packet);
        $written = 0;

        while ($written < $total) {
            $n = fwrite($socket, substr($packet, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('Phord: socket write failed');
            }
            $written += $n;
        }

        fflush($socket);
    }

    /**
     * Read exactly $n bytes; null on a genuine EOF (peer closed the socket).
     *
     * `fread` on a blocking stream returns '' for BOTH a real EOF and a read
     * **timeout** (PHP's `default_socket_timeout`, 60s, or any `stream_set_timeout`).
     * The return value alone can't tell them apart, so an earlier version treated
     * every '' as a close — which made an idle worker, blocked here waiting for
     * its next job, exit on the 60s timeout and get rebooted (churn). `feof()`
     * distinguishes the two: it is true only on an actual end-of-file. On a
     * timeout we keep waiting; only a real EOF ends the read. A truncated frame
     * (peer closes mid-frame, after a partial read) is still a genuine EOF → null.
     *
     * @param  resource  $socket
     */
    private static function readExactly($socket, int $n): ?string
    {
        $buf = '';

        while (strlen($buf) < $n) {
            $chunk = fread($socket, $n - strlen($buf));

            if ($chunk === false || $chunk === '') {
                // EOF vs. timeout: feof() is true only on a real close. A timeout
                // (idle worker between jobs) just means "no data yet" — loop and
                // keep blocking for the next read window.
                if (feof($socket)) {
                    return null;
                }

                continue;
            }

            $buf .= $chunk;
        }

        return $buf;
    }
}
