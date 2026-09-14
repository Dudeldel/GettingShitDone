<?php

use App\Const\ClarifyConst;

/**
 * The two-minute threshold is stated in two languages, so "bound to a single source" is a
 * claim that needs enforcing rather than asserting.
 *
 * Before this test existed, `ClarifyConst::TWO_MINUTE_SECONDS` had no reference anywhere in
 * the backend — the timer is client-side by design — so the constant documented a binding it
 * did not create: changing the PHP value to 90 left every gate green while the SPA still ran
 * a 120-second clock. Reading the TypeScript from a PHP test is unusual, but it is the only
 * thing standing between the two halves of a cross-stack constant.
 */
it('keeps the SPA countdown bound to the domain constant', function () {
    $api = dirname(__DIR__, 3).'/frontend/src/api.ts';

    expect(is_file($api))->toBeTrue("Expected the SPA API client at {$api}.");

    $matched = preg_match('/export const TWO_MINUTE_SECONDS = (\d+)/', (string) file_get_contents($api), $found);

    // A rename is a drift too: if the export disappears, the binding is gone whether or not
    // the numbers still happen to agree.
    expect($matched)->toBe(1, 'frontend/src/api.ts no longer exports TWO_MINUTE_SECONDS.');
    expect((int) $found[1])->toBe(
        ClarifyConst::TWO_MINUTE_SECONDS,
        'The SPA countdown and ClarifyConst::TWO_MINUTE_SECONDS have drifted apart.',
    );
});
