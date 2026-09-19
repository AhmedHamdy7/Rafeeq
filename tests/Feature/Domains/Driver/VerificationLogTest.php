<?php

use App\Domains\Driver\Models\VerificationLog;
use Illuminate\Support\Facades\Schema;

it('records who changed what and why', function () {
    $log = VerificationLog::factory()->create();

    expect($log->admin)->not->toBeNull()
        ->and($log->old_value)->toBeArray()
        ->and($log->new_value)->toBeArray();
});

it('cannot be updated once written', function () {
    $log = VerificationLog::factory()->create();

    $log->update(['reason' => 'trying to rewrite history']);
})->throws(RuntimeException::class);

it('cannot be deleted', function () {
    $log = VerificationLog::factory()->create();

    $log->delete();
})->throws(RuntimeException::class);

it('has no updated_at column', function () {
    expect(Schema::hasColumn('verification_logs', 'updated_at'))->toBeFalse();
});
