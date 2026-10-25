<?php

namespace Vigil\Client\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Vigil\Client\VigilClient;
use Vigil\Client\VigilServiceProvider;

abstract class TestCase extends Orchestra
{
    protected FakeVigilClient $vigil;

    protected function getPackageProviders($app): array
    {
        return [VigilServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('vigil.url', 'https://vigil.test');
        $app['config']->set('vigil.key', 'test-key');
        $app['config']->set('vigil.enabled', true);
        $app['config']->set('logging.default', 'stack');
        $app['config']->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['null']]);

        $this->vigil = new FakeVigilClient;
        $app->instance(VigilClient::class, $this->vigil);
    }
}
