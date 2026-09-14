<?php

namespace Tests\Support;

use BackedEnum;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use ReflectionClass;

/**
 * What a FormRequest actually enforces, normalised into the shapes OpenAPI can express.
 *
 * The other half of the parity check. Limits are read off `rules()` at runtime and never restated
 * here — restating a limit in a test is the lived anti-pattern the test plan's §2 names, and it
 * would make this file a second source of truth for the very thing it is auditing.
 */
final class EnforcedRules
{
    /**
     * Rule tokens with no OpenAPI counterpart, and why.
     *
     * A token absent from BOTH this list and the mapping below fails the check by name. That is
     * deliberate: a parity check that silently ignores what it does not understand degrades into
     * a green test that verifies almost nothing, and it degrades quietly as rules are added.
     */
    public const UNREPRESENTABLE = [
        'nullable' => 'Widens the published type union; the field may also be a $ref, which cannot carry null.',
        'sometimes' => 'Conditional presence — OpenAPI has no equivalent.',
        'confirmed' => 'Generates a sibling *_confirmation field rather than constraining this one.',
        'prohibits' => 'Conditional exclusion between fields.',
        'prohibited_if' => 'Conditional exclusion between fields.',
        'prohibited_if_declined' => 'Conditional exclusion between fields.',
        'required_if' => 'Conditional requirement — OpenAPI `required` is unconditional.',
        'required_if_accepted' => 'Conditional requirement — OpenAPI `required` is unconditional.',
        'required_if_declined' => 'Conditional requirement — OpenAPI `required` is unconditional.',
        'required_without' => 'Conditional requirement — OpenAPI `required` is unconditional.',
        'unique' => 'Asserted against database state, not against the payload.',
    ];

    /**
     * Normalise one FormRequest's rules.
     *
     * @return array<string, array{required: bool, enum: list<string>|null, max: int|null, min: int|null, type: string|null, unknown: list<string>}>
     */
    public static function of(string $formRequest): array
    {
        $fields = [];

        foreach ((new $formRequest)->rules() as $field => $list) {
            $fields[$field] = self::field((array) $list);
        }

        return $fields;
    }

    /**
     * @param  array<int, mixed>  $list
     * @return array{required: bool, enum: list<string>|null, max: int|null, min: int|null, type: string|null, unknown: list<string>}
     */
    private static function field(array $list): array
    {
        $out = ['required' => false, 'enum' => null, 'max' => null, 'min' => null, 'type' => null, 'unknown' => []];

        foreach ($list as $rule) {
            if ($rule instanceof In || $rule instanceof Enum) {
                // Intersected, not overwritten: a field carrying both Rule::enum and a narrowing
                // Rule::in enforces only what survives BOTH, and that intersection is the set the
                // document has to publish.
                $values = $rule instanceof In ? self::inValues($rule) : self::enumValues($rule);
                $out['enum'] = $out['enum'] === null
                    ? $values
                    : array_values(array_intersect($out['enum'], $values));

                continue;
            }

            if (! is_string($rule)) {
                $out['unknown'][] = get_debug_type($rule);

                continue;
            }

            [$token, $arg] = array_pad(explode(':', $rule, 2), 2, null);

            match (true) {
                // `present` joins `required` here because Scramble publishes both into the
                // schema's `required` array — verified against UpdateItemAttributesRequest.
                $token === 'required', $token === 'present' => $out['required'] = true,
                $token === 'max' => $out['max'] = (int) $arg,
                $token === 'min' => $out['min'] = (int) $arg,
                $token === 'email' => $out['type'] = 'string',
                $token === 'date_format' => $out['type'] = 'string',
                in_array($token, ['string', 'boolean', 'integer', 'numeric', 'array'], true) => $out['type'] = $token,
                array_key_exists($token, self::UNREPRESENTABLE) => null,
                default => $out['unknown'][] = $token,
            };
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function inValues(In $rule): array
    {
        // `Rule::in` stringifies to `in:"a","b"`; there is no public accessor for its values.
        $raw = substr((string) $rule, strlen('in:'));

        return array_values(array_map(
            static fn (string $value): string => trim($value, '"'),
            str_getcsv($raw, escape: '\\'),
        ));
    }

    /**
     * @return list<string>
     */
    private static function enumValues(Enum $rule): array
    {
        $property = (new ReflectionClass($rule))->getProperty('type');
        $property->setAccessible(true);
        /** @var class-string<BackedEnum> $enum */
        $enum = $property->getValue($rule);

        return array_values(array_map(
            static fn (BackedEnum $case): string => (string) $case->value,
            $enum::cases(),
        ));
    }
}
