<?php

namespace Vigil\Client\Facades;

use Illuminate\Support\Facades\Facade;
use Vigil\Client\AiSpendRecorder;

/**
 * @method static void record(string $provider, float $costUsd)
 * @method static void flush()
 * @method static array<string, float> pending()
 */
class AiSpend extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AiSpendRecorder::class;
    }
}
