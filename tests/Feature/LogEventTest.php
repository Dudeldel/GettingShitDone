<?php

use App\Logging\LogEvent;
use Illuminate\Support\Facades\Log;

/**
 * Pins the generic `emit()` envelope: a named static method delegating to the protected
 * building block, producing the ECS `event.*` shape every domain event must carry.
 *
 * The fixture method is named after nothing in the domain ON PURPOSE. It was originally
 * called `itemClarified()`, which collided with a fatal error the moment S-02 added the real
 * `LogEvent::itemClarified()` with a different signature — a subclass cannot narrow its
 * parent. Any name used here must stay one that no slice will ever want.
 */
class FixtureDomainEvent extends LogEvent
{
    public static function fixtureEventOccurred(string $subjectId): void
    {
        self::emit('fixture.event.success', 'web', 'success', ['subjectId' => $subjectId]);
    }
}

it('emits a structured domain event with the event.* envelope via a named method', function () {
    Log::spy();

    FixtureDomainEvent::fixtureEventOccurred('SUBJECT-1');

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
        return $level === 'info'
            && $message === 'fixture.event.success'
            && $context['event']['action'] === 'fixture.event.success'
            && $context['event']['category'] === 'web'
            && $context['event']['outcome'] === 'success'
            && $context['subjectId'] === 'SUBJECT-1';
    });
});
