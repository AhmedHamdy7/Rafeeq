<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Every model must be able to persist itself through its factory.
 *
 * This is deliberately broad rather than deep: it caught a real bug that
 * eight per-model test files did not, because those eight models had
 * factories nobody ever executed (RecommendationCache resolved to a
 * "recommendation_caches" table that does not exist — see
 * RAFEEQ_PROGRESS.md standard #21). Any future model with a mistyped table
 * name, a bad cast, a missing column, or a broken factory chain fails here
 * the moment it is added.
 *
 * @return list<class-string<Model>>
 */
function allDomainModels(): array
{
    $models = [];

    // Not app_path(): Pest resolves datasets outside the Laravel container.
    foreach (glob(__DIR__.'/../../app/Domains/*/Models/*.php') as $path) {
        $domain = basename(dirname($path, 2));
        $name = basename($path, '.php');
        $class = "App\\Domains\\{$domain}\\Models\\{$name}";

        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            $models[] = $class;
        }
    }

    sort($models);

    if ($models === []) {
        throw new RuntimeException('Model discovery found nothing — the smoke test would silently pass.');
    }

    return $models;
}

// Lazy: the app must be booted before app_path() and the autoloader resolve.
dataset('domain models', fn () => allDomainModels());

it('resolves every model to a table that actually exists', function (string $modelClass) {
    $model = new $modelClass;

    expect(Schema::hasTable($model->getTable()))
        ->toBeTrue("{$modelClass} resolves to table '{$model->getTable()}', which does not exist");
})->with('domain models');

it('persists every model through its factory', function (string $modelClass) {
    $model = $modelClass::factory()->create();

    expect($model->exists)->toBeTrue()
        ->and($model->fresh())->not->toBeNull("{$modelClass} could not be read back after creation");
})->with('domain models');
