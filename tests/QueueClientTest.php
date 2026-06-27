<?php

namespace Phord\Tests;

use Phord\Channel;
use Phord\Queue\Client;
use PHPUnit\Framework\TestCase;

/**
 * The enqueue Client threads the projected filter-map to the BEAM as an explicit
 * `queue.push` param — alongside the opaque payload, never inside it
 * (docs/design-records/job-filters.md). The core stays framework-agnostic: it forwards a map it
 * does not interpret.
 */
class QueueClientTest extends TestCase
{
    public function test_push_threads_filters_as_an_explicit_param(): void
    {
        $channel = $this->recordingChannel();
        $client = new Client($channel);

        $client->push('opaque-payload', 'emails', 0, 3, ['backoff' => '5'], ['customer_id' => '4711', 'region' => 'eu']);

        $this->assertSame('queue.push', $channel->lastMethod);
        $this->assertSame(['customer_id' => '4711', 'region' => 'eu'], $channel->lastParams['filters']);
        // Payload is still its own param — filters live ALONGSIDE it, not inside.
        $this->assertSame('opaque-payload', $channel->lastParams['payload']);
    }

    public function test_push_omits_filters_when_none(): void
    {
        $channel = $this->recordingChannel();
        $client = new Client($channel);

        $client->push('opaque-payload', 'default');
        $this->assertArrayNotHasKey('filters', $channel->lastParams);

        // An empty map is treated as "no filters" too (omitted).
        $client->push('opaque-payload', 'default', 0, null, [], []);
        $this->assertArrayNotHasKey('filters', $channel->lastParams);
    }

    public function test_push_threads_job_class_when_present_and_omits_when_null(): void
    {
        $channel = $this->recordingChannel();
        $client = new Client($channel);

        $client->push('opaque-payload', 'default', 0, null, [], null, 'App\\Jobs\\ProcessInvoice');
        $this->assertSame('App\\Jobs\\ProcessInvoice', $channel->lastParams['job_class']);

        $client->push('opaque-payload', 'default');
        $this->assertArrayNotHasKey('job_class', $channel->lastParams);
    }

    private function recordingChannel(): Channel
    {
        return new class extends Channel {
            public ?string $lastMethod = null;

            public array $lastParams = [];

            public function __construct() {}

            // Skip the parent destructor — it touches uninitialized typed
            // properties on this socket-less double.
            public function __destruct() {}

            public function request(string $method, array $params = []): mixed
            {
                $this->lastMethod = $method;
                $this->lastParams = $params;

                return ['job_id' => 'job-1'];
            }
        };
    }
}
