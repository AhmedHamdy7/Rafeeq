<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;
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

/**
 * 🔴 Every code must have real wording in BOTH languages.
 *
 * Written after it had already happened: `LIVE_SHARE_ALREADY_ENDED` shipped with a status and no
 * translation, so `__('errors.X')` fell back to returning the key — and the API answered a person
 * with the literal string `errors.LIVE_SHARE_ALREADY_ENDED`. Nothing failed, because a missing
 * translation is not an error in Laravel; it is a string that happens to be the key.
 *
 * That is the worst shape of bug for this particular file. The envelope's `message` is what a
 * client shows when it has no case for the `code`, which is exactly the situation a NEW code
 * creates — so the first person to see a new error is the most likely to be shown a machine name.
 * And in the safety codes, that person may be in a bad situation.
 */
it('gives every error code real wording in Arabic and in English', function () {
    $untranslated = [];

    foreach (ErrorCode::cases() as $code) {
        foreach (['en', 'ar'] as $locale) {
            $key = 'errors.'.$code->value;

            // `__()` returns the KEY when there is no line, so comparing against it is the check.
            // `Lang::has()` would do, but this is the exact value a client would be shown.
            if (__($key, locale: $locale) === $key) {
                $untranslated[] = "{$code->value} ({$locale})";
            }
        }
    }

    expect($untranslated)->toBeEmpty(
        "These error codes have no wording, so the API answers with the key itself: \n- "
        .implode("\n- ", $untranslated)
        ."\n\nAdd a line to lang/en/errors.php AND lang/ar/errors.php."
    );
});

/**
 * `toContain($needle, $message)` does not take a message. Every argument after the
 * first is ANOTHER needle to look for, so a helpful failure message silently becomes
 * a second assertion that the haystack contains the message itself.
 *
 * Written the second time it happened in one sitting. It fails loudly rather than
 * passing vacuously, so it costs a round trip rather than correctness — but it costs
 * one every time, and the fix (`expect(str_contains(...))->toBeTrue($message)`) is not
 * obvious from the error.
 */
it('never passes a failure message to toContain', function () {
    $offenders = [];

    foreach (glob(__DIR__.'/../**/*Test.php') as $file) {
        $source = (string) file_get_contents($file);

        // A `toContain(` whose first argument is followed by a comma and then a
        // string long enough to be prose rather than a second needle.
        if (preg_match('/->toContain\([^)]*,\s*\n?\s*[\'"][^\'"]{25,}/', $source) === 1) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBeEmpty(
        'These files pass what looks like a failure message to toContain(), where it is treated as '
        ."another needle. Use expect(str_contains(...))->toBeTrue('message') instead: \n- "
        .implode("\n- ", $offenders)
    );
});

/**
 * 🔴 Binding standard #47.
 *
 * `bookings.live_booking_key` and `SeatAvailabilityChecker::assertNoDuplicateBooking()`
 * are two statements of one rule: which booking statuses count as somebody holding a
 * seat. When they differed, the index refused a rebooking the checker had just
 * allowed — so the constraint fired AFTER the code said yes, and the passenger got a
 * 500 instead of a refusal or a seat.
 *
 * Read from `information_schema` rather than from the migration file, because the
 * migration is what was written and this is what the database is actually enforcing.
 */
it('keeps the live-booking index and the duplicate check saying the same thing', function () {
    $expression = DB::table('information_schema.COLUMNS')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', 'bookings')
        ->where('COLUMN_NAME', 'live_booking_key')
        ->value('GENERATION_EXPRESSION');

    expect($expression)->not->toBeNull('bookings.live_booking_key is missing from the database.');

    /*
     * The two engines report the same expression differently. MariaDB echoes it as written
     * ('pending'); MySQL 8 — production — adds a charset introducer and escapes the quotes
     * (_utf8mb4\'pending\'). Read literally, the second fails every status below while the
     * index is perfectly correct, so the comparisons are made on the normalised form.
     */
    $expression = str_replace(['_utf8mb4', '\\'], '', (string) $expression);

    // The statuses the application counts as a live booking, read from the source of
    // truth for that decision.
    $checked = [];

    preg_match_all(
        '/BookingStatus::(\w+)->value/',
        (string) file_get_contents(__DIR__.'/../../app/Domains/Booking/Support/SeatAvailabilityChecker.php'),
        $matches,
    );

    foreach ($matches[1] as $case) {
        $checked[] = constant(BookingStatus::class.'::'.$case)->value;
    }

    $checked = array_values(array_unique($checked));

    expect($checked)->not->toBeEmpty();

    foreach ($checked as $status) {
        // `toBeTrue` with a message rather than `toContain`, which treats a second
        // argument as another needle to look for.
        expect(str_contains((string) $expression, "'{$status}'"))->toBeTrue(
            "The duplicate check counts '{$status}' as a live booking but the unique index does not. "
            .'A rebooking the code allows will hit a constraint violation and return 500.'
        );
    }

    // And nothing the index counts that the code does not — the same drift in reverse
    // silently forbids a booking nobody refused.
    preg_match_all("/'(\w+)'/", (string) $expression, $indexed);

    expect(array_values(array_diff($indexed[1], $checked)))->toBeEmpty(
        'The unique index treats statuses as live that the duplicate check does not, so a booking '
        .'the application allows will be refused by the database with no explanation.'
    );
});

/*
 * NOT checked here, on purpose: how a list is built (`foreach` with an
 * accumulator, never `array_map` over a filter) so the OpenAPI generator can
 * type its elements. That mistake has cost the contract its element type three
 * times now, but the symptom is asserted against the real published document
 * in OpenApiDocumentTest — "describes every field concretely" — which cannot
 * be fooled by a clever refactor the way a source-scan could.
 */

/**
 * Every `*Test.php` under `tests/`.
 *
 * @return array<int, string>
 */
function testFilePaths(): array
{
    $paths = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/..', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() === 'php' && str_ends_with($file->getFilename(), 'Test.php')) {
            $paths[] = $file->getPathname();
        }
    }

    return $paths;
}

/**
 * The global functions one file declares at top level.
 *
 * @return array<int, string>
 */
function globalFunctionsDeclaredIn(string $path): array
{
    preg_match_all(
        '/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m',
        (string) file_get_contents($path),
        $matches
    );

    return $matches[1];
}

/**
 * 🔴 Two test files must never declare a global function with the same name.
 *
 * PHP has one global function namespace, and a `function foo()` inside a test file is declared
 * the moment that file is compiled. Two files declaring the same name is a FATAL error that
 * happens before a single test runs — so the whole suite reports nothing useful, and the file
 * that broke it passes perfectly well on its own.
 *
 * That shape of failure cost time four times in one phase, in both directions: a helper defined
 * in one test file and called from another (which works until the first file is not collected),
 * and a helper whose name a second file then reused. The rule the project already follows for the
 * first case is "shared helpers live in Pest.php"; this catches the second, which is the one that
 * takes the whole suite down at once.
 */
it('never declares the same test helper in two files', function () {
    $declarations = [];

    foreach (testFilePaths() as $path) {
        foreach (globalFunctionsDeclaredIn($path) as $name) {
            $declarations[$name][] = basename($path);
        }
    }

    $collisions = [];

    foreach ($declarations as $name => $files) {
        $files = array_unique($files);

        if (count($files) > 1) {
            $collisions[] = $name.'() in '.implode(' and ', $files);
        }
    }

    // `toBeTrue` with a message rather than `toBeEmpty`, so the failure names every collision
    // instead of printing an array.
    expect($collisions === [])->toBeTrue(
        "These test helpers are declared in more than one file, which is a fatal error before\n"
        ."any test runs:\n- ".implode("\n- ", $collisions)
        ."\n\nRename one, or move the shared version to tests/Pest.php."
    );
});

/**
 * And the same collision against the shared file, which is the more likely one to introduce: a
 * new test file writes a helper whose name `Pest.php` already provides, and the suite dies before
 * anything runs.
 *
 * Checked by name rather than by resolving every call. A full call-graph check would need the
 * whole suite loaded, and this catches the case that actually happens.
 */
it('keeps shared test helpers in Pest.php rather than shadowed elsewhere', function () {
    $shared = globalFunctionsDeclaredIn(__DIR__.'/../Pest.php');

    expect($shared)->not->toBeEmpty('Pest.php declares no helpers — this check proved nothing.');

    $shadowed = [];

    foreach (testFilePaths() as $path) {
        foreach (array_intersect(globalFunctionsDeclaredIn($path), $shared) as $name) {
            $shadowed[] = $name.'() in '.basename($path);
        }
    }

    expect($shadowed === [])->toBeTrue(
        "These test files redeclare a helper that Pest.php already provides, which is fatal:\n- "
        .implode("\n- ", $shadowed)
    );
});
