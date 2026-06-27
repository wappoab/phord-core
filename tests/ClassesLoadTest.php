<?php

namespace Phord\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Boundary enforcement: the core classes must autoload with NO Laravel in the
 * tree. If anything in core grew an Illuminate dependency, requiring it here
 * (or the suite bootstrap) would fail.
 */
class ClassesLoadTest extends TestCase
{
    public function test_core_classes_autoload(): void
    {
        $this->assertTrue(class_exists(\Phord\Frame::class));
        $this->assertTrue(class_exists(\Phord\Env::class));
        $this->assertTrue(class_exists(\Phord\Channel::class));
        $this->assertTrue(class_exists(\Phord\Cache::class));
        $this->assertTrue(class_exists(\Phord\Worker\Connection::class));
        $this->assertTrue(class_exists(\Phord\Queue\Client::class));
        $this->assertTrue(class_exists(\Phord\Queue\WorkerConnection::class));
        $this->assertTrue(class_exists(\Phord\Process\PhordProcess::class));
        $this->assertTrue(class_exists(\Phord\Process\Connection::class));
        $this->assertTrue(class_exists(\Phord\Process\Runtime::class));
        $this->assertTrue(class_exists(\Phord\Process\BeamSupervisor::class));
    }

    public function test_laravel_is_not_installed(): void
    {
        // The whole point of core: it runs without Laravel.
        $this->assertFalse(class_exists(\Illuminate\Support\ServiceProvider::class));
    }
}
