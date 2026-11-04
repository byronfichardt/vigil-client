<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Vigil\Client\Facades\AiSpend;

it('still sends spend when log forwarding is off', function () {
    AiSpend::record('gemini', 0.5);

    event(new JobProcessed('database', Mockery::mock(Job::class)));

    expect($this->vigil->spendBatches)->toBe([[['provider' => 'gemini', 'cost_usd' => 0.5]]])
        ->and($this->vigil->logBatches)->toBeEmpty();
});
