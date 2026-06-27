<?php

namespace Phord\Tests\Process;

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

    private function process(): PhordProcess
    {
        return new class extends PhordProcess {
            public function handle(...$args): void {}
        };
    }
}
