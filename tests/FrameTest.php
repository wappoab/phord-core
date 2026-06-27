<?php

namespace Phord\Tests;

use Phord\Frame;
use PHPUnit\Framework\TestCase;

class FrameTest extends TestCase
{
    /** @return array{0: resource, 1: resource} */
    private function pair(): array
    {
        return stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    }

    public function test_round_trips_text(): void
    {
        [$a, $b] = $this->pair();
        Frame::write($a, 'hello');
        $this->assertSame('hello', Frame::read($b));
    }

    public function test_is_binary_safe(): void
    {
        [$a, $b] = $this->pair();
        $bin = random_bytes(2048);
        Frame::write($a, $bin);
        $this->assertSame($bin, Frame::read($b));
    }

    public function test_handles_empty_frame(): void
    {
        [$a, $b] = $this->pair();
        Frame::write($a, '');
        $this->assertSame('', Frame::read($b));
    }

    public function test_reads_multiple_frames_in_order(): void
    {
        [$a, $b] = $this->pair();
        Frame::write($a, 'one');
        Frame::write($a, 'two');
        $this->assertSame('one', Frame::read($b));
        $this->assertSame('two', Frame::read($b));
    }

    public function test_read_returns_null_on_eof(): void
    {
        [$a, $b] = $this->pair();
        fclose($a);
        $this->assertNull(Frame::read($b));
    }

    public function test_read_returns_null_on_truncated_frame_at_eof(): void
    {
        // Header promises 10 bytes but only 3 arrive before the peer closes:
        // a real EOF mid-frame is still a close → null (not a hang, not a retry).
        [$a, $b] = $this->pair();
        fwrite($a, pack('N', 10) . 'abc');
        fclose($a);
        $this->assertNull(Frame::read($b));
    }

    public function test_read_waits_through_a_read_timeout_for_late_data(): void
    {
        // The regression this guards: an idle reader blocked in fread() hits the
        // socket read timeout (fread returns '' WITHOUT EOF). That must NOT be
        // mistaken for a closed socket — it must keep waiting and still receive a
        // frame that arrives after several timeouts. (This is exactly the idle
        // queue-worker churn: a 60s default_socket_timeout was read as EOF, so
        // the worker exited and got rebooted every minute.)
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required to drive a real read timeout');
        }

        [$a, $b] = $this->pair();
        // Short read timeout so the reader times out a few times before data.
        stream_set_timeout($b, 0, 100_000); // 100ms

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // Child = writer: wait well past the reader's timeout, then send.
            fclose($b);
            usleep(350_000); // 350ms > 100ms → the reader times out ~3× first
            Frame::write($a, 'late-arrival');
            fclose($a);
            exit(0);
        }

        // Parent = reader: must block through the timeouts and still get the
        // frame, not return null on the first timeout.
        fclose($a);
        $got = Frame::read($b);
        pcntl_waitpid($pid, $status);

        $this->assertSame('late-arrival', $got);
    }
}
