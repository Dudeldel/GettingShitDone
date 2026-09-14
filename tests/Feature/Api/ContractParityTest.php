<?php

use App\Http\Requests\ListItemsRequest;
use Tests\Support\EnforcedRules;
use Tests\Support\PublishedContract;

/**
 * The published contract must be the enforced one (test plan §2 Risk #4).
 *
 * Scramble generates the document FROM the FormRequests, which makes this look circular. It is
 * not: the link under test is Scramble's translation, and a rule that does not survive it changes
 * what consumers are promised without changing what the endpoint does.
 *
 * Every assertion collects its failures and reports them together — one run should tell you
 * everything that drifted, not the first thing alphabetically.
 */

/** FormRequests that publish no request body, with the reason. */
const QUERY_ONLY = [
    ListItemsRequest::class => 'All fields are @query; they publish as operation parameters, not a body schema.',
];

it('publishes a request schema for every FormRequest, or names it query-only', function () {
    // Listing the covered classes instead of discovering them would let a new FormRequest ship
    // with no published schema and no failing test — the silent gap this check exists to close.
    $missing = [];

    foreach (PublishedContract::formRequests() as $class) {
        if (array_key_exists($class, QUERY_ONLY)) {
            continue;
        }
        if (PublishedContract::requestSchema($class) === null) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe([], 'FormRequests with no published schema and no QUERY_ONLY entry: '.implode(', ', $missing));
});

it('documents the query-only requests as operation parameters', function () {
    // The escape hatch must not become a hiding place: a class listed as query-only still has to
    // appear in the document somewhere.
    $documented = [];

    foreach (PublishedContract::spec()['paths'] as $operations) {
        foreach ($operations as $operation) {
            foreach ($operation['parameters'] ?? [] as $parameter) {
                $documented[] = $parameter['name'];
            }
        }
    }

    foreach (array_keys(QUERY_ONLY) as $class) {
        foreach (array_keys(EnforcedRules::of($class)) as $field) {
            expect($documented)->toContain($field);
        }
    }
});

it('understands every rule token in use, or names the one it does not', function () {
    // A check that silently skips what it cannot read degrades into a green test that verifies
    // almost nothing — quietly, as rules are added. An unmapped token fails here by name so that
    // adding one forces a one-line decision instead of opening a coverage hole.
    $unknown = [];

    foreach (PublishedContract::formRequests() as $class) {
        foreach (EnforcedRules::of($class) as $field => $enforced) {
            foreach ($enforced['unknown'] as $token) {
                $unknown[] = class_basename($class)."::{$field} → {$token}";
            }
        }
    }

    expect($unknown)->toBe([], 'Rule tokens neither mapped nor listed in EnforcedRules::UNREPRESENTABLE: '.implode(', ', $unknown));
});

it('publishes exactly the values each field actually accepts', function () {
    // THE assertion for this change. A narrowed Rule::in and a full-enum $ref both look like
    // "this field has an enum" until the reference is resolved and the value SETS are compared —
    // which is where a document offering a value the endpoint refuses hides.
    $drift = [];

    foreach (PublishedContract::formRequests() as $class) {
        $schema = PublishedContract::requestSchema($class);

        if ($schema === null) {
            continue;
        }

        foreach (EnforcedRules::of($class) as $field => $enforced) {
            if ($enforced['enum'] === null || ! isset($schema['properties'][$field])) {
                continue;
            }

            $published = PublishedContract::resolve($schema['properties'][$field])['enum'] ?? [];

            sort($published);
            $expected = $enforced['enum'];
            sort($expected);

            if ($published !== $expected) {
                $drift[] = class_basename($class)."::{$field} enforces [".implode(',', $expected)
                    .'] but publishes ['.implode(',', $published).']';
            }
        }
    }

    expect($drift)->toBe([], implode(' | ', $drift));
});

it('publishes every length and count limit the rules apply', function () {
    // `max:` means three different things in OpenAPI depending on what it constrains — a string's
    // length, an array's size, or a number's value. Looking for only one of them would pass a
    // field whose real limit is published nowhere.
    $drift = [];

    foreach (PublishedContract::formRequests() as $class) {
        $schema = PublishedContract::requestSchema($class);

        if ($schema === null) {
            continue;
        }

        foreach (EnforcedRules::of($class) as $field => $enforced) {
            foreach (['max' => ['maxLength', 'maxItems', 'maximum'], 'min' => ['minLength', 'minItems', 'minimum']] as $bound => [$forString, $forArray, $forNumber]) {
                if ($enforced[$bound] === null) {
                    continue;
                }

                // `field.*` constrains the ELEMENTS, so its counterpart lives under the parent's
                // `items`, not on a property of its own.
                $isElement = str_ends_with($field, '.*');
                $property = $isElement ? substr($field, 0, -2) : $field;
                $node = $schema['properties'][$property] ?? null;

                if (! is_array($node)) {
                    continue;
                }

                $node = PublishedContract::resolve($node);
                $key = match (true) {
                    $isElement => $forString,
                    $enforced['type'] === 'array' => $forArray,
                    in_array($enforced['type'], ['integer', 'numeric'], true) => $forNumber,
                    default => $forString,
                };
                $published = $isElement ? ($node['items'][$key] ?? null) : ($node[$key] ?? null);

                if ($published !== $enforced[$bound]) {
                    $drift[] = class_basename($class)."::{$field} enforces {$bound} {$enforced[$bound]} but publishes {$key}=".var_export($published, true);
                }
            }
        }
    }

    expect($drift)->toBe([], implode(' | ', $drift));
});

it('marks as required exactly the fields that are unconditionally required', function () {
    $drift = [];

    foreach (PublishedContract::formRequests() as $class) {
        $schema = PublishedContract::requestSchema($class);

        if ($schema === null) {
            continue;
        }

        $published = $schema['required'] ?? [];

        foreach (EnforcedRules::of($class) as $field => $enforced) {
            if (str_ends_with($field, '.*')) {
                continue;
            }
            if ($enforced['required'] !== in_array($field, $published, true)) {
                $drift[] = class_basename($class)."::{$field} enforces required=".var_export($enforced['required'], true)
                    .' but publishes required='.var_export(in_array($field, $published, true), true);
            }
        }
    }

    expect($drift)->toBe([], implode(' | ', $drift));
});
