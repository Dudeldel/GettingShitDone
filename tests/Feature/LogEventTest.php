<?php

use App\Logging\LogEvent;
use Illuminate\Support\Facades\Log;

it('emits a structured domain event with the event.* envelope', function () {
    Log::spy();

    LogEvent::emit('item.clarified.success', 'web', 'success', ['itemId' => 'ITEM-1']);

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
        return $level === 'info'
            && $message === 'item.clarified.success'
            && $context['event']['action'] === 'item.clarified.success'
            && $context['event']['category'] === 'web'
            && $context['event']['outcome'] === 'success'
            && $context['itemId'] === 'ITEM-1';
    });
});
