<?php

use Vigil\Client\Tests\LogsOffTestCase;
use Vigil\Client\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(LogsOffTestCase::class)->in('LogsOff');
