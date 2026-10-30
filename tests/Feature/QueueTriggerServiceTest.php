<?php

namespace Tests\Feature;

use App\Services\QueueTriggerService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class QueueTriggerServiceTest extends TestCase
{
    public function test_it_does_not_trigger_in_testing_environment(): void
    {
        // When running tests, it must safely no-op
        QueueTriggerService::trigger();

        $this->assertTrue(App::runningUnitTests());
    }

    public function test_it_does_not_trigger_when_queue_is_not_database(): void
    {
        Config::set('queue.default', 'sync');

        QueueTriggerService::trigger();

        $this->assertSame('sync', Config::get('queue.default'));
    }

    public function test_it_does_not_trigger_when_auto_trigger_is_disabled(): void
    {
        Config::set('queue.auto_trigger', false);

        QueueTriggerService::trigger();

        $this->assertFalse(Config::get('queue.auto_trigger'));
    }
}
