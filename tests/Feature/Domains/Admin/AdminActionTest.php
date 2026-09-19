<?php

use App\Domains\Admin\Models\AdminAction;
use Illuminate\Support\Facades\Schema;

it('cannot be updated once written', function () {
    $action = AdminAction::factory()->create();

    $action->update(['reason' => 'rewriting the audit trail']);
})->throws(RuntimeException::class);

it('cannot be deleted', function () {
    $action = AdminAction::factory()->create();

    $action->delete();
})->throws(RuntimeException::class);

it('has no updated_at column', function () {
    expect(Schema::hasColumn('admin_actions', 'updated_at'))->toBeFalse();
});
