<?php

use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Str;

/**
 * Mechanical checks for the mistakes this codebase has actually made more than
 * once.
 *
 * Every rule here exists because something slipped through review and was
 * caught later by accident. A convention that depends on remembering it is a
 * convention that will be broken again on a tired afternoon — so each of these
 * is written the moment the same mistake appears twice, and it names the fix in
 * its failure message rather than just going red.
 *
 * These are NOT style checks. Pint handles style. Each of these prevents a
 * specific defect: a lie in the published API contract, a leak of internal
 * notes to an external team, or a field with no declared type.
 */

/**
 * @return array<int, string>
 */
function phpFilesUnder(string ...$globs): array
{
    $files = [];

    foreach ($globs as $glob) {
        $files = [...$files, ...glob(__DIR__.'/../../'.$glob)];
    }

    return $files;
}

/**
 * Binding standard #39.
 *
 * `Rule::in([...])` and `'in:a,b'` constrain the VALUE but declare no type. The
 * field then has no type in the API contract, and the validator accepts, say,
 * an array where a string was meant. Twice now a field has shipped this way.
 */
it('declares a type alongside every value constraint in a FormRequest', function () {
    $offenders = [];

    foreach (phpFilesUnder('app/Http/Requests/*/*.php') as $file) {
        $source = (string) file_get_contents($file);

        // Each rule array entry lives on its own line or wraps; match the
        // field name and the rules that follow it up to the closing bracket.
        preg_match_all("/'([\w.]+)'\s*=>\s*\[(.*?)\],\n/s", $source, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $field, $rules]) {
            $constrainsValues = str_contains($rules, 'Rule::in')
                || preg_match("/'in:/", $rules) === 1;

            if (! $constrainsValues) {
                continue;
            }

            $declaresType = preg_match("/'(string|integer|numeric|boolean|array|date)'/", $rules) === 1;

            if (! $declaresType) {
                $offenders[] = basename($file).': '.$field;
            }
        }
    }

    expect($offenders)->toBeEmpty(
        "These fields constrain their values but declare no type. Add 'string' (or the right "
        ."type) next to the rule, or use Rule::enum() which pins both: \n- ".implode("\n- ", $offenders)
    );
});

/**
 * Binding standard #38.
 *
 * Scramble publishes the comment above each validation rule as that field's
 * description in the OpenAPI document the external Flutter team reads. Notes
 * written for maintainers ended up in the published contract once already —
 * "pitfall #31" and "filter-then-map" are not things a mobile developer can
 * act on.
 */
it('keeps maintainer jargon out of the comments that become API documentation', function () {
    // Words that only mean something to someone with this repository open.
    $internalJargon = ['pitfall #', 'Bible §', 'ERD §', 'MASTER_PLAN', 'binding standard', 'Scramble'];

    $offenders = [];

    foreach (phpFilesUnder('app/Http/Requests/*/*.php') as $file) {
        $source = (string) file_get_contents($file);

        // Only the body of rules(); the class docblock above it, and any other
        // method below it, are exactly where these notes are supposed to live.
        //
        // `before`, not `Str::between`: that helper uses `beforeLast`, so it
        // would swallow every method after this one and flag their docblocks.
        $rulesBody = Str::before(
            Str::after($source, 'public function rules(): array'),
            "\n    }",
        );

        foreach ($internalJargon as $term) {
            if (str_contains($rulesBody, $term)) {
                $offenders[] = basename($file).': "'.$term.'"';
            }
        }
    }

    expect($offenders)->toBeEmpty(
        'These comments sit above validation rules, so they are published as field descriptions to the '
        ."external mobile team. Move the reasoning to the class docblock: \n- ".implode("\n- ", $offenders)
    );
});

/**
 * Binding standard #12: the domain layer stays free of Laravel, so the rules it
 * encodes can be read and tested without booting a framework.
 */
it('keeps Laravel out of every domain Enums namespace', function () {
    $offenders = [];

    foreach (phpFilesUnder('app/Domains/*/Enums/*.php') as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match('/^use (Illuminate|Laravel)\\\\/m', $source) === 1) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBeEmpty(
        'These enums import framework code. Move anything that needs Eloquent or Illuminate into the '
        ."domain's Support namespace: \n- ".implode("\n- ", $offenders)
    );
});

/**
 * Every code an Action or controller can throw must reach the OpenAPI document,
 * and every code in the catalogue must have a status that is not a guess.
 * `OpenApiDocumentTest` covers the first; this covers the catalogue itself, so
 * a new code cannot be added without deciding what it means over HTTP.
 */
it('gives every error code a status that suits what it means', function () {
    foreach (ErrorCode::cases() as $code) {
        $status = $code->defaultStatus();

        expect($status)->toBeGreaterThanOrEqual(400, "{$code->value} is not an error status")
            ->and($status)->toBeLessThan(600, "{$code->value} is not a valid status");

        // A 4xx that reads as "our fault" tells the client to retry something
        // that will never succeed — the exact bug standard #27 was written for.
        if ($status < 500) {
            expect($code)->not->toBe(ErrorCode::ServerError);
        }
    }
});

/*
 * NOT checked here, on purpose: how a list is built (`foreach` with an
 * accumulator, never `array_map` over a filter) so the OpenAPI generator can
 * type its elements. That mistake has cost the contract its element type three
 * times now, but the symptom is asserted against the real published document
 * in OpenApiDocumentTest — "describes every field concretely" — which cannot
 * be fooled by a clever refactor the way a source-scan could.
 */
