<?php

namespace Phord\Process;

use RuntimeException;
use Throwable;

/**
 * Raw-PHP boot entry for a supervised PhordProcess (the framework-agnostic
 * counterpart to Phord\Laravel\Process\Runtime, which boots the Laravel kernel
 * and resolves the class from the container). Mirrors the worker entry split.
 *
 * BEAM spawns the process with `php <entry> <socketPath>`; the entry calls this:
 *
 *     \Phord\Process\Runtime::run($argv[1]);
 *
 * Sequence: connect → read the boot frame (class + args) → instantiate the class
 * directly → install the SIGTERM trap → send ready → run handle(...$args). When
 * handle() returns (the loop saw isRunning() flip, or finished on its own), the
 * process exits 0 and the Runner observes the exit.
 */
class Runtime
{
    public static function run(string $socketPath): void
    {
        try {
            $connection = Connection::connect($socketPath);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Phord process failed to connect to ' . $socketPath . ': ' . $e->getMessage() . "\n");
            exit(1);
        }

        $boot = $connection->readBoot();
        if ($boot === null) {
            fwrite(STDERR, "Phord process: Runner closed before boot\n");
            exit(1);
        }

        try {
            $process = self::instantiate($boot['class']);
            $process->installSignalHandler();
            // Raw core: handlers are invoked plainly (no container DI).
            $process->bindControlChannel($connection);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Phord process boot failed: ' . $e->getMessage() . "\n");
            $connection->sendBootError($e->getMessage());
            exit(1);
        }

        $connection->sendReady();
        fwrite(STDERR, "Phord process booted: {$boot['class']}\n");

        $process->handle(...$boot['args']);
    }

    /** Instantiate a PhordProcess subclass directly (no container). */
    protected static function instantiate(string $class): PhordProcess
    {
        if (! class_exists($class)) {
            throw new RuntimeException("Phord process: class {$class} not found");
        }

        $process = new $class();
        if (! $process instanceof PhordProcess) {
            throw new RuntimeException("Phord process: {$class} must extend " . PhordProcess::class);
        }

        return $process;
    }
}
