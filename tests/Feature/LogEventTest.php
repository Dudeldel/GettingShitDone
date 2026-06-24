<?php

use App\Logging\LogEvent;
use Illuminate\Support\Facades\Log;

/**
 * Demonstrates the intended pattern: a named static method delegating to the protected
 * LogEvent::emit() building block. Application code adds such methods to LogEvent itself;
 * this fixture stands in until the first real domain event lands.
 */
class FixtureDomainEvent extends LogEvent
{
    public static function itemClarified(string $itemId): void
    {
        self::emit('item.clarified.success', 'web', 'success', ['itemId' => $itemId]);
    }
}

it('emits a structured domain event with the event.* envelope via a named method', function () {
    Log::spy();

    FixtureDomainEvent::itemClarified('ITEM-1');

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
        return $level === 'info'
            && $message === 'item.clarified.success'
            && $context['event']['action'] === 'item.clarified.success'
            && $context['event']['category'] === 'web'
            && $context['event']['outcome'] === 'success'
            && $context['itemId'] === 'ITEM-1';
    });
});
