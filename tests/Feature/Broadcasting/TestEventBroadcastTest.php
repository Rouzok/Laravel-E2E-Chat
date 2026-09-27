<?php

use App\Events\TestEvent;
use Illuminate\Support\Facades\Event;

test('test event can be dispatched and broadcasted', function () {
    Event::fake();

    TestEvent::dispatch('Hello Reverb');

    Event::assertDispatched(TestEvent::class, function ($event) {
        return $event->message === 'Hello Reverb';
    });
});

test('test event broadcasts on correct channel', function () {
    $event = new TestEvent('Channel Test');

    expect($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0]->name)->toBe('test-channel');
});
