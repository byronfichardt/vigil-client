<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

it('sends reported exceptions to Vigil', function () {
    report(new RuntimeException('Payment gateway timed out'));

    expect($this->vigil->exceptions)->toHaveCount(1)
        ->and($this->vigil->exceptions[0])
        ->exception_class->toBe(RuntimeException::class)
        ->message->toBe('Payment gateway timed out');
});

it('still lets Laravel log the exception after sending it to Vigil', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event;
    });

    report(new RuntimeException('Payment gateway timed out'));

    expect($logged)->toHaveCount(1)
        ->and($logged[0]->level)->toBe('error')
        ->and($logged[0]->message)->toBe('Payment gateway timed out');
});

it('sends the Kamal version with each exception', function () {
    config(['vigil.app_version' => '9faffe75106d6795ed40616698432817988c0759']);

    report(new RuntimeException('boom'));

    expect($this->vigil->exceptions[0]['app_version'])->toBe('9faffe75106d6795ed40616698432817988c0759');
});

it('skips ignored exception types', function () {
    report(new Illuminate\Auth\AuthenticationException);

    expect($this->vigil->exceptions)->toBeEmpty();
});
