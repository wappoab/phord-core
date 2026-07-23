<?php

namespace Phord\Tests\Process;

use Phord\Frame;
use Phord\Process\Connection;
use Phord\Process\PhordProcess;
use PHPUnit\Framework\TestCase;

/**
 * The PhordProcess contract: the cooperative-stop flag and the SIGTERM trap that
 * flips it. This is the mechanism the whole supervisor design rests on — a
 * process blocked in its own loop can only be stopped by a signal, so SIGTERM
 * MUST flip isRunning() and let a `while ($this->isRunning())` loop exit cleanly.
 */
class PhordProcessTest extends TestCase
{
    protected function tearDown(): void
    {
        // Don't leave a SIGTERM handler bound to a closure from a finished test.
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, SIG_DFL);
        }
    }

    public function test_is_running_true_until_stop_requested(): void
    {
        $proc = $this->process();
        $this->assertTrue($proc->isRunning());

        $proc->requestStop();
        $this->assertFalse($proc->isRunning());
    }

    public function test_cooperative_loop_exits_and_runs_teardown_when_stop_requested(): void
    {
        // A handle() that loops on isRunning(); flip the flag mid-loop (exactly
        // what the SIGTERM trap does) and the loop must exit and run its teardown.
        $proc = new class extends PhordProcess {
            public bool $teardownRan = false;

            public function handle(...$args): void
            {
                $iterations = 0;
                while ($this->isRunning()) {
                    if (++$iterations === 3) {
                        $this->requestStop(); // simulate SIGTERM arriving mid-loop
                    }
                }
                $this->teardownRan = true; // teardown after the loop returns
            }
        };

        $proc->handle();
        $this->assertTrue($proc->teardownRan, 'the loop must exit and run teardown');
    }

    public function test_install_signal_handler_is_safe_and_idempotent(): void
    {
        $proc = $this->process();
        $proc->installSignalHandler();
        $proc->installSignalHandler();
        $this->assertTrue($proc->isRunning());
    }

    public function test_sigterm_flips_is_running_when_the_handler_is_installed(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('ext-pcntl + ext-posix required for the real SIGTERM path');
        }

        $proc = $this->process();
        $proc->installSignalHandler();
        $this->assertTrue($proc->isRunning());

        // The real thing: deliver SIGTERM to ourselves. Because the handler is
        // installed it flips the flag instead of terminating the process.
        posix_kill(getmypid(), SIGTERM);
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        $this->assertFalse($proc->isRunning(), 'SIGTERM must flip isRunning() to false');
    }

    // Inbound calls — method routing, reserved list, reply frames -----------

    public function test_tick_routes_a_call_to_a_handler_and_replies_the_result(): void
    {
        [$runner, $proc] = $this->wired();

        Frame::write($runner, json_encode(['id' => 1, 'method' => 'add', 'args' => [2, 3]]));
        $proc->tick();

        $this->assertSame(['id' => 1, 'result' => 5], json_decode(Frame::read($runner), true));
    }

    public function test_tick_serves_multiple_pending_calls_and_state_persists(): void
    {
        [$runner, $proc] = $this->wired();

        Frame::write($runner, json_encode(['id' => 1, 'method' => 'bump', 'args' => []]));
        Frame::write($runner, json_encode(['id' => 2, 'method' => 'bump', 'args' => []]));
        $proc->tick();

        $this->assertSame(['id' => 1, 'result' => 1], json_decode(Frame::read($runner), true));
        $this->assertSame(['id' => 2, 'result' => 2], json_decode(Frame::read($runner), true));
    }

    public function test_named_args_bind_by_name_through_routing(): void
    {
        [$runner, $proc] = $this->wired();

        // Named args survive __call → arrive as string keys → bind by parameter name.
        Frame::write($runner, json_encode(['id' => 1, 'method' => 'sub', 'args' => ['b' => 3, 'a' => 10]]));
        $proc->tick();

        $this->assertSame(['id' => 1, 'result' => 7], json_decode(Frame::read($runner), true));
    }

    public function test_reserved_and_unknown_methods_error_without_crashing(): void
    {
        // The base contract + handle() + magic + a nonexistent name are all
        // non-handlers: routing to any of them is a reported error, never fatal.
        $reserved = [
            'handle', 'isRunning', 'installSignalHandler', 'requestStop',
            'tick', 'listen', 'bindControlChannel', '__construct', 'nope',
        ];

        foreach ($reserved as $i => $method) {
            [$runner, $proc] = $this->wired();

            Frame::write($runner, json_encode(['id' => $i, 'method' => $method, 'args' => []]));
            $proc->tick(); // must NOT throw — a caller error is reported, not fatal

            $reply = json_decode(Frame::read($runner), true);
            $this->assertSame($i, $reply['id'], "id echoed for {$method}");
            $this->assertStringContainsString('not a callable handler', $reply['error'], $method);
        }
    }

    public function test_a_throwing_handler_reports_the_error_then_lets_it_crash(): void
    {
        [$runner, $proc] = $this->wired();

        Frame::write($runner, json_encode(['id' => 1, 'method' => 'boom', 'args' => []]));

        try {
            $proc->tick();
            $this->fail('a throwing handler must re-throw so the process crashes');
        } catch (\RuntimeException $e) {
            $this->assertSame('kaboom', $e->getMessage());
        }

        // …but the error frame was sent first, so the caller gets an error not a hang.
        $this->assertSame(['id' => 1, 'error' => 'kaboom'], json_decode(Frame::read($runner), true));
    }

    private function process(): PhordProcess
    {
        return new class extends PhordProcess {
            public function handle(...$args): void {}
        };
    }

    /**
     * A process with handler methods, wired to an in-process socket pair whose
     * other end plays the Runner. Returns [$runnerSocket, $process].
     *
     * @return array{0: resource, 1: PhordProcess}
     */
    private function wired(): array
    {
        [$runner, $php] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        $proc = new class extends PhordProcess {
            private int $n = 0;

            public function handle(...$args): void {}

            public function add(int $a, int $b): int
            {
                return $a + $b;
            }

            public function sub(int $a, int $b): int
            {
                return $a - $b;
            }

            public function bump(): int
            {
                return ++$this->n;
            }

            public function boom(): void
            {
                throw new \RuntimeException('kaboom');
            }
        };

        $proc->bindControlChannel(new Connection($php));

        return [$runner, $proc];
    }
}
