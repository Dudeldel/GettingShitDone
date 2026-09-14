<?php

namespace Tests\Support;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;

/**
 * The OpenAPI document as published, read WHOLE.
 *
 * Generated in process rather than shelled out to `scramble:export`: the document under test is
 * then the one this build produces, with no git-ignored file on disk to go stale, and the check
 * runs identically on a laptop and in CI without a workflow step anyone can forget.
 *
 * Memoised because generation walks every controller; one document per test process is plenty.
 */
final class PublishedContract
{
    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        // json round-trip rather than a hand-written object walk: the published artefact IS json,
        // so anything that survives this is what a consumer actually receives.
        return self::$spec ??= (array) json_decode(
            (string) json_encode(app(Generator::class)(Scramble::getGeneratorConfig('default'))),
            associative: true,
        );
    }

    /**
     * The request-body schema for a FormRequest, or null when it publishes none (query-only).
     *
     * @return array<string, mixed>|null
     */
    public static function requestSchema(string $formRequest): ?array
    {
        $name = class_basename($formRequest);

        $schema = self::spec()['components']['schemas'][$name] ?? null;

        return is_array($schema) ? $schema : null;
    }

    /**
     * Follow a `$ref` to the component it names.
     *
     * The load-bearing helper. A narrowed `Rule::in` and a full-enum `$ref` both look like "this
     * field has an enum" from the outside; only resolving the reference and comparing the value
     * SETS shows the document offering a value the endpoint refuses. Reading the node through a
     * key allowlist — `type`, `enum`, `maxLength` — is exactly how that goes unnoticed.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public static function resolve(array $node): array
    {
        $ref = $node['$ref'] ?? null;

        if (! is_string($ref)) {
            return $node;
        }

        $name = substr($ref, (int) strrpos($ref, '/') + 1);
        $target = self::spec()['components']['schemas'][$name] ?? [];

        // The referencing node's own keys win — a sibling `description` should not be lost, and a
        // field that both $refs and narrows would keep its narrowing.
        return array_merge(is_array($target) ? $target : [], array_diff_key($node, ['$ref' => null]));
    }

    /**
     * The published node for one field, with any `$ref` already resolved.
     *
     * @return array<string, mixed>
     */
    public static function property(string $formRequest, string $field): array
    {
        $schema = self::requestSchema($formRequest);

        return self::resolve($schema['properties'][$field] ?? []);
    }

    /**
     * Every FormRequest in the application, found on disk rather than listed.
     *
     * Listing them would let a new one be added with no published schema and no failing test —
     * the silent gap this whole check exists to close.
     *
     * @return list<class-string>
     */
    public static function formRequests(): array
    {
        $classes = [];

        foreach (glob(app_path('Http/Requests/*.php')) ?: [] as $file) {
            /** @var class-string $class */
            $class = 'App\\Http\\Requests\\'.basename($file, '.php');
            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
