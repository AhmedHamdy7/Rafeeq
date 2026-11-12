<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Support\Navigation;
use App\Http\Middleware\SetAdminLocale;
use Illuminate\Support\Arr;

/**
 * The new dashboard shell (RAFEEQ_Admin_Dashboard.html, phase 0): sidebar, top bar,
 * language, theme. The pages inside it have their own tests.
 */

/** @return list<string> every link key the sidebar shows this admin */
function sidebarKeys(AdminRole $role): array
{
    $keys = [];

    foreach (Navigation::for(adminWithRole($role)) as $group) {
        foreach ($group['items'] as $item) {
            $keys[] = $item['key'];
        }
    }

    return $keys;
}

it('draws the new shell around a dashboard page', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('class="ad-side"', false)
        ->assertSee('css/admin/tokens.css', false)
        ->assertSee(__('admin/shell.nav.home'))
        ->assertSee(__('admin/shell.groups.live'));
});

it('shows an Admin every page that exists, and only pages that exist', function () {
    $keys = sidebarKeys(AdminRole::SuperAdmin);

    expect($keys)->toContain('home', 'statistics', 'sos', 'live_trips', 'driver_verification', 'users', 'payments', 'settings', 'audit')
        // Sections of the design that are not built yet are not in the menu at all.
        ->not->toContain('announcements');
});

it('hides from a role the pages it may not open', function () {
    $finance = sidebarKeys(AdminRole::Finance);

    expect($finance)->toContain('payments')
        ->not->toContain('sos', 'driver_verification', 'users', 'settings');

    $verification = sidebarKeys(AdminRole::Verification);

    expect($verification)->toContain('identity')
        ->not->toContain('payments', 'sos', 'audit');
});

it('links only to routes that exist, so no menu item can 404', function () {
    foreach (Navigation::for(adminWithRole(AdminRole::SuperAdmin)) as $group) {
        foreach ($group['items'] as $item) {
            expect(route($item['route']))->toBeString();
        }
    }
});

it('switches the dashboard to Arabic and keeps it there', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    $this->from(route('admin.dashboard'))
        ->post(route('admin.locale', 'ar'))
        ->assertRedirect(route('admin.dashboard'));

    expect(session(SetAdminLocale::SESSION_KEY))->toBe('ar');

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee('lang="ar"', false)
        ->assertSee(__('admin/shell.nav.home', [], 'ar'));
});

it('switches back to English', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    $this->post(route('admin.locale', 'ar'));
    $this->post(route('admin.locale', 'en'));

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('dir="ltr"', false)
        ->assertSee(__('admin/shell.nav.home', [], 'en'));
});

it('refuses a language the dashboard does not have', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    $this->post(route('admin.locale', 'fr'))->assertNotFound();

    expect(session(SetAdminLocale::SESSION_KEY))->toBeNull();
});

it('never sends the switch anywhere outside the dashboard', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    $this->from('https://elsewhere.example/phish')
        ->post(route('admin.locale', 'ar'))
        ->assertRedirect(route('admin.home'));
});

it('lets a signed-out visitor choose the language of the sign-in page', function () {
    $this->post(route('admin.locale', 'ar'))->assertRedirect();

    expect(session(SetAdminLocale::SESSION_KEY))->toBe('ar');
});

it('opens the components gallery for signed-in staff only', function () {
    $this->get(route('admin.components'))->assertRedirect(route('admin.login'));

    actingAsAdmin(adminWithRole(AdminRole::Support));

    $this->get(route('admin.components'))
        ->assertOk()
        ->assertSee(__('admin/components.title'));
});

it('has every shell and gallery text in both languages', function () {
    foreach (['admin/shell', 'admin/components'] as $file) {
        $en = array_keys(Arr::dot(trans($file, [], 'en')));
        $ar = array_keys(Arr::dot(trans($file, [], 'ar')));

        expect($ar)->toEqualCanonicalizing($en);
    }
});
