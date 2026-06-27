<?php

namespace Phord\Tests;

use Phord\Env;
use PHPUnit\Framework\TestCase;

class EnvTest extends TestCase
{
    private array $keys = ['PHORD_CHANNEL_SOCKET', 'PHORD_CHANNEL_TOKEN', 'PHORD_MODE'];

    protected function setUp(): void
    {
        foreach ($this->keys as $k) {
            putenv($k);
            unset($_SERVER[$k]);
        }
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    public function test_reads_from_getenv(): void
    {
        putenv('PHORD_CHANNEL_SOCKET=/tmp/x.sock');
        putenv('PHORD_CHANNEL_TOKEN=secret');
        putenv('PHORD_MODE=worker');

        $this->assertSame('/tmp/x.sock', Env::socket());
        $this->assertSame('secret', Env::token());
        $this->assertSame('worker', Env::mode());
    }

    public function test_falls_back_to_server(): void
    {
        $_SERVER['PHORD_CHANNEL_SOCKET'] = '/tmp/fpm.sock';
        $_SERVER['PHORD_MODE'] = 'fpm';

        $this->assertSame('/tmp/fpm.sock', Env::socket());
        $this->assertSame('fpm', Env::mode());
    }

    public function test_getenv_takes_precedence_over_server(): void
    {
        putenv('PHORD_CHANNEL_SOCKET=/tmp/env.sock');
        $_SERVER['PHORD_CHANNEL_SOCKET'] = '/tmp/server.sock';

        $this->assertSame('/tmp/env.sock', Env::socket());
    }

    public function test_defaults_when_unset(): void
    {
        $this->assertNull(Env::socket());
        $this->assertNull(Env::token());
        $this->assertSame('unknown', Env::mode());
    }
}
