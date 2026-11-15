<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Identity\Models\User;

/**
 * The menu keeps its section lit on the section's own detail and sub-pages.
 */
function litMenuLink(string $html): ?string
{
    preg_match('/<a\s[^>]*href="([^"]+)"[^>]*aria-current="page"/', $html, $match);

    return $match[1] ?? null;
}

it('lights Users on a member’s profile', function () {
    $member = User::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    expect(litMenuLink($this->get(route('admin.members.show', $member))->assertOk()->getContent()))->toBe(route('admin.members'));
});

it('lights Bookings on the seat-request and waiting-list pages', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    expect(litMenuLink($this->get(route('admin.seat-requests'))->assertOk()->getContent()))->toBe(route('admin.bookings'));
});
