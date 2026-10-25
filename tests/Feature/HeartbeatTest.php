<?php

use Illuminate\Console\Scheduling\Schedule;

it('schedules a heartbeat every five minutes', function () {
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'vigil:heartbeat');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *');
});

it('sends the interval with the heartbeat', function () {
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'vigil:heartbeat');

    $event->run(app());

    expect($this->vigil->heartbeats)->toBe([5]);
});
