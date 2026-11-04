<?php

namespace Vigil\Client\Tests;

class LogsOffTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('vigil.logs.enabled', false);
    }
}
